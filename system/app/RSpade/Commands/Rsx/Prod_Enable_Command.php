<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Prod\Rsx_Prod_Env;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;
use Illuminate\Console\Command;
use App\RSpade\Core\Console\Rsx_Artisan;

/**
 * Enter sealed prod mode.
 *
 * Validates the environment, switches RSX_MODE, runs the build pipeline in an
 * authorized context, and - only on success - writes the seal that marks the
 * build immutable.
 */
class Prod_Enable_Command extends Command
{
    protected $signature = 'rsx:prod:enable
                            {--debug : Build the debug variant (unminified, sourcemaps, working console_debug)}';

    protected $description = 'Compile a sealed production build and enter prod mode';

    public function handle(): int
    {
        $debug = (bool) $this->option('debug');
        $mode = $debug ? Rsx::MODE_DEBUG : Rsx::MODE_PRODUCTION;

        // Heal FIRST: guarantee system/.env is a symlink to the authoritative root
        // .env before we read config or write RSX_MODE, so the mode write lands in
        // the single healed file (a drifted real system/.env would swallow it).
        $this->_heal_env();

        // Step 0: Validate the environment BEFORE changing anything.
        if (!$this->_validate_environment($debug)) {
            return 1;
        }

        $this->info('Entering ' . ($debug ? 'debug' : 'production') . ' mode (sealed build)...');
        $this->newLine();

        // This process is the authorized rebuild context: mark it so, so that both
        // the seal write and any guarded cache clearing below are permitted even if
        // a prior seal is still present.
        Rsx_Prod_Seal::authorize_process();

        // Step 1: Switch mode in .env.
        $this->line('  [1/3] Setting RSX_MODE=' . $mode . '...');
        Rsx_Prod_Env::set_mode($mode);

        // Step 2: Clear the old caches (removes any stale build + prior seal).
        $this->line('  [2/3] Clearing old caches...');
        Rsx_Prod_Env::clear_laravel_caches();
        Rsx_Prod_Env::clear_rsx_caches();
        Rsx_Prod_Seal::_reset_cache();

        // Step 3: Run the build pipeline in a fresh, authorized subprocess.
        // The subprocess boots reading the RSX_MODE just written to .env (step 1),
        // and receives its force + authorization as INVOCATION FLAGS - never env
        // prefixes. --force forces a rebuild in the production-like mode;
        // --authorized permits the guarded writes while a build is (re)sealed.
        $this->line('  [3/3] Running build pipeline...');
        $this->newLine();

        $exit_code = Rsx_Artisan::passthru('rsx:prod:build', ['--force', Rsx_Prod_Seal::AUTHORIZED_FLAG]);

        if ($exit_code !== 0) {
            $this->newLine();
            $this->error('Build pipeline failed (exit ' . $exit_code . '). No seal was written.');
            $this->line('The build is NOT sealed; fix the error and run rsx:prod:enable again.');

            return 1;
        }

        // Step 4: Seal the build.
        $seal = Rsx_Prod_Seal::write($mode);

        $this->newLine();
        $this->_print_summary($seal);

        return 0;
    }

    /**
     * Restore the .env symlink invariant and print a one-line summary if it acted.
     */
    private function _heal_env(): void
    {
        $report = \App\RSpade\Core\Prod\Rsx_Env_Symlink::heal();
        if ($report['status'] !== 'already_healthy') {
            $this->line('.env symlink invariant restored (status: ' . $report['status'] . ').');
        }
    }

    /**
     * Validate APP_ENV / APP_DEBUG for the requested mode.
     *
     * Strict production demands a real production environment. Debug is a
     * production-like LOCAL test build, so environment mismatches are warnings.
     */
    private function _validate_environment(bool $debug): bool
    {
        $app_env = app()->environment();
        $app_debug = (bool) config('app.debug');

        if ($debug) {
            if ($app_env !== 'production') {
                $this->warn("Note: APP_ENV is '{$app_env}' (debug builds do not require 'production').");
            }

            return true;
        }

        $problems = [];
        if ($app_env !== 'production') {
            $problems[] = "APP_ENV is '{$app_env}' - set APP_ENV=production in .env";
        }
        if ($app_debug) {
            $problems[] = 'APP_DEBUG is true - set APP_DEBUG=false in .env';
        }

        if (!empty($problems)) {
            $this->error('Cannot enter strict production mode - environment is not production-ready:');
            $this->newLine();
            foreach ($problems as $problem) {
                $this->line('  - ' . $problem);
            }
            $this->newLine();
            $this->line('Fix the above, or build the debug variant instead:');
            $this->line('  php artisan rsx:prod:enable --debug');

            return false;
        }

        return true;
    }

    /**
     * Print the post-seal summary (mode, build key, asset count, total size).
     */
    private function _print_summary(array $seal): void
    {
        $build_root = Rsx_Prod_Seal::_build_root();
        $total_bytes = 0;
        foreach ($seal['assets'] as $asset) {
            $abs = $build_root . '/' . $asset['file'];
            if (is_file($abs)) {
                $total_bytes += filesize($abs);
            }
        }

        $this->info('[OK] Sealed ' . $seal['rsx_mode'] . ' build');
        $this->line('     Build key:  ' . $seal['build_key']);
        $this->line('     Assets:     ' . count($seal['assets']) . ' files, ' . $this->_format_size($total_bytes));
        $this->line('     Git commit: ' . ($seal['git_commit'] ?? 'unknown'));
        $this->line('     Sealed at:  ' . $seal['created_at']);
        $this->newLine();
        $this->line('Verify at any time:  php artisan rsx:prod:verify');
        $this->line('Rebuild the assets:  php artisan rsx:prod:refresh');
        $this->line('Leave prod mode:     php artisan rsx:prod:disable');
        $this->newLine();
        $this->line('Before deploying the live application, read and verify:');
        $this->line('  php artisan rsx:man prelaunch_checklist');
    }

    private function _format_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }
}
