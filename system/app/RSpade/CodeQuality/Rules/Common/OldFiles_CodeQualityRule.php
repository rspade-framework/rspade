<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Rule to detect .old. files that should not be committed
 * Only runs during pre-commit checks
 */
class OldFiles_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'FILE-OLD-01';
    }

    public function get_name(): string
    {
        return 'Old Files Detection';
    }

    public function get_description(): string
    {
        return 'Detects .old files that should not be committed';
    }

    public function get_file_patterns(): array
    {
        // Check all files
        return ['*'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Only run this rule during pre-commit tests
     */
    public function should_run(array $options = []): bool
    {
        return isset($options['pre-commit-tests']) && $options['pre-commit-tests'] === true;
    }

    /**
     * Check for .old. or .*.old file naming patterns
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

        $basename = basename($file_path);

        // Check for .old. pattern (e.g., something.old.php)
        if (preg_match('/\\.old\\.\\w+$/', $basename)) {
            $this->add_violation(
                $file_path,
                1,
                "File uses forbidden .old.(extension) naming pattern",
                $basename,
                "The .old.(extension) pattern is NOT ALLOWED. Files named like 'file.old.php' " .
                "are still treated as .php files and included in bundles as live code.\n\n" .
                "SOLUTIONS:\n" .
                "1. Rename to '.php.old' or '.js.old' (extension at the end)\n" .
                "2. Move to /archived/ directory outside scan paths\n" .
                "3. Delete the file if no longer needed",
                'critical'
            );
        }

        // Check for .*.old pattern (e.g., something.php.old)
        if (preg_match('/\\.\\w+\\.old$/', $basename)) {
            $this->add_violation(
                $file_path,
                1,
                "Old file detected - should not be committed",
                $basename,
                "Files ending in .old should not be committed to the repository.\n\n" .
                "SOLUTIONS:\n" .
                "1. Move to /archived/ directory outside scan paths\n" .
                "2. Delete the file if no longer needed\n" .
                "3. Use proper version control (git) to track file history",
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