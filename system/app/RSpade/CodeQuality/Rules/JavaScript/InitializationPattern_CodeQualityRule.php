<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\CodeQuality\Support\InitializationSuggestions;

class InitializationPattern_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-INIT-USER-01';
    }

    public function get_name(): string
    {
        return 'User Code Initialization Pattern Check';
    }

    public function get_description(): string
    {
        return 'Enforces proper initialization patterns for user JavaScript code in /rsx directory';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check JavaScript file for proper initialization patterns
     * User code in /rsx should use on_modules_* or on_app_* methods
     * Framework methods (_on_framework_*) are forbidden in user code
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in /rsx directory
        if (!str_contains($file_path, '/rsx/')) {
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

            // Check for Rsx.on('ready') pattern
            if (preg_match('/\bRsx\s*\.\s*on\s*\(\s*[\'"]ready[\'"]/i', $line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Rsx.on('ready') is deprecated. Use ES6 class lifecycle methods instead.",
                    trim($line),
                    InitializationSuggestions::get_user_suggestion(),
                    'high'
                );
            }

            // Check for framework methods (forbidden in user code)
            $framework_methods = [
                '_on_framework_core_define',
                '_on_framework_core_init',
                '_on_framework_module_define',
                '_on_framework_module_init'
            ];

            foreach ($framework_methods as $method) {
                if (preg_match('/\bstatic\s+(async\s+)?' . preg_quote($method) . '\s*\(\s*\)/', $line)) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Framework initialization method '{$method}' cannot be used in user code.",
                        trim($line),
                        InitializationSuggestions::get_user_suggestion(),
                        'critical'
                    );
                }
            }

            // Check for jQuery ready patterns (handled by DocumentReadyRule but add context here)
            if (preg_match('/\$\s*\(\s*document\s*\)\s*\.\s*ready\s*\(/', $line) ||
                preg_match('/\$\s*\(\s*function\s*\(/', $line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "jQuery ready patterns are not allowed. Use ES6 class lifecycle methods.",
                    trim($line),
                    InitializationSuggestions::get_user_suggestion(),
                    'high'
                );
            }
        }
    }
}