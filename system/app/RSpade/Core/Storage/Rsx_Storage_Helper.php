<?php

namespace App\RSpade\Core\Storage;

/**
 * Central storage path helper to isolate environments
 *
 * Production mode or Docker environment: Uses standard storage/rsx-TYPE paths
 * IDE/development environments: Uses storage/.ide-rsx-TYPE/[hash] paths to prevent conflicts
 */
class Rsx_Storage_Helper
{
    /**
     * Cache of the environment hash
     */
    private static ?string $_env_hash = null;

    /**
     * Cache of whether we're in production/Docker mode
     */
    private static ?bool $_is_standard_env = null;

    /**
     * Cache of created directories
     */
    private static array $_created_dirs = [];

    /**
     * Check if we should use standard paths (production or Docker)
     */
    private static function _is_standard_environment(): bool
    {
        if (self::$_is_standard_env === null) {
            // Use standard paths if:
            // 1. We're in production mode
            // 2. Base path is /var/www/html (Docker environment)
            self::$_is_standard_env =
                config('app.env') === 'production' ||
                base_path() === '/var/www/html';
        }
        return self::$_is_standard_env;
    }

    /**
     * Get the environment hash based on the base path
     * Uses 16 characters for uniqueness
     */
    private static function _get_env_hash(): string
    {
        if (self::$_env_hash === null) {
            $base_path = base_path();
            // Use MD5 for consistent hash across platforms
            $full_hash = md5($base_path);
            // Take first 16 characters for uniqueness
            self::$_env_hash = substr($full_hash, 0, 16);
        }
        return self::$_env_hash;
    }

    /**
     * Get the storage path for a specific RSX storage type
     *
     * @param string $type The storage type (e.g., 'build', 'tmp', 'locks')
     * @param string|null $subpath Optional subpath within the storage directory
     * @return string The full path to the storage directory
     */
    public static function get_path(string $type, ?string $subpath = null): string
    {
        if (self::_is_standard_environment()) {
            // Production/Docker: use standard paths
            $base_path = storage_path("rsx-{$type}");
        } else {
            // IDE/Development: use isolated paths with hash
            $env_hash = self::_get_env_hash();
            $base_path = storage_path(".ide-rsx-{$type}/{$env_hash}");
        }

        // Create directory if it doesn't exist and we haven't created it yet
        if (!isset(self::$_created_dirs[$base_path])) {
            if (!is_dir($base_path)) {
                mkdir($base_path, 0755, true);
            }
            self::$_created_dirs[$base_path] = true;
        }

        if ($subpath !== null) {
            $full_path = "{$base_path}/{$subpath}";

            // If subpath contains directories, ensure they exist
            $dir_part = dirname($full_path);
            if ($dir_part !== $base_path && !isset(self::$_created_dirs[$dir_part])) {
                if (!is_dir($dir_part)) {
                    mkdir($dir_part, 0755, true);
                }
                self::$_created_dirs[$dir_part] = true;
            }

            return $full_path;
        }

        return $base_path;
    }

    /**
     * Get the build storage path
     * Used for compiled assets that persist across cache clears
     */
    public static function build(string $subpath = null): string
    {
        return self::get_path('build', $subpath);
    }

    /**
     * Get the tmp storage path
     * Used for temporary files cleared on cache clear
     */
    public static function tmp(string $subpath = null): string
    {
        return self::get_path('tmp', $subpath);
    }

    /**
     * Get the locks storage path
     * Used for lock files that are never cleared
     */
    public static function locks(string $subpath = null): string
    {
        return self::get_path('locks', $subpath);
    }

    /**
     * Clear a specific storage type for the current environment
     *
     * @param string $type The storage type to clear
     * @param bool $recursive Whether to recursively delete subdirectories
     */
    public static function clear(string $type, bool $recursive = true): void
    {
        $path = self::get_path($type);

        // Guardrail: refuse to clear a sealed prod build asset from an
        // unauthorized context. Short-circuits instantly when not sealed.
        \App\RSpade\Core\Prod\Rsx_Prod_Seal::assert_mutable($path, 'Rsx_Storage_Helper::clear');

        if (!is_dir($path)) {
            return;
        }

        if ($recursive) {
            // Recursively delete all contents
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
        } else {
            // Just delete files in the directory
            foreach (glob("{$path}/*") as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Clear all IDE storage directories (for rsx:clean command)
     * This removes ALL .ide-rsx-* directories regardless of hash
     */
    public static function clear_all_ide_storage(): void
    {
        $storage_path = storage_path();

        // Find all .ide-rsx-* directories
        foreach (glob("{$storage_path}/.ide-rsx-*") as $ide_dir) {
            if (!is_dir($ide_dir)) {
                continue;
            }

            // Recursively delete entire directory
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($ide_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }

            // Remove the main directory
            rmdir($ide_dir);
        }
    }

    /**
     * Get the environment hash for external tools
     * Returns null in standard environments
     */
    public static function get_env_hash(): ?string
    {
        if (self::_is_standard_environment()) {
            return null;
        }
        return self::_get_env_hash();
    }

    /**
     * Check if using standard environment paths
     */
    public static function is_standard_environment(): bool
    {
        return self::_is_standard_environment();
    }
}