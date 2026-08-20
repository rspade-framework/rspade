<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Prod;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use App\RSpade\Core\Rsx;

/**
 * Shared .env mode rewrite + cache clearing for the prod-mode commands.
 *
 * This is the SINGLE implementation of "change RSX_MODE in .env" and "clear the
 * caches by direct unlink so we do not trigger a Manifest::init in this process".
 * rsx:prod:enable / rsx:prod:refresh / rsx:prod:disable all route through here so
 * there is exactly one way to do each of these things.
 *
 * The cache-clearing methods delete files/directories directly (never via the
 * guarded file_put_contents_safe / rmdir_recursive choke points), so they are the
 * mechanism by which an AUTHORIZED rebuild command tears down the old build before
 * producing a new one.
 *
 * SINGLE-FILE .env ASSUMPTION: get_env_mode()/set_mode() read and write ONLY
 * base_path('.env'). That is correct by construction because Rsx_Env_Symlink keeps
 * base_path('.env') a symlink to the authoritative project-root .env (the healer
 * runs on framework pull, rsx:clean, and every prod-mode transition before this
 * class writes). If that invariant is ever broken, a write here would land in a
 * drifted file Laravel does not read - the healer, not a second write path here,
 * is the fix.
 */
class Rsx_Prod_Env
{
    /**
     * Read the current RSX_MODE straight from the .env file (not env(), which is
     * frozen at process boot).
     */
    public static function get_env_mode(): string
    {
        $env_path = base_path('.env');
        if (!file_exists($env_path)) {
            return Rsx::MODE_DEVELOPMENT;
        }

        $contents = file_get_contents($env_path);
        $found = preg_match('/^RSX_MODE=(.*)$/m', $contents, $matches);
        if ($found) {
            return trim($matches[1]);
        }

        return Rsx::MODE_DEVELOPMENT;
    }

    /**
     * Write RSX_MODE into the .env file, then commit the new mode to this running
     * process (see _apply_mode_to_process).
     */
    public static function set_mode(string $mode): void
    {
        $env_path = base_path('.env');

        if (!file_exists($env_path)) {
            file_put_contents_safe($env_path, "RSX_MODE={$mode}\n");
            self::_apply_mode_to_process($mode);

            return;
        }

        $contents = file_get_contents($env_path);

        if (preg_match('/^RSX_MODE=.*$/m', $contents)) {
            $contents = preg_replace('/^RSX_MODE=.*$/m', "RSX_MODE={$mode}", $contents);
        } elseif (preg_match('/^APP_DEBUG=.*$/m', $contents)) {
            $contents = preg_replace('/^(APP_DEBUG=.*)$/m', "$1\nRSX_MODE={$mode}", $contents, 1);
        } elseif (preg_match('/^APP_URL=.*$/m', $contents)) {
            $contents = preg_replace('/^(APP_URL=.*)$/m', "$1\nRSX_MODE={$mode}", $contents, 1);
        } else {
            $contents = rtrim($contents) . "\nRSX_MODE={$mode}\n";
        }

        file_put_contents_safe($env_path, $contents);
        self::_apply_mode_to_process($mode);
    }

    /**
     * Commit a just-written RSX_MODE to THIS process's environment + mode cache.
     *
     * Writing .env is not enough: the prod-mode commands passthru a build subprocess
     * (rsx:prod:build / rsx:bundle:compile), and that child INHERITS this process's
     * environment. Laravel's Dotenv does NOT override an already-set env var, so a
     * child that inherits the parent's boot-time RSX_MODE ignores the .env value we
     * just wrote - the classic symptom being an rsx:prod:enable (parent booted in
     * development) that spawns a build which runs unminified/unstripped, and the
     * mirror-image rsx:prod:disable (parent booted in production) whose dev pre-warm
     * throws "Manifest not built for production mode". Syncing the process env here
     * makes the inherited value match .env. RSX_MODE is a deployment env fact (the
     * env-params ruling lists it as env-worthy) - this keeps the environment
     * consistent with .env, it is NOT an invocation parameter.
     */
    private static function _apply_mode_to_process(string $mode): void
    {
        putenv("RSX_MODE={$mode}");
        $_ENV['RSX_MODE'] = $mode;
        $_SERVER['RSX_MODE'] = $mode;
        Rsx::clear_mode_cache();
    }

    /**
     * Clear Laravel's compiled caches by deleting files directly.
     *
     * Direct unlink avoids booting an artisan subcommand (which would trigger
     * Manifest::init in this process).
     */
    public static function clear_laravel_caches(): void
    {
        $bootstrap_cache = base_path('bootstrap/cache');

        foreach (['config.php', 'routes-v7.php', 'services.php', 'packages.php'] as $name) {
            $file = "{$bootstrap_cache}/{$name}";
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $views_path = storage_path('framework/views');
        if (is_dir($views_path)) {
            foreach (glob("{$views_path}/*.php") as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Clear the RSX build + tmp storage directories by direct recursive delete.
     */
    public static function clear_rsx_caches(): void
    {
        foreach (['rsx-build', 'rsx-tmp'] as $type) {
            $path = storage_path($type);
            if (is_dir($path)) {
                self::_clear_directory_contents($path);
            }
        }
    }

    /**
     * Recursively delete the contents of a directory (leaving the directory).
     */
    protected static function _clear_directory_contents(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }
}
