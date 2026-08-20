<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Framework\Framework_Mutations;
use App\RSpade\Core\Framework\Framework_Verify;
use Illuminate\Console\Command;

/**
 * rsx:framework:verify - offline framework-tree tamper check.
 *
 * Reads system/.rspade-release.json (the release inventory) + the mutation marker
 * store, and reports any on-disk deviation the framework did NOT author:
 *   - unauthorized: mismatch/missing/extra under an owned zone (app/RSpade,
 *     vendor, node_modules, bin, docs) -> exit 1 (the pull script aborts on these).
 *   - local_changes: the same, anywhere else in system/ -> exit 0 with a notice.
 *   - committed_drift: a finding whose path git reports as identical to the
 *     committed state -> exit 0 with a notice (owner ruling 2026-08-05; see
 *     Framework_Verify). This command assembles that git information; when git
 *     is unavailable or errors it passes null and the classification is exactly
 *     what it was before the rule existed.
 *
 * Silent-success: a clean tree prints one [OK] line. No release manifest present
 * (this framework-dev tree) -> INFO + exit 0. --json emits the machine shape for
 * the future pull script; --diff prints disk-vs-shadow diffs for marked files.
 */
class Framework_Verify_Command extends Command
{
    protected $signature = 'rsx:framework:verify {--json : Emit machine-readable JSON} {--diff : Print disk-vs-shadow diffs for flagged files} {--base-dir= : Verify a tree rooted elsewhere (test seam; default = base_path())}';

    protected $description = 'Verify the framework tree against its release inventory + mutation markers';

