<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

/**
 * Central location for migration path management
 *
 * RSpade supports migrations in two base locations:
 * 1. /database/migrations - Framework and system migrations
 * 2. /rsx/resource/migrations - Application migrations (outside manifest scanning)
 *
 * The /resource/ directory is excluded from manifest scanning, making it suitable
 * for migrations and other framework-related code that doesn't follow RSX conventions.
 *
 * Migration files may also live in nested subdirectories of these base paths (for
 * example /database/migrations/rspade for app-specific framework migrations). Files
 * are discovered RECURSIVELY across all base paths and ordered by their timestamp
 * basename so that cross-directory foreign-key dependencies resolve regardless of
 * which directory a migration physically lives in. Laravel records (and the
 * migration whitelist keys) migrations by basename, so the basename is the canonical
 * identity used for both ordering and de-duplication.
 */
class MigrationPaths
{
    /**
     * Get all migration base directories in order they should be scanned.
     *
     * These are the top-level directories. Migration files are discovered
     * recursively beneath them - see get_all_migration_files().
     *
     * @return array Array of absolute paths to migration base directories
     */
    public static function get_all_paths(): array
    {
        return [
            database_path('migrations'),
            base_path('rsx/resource/migrations'),
        ];
    }

    /**
     * Get the default path for new migrations created via make:migration:safe
     *
     * @return string Absolute path to default migration directory
     */
    public static function get_default_path(): string
    {
        return database_path('migrations');
    }

    /**
     * Ensure all migration directories exist
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

    /**
     * Get all migration files from all base paths (recursively), ordered by
     * timestamp basename across paths.
     *
     * This is the single source of truth for the complete, ordered migration set.
     * The runner, the test-DB migration hash, and any pending-migration scan all
     * use this so they agree on the same file list and the same execution order.
     *
     * Ordering is by BASENAME (not full path), so a migration in a nested
     * subdirectory interleaves with migrations in sibling base paths by its
     * timestamp - this is what lets cross-directory foreign-key dependencies
     * resolve (e.g. database/migrations/rspade/* running after the
     * rsx/resource/migrations/* tables they reference).
     *
     * @return array Array of absolute file paths, ordered by basename
     */
    public static function get_all_migration_files(): array
    {
        $files = [];

        foreach (static::get_all_paths() as $path) {
            foreach (static::_scan_migration_files($path) as $file) {
                $files[] = $file;
            }
        }

        static::_sort_by_basename($files);

        return $files;
    }

    /**
     * Recursively collect *.php migration files beneath a base directory.
     *
     * @param string $path Base directory to scan
     * @return array Array of absolute file paths (unordered)
     */
    public static function _scan_migration_files(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Sort migration file paths in place by their basename (deterministic).
     *
     * Basenames are unique across all migration paths; ties (which would only
     * occur for an identical basename in two directories - not a supported state)
     * fall back to full-path comparison so the sort stays total and stable.
     *
     * @param array $files Array of absolute file paths, sorted in place
     * @return void
     */
    public static function _sort_by_basename(array &$files): void
    {
        usort($files, function ($a, $b) {
            return [basename($a), $a] <=> [basename($b), $b];
        });
    }
}
