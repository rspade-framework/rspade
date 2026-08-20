<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class FilenameSpaces_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'FILE-SPACE-01';
    }

    public function get_name(): string
    {
        return 'Filename Spaces Check';
    }

    public function get_description(): string
    {
        return 'Filenames and directory paths must not contain spaces';
    }

    public function get_file_patterns(): array
    {
        // Return multiple common patterns to match all files
        // The checker uses these with matches_pattern which does simple extension checking
        return ['*.php', '*.js', '*.css', '*.scss', '*.blade.php', '*.json', '*.xml', '*.md', '*.txt', '*.yml', '*.yaml', '*.sql', '*.sh', '*.jqhtml', '*.ts', '*.tsx', '*.jsx', '*'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Whether this rule is called during manifest scan
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * spaces in filenames break shell commands and framework operations.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    /**
     * Process file during manifest update - throws immediately on violation
     * This is more efficient than checking later
     */
    public function on_manifest_file_update(string $file_path, string $contents, array $metadata = []): ?array
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return null;
        }

        // Get relative path from absolute
        $relative_path = str_replace(base_path() . '/', '', $file_path);

        // Check for spaces in the entire path
        if (str_contains($relative_path, ' ')) {
            // Get just the filename
            $filename = basename($relative_path);
            $dirname = dirname($relative_path);

            // Build error message
            if (str_contains($filename, ' ')) {
                $suggested = str_replace(' ', '_', $filename);
                $error_message = "Code Quality Violation (FILE-SPACE-01) - Filename '{$filename}' contains spaces\n\n";
                $error_message .= "File: {$relative_path}\n\n";
                $error_message .= "Spaces in filenames break shell commands, URLs, and tooling.\n\n";
                $error_message .= "Resolution:\nRename file to '{$suggested}' (replace spaces with underscores or remove them).";
                throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                    $error_message,
                    0,
                    null,
                    $file_path,
                    1
                );
            }

            if (str_contains($dirname, ' ')) {
                // Find which directory has the space
                $path_parts = explode('/', $dirname);
                $problematic_dirs = array_filter($path_parts, fn($part) => str_contains($part, ' '));
                $problematic_str = implode(', ', $problematic_dirs);

                $error_message = "Code Quality Violation (FILE-SPACE-01) - Directory path contains spaces\n\n";
                $error_message .= "File: {$relative_path}\n\n";
                $error_message .= "Directories with spaces: {$problematic_str}\n\n";
                $error_message .= "Resolution:\nRename directories to remove spaces. This is critical as spaces in paths break shell commands, git operations, and various build tools.";
                throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                    $error_message,
                    0,
                    null,
                    $file_path,
                    1
                );
            }
        }

        // No metadata needed - we throw on violation
        return null;
    }

    /**
     * Check if a filename or any directory in its path contains spaces
     * This method is now just a fallback - on_manifest_file_update handles the real work
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Check for spaces in the entire path
        if (str_contains($file_path, ' ')) {
            // Get just the filename
            $filename = basename($file_path);
            $dirname = dirname($file_path);

            // Determine if the space is in the filename or directory
            if (str_contains($filename, ' ')) {
                $suggested = str_replace(' ', '_', $filename);

                $this->add_violation(
                    $file_path,
                    0,
                    "Filename '{$filename}' contains spaces which will cause issues with shell commands, URLs, and tooling.",
                    $filename,
                    "Rename file to '{$suggested}' (replace spaces with underscores or remove them).",
                    'critical'
                );
            }

            if (str_contains($dirname, ' ')) {
                // Find which directory has the space
                $path_parts = explode('/', $dirname);
                $problematic_dirs = array_filter($path_parts, fn($part) => str_contains($part, ' '));
                $problematic_str = implode(', ', $problematic_dirs);

                $this->add_violation(
                    $file_path,
                    0,
                    "Directory path contains spaces in: {$problematic_str}",
                    $dirname,
                    "Rename directories to remove spaces. This is critical as spaces in paths break shell commands, git operations, and various build tools.",
                    'critical'
                );
            }
        }
    }
}