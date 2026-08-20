<?php

namespace App\RSpade\Core\Framework;

/**
 * Framework mutation marker store.
 *
 * The framework legitimately rewrites its own tracked files at rebuild time
 * (Php_Fixer `use`-header rewrites, class-override `.php` -> `.php.upstream`
 * renames + restores, composer classmap regeneration, model_codegen docblock
 * regen). Downstream, these churn edits are indistinguishable from a developer's
 * genuine edit unless the framework records that it authored them. This store is
 * that record: every framework-authored write to a file inside an OWNED zone is
 * captured here, so a later tamper check (Framework_Verify) can subtract the
 * legitimate churn BY CONSTRUCTION - no regex, no diff heuristics.
 *
 * STORE LAYOUT (under storage/rsx-framework/, gitignored, survives rsx:clean - the
 * same directory Framework_Maintenance keeps its update flag in):
 *
 *   mutations.json          index:
 *     {
 *       "version": 1,
 *       "entries": {
 *         "<rel_path>": {
 *           "mechanism":         "file_write"|"php_fixer"|"model_codegen"
 *                                |"class_override_rename"|"class_override_selfheal"
 *                                |"class_override_restore"|"composer_dump",
 *           "state":             "present"|"absent",
 *           "post_write_sha256": "<hex>"|null,   // null iff state==absent
 *           "renamed_to":        "<rel_path>"|null,
 *           "updated_at":        "<ISO 8601>"
 *         }, ...
 *       }
 *     }
 *
 *   shadow/<rel_path>       byte-exact copy of the framework-authored content of
 *                           each PRESENT entry (the "expected state" the tamper
 *                           diff compares disk against). Absent entries have no
 *                           shadow.
 *
 * `<rel_path>` is always relative to base_path() (= system/).
 *
 * OWNED ZONES (the only paths this store records; everything else is a fast
 * no-op that never touches the disk): the directory prefixes and the individual
 * FILES listed below, all relative to base_path(). Writes to the store itself live
 * under storage/, which is NOT an owned zone, so recording never recurses through
 * the write hook.
 *
 * These two lists are the PHP half of a pair; the updater's own copies live in
 * system/bin/framework-pull-upstream.sh.dist (OWNED_DIRS / OWNED_FILES) and the two
 * must be changed together. A zone the updater hard-syncs but the verifier does not
 * classify as owned is a zone whose drift nothing reports.
 *
 * Framework-internal. Dependency-free (no Manifest calls) so it works during a
 * manifest rebuild. Fails loud: a corrupt mutations.json throws; a MISSING store
 * is simply "no markers".
 */
class Framework_Mutations
{
    /**
     * Owned-zone directory prefixes, relative to base_path(). A path is recorded
     * only when its resolved absolute location falls under one of these (or is one
     * of the exact owned files below).
     *
     * @var string[]
     */
    private const OWNED_ZONE_DIRS = [
        'app/RSpade/',
        'vendor/',
        'node_modules/',
        'bin/',
        'docs/',
        'database/',
        'config/',
        'resources/',
        'supervisor/',
    ];

    /**
     * Exact owned FILES, relative to base_path(). A first-class peer of the
     * directory list above, not a special case: any individual file may be owned,
     * and is then synced authoritatively exactly like a directory.
     *
     * .gitignore is owned because it is framework-authored, governs framework-tree
     * hygiene, and is precisely the kind of file an app is tempted to tweak - after
     * which the three-way pass preserves the local edit and that box silently stops
     * receiving every future upstream ignore rule. That is not hypothetical: one
     * local edit to config/rsx.php froze the file the same way and cost a downstream
     * app an outage two releases later (2026-08-11).
     *
     * app/Http/Kernel.php is owned because it is pure framework wiring - the
     * maintenance/migration/Playwright middlewares plus the deliberate REMOVALS of
     * Laravel's session/CSRF/ConvertEmptyStrings entries - that a framework release
     * regularly has to edit on the app's behalf. Under three-way treatment one local
     * tweak freezes it and that box stops receiving every future change to the request
     * stack. Apps append their own middleware through config('rsx.middleware') instead,
     * which is update-proof (2026-08-19).
     *
     * @var string[]
     */
    private const OWNED_ZONE_FILES = [
        'artisan',
        '.rspade-release.json',
        '.gitignore',
        'app/Http/Kernel.php',
    ];

