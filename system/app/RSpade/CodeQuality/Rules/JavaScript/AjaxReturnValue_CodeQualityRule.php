<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class AjaxReturnValue_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-AJAX-02';
    }

    public function get_name(): string
    {
        return 'AJAX Return Value Property Check';
    }

    public function get_description(): string
    {
        return "Detects unnecessary access to _ajax_return_value property in AJAX responses";
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'medium';
    }

    /**
     * Check JavaScript files for _ajax_return_value property access
     * Ajax.call() already unwraps the response, so accessing _ajax_return_value is unnecessary
     * Instead of: data._ajax_return_value.user_id
     * Should use: data.user_id
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip Ajax.js itself
        if (str_ends_with($file_path, '/Ajax.js')) {
            return;
        }

        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Only check files in rsx/ or app/RSpade/
        if (!str_contains($file_path, '/rsx/') && !str_contains($file_path, '/app/RSpade/')) {
            return;
        }

        // If in app/RSpade, check if it's in an allowed subdirectory
        if (str_contains($file_path, '/app/RSpade/') && !$this->is_in_allowed_rspade_directory($file_path)) {
            return;
        }

        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $lines = $sanitized_data['lines'];

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Skip comments
            $trimmed_line = trim($line);
            if (str_starts_with($trimmed_line, '//') || str_starts_with($trimmed_line, '*')) {
                continue;
            }

            // Check for _ajax_return_value usage
            if (str_contains($line, '_ajax_return_value')) {
                // Try to extract context for better suggestion
                $suggestion = "Ajax.call() automatically unwraps the server response. ";

                // Check if we can detect the specific property being accessed
                if (preg_match('/(\w+)\._ajax_return_value\.(\w+)/', $line, $matches)) {
                    $variable = $matches[1];
                    $property = $matches[2];
                    $suggestion .= "Instead of '{$variable}._ajax_return_value.{$property}', ";
                    $suggestion .= "use '{$variable}.{$property}' directly.";
                } elseif (preg_match('/(\w+)\._ajax_return_value/', $line, $matches)) {
                    $variable = $matches[1];
                    $suggestion .= "Instead of '{$variable}._ajax_return_value', ";
                    $suggestion .= "use '{$variable}' directly - it already contains the unwrapped response.";
                } else {
                    $suggestion .= "The response from Ajax.call() is already unwrapped, ";
                    $suggestion .= "so you can access properties directly without the _ajax_return_value wrapper.";
                }

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Unnecessary access to '_ajax_return_value' property detected.",
                    trim($line),
                    $suggestion,
                    'medium'
                );
            }
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