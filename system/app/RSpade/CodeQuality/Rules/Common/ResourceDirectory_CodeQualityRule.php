<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Rule to detect incorrect 'resources' directory naming
 * Should be 'resource' (singular) not 'resources' (plural)
 */
class ResourceDirectory_CodeQualityRule extends CodeQualityRule_Abstract
{
    protected static $checked_directories = [];

    public function get_id(): string
    {
        return 'DIR-RESOURCE-01';
    }

    public function get_name(): string
    {
        return 'Resource Directory Naming';
    }

    public function get_description(): string
    {
        return 'Enforces singular "resource" directory naming convention';
    }

    public function get_file_patterns(): array
    {
        // Check all files to extract directory paths
        return ['*'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check for 'resources' directory in file path
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip directories outside our scan paths
        if (!str_contains($file_path, '/rsx/') && !str_contains($file_path, '/app/RSpade/')) {
            return;
        }

        // If in app/RSpade, check if it's in an allowed subdirectory
        if (str_contains($file_path, '/app/RSpade/') && !$this->is_in_allowed_rspade_directory($file_path)) {
            return;
        }

        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip if already inside a 'resource' directory (contents are invisible to framework)
        if (preg_match('#/resource/#', $file_path)) {
            return;
        }

        // Check if path contains 'resources' directory
        if (preg_match('#/resources/#', $file_path, $matches, PREG_OFFSET_CAPTURE)) {
            // Extract the directory path up to and including 'resources'
            $offset = $matches[0][1];
            $dir_path = substr($file_path, 0, $offset + strlen('/resources'));

            // Only report once per directory
            if (isset(static::$checked_directories[$dir_path])) {
                return;
            }
            static::$checked_directories[$dir_path] = true;

            $this->add_violation(
                $file_path,
                1,
                "Directory named 'resources' (plural) detected - should be 'resource' (singular)",
                "Directory: {$dir_path}/",
                "The directory name 'resources' is not allowed in RSX.\n\n" .
                "USE 'resource' INSTEAD (singular, not plural).\n\n" .
                "WHY THIS MATTERS:\n" .
                "'resource' is a special directory that is IGNORED by:\n" .
                "- The RSpade manifest system\n" .
                "- The autoloader\n" .
                "- Bundle generation\n" .
                "- All Manifest.php functions\n\n" .
                "PURPOSE OF resource/ DIRECTORY:\n" .
                "Store special-purpose files that are referenced but not executed:\n" .
                "- Raw source code (e.g., Bootstrap 5 source)\n" .
                "- Supplemental utilities (e.g., Node.js applications)\n" .
                "- Documentation files\n" .
                "- Assets that should not be bundled\n\n" .
                "ACTION REQUIRED:\n" .
                "Rename the directory from 'resources' to 'resource'",
                'high'
            );
        }
    }

    /**
     * Check if a file in /app/RSpade/ is in an allowed subdirectory
     * Based on scan_directories configuration
     */
    private function is_in_allowed_rspade_directory(string $file_path): bool
    {
        // Get allowed subdirectories from config
        $scan_directories = config('rsx.manifest.scan_directories', []);

        // Extract allowed RSpade subdirectories
        $allowed_subdirs = [];
        foreach ($scan_directories as $scan_dir) {
            if (str_starts_with($scan_dir, 'app/RSpade/')) {
                $subdir = substr($scan_dir, strlen('app/RSpade/'));
                if ($subdir) {
                    $allowed_subdirs[] = $subdir;
                }
            }
        }

        // Check if file is in any allowed subdirectory
        foreach ($allowed_subdirs as $subdir) {
            if (str_contains($file_path, '/app/RSpade/' . $subdir . '/') ||
                str_contains($file_path, '/app/RSpade/' . $subdir)) {
                return true;
            }
        }

        return false;
    }
}