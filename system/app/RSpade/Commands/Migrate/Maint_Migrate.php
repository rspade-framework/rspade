<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Migrate;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use App\Providers\AppServiceProvider;
use App\RSpade\Core\Database\MigrationValidator;
use App\RSpade\Core\Database\SqlQueryTransformer;
use App\RSpade\Core\Rsx;
use App\RSpade\SchemaQuality\SchemaQualityChecker;
use App\RSpade\Core\Console\Rsx_Artisan;

/**
 * Unified migration command with mode-aware behavior
 *
 * In DEVELOPMENT mode:
 * - Automatically creates database snapshot before migrations
 * - Runs migrations with validation and normalization
 * - On success: commits changes, removes snapshot, regenerates constants/bundles
 * - On failure: automatically rolls back to snapshot and exits migration mode
 *
 * In DEBUG/PRODUCTION mode:
 * - Runs migrations without snapshot protection
 * - Runs schema normalization
 * - Does NOT update source code (constants, bundles) - source is read-only
 *
 * This command is automatically used when running 'php artisan migrate' due to
 * a modification in the artisan script.
 */
class Maint_Migrate extends Command
{
    use PrivilegedCommandTrait;

    protected $signature = 'migrate {--force} {--seed} {--step} {--path=*} {--framework-only : Run only framework migrations (system/database/migrations)} {--rsx-storage-root= : INTERNAL - test-isolation seam. Roots the file subsystem (blob/thumbnail/rendition store) at this absolute path so a data-seed migration writing blobs stays in the test-scoped store. Set only by rsx:test provisioning; never used in normal migrations. See backlog B-38.}';

    protected $description = 'Run migrations with automatic snapshot protection in development mode';

    protected $flag_file = '/var/www/html/.migrating';
    protected $mysql_data_dir = '/var/lib/mysql';
    protected $backup_dir = '/var/lib/mysql_backup';

    // Replaceable: Migrate_Restore_Command subclasses this command for its snapshot
    // machinery (rollback_snapshot and friends) and replaces handle() wholesale - a
    // restore never runs the migrate flow.
    #[Replaceable]
    public function handle()
    {
        // Test-isolation seam (backlog B-38): when the rsx:test provisioning
        // subprocess passes --rsx-storage-root, root the entire file subsystem there
        // so a data-seed migration writing blobs (e.g. import_sample_documents) lands
        // in the test-scoped store, never the shared developer blob store. This is a
        // per-invocation --flag, not an env var (owner ruling on invocation intent).
        $storage_root = $this->option('rsx-storage-root');
        if (!empty($storage_root)) {
            config(['rsx.files.storage_root' => $storage_root]);
        }

        // WHERE this is running decides how it runs - the CONTAINER, not RSX_MODE.
        //
        //   dev container   snapshot, migrate, then discard the snapshot. A
        //                   developer breaks schemas all day and wants the undo,
        //                   not a growing pile of copies.
        //
        //   prod container  snapshot, migrate, and KEEP it. Same protection, but
        //                   the copy is left behind: on a production box that is
        //                   the last image of the database before the change, and
        //                   throwing it away the moment migrations pass is exactly
        //                   the wrong instinct.
        //
        //   no container    migrate only. Stopping services and copying a data
        //                   directory are things this framework's container does;
        //                   somewhere else, they are things this framework has no
        //                   business doing to somebody's machine.
        //
        // The one refusal: DEVELOPMENT mode outside the container. Development
        // means the source tree is being edited and regenerated, and a migration
        // there is expected to be undoable. Silently dropping that protection
        // because of where the command was typed is not a trade to make on
        // somebody's behalf.
        $is_framework_only = $this->option('framework-only');
        $in_container = Rsx::is_rspade_container();

        if (Rsx::is_development() && !$in_container) {
            return $this->refuse_development_outside_container();
        }

        // Framework-only runs never snapshot: they are a schema-only subset used
        // by tooling, and the snapshot exists to protect a developer's data.
        if ($in_container && !$is_framework_only) {
            return $this->run_with_snapshot();
        }

        return $this->run_without_snapshot();
    }

    /**
     * Development mode, no RSpade container: refuse, and say exactly what to type.
     *
     * An error that only says "wrong environment" leaves somebody guessing at the
     * invocation. This prints it.
     */
    protected function refuse_development_outside_container(): int
    {
        $this->error('[ERROR] Development-mode migrations run inside the RSpade container.');
        $this->info('');
        $this->info('  Migrations in development are snapshot-protected: the database is copied');
        $this->info('  first, so a bad migration rolls back instead of leaving you to repair it.');
        $this->info('  That protection is the container\'s - it stops the supervised MySQL');
        $this->info('  service and copies its data directory - and this is not that container.');
        $this->info('');
        $this->info('  Run it there instead:');
        $this->info('');
        $this->info('      docker compose exec app php artisan migrate');
        $this->info('');
        $this->info('  Or from a shell inside the container:');
        $this->info('');
        $this->info('      docker compose exec app bash');
        $this->info('      php artisan migrate');
        $this->info('');
        $this->info('  A deployed application migrates here normally - this refusal is only for');
        $this->info('  development mode (RSX_MODE=development).');

        return 1;
    }

