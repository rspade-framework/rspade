<?php

namespace App\RSpade\Core\Framework;

/**
 * Offline framework-tree tamper verifier.
 *
 * Given a release inventory (`.rspade-release.json`: {release_id, date, files:
 * {rel_path: sha256}}) and the mutation marker store (Framework_Mutations), it
 * decides, per file, whether the on-disk framework tree matches its expected
 * pristine-or-legitimately-mutated state:
 *
 *   expected(path) = shadow/marker content if the path is marked, else the
 *                    release-inventory pristine hash.
 *   A path a marker records ABSENT (renamed away) is expected absent.
 *   Extra files under an owned zone that are neither in the inventory nor
 *   explained by a present marker (e.g. a `.php.upstream` rename target) are
 *   findings too.
 *
 * Each mismatch/missing/extra is classified by zone: under an OWNED zone
 * (app/RSpade, vendor, node_modules, bin, docs) it is 'unauthorized' (the pull
 * script aborts on these); anywhere else in system/ it is a 'local_change'
 * (allowed; the three-way merge handles it). EXCEPTION: the composer autoloader
 * outputs (vendor/composer/autoload_*.php, installed.php/json, InstalledVersions.php,
 * vendor/autoload.php) are REGENERABLE - the rebuild re-dumps them and the
 * class-override system re-aliases overrides into the classmap - so they are never a
 * finding (see __is_regenerable). Without this, every downstream app hard-stops on
 * update because its classmap drifted from override injection.
 *
 * COMMITTED DRIFT (owner ruling 2026-08-05): the verifier ALSO consults git.
 * When the caller supplies `git_dirty_paths` (the set of paths git reports as
 * differing from the committed state), any finding whose path git considers
 * CLEAN is moved out of 'unauthorized'/'local_changes' into 'committed_drift' -
 * reported, never fatal. Rationale: a git-clean path is already committed, so
 * the rsx:clean reset (`git checkout -- system`) cannot destroy anything for it
 * and the drift is recoverable from history. Commits are the acceptance
 * boundary: once a change is committed, blocking an update or a reset on it
 * protects nothing. `git_dirty_paths` is nullable - null means NO git
 * information was available (not a repo, git absent, git errored) and the
 * classification behaves exactly as it did before this rule existed. It is
 * never inferred: an error must yield null, never an empty (= everything
 * clean) set.
 *
 * PURE + testable: verify() takes every input explicitly (base dir, inventory,
 * markers, owned-zone lists, git dirty set) so a synthetic tree can be verified
 * without artisan, git, or the real store. The command
 * (Framework_Verify_Command) is a thin wrapper that assembles the real inputs
 * and formats output. Hashing streams via hash_file() - large trees
 * (vendor/node_modules) are never slurped into memory.
 */