    public function handle(): int
    {
        $as_json = (bool) $this->option('json');
        $show_diff = (bool) $this->option('diff');

        // --base-dir points the whole check (inventory, markers, hashing base) at
        // an arbitrary tree. The pull script never uses it (it verifies the real
        // tree); the CLI test suite uses it to verify fixture trees with the real
        // implementation. Default: the live framework tree.
        $override_base = $this->option('base-dir');
        $store_root = null;
        if ($override_base !== null && $override_base !== '') {
            $base_dir = rtrim($override_base, '/');
            $manifest_path = $base_dir . '/.rspade-release.json';
            $store_root = $base_dir . '/storage/rsx-framework';
        } else {
            $base_dir = base_path();
            $manifest_path = base_path('.rspade-release.json');
        }

        // No release manifest -> this is a framework-development tree (or a
        // pre-conversion checkout). Nothing to verify against.
        if (!file_exists($manifest_path)) {
            if ($as_json) {
                $this->line(json_encode([
                    'clean'         => true,
                    'no_manifest'   => true,
                    'unauthorized'  => [],
                    'local_changes' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            $this->line('[INFO] No release manifest (.rspade-release.json) - framework development tree or pre-conversion checkout; nothing to verify.');

            return 0;
        }

        $manifest = json_decode(file_get_contents($manifest_path), true);
        if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
            throw new \RuntimeException(
                "Fatal: release manifest at {$manifest_path} is malformed (expected a 'files' map)."
            );
        }

        $owned = Framework_Mutations::owned_zones();
        $markers = Framework_Mutations::get_index($store_root)['entries'];

        $result = Framework_Verify::verify([
            'base_dir'          => $base_dir,
            'inventory'         => $manifest['files'],
            'markers'           => $markers,
            'owned_zone_dirs'   => $owned['dirs'],
            'owned_zone_files'  => $owned['files'],
            'pristine_provider' => $this->__build_pristine_provider($base_dir, $store_root, $manifest['files']),
            'git_dirty_paths'   => $git_dirty_paths = $this->__collect_git_dirty_paths($base_dir),
        ]);

        // SAY SO when git could not answer. A null here silently disables the committed-drift
        // exemption, so a safely-committed framework edit is reported as unauthorized tampering
        // and the tamper gate refuses to update - with nothing anywhere explaining why. Degrading
        // quietly is what turned this into a multi-day update deadlock nobody could diagnose
        // (field report, 2026-08-10). The verdict below is still honest; it is just STRICTER than it
        // would be with git information, and the operator needs to know which one they are reading.
        if ($git_dirty_paths === null) {
            $this->getOutput()->getErrorOutput()->writeln(
                '[WARNING] Could not read git status for this tree, so the committed-drift '
                . 'exemption did NOT run. Findings below may include changes that are safely '
                . 'committed. Check `git -C <project> status -- system` by hand before treating '
                . 'any of them as tampering.'
            );
        }

        if ($as_json) {
            $this->line(json_encode([
                'clean'           => $result['clean'],
                'no_manifest'     => false,
                'unauthorized'    => $result['unauthorized'],
                'local_changes'   => $result['local_changes'],
                'framework_churn' => $result['framework_churn'] ?? [],
                'committed_drift' => $result['committed_drift'] ?? [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return empty($result['unauthorized']) ? 0 : 1;
        }

        // Human-readable output.
        if (!empty($result['framework_churn'])) {
            $n = count($result['framework_churn']);
            $this->line("[INFO] {$n} structurally-authorized class-override artifact(s) (rename pairs / use-rewrites) - expected framework churn, not findings.");
        }

        if (!empty($result['committed_drift'])) {
            $this->line('[INFO] Committed drift (accepted - the working tree matches git HEAD, so nothing can be lost):');
            $this->line('');
            foreach ($result['committed_drift'] as $finding) {
                $this->__print_finding($finding, $show_diff, $base_dir, $store_root);
            }
            $this->line('');
        }

        if ($result['clean']) {
            $file_count = count($manifest['files']);
            $this->line("[OK] Framework tree verified clean ({$file_count} inventory files).");

            return 0;
        }

        if (!empty($result['unauthorized'])) {
            $this->line('[ERROR] Unauthorized changes detected under framework-owned zones:');
            $this->line('');
            foreach ($result['unauthorized'] as $finding) {
                $this->__print_finding($finding, $show_diff, $base_dir, $store_root);
            }
            $this->line('');
        }

        if (!empty($result['local_changes'])) {
            $this->line('[WARNING] Local system changes (outside owned zones - allowed, merged three-way on update):');
            $this->line('');
            foreach ($result['local_changes'] as $finding) {
                $this->__print_finding($finding, $show_diff, $base_dir, $store_root);
            }
            $this->line('');
        }

        // Exit policy: the pull script gates on unauthorized findings only.
        return empty($result['unauthorized']) ? 0 : 1;
    }

    /**
     * Collect the set of framework-tree paths git reports as differing from the
     * committed state, base-dir-relative (the shape Framework_Verify findings
     * use). Feeds the committed-drift rule: a finding on a path NOT in this set
     * is already committed and never gates.
     *
     * Runs `git status --porcelain=v1 -z --ignored` from the PROJECT ROOT
     * (dirname of the framework tree), scoped to the framework directory by
     * pathspec. `--ignored` is deliberate: an ignored file is untracked, so it
     * is NOT recoverable from history and must keep flagging. Untracked and
     * ignored DIRECTORIES arrive collapsed as a single entry ending in '/',
     * which the verifier treats as a prefix. Rename entries carry both the new
     * and the old path (each NUL-terminated) and both count as dirty.
     *
     * The inherited git context is stripped (env -u GIT_DIR / GIT_WORK_TREE /
     * GIT_INDEX_FILE): this command is reachable from inside the pre-commit
     * hook via rsx:clean, where git exports those and would re-target the call
     * at the in-progress commit's index (same reason Clean_Command strips them).
     *
     * Output goes to a temp file rather than the exec_safe capture: exec_safe
     * merges stderr into stdout and splits on newlines, neither of which
     * survives a NUL-separated stream intact.
     *
     * @return string[]|null null when git could not answer (not a repo, git
     *         absent, command failed) - NEVER an empty set, which would mean
     *         "everything is committed" and open the gate.
     */
    private function __collect_git_dirty_paths(string $base_dir): ?array
    {
        $project_root = dirname($base_dir);
        $tree_name = basename($base_dir);

        // --no-optional-locks IS LOAD-BEARING. `git status` normally REFRESHES the index and
        // takes .git/index.lock to write it back; if that lock is held - or orphaned by a
        // process killed mid-write, which a status-line render does routinely - status exits
        // non-zero and this method returns null. Null means "no git information", which
        // SILENTLY disables the committed-drift exemption in Framework_Verify (PASS E), so a
        // framework file whose edit is safely COMMITTED gets reclassified as unauthorized
        // tampering and the tamper gate refuses the update.
        //
        // That is the deadlock seen on a live box (field report, 2026-08-10): a committed edit to a
        // framework file blocked every pull, and the result varied between invocations
        // depending on whether a lock happened to be present - which is exactly what an
        // index-lock-sensitive probe looks like from outside. Read-only status cannot be
        // blocked by a lock at all, so the exemption now runs whenever git can answer.
        $git = 'env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git --no-optional-locks -C '
            . escapeshellarg($project_root);

        $out = [];
        $rc = 0;
        \exec_safe($git . ' rev-parse --git-dir 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            return null; // not a git repository (or no git) -> no information
        }

        // The framework tree may sit below the repository root; porcelain paths
        // are always repository-root-relative, so the leading prefix is
        // whatever git reports for the project root plus the tree name.
        $out = [];
        \exec_safe($git . ' rev-parse --show-prefix 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            return null;
        }
        $strip = trim($out[0] ?? '') . $tree_name . '/';

        $tmp = tempnam(sys_get_temp_dir(), 'rsxgitstatus');
        if ($tmp === false) {
            return null;
        }
        $out = [];
        \exec_safe(
            $git . ' status --porcelain=v1 -z --ignored -- ' . escapeshellarg($tree_name)
            . ' > ' . escapeshellarg($tmp) . ' 2>/dev/null',
            $out,
            $rc
        );
        $blob = ($rc === 0) ? file_get_contents($tmp) : false;
        @unlink($tmp);

        if ($rc !== 0 || $blob === false) {
            return null;
        }

        $fields = explode("\0", $blob);
        $dirty = [];
        for ($i = 0; $i < count($fields); $i++) {
            $field = $fields[$i];
            if ($field === '') {
                continue;
            }
            // Entry shape: "XY <path>". A rename/copy status is followed by one
            // extra field holding the ORIGINAL path.
            $status = substr($field, 0, 2);
            $path = substr($field, 3);
            if ($path !== '') {
                $dirty[] = $path;
            }
            if (str_contains($status, 'R') || str_contains($status, 'C')) {
                $i++;
                if (isset($fields[$i]) && $fields[$i] !== '') {
                    $dirty[] = $fields[$i];
                }
            }
        }

        // Reduce repository-root-relative paths to framework-tree-relative ones.
        $relative = [];
        foreach ($dirty as $path) {
            // The whole framework tree collapsed into one untracked/ignored
            // entry: NOTHING under it is committed. Report no information, which
            // classifies every finding exactly as it did before this rule - the
            // same outcome, without inventing a dirty entry per file.
            if ($path === $strip || $path === rtrim($strip, '/')) {
                return null;
            }
            if (str_starts_with($path, $strip)) {
                $relative[] = substr($path, strlen($strip));
            }
        }

        return $relative;
    }

    /**
     * Build the pristine-bytes provider for Framework_Verify's structural
     * override-artifact authorization (PASS D). Bytes come from the local
     * upstream distribution cache (Framework_Maintenance::upstream_cache_dir());
     * the exact packaging commit for the installed release is unknown, so the
     * provider resolves it by PROOF: it walks recent cache commits and keeps
     * the first whose bytes for the requested path hash-match the release
     * inventory. Every retrieval is inventory-validated (here AND again in
     * verify itself), so a wrong commit can only ever produce null - never
     * wrong pristine bytes. Returns null (provider disabled) when no cache
     * exists; verification then simply falls back to ledger-only behavior.
     */
    private function __build_pristine_provider(string $base_dir, ?string $store_root, array $inventory): ?callable
    {
        // Real runs read the shared cache through its single source of truth; a
        // --base-dir run (test seam) reads the fixture's own clone beside its store,
        // so a test never depends on - or disturbs - the shared cache.
        $git_dir = $store_root !== null
            ? $store_root . '/upstream.git'
            : Framework_Maintenance::upstream_cache_dir();
        if (!is_dir($git_dir)) {
            return null;
        }

        $resolved_commit = null;

        return function (string $rel) use ($git_dir, $inventory, &$resolved_commit): ?string {
            if (!isset($inventory[$rel])) {
                return null;
            }

            // exec()-style capture strips trailing-newline fidelity, so fetch
            // to a temp file for byte-exact content (hash validation depends
            // on exact bytes).
            $cat = function (string $commit, string $path) use ($git_dir): ?string {
                $tmp = tempnam(sys_get_temp_dir(), 'rsxpristine');
                $rc = 0;
                $out = [];
                \exec_safe(
                    'git --git-dir=' . escapeshellarg($git_dir)
                    . ' cat-file blob ' . escapeshellarg($commit . ':' . $path)
                    . ' > ' . escapeshellarg($tmp) . ' 2>/dev/null',
                    $out,
                    $rc
                );
                $bytes = ($rc === 0) ? file_get_contents($tmp) : null;
                @unlink($tmp);

                return ($bytes === false || $bytes === null) ? null : $bytes;
            };

            // Fast path: a commit already proven for an earlier path.
            if ($resolved_commit !== null) {
                $bytes = $cat($resolved_commit, $rel);
                if ($bytes !== null && hash('sha256', $bytes) === $inventory[$rel]) {
                    return $bytes;
                }
                // Proven commit lacks matching bytes for THIS path - fall
                // through and re-resolve (packaging edge), else fail closed.
            }

            $out = [];
            $rc = 0;
            \exec_safe(
                'git --git-dir=' . escapeshellarg($git_dir) . ' log --all --format=%H -60 2>/dev/null',
                $out,
                $rc
            );
            if ($rc !== 0) {
                return null;
            }
            foreach ($out as $commit) {
                $commit = trim($commit);
                if ($commit === '') {
                    continue;
                }
                $bytes = $cat($commit, $rel);
                if ($bytes !== null && hash('sha256', $bytes) === $inventory[$rel]) {
                    $resolved_commit = $commit;

                    return $bytes;
                }
            }

            return null; // no cache commit carries pristine bytes -> fail closed
        };
    }

    /**
     * Print one finding, and (with --diff) a disk-vs-shadow diff for marked files
     * or a hash-level notice for inventory-only mismatches.
     */
    private function __print_finding(array $finding, bool $show_diff, string $base_dir, ?string $store_root): void
    {
        $kind_label = [
            'mismatch'           => 'CHANGED',
            'missing'            => 'MISSING',
            'extra'              => 'EXTRA',
            'unexpected_present' => 'REAPPEARED',
        ][$finding['kind']] ?? strtoupper($finding['kind']);

        $this->line("  [{$kind_label}] {$finding['path']}");

        if (!$show_diff) {
            return;
        }

        if ($finding['kind'] === 'mismatch' && !empty($finding['has_shadow'])) {
            $shadow = Framework_Mutations::shadow_path($finding['path'], $store_root);
            $disk = $base_dir . '/' . $finding['path'];
            if (file_exists($shadow) && file_exists($disk)) {
                $out = [];
                $rc = 0;
                \exec_safe('diff -u ' . escapeshellarg($shadow) . ' ' . escapeshellarg($disk) . ' 2>&1', $out, $rc);
                foreach ($out as $line) {
                    $this->line('    ' . $line);
                }
                $this->line('');

                return;
            }
        }

        if ($finding['kind'] === 'mismatch') {
            $this->line('    (inventory-only mismatch - no shadow to diff)');
            $this->line('    expected sha256: ' . ($finding['expected_sha'] ?? '(none)'));
            $this->line('    on-disk  sha256: ' . ($finding['disk_sha'] ?? '(none)'));
            $this->line('');
        }
    }
}