    /**
     * Run migrations with automatic snapshot protection (development mode)
     */
    protected function run_with_snapshot(): int
    {
        $keep_snapshot = !Rsx::is_rspade_dev_container();

        $this->info($keep_snapshot
            ? ' Production container: snapshot protection (the snapshot is KEPT)'
            : ' Development container: automatic snapshot protection');
        $this->info('');

        // Step 1: Create snapshot
        $this->info('[1/4] Creating database snapshot...');
        if (!$this->create_snapshot()) {
            return 1;
        }

        // Step 2: Run migrations
        $this->info('');
        $this->info('[2/4] Running migrations...');
        $migration_result = $this->execute_migrations();

        if ($migration_result !== 0) {
            // Migration failed - rollback and exit migration mode
            $this->error('');
            $this->error('[ERROR] Migration failed!');
            $this->warn(' Automatically rolling back to snapshot...');
            $this->info('');

            $this->rollback_snapshot();
            $this->cleanup_migration_mode();

            $this->info('');
            $this->info('[OK] Database restored to pre-migration state.');
            $this->info('');
            $this->line('Fix your migration files and run "php artisan migrate" again.');

            return 1;
        }

        // Step 3: Run schema quality check
        $this->info('');
        $this->info('[3/4] Running schema quality check...');

        $checker = new SchemaQualityChecker();
        $checker->check();

        if ($checker->has_violations()) {
            $this->error('[ERROR] Schema standards check failed with ' . $checker->get_violation_count() . ' violation(s):');
            $this->info('');

            // Display violations
            $grouped = $checker->get_violations_by_severity();
            foreach (['critical', 'high', 'medium', 'low'] as $severity) {
                if (!empty($grouped[$severity])) {
                    $this->line(strtoupper($severity) . ' VIOLATIONS:');
                    foreach ($grouped[$severity] as $violation) {
                        $this->line($violation->format_output());
                    }
                }
            }

            $this->info('');
            $this->warn(' Rolling back due to schema violations...');

            $this->rollback_snapshot();
            $this->cleanup_migration_mode();

            $this->info('');
            $this->warn('[WARNING]  Migration has been rolled back. Fix the schema violations and try again.');
            return 1;
        }

        $this->info('[OK] Schema quality check passed.');

        // Step 4: Commit.
        $this->info('');
        $this->info('[4/4] Committing changes...');

        if ($keep_snapshot) {
            // Leave the copy where it is. The migration succeeded, so nothing here
            // needs undoing - but on a production box this is the database as it
            // was immediately before the change, and that is worth more than the
            // disk it occupies. Removing it is a deliberate act.
            $this->release_migration_flag();
            $this->info('[OK] Snapshot RETAINED at ' . $this->backup_dir . '.');
            $this->info('     Remove it when you are satisfied with the migration:');
            $this->info('         rm -rf ' . $this->backup_dir);
        } else {
            $this->commit_snapshot();
        }

        // Post-migration source regeneration, which only makes sense where the
        // source tree is writable and gets edited.
        if (Rsx::is_development()) {
            $this->info('');
            $this->info('Running post-migration tasks...');

            // Regenerate model constants
            $this->call('rsx:constants:regenerate');

            // Recompile bundles
            $this->newLine();
            $this->info('Recompiling bundles...');
            Rsx_Artisan::passthru('rsx:bundle:compile');
        }

        $this->info('');
        $this->info('[OK] Migration completed successfully!');

        return 0;
    }

    /**
     * Run migrations without snapshot protection (debug/production mode)
     */
    protected function run_without_snapshot(): int
    {
        $mode_label = Rsx::get_mode_label();
        $is_framework_only = $this->option('framework-only');

        if ($is_framework_only) {
            $this->info(" Framework-only migrations (no snapshot protection)");
        } else {
            $this->info(" {$mode_label} mode: Running without snapshot protection");
        }
        $this->info(' Source code is read-only - constants/bundles will not be regenerated.');
        $this->info('');

        // Run migrations
        $migration_result = $this->execute_migrations();

        if ($migration_result !== 0) {
            $this->error('');
            $this->error('[ERROR] Migration failed!');
            $this->warn('[WARNING]  No snapshot available - database may be in inconsistent state.');
            return 1;
        }

        // In debug/production mode, check manifest consistency with database
        if (!$is_framework_only) {
            AppServiceProvider::disable_query_echo();
            $this->info('');
            $consistency_check_exit = $this->call('rsx:migrate:check_consistency');
            if ($consistency_check_exit !== 0) {
                $this->warn('[WARNING]  Manifest-database consistency check failed.');
                $this->warn('Source code may be out of sync with database schema.');
            }
        }

        $this->info('');
        $this->info('[OK] Migration completed!');

        return 0;
    }

