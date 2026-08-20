<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

/**
 * Central location for seeder path management
 *
 * RSpade uses /rsx/resource/seeders as the default location for user seeders.
 * The /resource/ directory is excluded from manifest scanning, making it suitable
 * for seeders and other framework-related code that doesn't follow RSX conventions.
 */
class SeederPaths
{
    /**
     * Get the default path for new seeders created via make:seeder
     *
     * @return string Absolute path to default seeder directory
     */
    public static function get_default_path(): string
    {
        return base_path('rsx/resource/seeders');
    }

    /**
     * Get all seeder directories to scan
     *
     * @return array Array of absolute paths to seeder directories
     */
    public static function get_all_paths(): array
    {
        return [
            static::get_default_path(),
        ];
    }

    /**
     * Ensure all seeder directories exist
     *
     * @return void
     */
    public static function ensure_directories_exist(): void
    {
        foreach (static::get_all_paths() as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
}