    /**
     * Optional one-shot mechanism label. A caller (Php_Fixer, model_codegen)
     * sets this immediately before its file_put_contents_safe() write so the
     * generic write hook records a precise mechanism instead of the default
     * 'file_write'. Consumed (reset to null) by the next record_write().
     *
     * @var string|null
     */
    public static ?string $next_mechanism = null;

    // =====================================================================
    // Public API
    // =====================================================================

    /**
     * Record a framework-authored content write. Fast no-op unless $abs_path
     * resolves inside an owned zone under base_path() (cheap string prefix
     * checks; never hits the disk for out-of-zone paths).
     *
     * @param string $abs_path Absolute path that was just written (already
     *                         symlink-resolved by file_put_contents_safe).
     * @param string $mechanism Mechanism label; overridden by a pending
     *                          self::$next_mechanism hint if one is set.
     */
    public static function record_write(string $abs_path, string $mechanism): void
    {
        $hint = self::$next_mechanism;
        self::$next_mechanism = null;

        $rel = self::__owned_rel_path($abs_path);
        if ($rel === null) {
            return;
        }

        // The hint (set by the specific caller) wins over the generic label.
        $effective = $hint ?? $mechanism;

        self::__put_present_entry($rel, $abs_path, $effective, null);
    }

    /**
     * Record a rename of a framework file to another path (the class-override
     * `.php` -> `.php.upstream` rename, and its self-heal unlink variant). The
     * source path becomes expected-ABSENT; the destination becomes expected-
     * PRESENT with its current on-disk content shadowed.
     *
     * @param string $from_abs Absolute path now absent (the original `.php`).
     * @param string $to_abs   Absolute path now present (the `.php.upstream`).
     */
    public static function record_rename(string $from_abs, string $to_abs, string $mechanism): void
    {
        $from_rel = self::__owned_rel_path($from_abs);
        $to_rel = self::__owned_rel_path($to_abs);

        // Nothing to do if neither endpoint is in an owned zone.
        if ($from_rel === null && $to_rel === null) {
            return;
        }

        $index = self::get_index();

        if ($to_rel !== null) {
            self::__write_shadow($to_rel, $to_abs);
            $index['entries'][$to_rel] = [
                'mechanism'         => $mechanism,
                'state'             => 'present',
                'post_write_sha256' => hash_file('sha256', $to_abs),
                'renamed_to'        => null,
                'updated_at'        => self::__now(),
            ];
        }

        if ($from_rel !== null) {
            self::__remove_shadow($from_rel);
            $index['entries'][$from_rel] = [
                'mechanism'         => $mechanism,
                'state'             => 'absent',
                'post_write_sha256' => null,
                'renamed_to'        => $to_rel,
                'updated_at'        => self::__now(),
            ];
        }

        self::__save_index($index);
    }

    /**
     * Attest an ALREADY-APPLIED class-override rename at its steady state
     * (`.php` absent, `.php.upstream` present). Recording is otherwise
     * event-driven - it fires only when the rename is APPLIED - so a rename
     * that predates the marker store (a pre-ledger install, a lost store) is
     * a rebuild fixpoint that would never be re-attested. The manifest's
     * override pass calls this on the steady-state branch every rebuild,
     * making the ledger self-healing.
     *
     * No-ops (no index/shadow IO) when the pair is already attested with a
     * recorded hash matching the on-disk `.upstream` bytes.
     *
     * @param string $from_abs Absolute path expected absent (the `.php`).
     * @param string $to_abs   Absolute path present (the `.php.upstream`).
     */
    public static function attest_rename(string $from_abs, string $to_abs, string $mechanism): void
    {
        $from_rel = self::__owned_rel_path($from_abs);
        $to_rel = self::__owned_rel_path($to_abs);

        if ($from_rel === null && $to_rel === null) {
            return;
        }

        $entries = self::get_index()['entries'];
        $from_ok = $from_rel === null
            || (($entries[$from_rel]['state'] ?? null) === 'absent');
        $to_ok = $to_rel === null
            || ((($entries[$to_rel]['state'] ?? null) === 'present')
                && ($entries[$to_rel]['post_write_sha256'] ?? null) === hash_file('sha256', $to_abs));

        if ($from_ok && $to_ok) {
            return; // fully attested already - keep rebuilds IO-free
        }

        self::record_rename($from_abs, $to_abs, $mechanism);
    }

