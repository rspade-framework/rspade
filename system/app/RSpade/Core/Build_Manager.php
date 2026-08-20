<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core;

use Illuminate\Support\Facades\File;

class Build_Manager
{
    /**
     * Base paths for different artifact types
     */
    protected const PATHS = [
        'build' => 'build',
        'cache' => 'build/cache',
        'temp' => 'build/temp',
    ];

    /**
     * Get the full path for a build artifact
     *
     * @param string $path Relative path within build directory
     * @param string $type Type of artifact ('build', 'cache', 'temp')
     * @return string
     */
    public static function path($path = '', $type = 'build')
    {
        $base = base_path(self::PATHS[$type] ?? self::PATHS['build']);

        if (empty($path)) {
            return $base;
        }

        return $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Get path for a build artifact
     */
    public static function build($path = '')
    {
        return self::path($path, 'build');
    }

    /**
     * Get path for a cache artifact
     */
    public static function cache($path = '')
    {
        return self::path($path, 'cache');
    }

    /**
     * Get path for a temporary file
     */
    public static function temp($path = '')
    {
        return self::path($path, 'temp');
    }

    /**
     * Ensure a directory exists
     */
    public static function ensure_directory($path)
    {
        $dir = is_dir($path) ? $path : dirname($path);

        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $path;
    }

    /**
     * Write content to a build file
     */
    public static function put($path, $content, $type = 'build')
    {
        $full_path = self::path($path, $type);
        self::ensure_directory($full_path);

        return File::put($full_path, $content);
    }

    /**
     * Get content from a build file
     */
    public static function get($path, $type = 'build')
    {
        $full_path = self::path($path, $type);

        if (File::exists($full_path)) {
            return File::get($full_path);
        }

        return null;
    }

    /**
     * Check if a build file exists
     */
    public static function exists($path, $type = 'build')
    {
        return File::exists(self::path($path, $type));
    }

    /**
     * Clear all files in a directory
     */
    public static function clear($type = 'cache', $older_than_hours = null)
    {
        $path = self::path('', $type);

        if (!File::isDirectory($path)) {
            return 0;
        }

        $count = 0;
        $now = time();
        $cutoff = $older_than_hours ? $now - ($older_than_hours * 3600) : 0;

        // Get all files recursively
        $files = File::allFiles($path);

        foreach ($files as $file) {
            // Skip if checking age and file is too new
            if ($cutoff > 0 && $file->getMTime() > $cutoff) {
                continue;
            }

            File::delete($file->getPathname());
            $count++;
        }

        // Clean up empty directories
        self::__clean_empty_directories($path);

        return $count;
    }

    /**
     * Clear build artifacts
     */
    public static function clear_build($subdirectory = null)
    {
        if ($subdirectory) {
            $path = self::build($subdirectory);
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
                return true;
            }
            return false;
        }

        return self::clear('build');
    }

    /**
     * Clear cache files
     */
    public static function clear_cache()
    {
        return self::clear('cache');
    }

    /**
     * Clear temporary files
     */
    public static function clear_temp($older_than_hours = null)
    {
        return self::clear('temp', $older_than_hours);
    }

    /**
     * Clean up empty directories recursively
     */
    protected static function __clean_empty_directories($path)
    {
        if (!File::isDirectory($path)) {
            return;
        }

        $is_empty = true;

        foreach (File::directories($path) as $directory) {
            self::clean_empty_directories($directory);
            if (File::exists($directory)) {
                $is_empty = false;
            }
        }

        if ($is_empty && count(File::files($path)) === 0) {
            // Don't delete the base directories
            if (!in_array($path, [
                self::path('', 'build'),
                self::path('', 'cache'),
                self::path('', 'temp')
            ])) {
                File::deleteDirectory($path);
            }
        }
    }

    /**
     * Get statistics about build directories
     */
    public static function stats()
    {
        $stats = [];

        foreach (self::PATHS as $type => $path) {
            $full_path = base_path($path);

            if (File::isDirectory($full_path)) {
                $files = File::allFiles($full_path);
                $size = 0;

                foreach ($files as $file) {
                    $size += $file->getSize();
                }

                $stats[$type] = [
                    'files' => count($files),
                    'size' => $size,
                    'size_human' => self::format_bytes($size)
                ];
            } else {
                $stats[$type] = [
                    'files' => 0,
                    'size' => 0,
                    'size_human' => '0 B'
                ];
            }
        }

        return $stats;
    }

    /**
     * Format bytes to human readable
     */
    public static function format_bytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