    /**
     * Execute the actual migration process
     */
    protected function execute_migrations(): int
    {
        // Enable SQL query transformation for migrations
        SqlQueryTransformer::enable();
        $this->register_query_transformer();

        // Enable full query logging to stdout for migrations
        AppServiceProvider::set_query_log_mode(AppServiceProvider::QUERY_LOG_ALL_STDOUT);

        // Ensure migrations table exists (create it if needed)
        $this->ensure_migrations_table_exists();

        $is_framework_only = $this->option('framework-only');
        $is_development = Rsx::is_development();

        // Get all the options
        $force = $this->option('force');
        $seed = $this->option('seed');
        $step = $this->option('step');
        $paths = $this->option('path');

        // Check if path option is used and throw exception
        if (!empty($paths)) {
            $this->error('[ERROR] Migration by path is disabled!');
            $this->error('');
            $this->line('This command enforces running all pending migrations in order.');
            $this->line('Please run migrations without the --path option.');
            SqlQueryTransformer::disable();
            return 1;
        }

        // Determine which migration paths to use for whitelist check
        $paths_to_check = $is_framework_only
            ? [database_path('migrations')]
            : MigrationPaths::get_all_paths();

        // Check migration whitelist
        if (!$this->checkMigrationWhitelist($paths_to_check)) {
            SqlQueryTransformer::disable();
            return 1;
        }

        // Validate migration files for Schema builder usage (only in development)
        if ($is_development && !$this->_validate_schema_rules()) {
            SqlQueryTransformer::disable();
            return 1;
        }

        // Run normalize_schema BEFORE migrations to fix existing tables
        // Use --production flag if not using snapshots (framework-only or non-development mode)
        $use_snapshot = $is_development && !$is_framework_only;
        $requiredColumnsArgs = $use_snapshot ? [] : ['--production' => true];

        $this->info("\n Pre-migration normalization (fixing existing tables)...\n");
        // normalize_schema fails loud (throws) rather than performing a logical
        // rollback of its own. Convert a throw (or a non-zero exit) into a non-zero
        // return so the caller's migration-failure branch (run_with_snapshot ->
        // rollback_snapshot + cleanup_migration_mode) performs the real datadir
        // snapshot rollback, exactly as the mid-loop normalization failure does.
        try {
            $normalizeExitCode = $this->call('migrate:normalize_schema', $requiredColumnsArgs);
        } catch (\Exception $e) {
            $this->error('Pre-migration normalization failed: ' . $e->getMessage());
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();
            return 1;
        }
        if ($normalizeExitCode !== 0) {
            $this->error('Pre-migration normalization failed');
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();
            return $normalizeExitCode;
        }

        // Use a buffered output to capture migration output
        $bufferedOutput = new BufferedOutput();

        try {
            // Run the standard migrations and capture the output
            $originalSqlMode = DB::selectOne('SELECT @@sql_mode as sql_mode')->sql_mode;
            DB::statement("SET sql_mode = REPLACE(@@sql_mode, 'NO_ZERO_DATE', '');");

            // Run migrations directly via migrator to avoid recursion
            $migrator = app('migrator');
            $migrator->setOutput($bufferedOutput);

            // Use all migration paths (framework + user), or just framework if --framework-only
            $migrationPaths = $is_framework_only
                ? [database_path('migrations')]
                : MigrationPaths::get_all_paths();

            // Run migrations one-by-one with normalization after each
            $this->run_migrations_with_normalization($migrator, $migrationPaths, $step, $requiredColumnsArgs);

            $exitCode = 0;

            // Handle seeding if requested
            if ($seed) {
                $this->call('db:seed', ['--force' => $force]);
            }

            $migrationOutput = $bufferedOutput->fetch();

            // Show the output from the migration command
            $this->output->write($migrationOutput);

            DB::statement('SET sql_mode = ?', [$originalSqlMode]);

        } catch (\Exception $e) {
            // Restore SQL mode
            try {
                if (isset($originalSqlMode)) {
                    DB::statement('SET sql_mode = ?', [$originalSqlMode]);
                }
            } catch (\Exception $sqlEx) {
                // Ignore SQL mode restore errors
            }

            $this->error('');
            $this->error('Error: ' . $e->getMessage());

            // Disable query logging before returning
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();

            return 1;
        }

        // Run normalize_schema AFTER migrations to add framework columns to new tables
        $this->info("\n Post-migration normalization (adding framework columns to new tables)...\n");

        // Switch to destructive-only query logging for normalize_schema
        AppServiceProvider::set_query_log_mode(AppServiceProvider::QUERY_LOG_DESTRUCTIVE_STDOUT);

        // normalize_schema fails loud (throws) rather than performing a logical
        // rollback of its own. Convert a throw (or a non-zero exit) into a non-zero
        // return so the caller's migration-failure branch (run_with_snapshot ->
        // rollback_snapshot + cleanup_migration_mode) performs the real datadir
        // snapshot rollback, exactly as the mid-loop normalization failure does.
        try {
            $normalizeExitCode = $this->call('migrate:normalize_schema', $requiredColumnsArgs);
        } catch (\Exception $e) {
            $this->error('Post-migration normalization failed: ' . $e->getMessage());
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();
            return 1;
        }
        if ($normalizeExitCode !== 0) {
            $this->error('Post-migration normalization failed');
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();
            return $normalizeExitCode;
        }

        // Disable query logging
        AppServiceProvider::disable_query_echo();
        SqlQueryTransformer::disable();

        return $exitCode;
    }