    /**
     * Record the reverse rename (class-override removed: `.php.upstream` -> `.php`).
     * The `.upstream` source entry is dropped entirely (it is gone). The restored
     * `.php` is recorded PRESENT with its CURRENT on-disk content - which is the
     * archived framework-authored original the rename put back - so the verify
     * pass never false-flags it, whatever that content is (pristine, or an earlier
     * Php_Fixer-rewritten state baked into the archive). A later Php_Fixer write to
     * the same path simply supersedes this marker via the generic hook.
     *
     * @param string $from_abs Absolute path now absent (the `.php.upstream`).
     * @param string $to_abs   Absolute path now present (the restored `.php`).
     */
    public static function record_restore(string $from_abs, string $to_abs): void
    {
        $from_rel = self::__owned_rel_path($from_abs);
        $to_rel = self::__owned_rel_path($to_abs);

        if ($from_rel === null && $to_rel === null) {
            return;
        }

        $index = self::get_index();

        // Drop the .upstream entry + its shadow - it no longer exists.
        if ($from_rel !== null) {
            unset($index['entries'][$from_rel]);
            self::__remove_shadow($from_rel);
        }

        // Record the restored .php as present with its current content.
        if ($to_rel !== null) {
            self::__write_shadow($to_rel, $to_abs);
            $index['entries'][$to_rel] = [
                'mechanism'         => 'class_override_restore',
                'state'             => 'present',
                'post_write_sha256' => hash_file('sha256', $to_abs),
                'renamed_to'        => null,
                'updated_at'        => self::__now(),
            ];
        }

        self::__save_index($index);
    }

    /**
     * Hash + shadow the composer autoloader outputs that a `composer dump-autoload`
     * regenerates. Records only the outputs that actually exist. Called at the dump
     * call site so BOTH the real runner and the test seam are covered.
     */
    public static function record_composer_dump(): void
    {
        $targets = [
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_static.php',
            'vendor/composer/autoload_real.php',
            'vendor/composer/autoload_files.php',
            'vendor/composer/installed.json',
            'vendor/composer/installed.php',
        ];

        $index = self::get_index();

        foreach ($targets as $rel) {
            $abs = base_path($rel);
            if (!file_exists($abs)) {
                continue;
            }

            self::__write_shadow($rel, $abs);
            $index['entries'][$rel] = [
                'mechanism'         => 'composer_dump',
                'state'             => 'present',
                'post_write_sha256' => hash_file('sha256', $abs),
                'renamed_to'        => null,
                'updated_at'        => self::__now(),
            ];
        }

        self::__save_index($index);
    }

    /**
     * Wipe the entire store (index + all shadows). Called by the updater after it
     * has restored a pristine tree - there is nothing left to explain.
     */
    public static function reset(): void
    {
        $index_path = self::__index_path();
        if (file_exists($index_path)) {
            unlink($index_path);
        }

        $shadow_root = self::__shadow_root();
        if (is_dir($shadow_root)) {
            self::__rmdir_recursive($shadow_root);
        }
    }

