<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\CodeQuality\Support\InitializationSuggestions;

class FrameworkInitialization_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-INIT-FW-01';
    }

    public function get_name(): string
    {
        return 'Framework Code Initialization Pattern Check';
    }

    public function get_description(): string
    {
        return 'Enforces proper initialization patterns for framework JavaScript code in /app/RSpade directory';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Check JavaScript file for proper framework initialization patterns
     * Framework code in /app/RSpade should use _on_framework_* methods
     * User methods (on_modules_*, on_app_*) are forbidden in framework code
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in /app/RSpade directory
        if (!str_contains($file_path, '/app/RSpade/')) {
            return;
        }

        // Check if it's in an allowed subdirectory
        if (!$this->is_in_allowed_rspade_directory($file_path)) {
            return;
        }

        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Get original content for pattern detection
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);

        // Also get sanitized content to skip comments
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $sanitized_lines = $sanitized_data['lines'];

        foreach ($original_lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Skip comments using sanitized version
            if (isset($sanitized_lines[$line_num])) {
                $sanitized_trimmed = trim($sanitized_lines[$line_num]);
                if (empty($sanitized_trimmed)) {
                    continue; // Skip empty/comment lines
                }
            }

            // Check for user code methods (forbidden in framework code)
            $user_methods = [
                'on_modules_define',
                'on_modules_init',
                'on_app_define',
                'on_app_init',
                'on_app_ready'
            ];

            foreach ($user_methods as $method) {
                if (preg_match('/\bstatic\s+(async\s+)?' . preg_quote($method) . '\s*\(\s*\)/', $line)) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "User initialization method '{$method}' cannot be used in framework code.",
                        trim($line),
                        InitializationSuggestions::get_framework_suggestion(),
                        'critical'
                    );
                }
            }

            // Check for Rsx.on('ready') pattern
            if (preg_match('/\bRsx\s*\.\s*on\s*\(\s*[\'\"]ready[\'\"]/i', $line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Rsx.on('ready') is deprecated. Use framework lifecycle methods instead.",
                    trim($line),
                    InitializationSuggestions::get_framework_suggestion(),
                    'high'
                );
            }

            // Check for jQuery ready patterns (should not be in framework code)
            if (preg_match('/\$\s*\(\s*document\s*\)\s*\.\s*ready\s*\(/', $line) ||
                preg_match('/\$\s*\(\s*function\s*\(/', $line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "jQuery ready patterns are not allowed in framework code. Use framework lifecycle methods.",
                    trim($line),
                    InitializationSuggestions::get_framework_suggestion(),
                    'high'
                );
            }

            // Validate correct framework method usage (informational)
            $framework_methods = [
                '_on_framework_core_define',
                '_on_framework_core_init',
                '_on_framework_module_define',
                '_on_framework_module_init'
            ];

            foreach ($framework_methods as $method) {
                if (preg_match('/\bstatic\s+(async\s+)?' . preg_quote($method) . '\s*\(\s*\)/', $line)) {
                    // This is correct usage - no violation
                    // Could log this for validation purposes if needed
                }
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