    /**
     * Create a database snapshot
     */
    protected function create_snapshot(): bool
    {
        // Check if already in migration mode (shouldn't happen with new unified command)
        if (file_exists($this->flag_file)) {
            // Clean up stale migration mode
            $this->warn('[WARNING]  Found stale migration mode flag. Cleaning up...');
            $this->cleanup_migration_mode();
        }

        try {
            // Before touching anything, CONFIRM the mysqld topology is the single supervised
            // instance this flow assumes (B-47). A stray second mysqld would keep serving the
            // datadir after `supervisorctl stop mysql`, so the snapshot would either capture
            // inconsistent data or hang - this fails loud with a diagnostic instead.
            $this->preflight_mysqld_topology();

            // Stop MySQL and CONFIRM it is actually down before copying. Copying a live
            // datadir would capture an inconsistent snapshot.
            $this->shell_exec_privileged('supervisorctl stop mysql 2>&1');
            $this->wait_for_mysql_stopped();

            // Always remove any old backup unconditionally (rm -rf is a no-op if absent).
            // Do NOT gate on is_dir(): PHP can't reliably stat the mysql-owned datadir, and
            // a skipped removal makes the next copy NEST into an existing dir (datadir
            // corruption). run_privileged_command throws on failure, so a failed removal
            // aborts the snapshot instead of silently nesting.
            $this->run_privileged_command(['rm', '-rf', $this->backup_dir]);

            // Copy the datadir with `cp -rT`:
            //   -T (--no-target-directory): treat DEST as the literal target, so the copy
            //      can NEVER nest as backup_dir/mysql/ even if backup_dir somehow exists.
            //      THIS is the fix for the datadir-corruption bug (`cp -r` nested on the
            //      basename collision between the `mysql` datadir and its `mysql/` subdir).
            //   -r (not -a): do NOT preserve ownership/perms/times. The datadir is an
            //      idmapped btrfs mount that rejects those attribute ops ("Operation not
            //      permitted"), which would abort the copy. The backup is only a holding
            //      copy; on rollback we chown it back to mysql:mysql.
            $this->run_privileged_command(['cp', '-rT', $this->mysql_data_dir, $this->backup_dir]);

            // Integrity guard: refuse to trust a backup that is not a sane datadir (e.g.
            // the nested-copy corruption signature). A bad backup must never be restorable.
            if (!$this->verify_datadir_sane($this->backup_dir)) {
                throw new \Exception('Snapshot integrity check failed: ' . $this->backup_dir . ' is not a clean datadir (nested-copy corruption?). Snapshot aborted.');
            }

            // Start MySQL
            $this->shell_exec_privileged('mkdir -p /var/run/mysqld');
            $this->shell_exec_privileged('chown -R mysql:mysql /var/run/mysqld');
            $this->shell_exec_privileged('supervisorctl start mysql 2>&1');

            // Wait for MySQL to be ready
            $this->wait_for_mysql_ready();

            // Create migration flag file
            file_put_contents_safe($this->flag_file, json_encode([
                'started_at' => now()->toIso8601String(),
                'started_by' => get_current_user(),
                'backup_dir' => $this->backup_dir,
            ], JSON_PRETTY_PRINT));

            $this->info('[OK] Snapshot created successfully.');
            return true;

        } catch (\Exception $e) {
            $this->error('[ERROR] Failed to create snapshot: ' . $e->getMessage());

            // Try to restart MySQL
            $this->shell_exec_privileged('supervisorctl start mysql 2>&1');

            return false;
        }
    }

    /**
     * Rollback to snapshot
     */
    protected function rollback_snapshot(): bool
    {
        if (!is_dir($this->backup_dir)) {
            $this->error('[ERROR] Backup directory not found!');
            return false;
        }

        // NEVER restore a corrupt backup over the live datadir. Verify the backup is a
        // sane datadir BEFORE we touch (clear) the live data, so a bad snapshot can't
        // destroy a recoverable datadir.
        if (!$this->verify_datadir_sane($this->backup_dir)) {
            $this->error('[ERROR] Refusing to roll back: snapshot at ' . $this->backup_dir . ' failed its integrity check (nested-copy corruption?). The live datadir is left untouched.');
            return false;
        }

        try {
            // Stop MySQL and CONFIRM it is down before mutating the datadir.
            $this->shell_exec_privileged('supervisorctl stop mysql 2>&1');
            $this->wait_for_mysql_stopped();

            // Clear the live datadir safely. `find -mindepth 1 -delete` removes every entry
            // (including dotfiles) WITHOUT the `rm -rf datadir/.*` hazard, where `.*`
            // expands to `..` and could reach the parent.
            $this->shell_exec_privileged("find {$this->mysql_data_dir} -mindepth 1 -delete 2>/dev/null");

            // Restore with `cp -rT` so the copy lands AS the datadir (never nested).
            // `-r` (not -a) because this idmapped btrfs mount rejects attribute
            // preservation; ownership is restored explicitly below.
            $this->run_privileged_command(['cp', '-rT', $this->backup_dir, $this->mysql_data_dir]);

            // Restore mysql:mysql ownership (cp -rT did not preserve it).
            $this->run_privileged_command(['chown', '-R', 'mysql:mysql', $this->mysql_data_dir]);

            // Verify the restored datadir is sane before bringing MySQL back up.
            if (!$this->verify_datadir_sane($this->mysql_data_dir)) {
                throw new \Exception('Restore integrity check failed: ' . $this->mysql_data_dir . ' is not a clean datadir after rollback.');
            }

            // Start MySQL
            $this->shell_exec_privileged('mkdir -p /var/run/mysqld');
            $this->shell_exec_privileged('supervisorctl start mysql 2>&1');

            // Wait for MySQL to be ready
            $this->wait_for_mysql_ready();

            return true;

        } catch (\Exception $e) {
            $this->error('[ERROR] Rollback failed: ' . $e->getMessage());

            // Try to restart MySQL
            $this->shell_exec_privileged('supervisorctl start mysql 2>&1');

            return false;
        }
    }