    /**
     * RECONCILING prune of the marker store: drop only the markers whose
     * attestation is NO LONGER TRUE, keep every marker that still describes the
     * current disk. This replaces the updater's old blind wipe.
     *
     * Why reconcile instead of wipe: the pull script's owned-zone sync restores
     * pristine framework files, but a downstream box is LIVE - cron, supervisor
     * workers and web requests boot artisan continuously, and each boot runs the
     * dev auto-rebuild. A boot that fires between the sync and this prune can
     * legitimately re-apply the framework's own churn (class-override renames,
     * use-header rewrites) AND record it. A blind wipe then destroys those valid
     * records, and the pull's own rebuild - finding the churn already applied -
     * is an idempotent fixpoint that no-ops and records nothing, leaving an EMPTY
     * ledger so the next verify false-flags all of that churn forever. A marker
     * whose recorded content still matches disk is exactly as good as one the
     * pull's own rebuild would have written, whenever it was written - so keeping
     * it dissolves the race.
     *
     * Keep/drop matrix (per index entry):
     *   - state 'present': KEEP iff the shadow file exists AND sha256(disk) ===
     *     post_write_sha256 AND sha256(shadow) === post_write_sha256 (the shadow
     *     must attest the SAME bytes it recorded). A missing/divergent shadow, a
     *     null recorded hash, or divergent disk -> DROP entry + shadow.
     *   - state 'absent' (a renamed-away .php): KEEP iff the file is still absent
     *     from disk; DROP if it reappeared.
     *   - any other/unknown state: DROP (defensive).
     *
     * Missing store -> no-op. Corrupt store -> get_index() throws (fail loud),
     * same as every other reader. When zero entries survive the store is deleted
     * OUTRIGHT (index + shadows): a MISSING store is the canonical "no markers"
     * state everywhere else, so this keeps the representation consistent and never
     * leaves a stale empty index behind. (Only mutations.json + shadow/ are ours; the
     * maintenance flag sharing the directory is Framework_Maintenance's and is untouched.)
     *
     * $base_dir (default base_path()) is the disk root entries hash against;
     * $store_root (default the real storage/rsx-framework store) roots the index +
     * shadows. Both overrides are the test seam mirroring get_index()/shadow_path()
     * and the verify command's --base-dir.
     */
    public static function prune(?string $base_dir = null, ?string $store_root = null): void
    {
        $index_path = self::__index_path($store_root);

        // Missing store -> nothing to reconcile.
        if (!file_exists($index_path)) {
            return;
        }

        // Corrupt store -> get_index() throws (fail loud). Otherwise: the entries.
        $index = self::get_index($store_root);

        $base = $base_dir !== null ? rtrim($base_dir, '/') : base_path();

        foreach ($index['entries'] as $rel => $entry) {
            $state = $entry['state'] ?? null;
            $disk = $base . '/' . $rel;
            $keep = false;

            if ($state === 'present') {
                $expected = $entry['post_write_sha256'] ?? null;
                $shadow = self::shadow_path($rel, $store_root);
                $keep = $expected !== null
                    && is_file($shadow)
                    && is_file($disk)
                    && hash_file('sha256', $disk) === $expected
                    && hash_file('sha256', $shadow) === $expected;
            } elseif ($state === 'absent') {
                $keep = !file_exists($disk);
            }

            if (!$keep) {
                unset($index['entries'][$rel]);
                self::__remove_shadow($rel, $store_root);
            }
        }

        // Zero survivors -> delete the store outright (index + shadows).
        if (empty($index['entries'])) {
            unlink($index_path);
            $shadow_root = self::__shadow_root($store_root);
            if (is_dir($shadow_root)) {
                self::__rmdir_recursive($shadow_root);
            }

            return;
        }

        self::__save_index($index, $store_root);
    }

    /**
     * The owned-zone definition (single source of truth), for the verify command.
     *
     * @return array{dirs:string[],files:string[]}
     */
    public static function owned_zones(): array
    {
        return ['dirs' => self::OWNED_ZONE_DIRS, 'files' => self::OWNED_ZONE_FILES];
    }

