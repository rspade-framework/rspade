<?php

namespace App\RSpade\Core\Providers;

use Exception;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rsx_Preboot_Service - Pre-bootstrap debug and development tooling
 *
 * This service runs BEFORE the RSX framework bootstraps, during AppServiceProvider::register().
 * It handles debug features that need to run early:
 * - FORCE_REBUILD_EVERY_REQUEST: Clears build/tmp caches
 * - ENHANCED_DEBUG: Sequential request locking for debugging race conditions
 * - Console debug trace mode initialization
 *
 * EXECUTION ORDER:
 * 1. Rsx_Preboot_Service::init() (this class - early debug setup)
 * 2. Rsx_Framework_Provider::boot() (RSX framework initialization)
 *
 * HOW THIS IS CALLED:
 * File: app/Providers/AppServiceProvider.php
 * Line: ~112 in register() method
 * Mechanism: Direct static method call
 * Code: Rsx_Preboot_Service::init();
 *
 * WHY IT'S CALLED HERE:
 * - AppServiceProvider::register() runs very early in Laravel's lifecycle
 * - Must run BEFORE Rsx_Framework_Provider::boot() to handle debug setup
 * - register() runs before any provider's boot() method
 * - Ensures debug locks/cache clearing happen before manifest builds
 *
 * STATIC DESIGN:
 * Following framework philosophy - this is a static utility class, not instantiated.
 */
class Rsx_Preboot_Service
{
    /**
     * File pointer for flock if using file-based locking
     */
    protected static $lock_fp = null;

    /**
     * Track if build cache was already cleared in this session
     */
    public static $build_cache_cleared = false;

    /**
     * Initialize RSpade framework services
     * Called from AppServiceProvider::register()
     */
    public static function init(): void
    {
        // Handle force rebuild if enabled
        if (env('FORCE_REBUILD_EVERY_REQUEST', false)) {
            // Clear build cache on first init
            static::__clear_build_cache_once();
        }

        // Handle enhanced debug features if enabled
        if (env('ENHANCED_DEBUG', false)) {
            // Handle sequential request debugging
            static::__handle_sequential_debug_lock();
        }

        // Handle console debug trace mode if enabled
        \App\RSpade\Core\Debug\Debugger::_try_enable_trace_mode();

        // Future initialization directives will go here
    }

    /**
     * Clear build cache once per session
     *
     * Removes all files in storage/rsx-build/* and storage/rsx-tmp/* when FORCE_REBUILD_EVERY_REQUEST is enabled,
     * but only once per PHP process to avoid repeated clearing.
     */
    protected static function __clear_build_cache_once(): void
    {
        // Only clear once per session
        if (static::$build_cache_cleared) {
            return;
        }

        static::$build_cache_cleared = true;

        // Clear both build and tmp directories
        $rsx_build = storage_path('rsx-build');
        $rsx_tmp = storage_path('rsx-tmp');

        // Clear build directory if exists
        if (is_dir($rsx_build)) {
            console_debug('CACHE', "Clearing RSX build cache in {$rsx_build}");
            rmdir_recursive($rsx_build, false);
        }

        // Clear tmp directory if exists
        if (is_dir($rsx_tmp)) {
            console_debug('CACHE', "Clearing RSX temp cache in {$rsx_tmp}");
            rmdir_recursive($rsx_tmp, false);
        }

        console_debug('CACHE', 'RSX caches cleared');
    }

    /**
     * Handle sequential request debugging lock
     *
     * When ENHANCED_DEBUG is set in .env, this ensures only one
     * PHP process can run application code at a time. This is purely for debugging
     * purposes to simplify troubleshooting race conditions and concurrency issues.
     *
     * Uses database advisory locks if available, otherwise falls back to file locks.
     */
    protected static function __handle_sequential_debug_lock(): void
    {
        // Log that we're entering sequential mode
        console_debug('LOCK', 'Acquiring sequential request lock...');

        // Try database advisory lock first (if database is configured)
        if (static::__try_database_lock()) {
            return;
        }

        // Fall back to file-based lock
        static::__acquire_file_lock();
    }

    /**
     * Try to acquire database advisory lock
     *
     * @return bool True if lock acquired, false if database not available
     */
    protected static function __try_database_lock(): bool
    {
        try {
            // Check if database is configured
            if (!config('database.default')) {
                return false;
            }

            $lock_name = 'rspade_debug_sequential_' . md5(base_path());
            $timeout = 60; // Wait up to 60 seconds for lock

            // MySQL/MariaDB advisory lock
            if (config('database.default') === 'mysql') {
                $result = DB::select('SELECT GET_LOCK(?, ?) as acquired', [$lock_name, $timeout]);

                if ($result[0]->acquired ?? false) {
                    // Register shutdown function to release lock
                    register_shutdown_function(function () use ($lock_name) {
                        try {
                            DB::select('SELECT RELEASE_LOCK(?)', [$lock_name]);
                        } catch (Exception $e) {
                            // Ignore errors on shutdown
                        }
                    });

                    console_debug('LOCK', "Database advisory lock acquired: {$lock_name}");

                    return true;
                }
            }

            // PostgreSQL advisory lock
            if (config('database.default') === 'pgsql') {
                // Use hash of lock name as bigint for PostgreSQL
                $lock_id = crc32($lock_name);

                DB::select('SELECT pg_advisory_lock(?)', [$lock_id]);

                // Register shutdown function to release lock
                register_shutdown_function(function () use ($lock_id) {
                    try {
                        DB::select('SELECT pg_advisory_unlock(?)', [$lock_id]);
                    } catch (Exception $e) {
                        // Ignore errors on shutdown
                    }
                });

                console_debug('LOCK', "Database advisory lock acquired: {$lock_name}");

                return true;
            }
        } catch (Exception $e) {
            // Database not available or error occurred
            // Fall back to file lock
        }

        return false;
    }

    /**
     * Acquire file-based lock
     *
     * Uses flock() for cross-process locking when database locks aren't available
     */
    protected static function __acquire_file_lock(): void
    {
        $lock_file = storage_path('framework/debug_sequential.lock');

        // Ensure directory exists
        $dir = dirname($lock_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Open lock file
        static::$lock_fp = fopen($lock_file, 'w+');

        if (!static::$lock_fp) {
            throw new RuntimeException("Could not open debug lock file: {$lock_file}");
        }

        // Acquire exclusive lock (blocking)
        if (!flock(static::$lock_fp, LOCK_EX)) {
            throw new RuntimeException('Could not acquire debug sequential lock');
        }

        // Write PID to lock file for debugging
        fwrite(static::$lock_fp, getmypid() . "\n");
        fflush(static::$lock_fp);

        // Register shutdown function to release lock
        register_shutdown_function(function () {
            if (static::$lock_fp) {
                flock(static::$lock_fp, LOCK_UN);
                fclose(static::$lock_fp);
                static::$lock_fp = null;
            }
        });

        console_debug('LOCK', "File lock acquired: {$lock_file}");
    }
}
