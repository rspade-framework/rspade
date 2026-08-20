<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\Console\Commands\FrameworkDeveloperCommand;
use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Console\Rsx_Artisan;

class Manifest_Build_Command extends FrameworkDeveloperCommand
{
    /**
     * Framework-internal flag (the `--_` convention - argv-stripped pre-boot, declared as no
     * InputOption, so it appears in no help output): skip the pending-migration notice this
     * build normally prints as its last line. Passed by the framework updater, whose own
     * final `migrate:status_notice` is the single per-pull notice.
     */
    public const FLAG_NO_SCHEMA_CHECK = '--_no-check-schema-updates-pending';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:manifest:build
                            {--force : Execute rsx:clean then rsx:manifest:build via PHP exec}
                            {--clean : Clear all caches before building (forces complete rebuild)}
                            {--build-debug : Enable verbose build output (shows file-by-file processing)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build or rebuild the RSX manifest (incremental by default, use --clean for full rebuild)';

    /**
     * Create a new command instance
     */
    public function __construct()
    {
        parent::__construct();

        // Check if --build-debug flag is present in command line args
        // Define BUILD_DEBUG_MODE early so it's set before service providers boot
        if (in_array('--build-debug', $_SERVER['argv'] ?? [])) {
            define('BUILD_DEBUG_MODE', true);
        }
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Prevent being called via $this->call() - must use passthru for fresh process
        $this->prevent_call_from_another_command();

        // Handle --force flag: complete clean and rebuild via exec.
        //
        // Each subcommand runs in a FRESH subprocess so in-memory caches are truly
        // gone - the whole point of --force. Rsx_Artisan owns the command line (the
        // absolute artisan path, the right PHP binary, and the lock-group flag that
        // stops a child from deadlocking against a lock this process holds). Two
        // things left for this caller (the field regression fixed here):
        //   * CAPTURE each exit code and abort loudly if a subcommand fails.
        //   * EXIT with the real status. The old `die()` exited 0 unconditionally,
        //     so a total no-op reported success (a --force that silently did nothing).
        if ($this->option('force')) {
            // rsx:clean (sanctioned: this IS the documented --force semantics).
            // --_no-system-reset: build tooling wants cache invalidation only - the
            // downstream system/ git reset (and its integrity check) is out of scope here.
            $clean_status = Rsx_Artisan::passthru('rsx:clean', ['--_no-system-reset']);
            if ($clean_status !== 0) {
                $this->error('[ERROR] rsx:manifest:build --force: rsx:clean failed (exit ' . $clean_status . '); aborting the forced rebuild.');
                return $clean_status;
            }

            // Full rebuild WITHOUT --force (no recursion into this branch). The internal
            // notice-suppression flag was stripped from argv pre-boot, so forward it
            // explicitly or the inner build would print the notice this run was told to skip.
            $suppress = Rsx_Internal_Flags::has(self::FLAG_NO_SCHEMA_CHECK)
                ? [self::FLAG_NO_SCHEMA_CHECK]
                : [];
            $build_status = Rsx_Artisan::passthru('rsx:manifest:build', $suppress);
            if ($build_status !== 0) {
                $this->error('[ERROR] rsx:manifest:build --force: the rebuild failed (exit ' . $build_status . ').');
                return $build_status;
            }

            return 0;
        }

        // Check if in production mode with existing manifest
        if (config('app.env') === 'production') {
            $manifest_file = storage_path('rsx-build/manifest_data.php');

            if (file_exists($manifest_file)) {
                $file_age = time() - filemtime($manifest_file);

                // If manifest exists and is older than 5 seconds, block rebuild
                if ($file_age > 5) {
                    $this->error('Production manifest rebuild blocked');
                    $this->line('');
                    $this->line('The manifest file exists in production and is ' . round($file_age / 60, 1) . ' minutes old.');
                    $this->line('Rebuilding the manifest in production is not permitted without explicit confirmation.');
                    $this->line('');
                    $this->line('Use --force flag to override this protection and rebuild anyway.');
                    return 1;
                }
            }
        }

        $start_time = microtime(true);

        // Reset debug options
        Manifest::$_debug_options = [];

        // Handle --clean flag: clear caches first
        if ($this->option('clean')) {
            $this->info('Clearing caches before build...');
            // Clear RSX caches only (--_no-system-reset: no git-state changes from build
            // tooling).
            Rsx_Artisan::passthru('rsx:clean', ['--_no-system-reset']);
            Manifest::clear();
        }

        $this->info('Building RSX manifest...');

        // Default behavior: incremental build (don't clear unless --clean was used)
        // Manifest::init() handles everything - loading cache and doing incremental updates
        Manifest::init();
        $stats = Manifest::get_stats();

        $elapsed = round((microtime(true) - $start_time) * 1000, 2);

        $this->info('Manifest built successfully!');
        $this->line('');
        $this->line('Summary:');
        $this->line('  Build Hash: ' . Manifest::get_build_key());
        $this->line('  Total Files: ' . $stats['total_files']);
        $this->line('  PHP Classes: ' . $stats['php']);
        $this->line('  JS Classes: ' . $stats['js']);
        $this->line('  Blade Views: ' . $stats['blade']);
        $this->line('  Build Time: ' . $elapsed . 'ms');

        // Reset debug options
        Manifest::$_debug_options = [];

        // ENVIRONMENT UPDATES. system/ is vendored, so a plain `git pull` DELIVERS new
        // system/bin/environment_updates/*.sh - but only rsx:framework:pull ever EXECUTED
        // them, so an environment that never runs a framework update carried the scripts
        // with none of their effects (no pre-commit system/ guard, no status line, no
        // storage relocation). Git never distributes .git/hooks, so the primary trigger
        // must live in code: a SUCCESSFUL build runs in every environment regardless of
        // hooks, clone age, or how the code arrived.
        //
        // A FAILED build never reaches here (the tree state is unknown). The scripts are
        // self-detecting and SILENT when already applied, so a healthy build's output
        // grows by zero characters.
        if (self::__should_run_environment_updates()) {
            $post_update = base_path('bin/post-update.sh');

            // The lock keeps overlapping builds from running the scripts concurrently.
            // A timeout means someone else is already running them - skip silently, the
            // work is being done. This is the one expected failure here, so it is caught
            // narrowly around the acquire and nowhere else.
            $lock_token = null;
            try {
                $lock_token = RsxLocks::system_lock('ENVIRONMENT_UPDATES', 5);
            } catch (\Throwable $e) {
                $lock_token = null;
            }

            if ($lock_token !== null) {
                try {
                    if (is_file($post_update)) {
                        // The env contract post-update.sh documents, exported exactly as the
                        // updater's run_post_update() does (bin/framework-pull-upstream.sh.dist).
                        // IS_FRAMEWORK_DEVELOPER is the ONE thing that differs: the updater
                        // hardcodes false (a pull is downstream by definition) while a build
                        // runs in the monorepo too, so it is read from config here.
                        $command = 'PROJECT_ROOT=' . escapeshellarg(dirname(base_path()))
                            . ' SYSTEM_DIR=' . escapeshellarg(base_path())
                            . ' IS_FRAMEWORK_DEVELOPER=' . escapeshellarg(config('rsx.code_quality.is_framework_developer', false) ? 'true' : 'false')
                            . ' bash ' . escapeshellarg($post_update);

                        $post_update_status = 0;
                        passthru('bash -c ' . escapeshellarg($command), $post_update_status);

                        // NON-FATAL: the build succeeded, and post-update.sh already isolates
                        // per-script failures. Report and carry on.
                        if ($post_update_status !== 0) {
                            $this->warn('[WARNING] Environment updates reported a failure (exit ' . $post_update_status . '); the build itself succeeded.');
                        }
                    }
                } finally {
                    RsxLocks::release_lock($lock_token);
                }
            }
        }

        // LAST line of a SUCCESSFUL build: tell the developer if the schema is behind. A build
        // is the moment new migration files have just landed in the tree (a git pull, a
        // framework update, a branch switch) and it is the command developers run constantly.
        // Silent when nothing is pending - a clean build's output grows by zero characters.
        // A FAILED build never reaches here (the tree state is unknown; the failure is the
        // message).
        if (!Rsx_Internal_Flags::has(self::FLAG_NO_SCHEMA_CHECK)) {
            $this->call('migrate:status_notice');
        }

        return 0;
    }

    /**
     * Framework-internal seam: may this build run the environment updates?
     *
     * Two gates, in order:
     *   1. DEVELOPMENT mode only (the RSX mode, never APP_DEBUG). debug/production are
     *      SEALED, immutable builds - they must not self-modify the environment.
     *   2. NOT under maintenance. rsx:framework:pull raises that window around its own
     *      sync + rebuild and invokes post-update.sh itself afterwards, so this gate is
     *      what makes a pull apply the updates exactly ONCE. An operator maintenance
     *      window also skips them, correctly: the services are stopped.
     *
     * @return bool
     */
    public static function __should_run_environment_updates(): bool
    {
        if (!Rsx::is_development()) {
            return false;
        }

        return !Framework_Maintenance::is_active();
    }

    /**
     * Prevent this command from being called via $this->call() from another command
     *
     * This command MUST run in a fresh process to ensure in-memory caches are cleared.
     * Use Rsx_Artisan::passthru() instead of $this->call() when calling from other commands.
     *
     * @return void
     */
    protected function prevent_call_from_another_command()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($trace as $frame) {
            // Check if we're being called from Artisan::call() or Command::call()
            if (isset($frame['class']) && isset($frame['function'])) {
                $class = $frame['class'];
                $function = $frame['function'];

                // Detect $this->call() from another command
                if ($function === 'call' && str_contains($class, 'Command')) {
                    $this->error('');
                    $this->error('[ERROR] FATAL ERROR: rsx:manifest:build cannot be called via $this->call()');
                    $this->error('');
                    $this->error('This command MUST run in a fresh process to properly clear in-memory caches.');
                    $this->error('');
                    $this->error('FIX: Use Rsx_Artisan::passthru() instead of $this->call():');
                    $this->error('');
                    $this->line('  // [ERROR] WRONG - runs in same process, caches remain in memory');
                    $this->line('  $this->call(\'rsx:manifest:build\');');
                    $this->error('');
                    $this->line('  // [OK] CORRECT - fresh process, all caches cleared');
                    $this->line('  Rsx_Artisan::passthru(\'rsx:manifest:build\');');
                    $this->error('');

                    exit(1);
                }
            }
        }
    }
}