    /**
     * Commit snapshot (remove backup and flag)
     */
    protected function commit_snapshot(): void
    {
        // Remove backup directory
        if (is_dir($this->backup_dir)) {
            $this->run_privileged_command(['rm', '-rf', $this->backup_dir]);
        }

        // Remove migration flag
        if (file_exists($this->flag_file)) {
            unlink($this->flag_file);
        }

        $this->info('[OK] Snapshot committed - backup removed.');
    }

    /**
     * Clear the migration flag WITHOUT touching the snapshot.
     *
     * commit_snapshot() removes both; this removes only the flag, for the
     * production-container path where the copy is deliberately kept. Leaving the
     * flag behind would make every later run believe a migration was still in
     * progress - and the application answers 503 while it is.
     */
    protected function release_migration_flag(): void
    {
        if (file_exists($this->flag_file)) {
            unlink($this->flag_file);
        }
    }

    /**
     * Cleanup migration mode (remove flag and backup)
     */
    protected function cleanup_migration_mode(): void
    {
        if (is_dir($this->backup_dir)) {
            $this->run_privileged_command(['rm', '-rf', $this->backup_dir]);
        }

        if (file_exists($this->flag_file)) {
            unlink($this->flag_file);
        }
    }

    /**
     * Wait for MySQL to be ready for connections
     *
     * Credentials come from the configured default connection - never hardcoded - and the
     * password rides in the child's environment (MYSQL_PWD), never on the command line,
     * where `ps` would expose it to every user on the box.
     */
    protected function wait_for_mysql_ready(): void
    {
        $connection = config('database.default');
        $db = config('database.connections.' . $connection);

        $command = 'mysql'
            . ' -h' . escapeshellarg((string) $db['host'])
            . ' -P' . escapeshellarg((string) $db['port'])
            . ' -u' . escapeshellarg((string) $db['username'])
            . ' -e ' . escapeshellarg("SELECT 'mysql_ready_probe'")
            . ' 2>/dev/null';

        $env = [];
        if ((string) $db['password'] !== '') {
            $env['MYSQL_PWD'] = (string) $db['password'];
        }

        $max_attempts = 120;
        $attempt = 0;

        while ($attempt < $max_attempts) {
            $output = [];
            $return_var = 0;
            \exec_safe($command, $output, $return_var, $env);

            if ($return_var === 0 && str_contains(implode("\n", $output), 'mysql_ready_probe')) {
                return;
            }

            sleep(1);
            $attempt++;
        }

        throw new \Exception('MySQL did not start within 120 seconds');
    }

    /**
     * Wait until mysqld has fully stopped before copying the datadir. Copying while
     * mysqld is still flushing/holding files open captures an inconsistent snapshot.
     */
    protected function wait_for_mysql_stopped(): void
    {
        $max_attempts = 60;
        $attempt = 0;

        while ($attempt < $max_attempts) {
            // pgrep returns empty when no mysqld process remains.
            $running = trim($this->shell_exec_privileged('pgrep -x mysqld 2>/dev/null') ?? '');
            if ($running === '') {
                return;
            }
            sleep(1);
            $attempt++;
        }

        throw new \Exception('MySQL did not stop within 60 seconds');
    }

