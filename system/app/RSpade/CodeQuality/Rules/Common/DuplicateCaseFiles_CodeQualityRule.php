<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class DuplicateCaseFiles_CodeQualityRule extends CodeQualityRule_Abstract
{
    private static bool $checked = false;

    public function get_id(): string
    {
        return 'FILE-CASE-DUP-01';
    }

    public function get_name(): string
    {
        return 'Duplicate Files with Different Case';
    }

    public function get_description(): string
    {
        return 'Detects files with same name but different case - breaks Windows/macOS compatibility';
    }

    public function get_file_patterns(): array
    {
        return ['*']; // Check all files
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Whether this rule is called during manifest scan
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * duplicate case files break Windows/macOS compatibility and must be caught immediately.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    /**
     * Check for duplicate files with different case
     * This checks the entire manifest once rather than per-file
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only run this check once for the entire manifest
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        // Get all files from the manifest
        $all_files = Manifest::get_all();

        // Build map of directories to files
        $files_by_dir = [];
        foreach ($all_files as $file => $file_metadata) {
            // Skip vendor and node_modules
            if (str_contains($file, '/vendor/') || str_contains($file, '/node_modules/')) {
                continue;
            }

            $dir = dirname($file);
            $filename = basename($file);

            if (!isset($files_by_dir[$dir])) {
                $files_by_dir[$dir] = [];
            }
            $files_by_dir[$dir][] = $filename;
        }

        // Check each directory for case-insensitive duplicates
        foreach ($files_by_dir as $dir => $filenames) {
            $seen_lowercase = [];

            foreach ($filenames as $filename) {
                $filename_lower = strtolower($filename);

                if (isset($seen_lowercase[$filename_lower])) {
                    $existing = $seen_lowercase[$filename_lower];

                    // Only report if actual case is different
                    if ($existing !== $filename) {
                        $file1 = $dir . '/' . $existing;
                        $file2 = $dir . '/' . $filename;

                        // Count uppercase characters to determine which file to favor
                        $uppercase_count1 = preg_match_all('/[A-Z]/', $existing);
                        $uppercase_count2 = preg_match_all('/[A-Z]/', $filename);

                        // Favor the file with more uppercase characters
                        $preferred_file = ($uppercase_count2 > $uppercase_count1) ? $file2 : $file1;

                        $error_message = "Code Quality Violation (FILE-CASE-DUP-01) - Duplicate files with different case\n\n";
                        $error_message .= "CRITICAL: This BREAKS Windows/macOS compatibility!\n\n";
                        $error_message .= "Directory: {$dir}\n";
                        $error_message .= "File 1: {$existing}\n";
                        $error_message .= "File 2: {$filename}\n\n";
                        $error_message .= "Resolution:\n";
                        $error_message .= "1. Run: diff -u '{$file1}' '{$file2}' to compare\n";
                        $error_message .= "2. Determine which file has the correct functionality\n";
                        $error_message .= "3. Remove the incorrect/older file\n";
                        $error_message .= "4. Update all references to use the correct filename\n";
                        $error_message .= "5. Test thoroughly - IDE may have been using wrong file!";

                        // Throw immediately on first duplicate found
                        throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                            $error_message,
                            0,
                            null,
                            base_path($preferred_file),
                            1
                        );
                    }
                } else {
                    $seen_lowercase[$filename_lower] = $filename;
                }
            }
        }

        // Reset for next manifest build
        self::$checked = false;
    }
}