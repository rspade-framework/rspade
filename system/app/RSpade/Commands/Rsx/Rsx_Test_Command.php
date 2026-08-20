<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\Console\Commands\FrameworkDeveloperCommand;
use App\RSpade\Core\Database\MigrationPaths;
use App\RSpade\Core\Manifest\Manifest;
use Exception;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use App\RSpade\Core\Console\Rsx_Artisan;

class Rsx_Test_Command extends FrameworkDeveloperCommand
{
    /**
     * Cache format version. Bump this whenever a change to the provisioning
     * logic (schema normalization, seed data, etc.) would make the resulting
     * test-database schema differ from an existing cached dump even though the
     * migration files themselves are unchanged. Bumping invalidates all caches.
     */
    const CACHE_VERSION = 1;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:test
                            {test?* : Test class(es) to run - substring match, repeat for a set}
                            {--filter=* : Run only tests whose class or method name matches - repeat for a set}
                            {--group=* : Run only the named test group(s) (the concern directory under tests/, e.g. locks)}
                            {--framework : Run framework tests (under app/RSpade) instead of application tests}
                            {--fresh : Drop and recreate the test database, then run all migrations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run RSX framework tests';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // All three selectors accept a SET. Within one selector the members are
        // OR'd (run this class OR that one); across selectors they are AND'd
        // (this group AND matching this filter), so each one narrows further.
        $specific_tests = array_filter((array) $this->argument('test'));
        $filters = array_filter((array) $this->option('filter'));
        $groups = array_filter((array) $this->option('group'));
        $framework_only = (bool) $this->option('framework');

        $this->info('RSX Test Runner');
        $this->info('================');
        $this->line('Suite: ' . ($framework_only ? 'framework (app/RSpade)' : 'application (rsx)'));
        if ($groups) {
            $this->line('Groups: ' . implode(', ', $groups));
        }
        if ($specific_tests) {
            $this->line('Tests: ' . implode(', ', $specific_tests));
        }
        if ($filters) {
            $this->line('Filters: ' . implode(', ', $filters));
        }
        $this->newLine();

        // Swap to the test database for the whole run (airtight - dev DB never
        // touched) and make sure its schema is up to date.
        if (!$this->prepare_test_database((bool)$this->option('fresh'))) {
            return 1;
        }

        // Rebuild manifest to ensure we have latest test classes
        $this->line('Building manifest...');
        Manifest::init();

        // Get all test classes extending Rsx_Test_Abstract
        $test_classes = Manifest::php_get_extending('Rsx_Test_Abstract');

        if (empty($test_classes)) {
            $this->warn('No test classes found.');
            $this->line('Test classes should extend App\\RSpade\\Core\\Testing\\Rsx_Test_Abstract');

            return 0;
        }

        // Deterministic, reproducible run order (by class name).
        ksort($test_classes);

        $total_tests = 0;
        $total_passed = 0;
        $total_failed = 0;
        $total_skipped = 0;

        // Tracks whether a prior $requires_db_reset class may have committed
        // data, so we can restore the clean baseline before the next class.
        $db_dirty = false;

        foreach ($test_classes as $test_class_info) {
            if (!isset($test_class_info['fqcn'])) {
                continue;
            }

            $class_name = $test_class_info['fqcn'];

            // Partition framework vs application tests by file location. Framework
            // tests live under app/RSpade/; everything else (rsx/) is application.
            // --framework runs only the former, the default runs only the latter.
            $is_framework_test = str_starts_with($test_class_info['file'] ?? '', 'app/RSpade/');
            if ($is_framework_test !== $framework_only) {
                continue;
            }

            // Group = the concern directory a test lives in: tests/<group>/php/...
            // (framework) or the first directory under rsx/tests/ (application).
            // A class outside every named group is skipped before anything else
            // looks at it.
            if ($groups && !self::__file_in_groups($test_class_info['file'] ?? '', $groups)) {
                continue;
            }

            // Skip unless this class is one of the requested ones (any match).
            if ($specific_tests) {
                $named = false;
                foreach ($specific_tests as $wanted) {
                    if (str_contains($class_name, $wanted)) {
                        $named = true;
                        break;
                    }
                }
                if (!$named) {
                    continue;
                }
            }

            // Skip abstract classes
            $reflection = new ReflectionClass($class_name);
            // @PHP-REFLECT-01-EXCEPTION - Test runner needs ReflectionClass for filtering
            if ($reflection->isAbstract()) {
                continue;
            }

            $short_name = basename(str_replace('\\', '/', $class_name));

            // Pre-filter: decide whether --filter selects this class BEFORE we
            // print "Running:" or run it. A filter matches a class either by its
            // (short) class name or by any of its test method names (case-
            // insensitive). When it matches the class NAME, every method reports;
            // when it matches only method names, just the matching methods report.
            // A class the filter selects nothing in is skipped entirely - no
            // "Running:" line, no ::run(), no participation in $db_dirty tracking.
            $class_matches = $filters && self::__matches_any($short_name, $filters);
            if ($filters && !$class_matches) {
                $any_method_matches = false;
                // Mirror Rsx_Test_Abstract::run()'s discovery: public methods
                // whose name starts with 'test_'.
                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (strpos($method->getName(), 'test_') !== 0) {
                        continue;
                    }
                    if (self::__matches_any($method->getName(), $filters)) {
                        $any_method_matches = true;
                        break;
                    }
                }
                if (!$any_method_matches) {
                    continue;
                }
            }

            // Per-class blank-slate reset. The database is only ever left dirty
            // by a prior $requires_db_reset class that may have committed; a
            // transaction-based class always rolls back. So we restore the clean
            // baseline whenever it is dirty - which gives a reset class its blank
            // slate and protects the next transaction class from leftover data.
            $needs_reset = $class_name::requires_db_reset();
            if ($db_dirty) {
                $this->line("Resetting test database for {$short_name}...");
                if (!$this->reset_test_db()) {
                    $this->error('  Error: failed to reset test database before ' . $short_name);
                    $total_failed++;

                    continue;
                }
                $db_dirty = false;
            }

            $this->info("Running: {$short_name}");

            try {
                // Run the tests
                $results = $class_name::run();

                // A reset class may have committed data - flag the DB as dirty
                // so the next class restores the clean baseline first.
                if ($needs_reset) {
                    $db_dirty = true;
                }

                // Process results
                foreach ($results as $test_name => $result) {
                    // Select this method when there's no filter, the class name
                    // matched the filter (report every method), or this specific
                    // method name matches (case-insensitive on both sides).
                    if (!(!$filters || $class_matches || self::__matches_any($test_name, $filters))) {
                        continue;
                    }

                    $total_tests++;

                    switch ($result['status']) {
                        case 'passed':
                            $total_passed++;
                            $this->line("  [OK] {$test_name}");
                            break;

                        case 'failed':
                            $total_failed++;
                            $this->error("  [FAIL] {$test_name}");
                            $this->line("    {$result['message']}", 'fg=red');
                            if (isset($result['file']) && isset($result['line'])) {
                                $this->line("    at {$result['file']}:{$result['line']}", 'fg=gray');
                            }
                            break;

                        case 'skipped':
                            $total_skipped++;
                            $this->line("  - {$test_name} (skipped)", 'fg=yellow');
                            $this->line("    {$result['message']}", 'fg=gray');
                            break;
                    }
                }
            } catch (Exception $e) {
                $this->error('  Error running test class: ' . $e->getMessage());
                $total_failed++;
            }

            $this->newLine();
        }