    /**
     * Fail LOUD, before anything is stopped, if the mysqld process topology is not the single
     * supervised instance the snapshot flow assumes (B-47).
     *
     * The snapshot stops MySQL with `supervisorctl stop mysql`, then waits for EVERY mysqld to
     * exit (wait_for_mysql_stopped) before copying the datadir. But `supervisorctl stop` only
     * stops the instance supervisor manages. If a stray SECOND mysqld is running - a leftover
     * manual `mysqld`/`mysqld_safe` from an earlier recovery, unmanaged by supervisor - it
     * keeps SERVING the datadir: the wait times out after 60s and every migration silently
     * fails. In the field this masqueraded as a generic timeout and went unnoticed for DAYS,
     * with pulled columns/tables simply absent until a runtime "unknown column" 500 exposed it.
     *
     * Two bad shapes are rejected here, with a diagnostic naming the pids, which one supervisor
     * manages, and how to kill the stray - so the migration never proceeds as if it succeeded:
     *   - MORE THAN ONE mysqld is running (at least one is a stray), or
     *   - the single running mysqld is NOT the one supervisor manages (stopping mysql would not
     *     stop the serving instance).
     *
     * A mysqld is "supervised" when its pid IS supervisor's managed pid (the common case, where
     * the program execs mysqld directly) OR is a descendant of it (the mysqld_safe-wrapper case,
     * where supervisor tracks the wrapper and mysqld is its child). Zero running mysqld is not
     * the rogue condition and is left to proceed: `supervisorctl stop` is then a no-op, the
     * datadir is cold and therefore consistent, and the flow starts MySQL again at the end.
     */
    protected function preflight_mysqld_topology(): void
    {
        $mysqld_pids = $this->_running_mysqld_pids();

        // Nothing serving - not a rogue topology (see docblock). Let the flow proceed.
        if (count($mysqld_pids) === 0) {
            return;
        }

        $supervised_pid = $this->_supervised_mysqld_pid();

        // Classify every running mysqld as supervised or stray.
        $supervised = [];
        $stray = [];
        foreach ($mysqld_pids as $pid) {
            $is_supervised = $supervised_pid !== null
                && ($pid === $supervised_pid || $this->_pid_has_ancestor($pid, $supervised_pid));
            if ($is_supervised) {
                $supervised[] = $pid;
            } else {
                $stray[] = $pid;
            }
        }

        // The good shape: exactly one mysqld, and supervisor manages it.
        if (count($stray) === 0 && count($supervised) === 1) {
            return;
        }

        // Bad topology - assemble a diagnostic and abort. create_snapshot()'s catch turns the
        // thrown message into a loud "[ERROR] Failed to create snapshot: ..." and returns
        // false, so run_with_snapshot() exits 1 without running a single migration.
        $lines = [];
        $lines[] = 'mysqld process topology is not the single supervised instance the snapshot requires (B-47).';
        $lines[] = '';
        $lines[] = 'Running mysqld processes:';
        foreach ($mysqld_pids as $pid) {
            $tag = in_array($pid, $stray, true) ? '[STRAY]     ' : '[supervised]';
            $lines[] = '  ' . $tag . ' pid ' . $pid . '  ' . $this->_pid_command_line($pid);
        }
        $lines[] = '';
        $lines[] = $supervised_pid !== null
            ? 'Supervisor manages mysqld pid ' . $supervised_pid . ' ([program:mysql]).'
            : 'Supervisor does NOT report a RUNNING mysql program, so NONE of the above is supervised.';
        $lines[] = '';
        $lines[] = '`supervisorctl stop mysql` only stops the supervised instance. A stray mysqld would';
        $lines[] = 'keep serving the datadir, so the snapshot would capture inconsistent data or hang';
        $lines[] = 'waiting for a process that never exits. Stop the stray instance(s) and retry:';
        foreach ($stray as $pid) {
            $lines[] = '  sudo kill ' . $pid . '        # graceful; use  sudo kill -9 ' . $pid . '  if it will not exit';
        }
        if (count($stray) === 0) {
            // No stray, but the single serving mysqld is not the supervised one (e.g. supervisor
            // reports mysql stopped while a manual mysqld serves). Point at supervisor.
            $lines[] = '  # Bring the serving mysqld back under supervisor: stop it, then';
            $lines[] = '  #   sudo supervisorctl start mysql';
        }

        throw new \Exception(implode("\n", $lines));
    }

    /**
     * The pids of every running `mysqld` process (exact command name), as ints.
     */
    protected function _running_mysqld_pids(): array
    {
        $raw = trim($this->shell_exec_privileged('pgrep -x mysqld 2>/dev/null') ?? '');
        if ($raw === '') {
            return [];
        }

        $pids = [];
        foreach (preg_split('/\s+/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && ctype_digit($line)) {
                $pids[] = (int) $line;
            }
        }

        return $pids;
    }

    /**
     * The pid supervisor manages for the `mysql` program, or null if it is not RUNNING.
     *
     * `supervisorctl status mysql` prints e.g.
     *   mysql                            RUNNING   pid 3706152, uptime 22:27:09
     * We only trust the pid when the state is RUNNING; STOPPED/FATAL/STARTING report no live pid.
     */
    protected function _supervised_mysqld_pid(): ?int
    {
        $status = trim($this->shell_exec_privileged('supervisorctl status mysql 2>/dev/null') ?? '');
        if ($status === '' || !str_contains($status, 'RUNNING')) {
            return null;
        }

        $marker = 'pid ';
        $at = strpos($status, $marker);
        if ($at === false) {
            return null;
        }

        // (int) reads the leading digits and stops at the trailing ", uptime ...".
        $pid = (int) substr($status, $at + strlen($marker));
        return $pid > 0 ? $pid : null;
    }