    /**
     * Absolute path of the shadow copy for a given owned-zone-relative path
     * (used by the verify command's --diff to compare disk against the
     * framework-authored content). A $store_root override lets the verify
     * command's --base-dir seam point at a fixture tree's store.
     */
    public static function shadow_path(string $rel, ?string $store_root = null): string
    {
        $root = $store_root !== null ? rtrim($store_root, '/') . '/shadow' : self::__shadow_root();

        return $root . '/' . $rel;
    }

    /**
     * Return the parsed marker index for consumers (Framework_Verify). A missing
     * store yields the empty shape; a corrupt store throws (fail loud). A
     * $store_root override (the verify command's --base-dir seam) reads a marker
     * store rooted elsewhere; the default reads the real storage/rsx-framework store.
     *
     * @return array{version:int,entries:array<string,array<string,mixed>>}
     */
    public static function get_index(?string $store_root = null): array
    {
        $index_path = $store_root !== null
            ? rtrim($store_root, '/') . '/mutations.json'
            : self::__index_path();
        if (!file_exists($index_path)) {
            return ['version' => 1, 'entries' => []];
        }

        $raw = file_get_contents($index_path);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['entries']) || !is_array($decoded['entries'])) {
            throw new \RuntimeException(
                "Fatal: framework mutation store is corrupt at {$index_path}.\n" .
                "Expected a JSON object with an 'entries' map. Delete the store to reset it."
            );
        }

        if (!isset($decoded['version'])) {
            $decoded['version'] = 1;
        }

        return $decoded;
    }

    // =====================================================================
    // Internal helpers
    // =====================================================================

    /**
     * Resolve an absolute path to its owned-zone-relative path, or null when the
     * path is not inside any owned zone. Pure string work - never touches disk.
     */
    private static function __owned_rel_path(string $abs_path): ?string
    {
        // Must be an absolute path under base_path() to be classifiable cheaply.
        if ($abs_path === '' || $abs_path[0] !== '/') {
            return null;
        }

        $base = base_path();
        $prefix = $base . '/';
        if (!str_starts_with($abs_path, $prefix)) {
            return null;
        }

        $rel = substr($abs_path, strlen($prefix));

        foreach (self::OWNED_ZONE_DIRS as $dir) {
            if (str_starts_with($rel, $dir)) {
                return $rel;
            }
        }

        foreach (self::OWNED_ZONE_FILES as $file) {
            if ($rel === $file) {
                return $rel;
            }
        }

        return null;
    }

    /**
     * Record a single PRESENT entry (hash + shadow + index) for an in-zone path.
     */
    private static function __put_present_entry(string $rel, string $abs_path, string $mechanism, ?string $renamed_to): void
    {
        self::__write_shadow($rel, $abs_path);

        $index = self::get_index();
        $index['entries'][$rel] = [
            'mechanism'         => $mechanism,
            'state'             => 'present',
            'post_write_sha256' => hash_file('sha256', $abs_path),
            'renamed_to'        => $renamed_to,
            'updated_at'        => self::__now(),
        ];
        self::__save_index($index);
    }

    /**
     * Copy $abs_path's bytes into the shadow tree at shadow/<rel>. Plain writes
     * (not file_put_contents_safe): the store lives under storage/, outside every
     * owned zone, so this cannot recurse through the write hook.
     */
    private static function __write_shadow(string $rel, string $abs_path): void
    {
        $dest = self::__shadow_root() . '/' . $rel;
        ensure_directory(dirname($dest));

        $bytes = @copy($abs_path, $dest);
        if ($bytes === false) {
            throw new \RuntimeException("Fatal: failed to shadow framework mutation {$rel} (from {$abs_path}).");
        }
    }

    /**
     * Remove a shadow copy if present. $store_root override targets a fixture
     * tree's store (test seam; default = the real store).
     */
    private static function __remove_shadow(string $rel, ?string $store_root = null): void
    {
        $dest = self::__shadow_root($store_root) . '/' . $rel;
        if (file_exists($dest)) {
            unlink($dest);
        }
    }

    /**
     * Persist the index to mutations.json. Plain write (see __write_shadow).
     * $store_root override targets a fixture tree's store (test seam).
     */
    private static function __save_index(array $index, ?string $store_root = null): void
    {
        $path = self::__index_path($store_root);
        ensure_directory(dirname($path));

        $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Fatal: failed to encode framework mutation index to JSON.');
        }

        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException("Fatal: failed to write framework mutation index at {$path}.");
        }
    }

    /**
     * The ONE choke point for the store's location: every index/shadow path in this
     * class resolves through here, so the store moves by editing this method alone.
     *
     * Resolved through storage_path(), which follows the storage relocation bridge -
     * the same relative location is therefore correct on a relocated tree
     * (<project>/storage/...) and a pre-relocation one (system/storage/...) without
     * ever hand-assembling an absolute path.
     *
     * NOT storage/framework: that is Laravel's own cache/session/view tree, which
     * developers routinely wipe wholesale to clear caches.
     *
     * Memoized so the one-time prior-layout migration below runs at most once per
     * process.
     */
    private static ?string $store_root_resolved = null;

    private static function __store_root(): string
    {
        if (self::$store_root_resolved !== null) {
            return self::$store_root_resolved;
        }

        $root = storage_path('rsx-framework');
        self::$store_root_resolved = $root;

        self::__migrate_prior_store($root);

        return $root;
    }

    /**
     * One-time relocation of an existing store from the prior layout
     * (storage/rsx-update) into $new_root. Lazy: it fires on the first store
     * resolution of the first process running this code, which during a framework
     * update is an artisan step of the rebuild - so the ledger is in place well
     * before prune/verify/attest read it.
     *
     * BEST-EFFORT BY DESIGN - the one deliberate exception to this class's fail-loud
     * rule. A lost store is an already-self-healing degradation (verify can then only
     * OVER-flag, never false-clean, and the manifest's override pass re-attests every
     * rebuild through attest_rename()), whereas throwing from the store's own path
     * resolver would take down every artisan boot on the box. A failed move therefore
     * continues with the new - possibly empty - root.
     */
    private static function __migrate_prior_store(string $new_root): void
    {
        $old_root = storage_path('rsx-update');

        // Already migrated, or nothing to migrate.
        if (file_exists($new_root . '/mutations.json') || !is_dir($old_root)) {
            return;
        }

        // The cached bare distribution clone is pure re-derivable data and now lives at
        // Framework_Maintenance::upstream_cache_dir(). DELETE the stale copy rather than
        // moving ~100MB of git objects around - the updater re-clones it on demand.
        $stale_cache = $old_root . '/upstream.git';
        if (is_dir($stale_cache)) {
            $out = [];
            $rc = 0;
            \exec_safe('rm -rf ' . escapeshellarg($stale_cache), $out, $rc);
        }

        if (is_file($old_root . '/mutations.json')) {
            ensure_directory($new_root);
            @rename($old_root . '/mutations.json', $new_root . '/mutations.json');
        }

        if (is_dir($old_root . '/shadow') && !is_dir($new_root . '/shadow')) {
            ensure_directory($new_root);
            @rename($old_root . '/shadow', $new_root . '/shadow');
        }

        // Drop the emptied prior directory. Silently declines while anything unexpected
        // remains in it (rmdir only succeeds on an empty directory) - nothing is ever
        // destroyed here beyond the re-derivable cache above.
        @rmdir($old_root);
    }

    /**
     * $store_root override (the prune/verify test seam) roots the index elsewhere;
     * default is the real storage/rsx-framework store.
     */
    private static function __index_path(?string $store_root = null): string
    {
        $root = $store_root !== null ? rtrim($store_root, '/') : self::__store_root();

        return $root . '/mutations.json';
    }

    private static function __shadow_root(?string $store_root = null): string
    {
        $root = $store_root !== null ? rtrim($store_root, '/') : self::__store_root();

        return $root . '/shadow';
    }

    private static function __now(): string
    {
        return date('c');
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private static function __rmdir_recursive(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
