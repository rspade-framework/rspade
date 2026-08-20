<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;
use Illuminate\Console\Command;

/**
 * Verify a sealed prod build against its seal.
 *
 * The cluster/CI tool: recomputes the sha256 of every sealed asset, checks the
 * build_key, checks env sanity, and reports OPcache posture (advisory). Exits 0
 * when the build matches the seal and the environment is sound, 1 otherwise.
 */
class Prod_Verify_Command extends Command
{
    protected $signature = 'rsx:prod:verify';

    protected $description = 'Verify the sealed production build matches its seal';

    public function handle(): int
    {
        if (!Rsx_Prod_Seal::exists()) {
            $this->error('No seal present - the system is not in sealed prod mode.');
            $this->line('  Compile and seal a build with: php artisan rsx:prod:enable');

            return 1;
        }

        $seal = Rsx_Prod_Seal::read();
        $rows = [];
        $failed = false;

        // --- Asset + build_key drift ---
        $drift = Rsx_Prod_Seal::verify();
        if (empty($drift)) {
            $rows[] = ['Assets', 'OK', count($seal['assets'] ?? []) . ' files match the seal'];
        } else {
            foreach ($drift as $finding) {
                $rows[] = ['Assets', 'FAIL', $finding];
                $failed = true;
            }
        }

        // --- Environment sanity ---
        $sealed_mode = $seal['rsx_mode'] ?? 'unknown';
        $strict_prod = ($sealed_mode === Rsx::MODE_PRODUCTION);
        $app_env = app()->environment();
        $app_debug = (bool) config('app.debug');

        if ($strict_prod) {
            if ($app_env === 'production') {
                $rows[] = ['APP_ENV', 'OK', 'production'];
            } else {
                $rows[] = ['APP_ENV', 'FAIL', "'{$app_env}' (strict prod build expects 'production')"];
                $failed = true;
            }

            if (!$app_debug) {
                $rows[] = ['APP_DEBUG', 'OK', 'false'];
            } else {
                $rows[] = ['APP_DEBUG', 'FAIL', 'true (must be false in strict production)'];
                $failed = true;
            }
        } else {
            $rows[] = ['APP_ENV', 'INFO', "'{$app_env}' (debug build - advisory only)"];
        }

        // --- OPcache posture (advisory, never affects exit) ---
        foreach ($this->_opcache_rows() as $row) {
            $rows[] = $row;
        }

        $this->info('Sealed ' . $sealed_mode . ' build (' . ($seal['created_at'] ?? 'unknown time') . ')');
        $this->line('  Build key: ' . ($seal['build_key'] ?? 'unknown'));
        $this->newLine();
        $this->table(['Check', 'Status', 'Detail'], $rows);
        $this->newLine();

        if ($failed) {
            $this->error('[FAIL] Verification failed - see FAIL rows above.');

            return 1;
        }

        $this->info('[OK] Sealed build verified.');

        return 0;
    }

    /**
     * OPcache advisory rows (report-only; a JIT-served prod box wants OPcache on
     * with timestamp validation off, but this command never fails on it).
     */
    private function _opcache_rows(): array
    {
        if (!function_exists('opcache_get_status')) {
            return [['OPcache', 'INFO', 'opcache extension not available in this SAPI']];
        }

        $status = @opcache_get_status(false);
        $enabled = is_array($status) && !empty($status['opcache_enabled']);
        $validate = ini_get('opcache.validate_timestamps');

        return [
            ['OPcache', 'INFO', $enabled ? 'enabled' : 'disabled (or off in CLI SAPI)'],
            ['OPcache validate_timestamps', 'INFO', ($validate === false ? 'unset' : (string) $validate)],
        ];
    }
}