    /**
     * Whether $ancestor appears anywhere in $pid's parent chain (walking ppids up to init).
     *
     * The visited-set guards against a malformed cycle; it is a correctness bound on a graph
     * walk, NOT a time budget - there is no deadline here.
     */
    protected function _pid_has_ancestor(int $pid, int $ancestor): bool
    {
        $seen = [];
        $current = $pid;

        while ($current > 1 && !isset($seen[$current])) {
            $seen[$current] = true;

            $ppid_raw = trim($this->shell_exec_privileged(
                'ps -o ppid= -p ' . escapeshellarg((string) $current) . ' 2>/dev/null'
            ) ?? '');
            if ($ppid_raw === '' || !ctype_digit($ppid_raw)) {
                return false;
            }

            $ppid = (int) $ppid_raw;
            if ($ppid === $ancestor) {
                return true;
            }
            $current = $ppid;
        }

        return false;
    }

    /**
     * The full command line of a pid (for the diagnostic), or a placeholder if it has exited.
     */
    protected function _pid_command_line(int $pid): string
    {
        $args = trim($this->shell_exec_privileged(
            'ps -o args= -p ' . escapeshellarg((string) $pid) . ' 2>/dev/null'
        ) ?? '');

        return $args !== '' ? $args : '(process exited)';
    }

    /**
     * Cheap integrity check that a directory is a sane MySQL datadir, NOT the nested-copy
     * corruption that a `cp -r SRC DEST`-into-existing-DEST produces. Run via privileged
     * shell because the datadir is mysql:mysql 0700 and PHP cannot stat it directly.
     *
     * Corruption signature: the system-schema subdir `mysql/` ends up holding a full
     * datadir copy, so `mysql/ibdata1` (a datadir-root file) or a nested `mysql/mysql/`
     * appears. A clean datadir has `mysql/` (system schema) but neither of those.
     */
    protected function verify_datadir_sane(string $dir): bool
    {
        $probe = function (string $test_expr) {
            return trim($this->shell_exec_privileged("$test_expr && echo yes || echo no") ?? '') === 'yes';
        };

        $has_system_schema = $probe("test -d " . escapeshellarg("$dir/mysql"));
        $nested_ibdata = $probe("test -e " . escapeshellarg("$dir/mysql/ibdata1"));
        $nested_schema = $probe("test -d " . escapeshellarg("$dir/mysql/mysql"));

        return $has_system_schema && !$nested_ibdata && !$nested_schema;
    }

    /**
     * Register query transformation listener
     */
    protected function register_query_transformer(): void
    {
        $original_statement = \Closure::bind(function () {
            return $this->connection->statement(...func_get_args());
        }, DB::getFacadeRoot(), DB::getFacadeRoot());

        DB::macro('statement', function ($query, $bindings = []) use ($original_statement) {
            $transformed = SqlQueryTransformer::transform($query);
            return DB::connection()->statement($transformed, $bindings);
        });
    }

    /**
     * Check if all pending migrations are whitelisted
     */
    protected function checkMigrationWhitelist(array $paths): bool
    {
        $whitelistPaths = [
            database_path('migrations/.migration_whitelist'),
            base_path('rsx/resource/migrations/.migration_whitelist'),
        ];

        $whitelistedMigrations = [];
        $foundAtLeastOne = false;

        foreach ($whitelistPaths as $whitelistPath) {
            if (file_exists($whitelistPath)) {
                $foundAtLeastOne = true;
                $whitelist = json_decode(file_get_contents($whitelistPath), true);
                $migrations = array_keys($whitelist['migrations'] ?? []);
                $whitelistedMigrations = array_merge($whitelistedMigrations, $migrations);
            }
        }

        if (!$foundAtLeastOne) {
            $this->warn('[WARNING]  No migration whitelist found. Creating one with existing migrations...');
            $this->createInitialWhitelist();
            return true;
        }

        // Discover recursively so migrations in nested subdirectories (e.g.
        // database/migrations/rspade/) are validated against the whitelist too.
        // The whitelist is keyed by basename, matching how Laravel records
        // migrations, so basename is the unit of comparison here.
        $migrationFiles = [];
        foreach ($paths as $path) {
            foreach (MigrationPaths::_scan_migration_files($path) as $file) {
                $migrationFiles[] = basename($file);
            }
        }

        $unauthorizedMigrations = array_diff($migrationFiles, $whitelistedMigrations);

        if (!empty($unauthorizedMigrations)) {
            $this->error('[ERROR] Unauthorized migrations detected!');
            $this->error('');
            $this->line('The following migrations were not created via php artisan make:migration:');
            foreach ($unauthorizedMigrations as $migration) {
                $this->line('   - ' . $migration);
            }
            $this->error('');
            $this->warn('[WARNING]  Manually created migrations can cause timestamp conflicts.');
            $this->error('');
            $this->line('To fix: Create migrations using "php artisan make:migration [name]"');

            return false;
        }

        return true;
    }