class Framework_Verify
{
    /**
     * Run the verification.
     *
     * @param array{
     *   base_dir:string,
     *   inventory:array<string,string>|null,
     *   markers:array<string,array<string,mixed>>,
     *   owned_zone_dirs:string[],
     *   owned_zone_files:string[],
     *   git_dirty_paths?:string[]|null
     * } $config git_dirty_paths: base-dir-relative paths git reports as
     *   differing from the committed state (a trailing '/' entry is a directory
     *   prefix, as porcelain reports collapsed untracked/ignored directories).
     *   null = no git information; [] = git says everything is committed.
     * @return array{
     *   no_manifest:bool,
     *   clean:bool,
     *   unauthorized:array<int,array<string,mixed>>,
     *   local_changes:array<int,array<string,mixed>>,
     *   committed_drift:array<int,array<string,mixed>>
     * }
     */
    public static function verify(array $config): array
    {
        $base_dir = rtrim($config['base_dir'], '/');
        $inventory = $config['inventory'];
        $markers = $config['markers'] ?? [];
        $owned_dirs = $config['owned_zone_dirs'];
        $owned_files = $config['owned_zone_files'];

        if ($inventory === null) {
            return [
                'no_manifest'     => true,
                'clean'           => true,
                'unauthorized'    => [],
                'local_changes'   => [],
                'committed_drift' => [],
            ];
        }

        $unauthorized = [];
        $local_changes = [];

        $classify = function (array $finding) use ($owned_dirs, $owned_files, &$unauthorized, &$local_changes): void {
            if (self::__is_regenerable($finding['path'])) {
                return; // composer autoloader output - drift is expected machine output, never a finding
            }
            if (self::__is_owned($finding['path'], $owned_dirs, $owned_files)) {
                $unauthorized[] = $finding;
            } else {
                $local_changes[] = $finding;
            }
        };

        // -----------------------------------------------------------------
        // PASS A: every inventory path.
        // -----------------------------------------------------------------
        foreach ($inventory as $rel => $inv_sha) {
            if (str_starts_with($rel, 'storage/')) {
                continue;
            }

            $abs = $base_dir . '/' . $rel;
            $marker = $markers[$rel] ?? null;
            $exists = file_exists($abs);

            // Marked absent (renamed away) -> expected gone.
            if ($marker !== null && ($marker['state'] ?? null) === 'absent') {
                if ($exists) {
                    $classify([
                        'path'         => $rel,
                        'kind'         => 'unexpected_present',
                        'expected_sha' => null,
                        'disk_sha'     => hash_file('sha256', $abs),
                        'has_shadow'   => false,
                    ]);
                }
                continue;
            }

            // Expected present: marker content overrides pristine inventory.
            $has_marker_present = ($marker !== null && ($marker['state'] ?? null) === 'present' && !empty($marker['post_write_sha256']));
            $expected_sha = $has_marker_present ? $marker['post_write_sha256'] : $inv_sha;

            if (!$exists) {
                $classify([
                    'path'         => $rel,
                    'kind'         => 'missing',
                    'expected_sha' => $expected_sha,
                    'disk_sha'     => null,
                    'has_shadow'   => $has_marker_present,
                ]);
                continue;
            }

            $disk_sha = hash_file('sha256', $abs);
            if ($disk_sha !== $expected_sha) {
                $classify([
                    'path'         => $rel,
                    'kind'         => 'mismatch',
                    'expected_sha' => $expected_sha,
                    'disk_sha'     => $disk_sha,
                    'has_shadow'   => $has_marker_present,
                ]);
            }
        }

        // -----------------------------------------------------------------
        // PASS B: marker entries recorded ABSENT that the inventory does not
        // cover (unusual - a renamed framework file is normally in inventory).
        // -----------------------------------------------------------------
        foreach ($markers as $rel => $marker) {
            if (($marker['state'] ?? null) !== 'absent') {
                continue;
            }
            if (isset($inventory[$rel])) {
                continue; // handled in PASS A
            }
            $abs = $base_dir . '/' . $rel;
            if (file_exists($abs)) {
                $classify([
                    'path'         => $rel,
                    'kind'         => 'unexpected_present',
                    'expected_sha' => null,
                    'disk_sha'     => hash_file('sha256', $abs),
                    'has_shadow'   => false,
                ]);
            }
        }

        // -----------------------------------------------------------------
        // PASS C: extra files on disk under owned zones, not in inventory and
        // not explained by a present marker (e.g. a `.php.upstream` target).
        // -----------------------------------------------------------------
        $extras = self::__sweep_extras($base_dir, $owned_dirs, $inventory, $markers);
        foreach ($extras as $extra) {
            if (self::__is_regenerable($extra['path'])) {
                continue; // composer autoloader output - regenerable, never unauthorized
            }
            // Extras are, by definition, under an owned zone -> always unauthorized.
            $unauthorized[] = $extra;
        }

        // -----------------------------------------------------------------
        // PASS D: structural authorization of class-override artifacts.
        //
        // The class-override machinery mutates the framework tree on every
        // rebuild: it renames an overridden `X.php` aside to `X.php.upstream`
        // and rewrites `use` imports in dependent framework files to point at
        // the app's override class. Those mutations are attested in the marker
        // ledger when they are APPLIED - but a mutation applied while the
        // ledger was absent (pre-ledger installs, a lost store) is a fixpoint
        // the rebuild never re-applies, so it can never be re-attested by
        // events alone. Without this pass, such installs demand --force on
        // every pull forever.
        //
        // Both artifact classes are verifiable STRUCTURALLY, with no ledger:
        //   - rename pair: `missing X.php` + `extra X.php.upstream` is
        //     authorized iff the .upstream bytes are byte-identical to the
        //     pristine release X.php (sha256 vs inventory) - tampering cannot
        //     hide in a file that must equal pristine.
        //   - use-rewrite: a `mismatch` is authorized iff the file differs
        //     from the pristine release bytes ONLY in `use` import lines
        //     (import lines alias names; redirecting a framework reference to
        //     an app class is exactly the power the sanctioned class-override
        //     feature already grants, so this confers no new trust). Pristine
        //     bytes come from the optional `pristine_provider` callable and
        //     are hash-validated against the inventory - fail-closed.
        //
        // Authorized findings move to `framework_churn` (reported, never
        // gated). The marker ledger remains the primary mechanism; this pass
        // is the stateless second layer that keeps verification correct on
        // any healthy overridden install.
        // -----------------------------------------------------------------
        $pristine_provider = $config['pristine_provider'] ?? null;
        $framework_churn = self::__authorize_override_artifacts(
            $unauthorized,
            $base_dir,
            $inventory,
            $pristine_provider
        );

        // -----------------------------------------------------------------
        // PASS E: committed-drift subtraction (owner ruling 2026-08-05).
        //
        // A finding whose path git reports as CLEAN is already committed: the
        // rsx:clean reset restores exactly what is on disk, so nothing can be
        // lost, and the change is recoverable from history. Such findings are
        // moved to `committed_drift` - reported, never fatal, and excluded from
        // both gating buckets. Runs AFTER pass D so the structural
        // override-artifact authorization still sees the complete unauthorized
        // set (a rename pair whose two halves differ in git state must still be
        // paired). A null dirty set means no git information was available and
        // this pass does nothing at all.
        // -----------------------------------------------------------------
        $committed_drift = [];
        $git_dirty_paths = $config['git_dirty_paths'] ?? null;
        if (is_array($git_dirty_paths)) {
            [$dirty_exact, $dirty_prefixes] = self::__index_dirty_paths($git_dirty_paths);

            $subtract = function (array &$bucket) use ($dirty_exact, $dirty_prefixes, &$committed_drift): void {
                $remaining = [];
                foreach ($bucket as $finding) {
                    if (self::__is_git_dirty($finding['path'], $dirty_exact, $dirty_prefixes)) {
                        $remaining[] = $finding;
                        continue;
                    }
                    $finding['committed'] = true;
                    $committed_drift[] = $finding;
                }
                $bucket = $remaining;
            };

            $subtract($unauthorized);
            $subtract($local_changes);
        }

        return [
            'no_manifest'     => false,
            'clean'           => empty($unauthorized) && empty($local_changes),
            'unauthorized'    => $unauthorized,
            'local_changes'   => $local_changes,
            'framework_churn' => $framework_churn,
            'committed_drift' => $committed_drift,
        ];
    }

