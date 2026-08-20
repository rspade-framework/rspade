<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Rsx;

/**
 * Environment_Health_Checks - the PHP runtime, Node binary, storage writability, and
 * application-mode posture checks for rsx:health.
 *
 * These are cross-cutting environment invariants with no single feature file to hang
 * them on, so they live together in the Health domain. Each method is a public static
 * `#[Health_Check('label')]` (a bare marker attribute - never a defined class), invoked
 * by Health_Check_Runner and returning a row (or a list of rows) per the runner's
 * contract.
 */
class Environment_Health_Checks
{
    /** PHP extensions the framework needs to boot every feature. */
    private const REQUIRED_EXTENSIONS = ['zip', 'gd', 'imagick', 'redis', 'pdo_mysql'];

    /** Minimum supported PHP version. */
    private const MIN_PHP_VERSION = '8.4';

    /**
     * PHP version + required extensions.
     *
     * @return array
     */
    #[Health_Check('PHP')]
    public static function php_environment(): array
    {
        $rows = [];

        // PHP version.
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=')) {
            $rows[] = ['label' => 'PHP Version', 'status' => 'OK', 'detail' => 'PHP ' . PHP_VERSION];
        } else {
            $rows[] = [
                'label' => 'PHP Version',
                'status' => 'FAIL',
                'detail' => 'PHP ' . PHP_VERSION . ' is below the required ' . self::MIN_PHP_VERSION,
                'remediation' => 'upgrade PHP to ' . self::MIN_PHP_VERSION . ' or newer',
            ];
        }

        // Required extensions: a summary OK for those present, a FAIL row per missing one.
        $present = [];
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (extension_loaded($ext)) {
                $present[] = $ext;
            } else {
                $missing[] = $ext;
            }
        }

        if (!empty($present)) {
            $rows[] = [
                'label' => 'PHP Extensions',
                'status' => 'OK',
                'detail' => 'loaded: ' . implode(', ', $present),
            ];
        }

        foreach ($missing as $ext) {
            $rows[] = [
                'label' => 'PHP Extension: ' . $ext,
                'status' => 'FAIL',
                'detail' => "required extension '{$ext}' is not loaded",
                'remediation' => "install/enable the php-{$ext} extension",
            ];
        }

        return $rows;
    }

    /**
     * Node.js binary (realtime relay, rsx:debug, build tooling).
     *
     * @return array
     */
    #[Health_Check('Node.js')]
    public static function node_binary(): array
    {
        $process = new Process(['node', '--version']);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return [
                'status' => 'FAIL',
                'detail' => 'node not found on PATH',
                'remediation' => 'install nodejs (apt-get install -y nodejs)',
            ];
        }

        return ['status' => 'OK', 'detail' => 'node ' . trim($process->getOutput())];
    }

    /**
     * Storage directory writability. The base storage_path() must exist and be writable;
     * the feature subdirs are created lazily on first use, so a missing one is a WARN.
     *
     * @return array
     */
    #[Health_Check('Storage Writable')]
    public static function storage_writability(): array
    {
        $rows = [];

        // The base storage directory is not created lazily - a missing one is a real fault.
        $base = storage_path();
        if (!is_dir($base)) {
            $rows[] = [
                'label' => 'storage/',
                'status' => 'FAIL',
                'detail' => 'storage directory missing: ' . $base,
                'remediation' => 'create ' . $base . ' and make it writable by the web/CLI user',
            ];
        } elseif (!is_writable($base)) {
            $rows[] = [
                'label' => 'storage/',
                'status' => 'FAIL',
                'detail' => 'not writable: ' . $base,
                'remediation' => 'chmod/chown ' . $base . ' so the web/CLI user can write',
            ];
        } else {
            $rows[] = ['label' => 'storage/', 'status' => 'OK', 'detail' => 'writable'];
        }

        // Feature subdirs: created on first use, so a missing one is only a WARN.
        $subdirs = [
            'storage/logs' => storage_path('logs'),
            'storage/rsx-thumbnails' => storage_path('rsx-thumbnails'),
            'storage/rsx-renditions' => storage_path('rsx-renditions'),
        ];

        foreach ($subdirs as $label => $path) {
            if (!is_dir($path)) {
                $rows[] = [
                    'label' => $label,
                    'status' => 'WARN',
                    'detail' => 'does not exist yet',
                    'remediation' => 'created lazily on first use - no action needed unless a feature fails to write it',
                ];
            } elseif (!is_writable($path)) {
                $rows[] = [
                    'label' => $label,
                    'status' => 'FAIL',
                    'detail' => 'not writable: ' . $path,
                    'remediation' => 'chmod/chown ' . $path . ' so the web/CLI user can write',
                ];
            } else {
                $rows[] = ['label' => $label, 'status' => 'OK', 'detail' => 'writable'];
            }
        }

        return $rows;
    }

    /**
     * Application-mode sanity: the active RSX_MODE, plus warnings for risky prod-mode
     * postures (APP_DEBUG on in production; debug-site backdoors active on a prod-mode box).
     *
     * @return array
     */
    #[Health_Check('Application Mode')]
    public static function mode_sanity(): array
    {
        $rows = [];

        $mode = Rsx::get_mode();
        $app_env = app()->environment();
        $app_debug = (bool) config('app.debug');

        if ($mode === Rsx::MODE_DEVELOPMENT) {
            $rows[] = [
                'label' => 'RSX Mode',
                'status' => 'INFO',
                'detail' => 'development (auto-rebuild, JIT compile, full debugging)',
            ];
        } else {
            $rows[] = [
                'label' => 'RSX Mode',
                'status' => 'INFO',
                'detail' => $mode . ' (sealed build); APP_ENV=' . $app_env,
            ];
        }

        // Production RSX_MODE with APP_DEBUG on: a leaky production posture.
        if ($mode === Rsx::MODE_PRODUCTION && $app_debug) {
            $rows[] = [
                'label' => 'Production Debug Flag',
                'status' => 'WARN',
                'detail' => 'RSX_MODE=production but APP_DEBUG=true',
                'remediation' => 'set APP_DEBUG=false in .env for a production box',
            ];
        }

        // A prod mode (debug or production) on a hostname that resolves as a debug site
        // means the developer backdoors (auto-fill login, debug tools) are live in prod.
        if (Rsx::is_production() && Rsx::is_debug_site()) {
            $rows[] = [
                'label' => 'Debug Site Backdoors',
                'status' => 'WARN',
                'detail' => 'debug backdoors active on a production-mode box (the hostname matches RSPADE_DEBUG_DOMAIN_SUFFIX)',
                'remediation' => 'clear RSPADE_DEBUG_DOMAIN_SUFFIX in .env, or serve production from a hostname that does not match it',
            ];
        }

        return $rows;
    }
}