    /**
     * Create initial whitelist with existing migrations
     */
    protected function createInitialWhitelist(): void
    {
        $whitelistLocations = [
            database_path('migrations/.migration_whitelist') => database_path('migrations'),
            base_path('rsx/resource/migrations/.migration_whitelist') => base_path('rsx/resource/migrations'),
        ];

        $totalMigrations = 0;

        foreach ($whitelistLocations as $whitelistPath => $migrationPath) {
            if (!is_dir($migrationPath)) {
                continue;
            }

            $whitelist = [
                'description' => 'This file tracks migrations created via php artisan make:migration',
                'purpose' => 'Prevents manually created migrations from running to avoid timestamp conflicts',
                'migrations' => [],
            ];

            foreach (glob($migrationPath . '/*.php') as $file) {
                $filename = basename($file);
                $whitelist['migrations'][$filename] = [
                    'created_at' => 'pre-whitelist',
                    'created_by' => 'system',
                    'command' => 'existing migration',
                ];
            }

            if (!empty($whitelist['migrations'])) {
                file_put_contents_safe($whitelistPath, json_encode($whitelist, JSON_PRETTY_PRINT));
                $count = count($whitelist['migrations']);
                $totalMigrations += $count;
                $location = str_replace(base_path() . '/', '', dirname($whitelistPath));
                $this->info("[OK] Created whitelist in {$location} with {$count} migration(s).");
            }
        }

        if ($totalMigrations === 0) {
            $this->info('[OK] No existing migrations found. Empty whitelists created.');
        }
    }

    /**
     * Validate migration files for Schema builder usage
     */
    protected function _validate_schema_rules(): bool
    {
        $repository = app('migration.repository');
        $pending_migrations = MigrationValidator::get_pending_migrations($repository);

        if (empty($pending_migrations)) {
            return true;
        }

        $this->info('Validating migration files for Schema builder usage...');

        $has_violations = false;

        foreach ($pending_migrations as $migration_path) {
            try {
                MigrationValidator::validate_migration_file($migration_path);
                $this->info("  [OK] " . basename($migration_path));
            } catch (\RuntimeException $e) {
                $has_violations = true;
                break;
            }
        }

        if ($has_violations) {
            $this->error('');
            $this->error('Migration validation failed!');
            $this->info('');
            $this->line('Please update your migration to use raw SQL instead of Schema builder.');
            $this->line('See: php artisan rsx:man migrations');
            return false;
        }

        // Remove down() methods
        $processed_migrations = [];
        foreach ($pending_migrations as $migration_path) {
            if (MigrationValidator::remove_down_method($migration_path)) {
                $processed_migrations[] = $migration_path;
            }
        }

        if (!empty($processed_migrations)) {
            $this->info('');
            $this->warn('[WARNING]  Modified migration files:');
            foreach ($processed_migrations as $path) {
                $this->line('  - ' . basename($path) . ' - down() method removed');
            }
            $this->info('');
        }

        return true;
    }

    /**
     * Ensure the migrations table exists
     */
    protected function ensure_migrations_table_exists(): void
    {
        try {
            $table = config('database.migrations', 'migrations');
            DB::select("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\Exception $e) {
            $table = config('database.migrations', 'migrations');
            $this->info('Creating migrations table...');

            DB::statement("
                CREATE TABLE IF NOT EXISTS {$table} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                    batch BIGINT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->info('[OK] Migrations table created');
        }
    }

    /**
     * Run migrations one-by-one with normalization after each
     */
    protected function run_migrations_with_normalization($migrator, array $migrationPaths, bool $step, array $requiredColumnsArgs): void
    {
        // Discover all migration files (recursively) and order them by timestamp
        // basename across all base paths. Ordering by basename (not full path) is
        // what lets a migration in a nested subdirectory (e.g.
        // database/migrations/rspade/) run AFTER the tables it references in a
        // sibling base path, resolving cross-directory foreign-key dependencies.
        $files = [];
        foreach ($migrationPaths as $path) {
            foreach (MigrationPaths::_scan_migration_files($path) as $file) {
                $files[] = $file;
            }
        }

        MigrationPaths::_sort_by_basename($files);

        $repository = $migrator->getRepository();
        $ran = $repository->getRan();

        $pending = [];
        foreach ($files as $file) {
            $migrationName = $migrator->getMigrationName($file);
            if (!in_array($migrationName, $ran)) {
                $pending[] = $file;
            }
        }

        if (empty($pending)) {
            $this->info('   INFO  Nothing to migrate.');
            return;
        }

        $totalMigrations = count($pending);
        $currentMigration = 1;

        foreach ($pending as $file) {
            $migrationName = $migrator->getMigrationName($file);

            $this->info("\n[$currentMigration/$totalMigrations] Running: $migrationName");
            $this->newLine();

            $migrator->runPending([$file], [
                'pretend' => false,
                'step' => $step
            ]);

            if ($currentMigration < $totalMigrations) {
                $this->info("\n Normalizing schema after migration...\n");

                AppServiceProvider::set_query_log_mode(AppServiceProvider::QUERY_LOG_DESTRUCTIVE_STDOUT);

                $normalizeExitCode = $this->call('migrate:normalize_schema', $requiredColumnsArgs);

                AppServiceProvider::set_query_log_mode(AppServiceProvider::QUERY_LOG_ALL_STDOUT);

                if ($normalizeExitCode !== 0) {
                    throw new \Exception("Normalization failed after migration: $migrationName");
                }
            }

            $currentMigration++;
        }

        $this->newLine();
        $this->info("[OK] All $totalMigrations migration" . ($totalMigrations > 1 ? 's' : '') . " completed successfully");
    }
}