    /**
     * Split a raw dirty-path list into an exact-match set and a directory-prefix
     * list. Porcelain collapses an untracked or ignored DIRECTORY into a single
     * entry ending in '/', which stands for everything beneath it.
     *
     * @param string[] $paths
     * @return array{0:array<string,true>,1:string[]}
     */
    private static function __index_dirty_paths(array $paths): array
    {
        $exact = [];
        $prefixes = [];
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }
            if (str_ends_with($path, '/')) {
                $prefixes[] = $path;
                continue;
            }
            $exact[$path] = true;
        }

        return [$exact, $prefixes];
    }

    /**
     * True when git reports this path as differing from the committed state
     * (directly, or by living under a collapsed dirty directory entry).
     *
     * @param array<string,true> $dirty_exact
     * @param string[] $dirty_prefixes
     */
    private static function __is_git_dirty(string $rel, array $dirty_exact, array $dirty_prefixes): bool
    {
        if (isset($dirty_exact[$rel])) {
            return true;
        }
        foreach ($dirty_prefixes as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PASS D implementation. Removes structurally-authorized override
     * artifacts from $unauthorized (by reference) and returns them, tagged
     * with an `authorized_as` reason, as the framework_churn list.
     *
     * @param array<int,array<string,mixed>> $unauthorized modified in place
     * @param callable|null $pristine_provider fn(string $rel): ?string -
     *        returns the pristine release bytes for a path, or null when
     *        unavailable. Callers SHOULD hash-validate against the inventory;
     *        this method validates again regardless (defense in depth).
     * @return array<int,array<string,mixed>>
     */
    private static function __authorize_override_artifacts(
        array &$unauthorized,
        string $base_dir,
        array $inventory,
        ?callable $pristine_provider
    ): array {
        $churn = [];
        $by_path = [];
        foreach ($unauthorized as $i => $finding) {
            $by_path[$finding['path']] = $i;
        }
        $authorized_idx = [];

        foreach ($unauthorized as $i => $finding) {
            $path = $finding['path'];

            // Rename pair: missing X.php whose X.php.upstream twin is pristine.
            if ($finding['kind'] === 'missing' && isset($inventory[$path])) {
                $upstream_rel = $path . '.upstream';
                $j = $by_path[$upstream_rel] ?? null;
                if ($j !== null && $unauthorized[$j]['kind'] === 'extra') {
                    $upstream_abs = $base_dir . '/' . $upstream_rel;
                    if (file_exists($upstream_abs)
                        && hash_file('sha256', $upstream_abs) === $inventory[$path]) {
                        $authorized_idx[$i] = 'class_override_rename';
                        $authorized_idx[$j] = 'class_override_rename';
                    }
                }
                continue;
            }

            // Use-rewrite: mismatch whose only delta vs pristine is import lines.
            if ($finding['kind'] === 'mismatch' && $pristine_provider !== null && isset($inventory[$path])) {
                $pristine = $pristine_provider($path);
                if ($pristine === null) {
                    continue; // no pristine bytes available -> stays unauthorized
                }
                if (hash('sha256', $pristine) !== $inventory[$path]) {
                    continue; // provider returned wrong bytes -> fail closed
                }
                $abs = $base_dir . '/' . $path;
                if (!file_exists($abs)) {
                    continue;
                }
                $disk = file_get_contents($abs);
                if ($disk === false) {
                    continue;
                }
                if (self::__strip_import_lines($pristine) === self::__strip_import_lines($disk)) {
                    $authorized_idx[$i] = 'class_override_use_rewrite';
                }
            }
        }

        if (empty($authorized_idx)) {
            return [];
        }

        $remaining = [];
        foreach ($unauthorized as $i => $finding) {
            if (isset($authorized_idx[$i])) {
                $finding['authorized_as'] = $authorized_idx[$i];
                $churn[] = $finding;
            } else {
                $remaining[] = $finding;
            }
        }
        $unauthorized = $remaining;

        return $churn;
    }

    /**
     * Remove `use ...;` import lines (plain class imports with optional
     * aliases - the only shape the override rewrite emits) and collapse
     * blank-line runs, so two versions of a file can be compared modulo
     * import churn. Anything else - code, comments, strings - survives
     * verbatim, so a single non-import edit defeats the equivalence.
     */
    private static function __strip_import_lines(string $content): string
    {
        $out = [];
        $blank_run = false;
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if (preg_match('/^use\s+\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*(\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?\s*;\s*$/', $line)) {
                continue;
            }
            if (trim($line) === '') {
                if ($blank_run) {
                    continue;
                }
                $blank_run = true;
                $out[] = '';
                continue;
            }
            $blank_run = false;
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * True when a relative path falls inside an owned zone (dir prefix or exact
     * file). Pure string work.
     */
    private static function __is_owned(string $rel, array $owned_dirs, array $owned_files): bool
    {
        foreach ($owned_dirs as $dir) {
            if (str_starts_with($rel, $dir)) {
                return true;
            }
        }

        return in_array($rel, $owned_files, true);
    }

    /**
     * True when a relative path is a REGENERABLE composer autoloader / install-metadata
     * output. These live under the vendor owned zone but are machine-generated by
     * `composer dump-autoload` on every rebuild: the class-override system re-aliases
     * overrides into the classmap (a framework `.php` renamed aside to `.php.upstream`),
     * so their drift is expected output, never a hand edit. The rebuild's
     * `_Manifest_Quality_Helper::_validate_composer_classmap()` heals them against the new
     * tree. Treat them as regenerable so the tamper gate never flags them - no `--force`
     * is ever required to update past a drifted autoloader. (composer/ClassLoader.php and
     * platform_check.php are NOT here: they are stable composer machinery, not regenerated
     * per rebuild, so they stay protected.)
     */
    private static function __is_regenerable(string $rel): bool
    {
        if ($rel === 'vendor/autoload.php') {
            return true;
        }
        if (str_starts_with($rel, 'vendor/composer/')) {
            $base = substr($rel, strlen('vendor/composer/'));
            if (str_contains($base, '/')) {
                return false; // only the direct composer/ outputs, never a package subdir
            }
            if (str_starts_with($base, 'autoload_') && str_ends_with($base, '.php')) {
                return true;
            }
            return in_array($base, ['installed.php', 'installed.json', 'InstalledVersions.php'], true);
        }
        return false;
    }

    /**
     * Sweep the disk under each owned-zone directory for files that are neither
     * in the inventory nor explained by a present marker. Skips .git and storage.
     *
     * @return array<int,array<string,mixed>> extra findings
     */
    private static function __sweep_extras(string $base_dir, array $owned_dirs, array $inventory, array $markers): array
    {
        $extras = [];

        foreach ($owned_dirs as $dir) {
            $zone_root = $base_dir . '/' . rtrim($dir, '/');
            if (!is_dir($zone_root)) {
                continue;
            }

            // Prune .git directories at the source so their contents are never walked.
            $dir_iterator = new \RecursiveDirectoryIterator($zone_root, \FilesystemIterator::SKIP_DOTS);
            $filter = new \RecursiveCallbackFilterIterator($dir_iterator, function ($current) {
                return $current->getFilename() !== '.git';
            });
            $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY);

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    continue;
                }

                $abs = $item->getPathname();
                $rel = substr($abs, strlen($base_dir) + 1);

                if (str_starts_with($rel, 'storage/')) {
                    continue;
                }
                if (isset($inventory[$rel])) {
                    continue; // covered by PASS A
                }
                $marker = $markers[$rel] ?? null;
                if ($marker !== null && ($marker['state'] ?? null) === 'present') {
                    continue; // explained (e.g. a .php.upstream rename target)
                }

                $extras[] = [
                    'path'         => $rel,
                    'kind'         => 'extra',
                    'expected_sha' => null,
                    'disk_sha'     => hash_file('sha256', $abs),
                    'has_shadow'   => false,
                ];
            }
        }

        return $extras;
    }
}