        // Summary
        $this->info('Test Summary');
        $this->info('============');
        $this->line("Total:   {$total_tests}");

        if ($total_passed > 0) {
            $this->line("Passed:  {$total_passed}", 'fg=green');
        }
        if ($total_failed > 0) {
            $this->line("Failed:  {$total_failed}", 'fg=red');
        }
        if ($total_skipped > 0) {
            $this->line("Skipped: {$total_skipped}", 'fg=yellow');
        }

        $this->newLine();

        if ($total_failed > 0) {
            $this->error('Tests failed!');

            return 1;
        }
        if ($total_tests === 0) {
            $this->warn('No tests were run.');

            return 0;
        }
        $this->info('All tests passed!');

        return 0;
    }

    /**
     * Case-insensitive substring match against ANY member of a set. An empty set
     * never matches - callers test the set separately when "no selector given"
     * should mean "everything".
     *
     * @param string $haystack
     * @param array $needles
     * @return bool
     */
    private static function __matches_any(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains(strtolower($haystack), strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a test file belongs to any of the named groups.
     *
     * A GROUP is the concern directory a test lives in - the thing that owns a
     * README.md and a test_catalog.md: `app/RSpade/tests/<group>/php/X_Test.php`
     * for framework tests, `rsx/tests/<group>/...` for application ones. An
     * application test sitting directly in `rsx/tests/` has no group and is
     * therefore never selected by --group.
     *
     * Matching is exact on the directory name (not a substring), so --group=locks
     * cannot accidentally drag in an unrelated concern whose name contains it.
     *
     * @param string $file Manifest-relative path of the test file
     * @param array $groups
     * @return bool
     */
    private static function __file_in_groups(string $file, array $groups): bool
    {
        if ($file === '' || !preg_match('#(?:^|/)tests/([^/]+)/#', $file, $m)) {
            return false;
        }

        foreach ($groups as $group) {
            if (strcasecmp($m[1], (string) $group) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify the test database is safe to use, sync its schema, and swap it in
     * as the default connection for the rest of this run.
     *
     * @param bool $fresh Drop and recreate the test database before migrating
     * @return bool True if the test database is ready, false to abort the run
     */
    protected function prepare_test_database(bool $fresh): bool
    {
        $dev_db = config('database.connections.mysql.database');
        $test_db = config('database.connections.test.database');

        // SAFETY: the test database must be distinct from the developer
        // database. If they match, refuse to run rather than risk wiping the
        // developer's data during a schema sync.
        if (empty($test_db)) {
            $this->error('[ERROR] No test database configured (database.connections.test.database is empty).');

            return false;
        }

        if ($test_db === $dev_db) {
            $this->error('[ERROR] Test database matches the developer database (' . $test_db . ').');
            $this->line('Set DB_TEST_DATABASE in .env to a separate database (e.g. rspade_test).');

            return false;
        }

        $this->line("Test database: {$test_db} (connection: test)");

        // Redirect the ENTIRE file subsystem (blob store, thumbnail cache, rendition
        // cache) to a test-scoped directory for the whole run, mirroring the DB swap.
        // Without this, a model-layer attachment delete on the TEST database unlinks a
        // shared disk file - destroying a developer-database blob whose bytes exist in
        // both (backlog B-38). Every file path resolves through Rsx_File_Paths, which
        // reads this key. Set BEFORE any provisioning so in-process work is covered,
        // and passed to the migrate subprocess below via --rsx-storage-root.
        //
        // No DB-match-style refusal guard here: unlike DB_TEST_DATABASE (user config
        // that could collide with the dev DB), this root is hard-coded by the runner
        // under storage/rsx-tmp - it can never point at the live store.
        $storage_root = storage_path('rsx-tmp/test-storage');
        ensure_directory($storage_root);
        config(['rsx.files.storage_root' => $storage_root]);

        // Sync schema when forced (--fresh) or when the test database has no
        // migrated schema yet. Migrations are forward-only and cannot reconcile
        // a partial/dirty database, so a sync always drops and recreates first.
        if ($fresh || !$this->test_db_has_schema($test_db)) {
            if (!$this->sync_test_schema($test_db)) {
                return false;
            }
        } else {
            // Existing schema: apply any PENDING migrations (framework + app) before the
            // run, so a plain rsx:test never executes against a stale test schema (a new
            // migration used to require --fresh; tests failed on missing columns).
            $pending = $this->test_db_pending_migrations();
            if (!empty($pending)) {
                $this->line('Applying ' . count($pending) . ' pending migration(s) to the test database...');
                if (!$this->run_migrate_subprocess($test_db)) {
                    return false;
                }
                // Deliberately NOT cached: the dump cache must hold only the pristine
                // freshly-provisioned baseline. This database may carry committed residue
                // from prior non-transactional test classes - caching it would poison
                // every future $requires_db_reset re-provision. The next reset/--fresh
                // builds + caches the new baseline under the new migration hash.
                $this->line('[OK] Test database schema is up to date.');
            }
        }

        // Swap the default connection to the test database for the whole run.
        config(['database.default' => 'test']);
        DB::purge('test');
        DB::setDefaultConnection('test');

        $this->newLine();

        return true;
    }

    /**
     * Re-provision the test database to a clean, freshly-migrated baseline
     * (used between $requires_db_reset classes). Reuses the migration-hash dump
     * cache, so this is fast when migrations are unchanged.
     *
     * @return bool
     */
    protected function reset_test_db(): bool
    {
        $test_db = config('database.connections.test.database');

        if (!$this->sync_test_schema($test_db)) {
            return false;
        }

        // The 'test' connection's PDO points at the dropped database - drop it
        // so the next query reconnects to the freshly created one.
        DB::purge('test');
        DB::setDefaultConnection('test');

        return true;
    }

    /**
     * Check whether the test database already has a migrated schema.
     *
     * @param string $test_db
     * @return bool
     */
    protected function test_db_has_schema(string $test_db): bool
    {
        $migrations_table = config('database.migrations', 'migrations');

        try {
            return DB::connection('test')->table($migrations_table)->count() > 0;
        } catch (\Throwable $e) {
            // Database or migrations table doesn't exist yet
            return false;
        }
    }

    /**
     * Drop, recreate and provision the test database.
     *
     * Provisioning is forward-only: the database is dropped and recreated, then
     * either restored from a cached mysqldump (when the migration set is
     * unchanged) or fully migrated and re-cached. Migrations run in a subprocess
     * in debug mode (RSX_MODE=debug) so they use the non-snapshot migration path
     * against the test database - the development-mode snapshot path operates on
     * the whole MySQL instance and must never run for a test schema sync.
     *
     * @param string $test_db
     * @return bool
     */
    protected function sync_test_schema(string $test_db): bool
    {
        // Issue DROP/CREATE via the developer connection - it is never bound to
        // the test database, so dropping the test database here is safe.
        $admin = DB::connection('mysql');

        $this->line("Recreating test database {$test_db}...");
        $admin->statement("DROP DATABASE IF EXISTS `{$test_db}`");
        $admin->statement("CREATE DATABASE `{$test_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Fast path: if a cached dump matches the current migration set, restore
        // it instead of re-running every migration.
        $hash = $this->compute_migration_hash();
        $cache_file = $this->test_cache_dir() . "/{$hash}.sql";

        if (is_file($cache_file)) {
            $this->line('Restoring test database from cache (migrations unchanged)...');
            if ($this->restore_test_db_from_cache($test_db, $cache_file)) {
                $this->line('[OK] Test database restored from cache.');

                return true;
            }

            // Corrupt/unusable cache - discard it, recreate the database, and
            // fall through to a full migration.
            $this->warn('[WARNING] Cached dump could not be restored; running a full migration.');
            @unlink($cache_file);
            $admin->statement("DROP DATABASE IF EXISTS `{$test_db}`");
            $admin->statement("CREATE DATABASE `{$test_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        $this->line('Running migrations against test database...');

        if (!$this->run_migrate_subprocess($test_db)) {
            return false;
        }

        // Cache the freshly migrated schema for next time (best effort).
        $this->dump_test_db_to_cache($test_db, $cache_file);

        $this->line('[OK] Test database schema is up to date.');

        return true;
    }

    /**
     * Run `migrate --force` in a fresh subprocess against the test database. Shared by
     * the full provisioning path and the incremental pending-migration apply.
     *
     * Debug mode is forced (no snapshot machinery - the development-mode snapshot path
     * operates on the whole MySQL instance and must never run for a test schema sync).
     * DB_DATABASE / RSX_MODE are exported as real environment variables (environment
     * facts: which database, which mode); Laravel's immutable dotenv will not override
     * them, so the subprocess targets the test database.
     *
     * Spawned through Rsx_Artisan so the child JOINS THIS PROCESS'S LOCK GROUP. Without
     * that it deadlocks against its own parent: the runner takes cluster:SITE_n on its
     * first site-scoped write and holds it until shutdown, then blocks here in waitpid
     * while the child queues for the same lock. Observed live, twice, for 10-12 hours.
     *
     * The subprocess does NOT inherit our in-process rsx.files.storage_root config, so a
     * data-seed migration that writes blobs (the template app's import_sample_documents
     * runs create_from_disk) would land in the REAL blob store. Per-invocation intent
     * rides as a --flag (owner ruling: NO env prefixes for invocation intent): the
     * migrate command reads --rsx-storage-root and sets the config before migrating, so
     * provisioning stays inside the isolated test root too (B-38).
     *
     * @param string $test_db
     * @return bool
     */
    protected function run_migrate_subprocess(string $test_db): bool
    {
        $storage_root = config('rsx.files.storage_root');

        $args = ['--force'];
        if (!empty($storage_root)) {
            $args[] = '--rsx-storage-root=' . (string) $storage_root;
        }

        $output = [];
        $exit_code = Rsx_Artisan::run('migrate', $args, $output, [
            'DB_DATABASE' => $test_db,
            'RSX_MODE' => 'debug',
        ]);

        if ($exit_code !== 0) {
            $this->error('[ERROR] Test database migration failed:');
            $this->line(implode("\n", $output), 'fg=red');

            return false;
        }

        return true;
    }

    /**
     * Migration files (framework + app, the same canonical set the migration hash uses)
     * that have no row in the test database's migrations table - i.e. pending there.
     *
     * @return array<int, string> pending migration names
     */
    protected function test_db_pending_migrations(): array
    {
        $migrations_table = config('database.migrations', 'migrations');

        try {
            $applied = DB::connection('test')->table($migrations_table)->pluck('migration')->all();
        } catch (\Throwable $e) {
            // No migrations table -> treated as no-schema by the caller's earlier check;
            // nothing sensible to report here.
            return [];
        }
        $applied = array_flip($applied);

        $pending = [];
        foreach (MigrationPaths::get_all_migration_files() as $file) {
            $name = basename($file, '.php');
            if (!isset($applied[$name])) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * Directory holding cached test-database dumps. Lives under storage so it
     * survives rsx:clean (which only wipes rsx-build and rsx-tmp).
     *
     * @return string
     */
    protected function test_cache_dir(): string
    {
        $dir = storage_path('db_backups/test-db-cache');
        ensure_directory($dir);

        return $dir;
    }

    /**
     * Content hash of the full migration set plus the MySQL version and cache
     * format version. A cached dump is only reused when this hash matches, so
     * any change to a migration file (or the server version) forces a rebuild.
     *
     * @return string
     */
    protected function compute_migration_hash(): string
    {
        $version = DB::connection('mysql')->selectOne('SELECT VERSION() AS v')->v;

        $entries = [];
        foreach (MigrationPaths::get_all_migration_files() as $file) {
            $entries[] = relative_path($file) . ':' . md5_file($file);
        }
        sort($entries);

        array_unshift($entries, 'cache_version:' . self::CACHE_VERSION, 'mysql:' . $version);

        return md5(implode("\n", $entries));
    }

    /**
     * Restore a cached dump into the (freshly created, empty) test database.
     *
     * @param string $test_db
     * @param string $cache_file
     * @return bool
     */
    protected function restore_test_db_from_cache(string $test_db, string $cache_file): bool
    {
        $conn = config('database.connections.test');

        // Password rides in the child's environment, never on the command line, where
        // `ps` would expose it to every user on the box.
        $command = sprintf(
            'mysql -h%s -P%s -u%s %s < %s 2>&1',
            escapeshellarg((string) $conn['host']),
            escapeshellarg((string) $conn['port']),
            escapeshellarg((string) $conn['username']),
            escapeshellarg($test_db),
            escapeshellarg($cache_file)
        );

        $output = [];
        $exit_code = 0;
        \exec_safe($command, $output, $exit_code, ['MYSQL_PWD' => (string) $conn['password']]);

        if ($exit_code !== 0) {
            $this->line(implode("\n", $output), 'fg=red');

            return false;
        }

        return true;
    }

    /**
     * Dump the migrated test database to the cache (best effort - failure here
     * never fails the test run). Written to a .partial file then renamed so a
     * crash mid-dump can never leave a truncated cache that would be restored.
     *
     * @param string $test_db
     * @param string $cache_file
     * @return void
     */
    protected function dump_test_db_to_cache(string $test_db, string $cache_file): void
    {
        // Keep only the current cache - stale dumps for other migration sets
        // are never reused (their hash won't match).
        $this->prune_test_cache();

        $conn = config('database.connections.test');
        $partial = $cache_file . '.partial';

        // Password rides in the child's environment, never on the command line, where
        // `ps` would expose it to every user on the box.
        $command = sprintf(
            'mysqldump -h%s -P%s -u%s --no-tablespaces --single-transaction --quick --lock-tables=false %s > %s 2>/dev/null',
            escapeshellarg((string) $conn['host']),
            escapeshellarg((string) $conn['port']),
            escapeshellarg((string) $conn['username']),
            escapeshellarg($test_db),
            escapeshellarg($partial)
        );

        $output = [];
        $exit_code = 0;
        \exec_safe($command, $output, $exit_code, ['MYSQL_PWD' => (string) $conn['password']]);

        if ($exit_code !== 0 || !is_file($partial) || filesize($partial) === 0) {
            @unlink($partial);
            $this->warn('[WARNING] Could not cache test database dump (continuing without cache).');

            return;
        }

        if (@rename($partial, $cache_file)) {
            $this->line('[OK] Cached test database schema for reuse.');
        } else {
            @unlink($partial);
        }
    }

    /**
     * Remove all cached dumps. The current run writes a fresh one keyed by the
     * current migration hash.
     *
     * @return void
     */
    protected function prune_test_cache(): void
    {
        foreach (glob($this->test_cache_dir() . '/*.sql*') as $file) {
            @unlink($file);
        }
    }
}
