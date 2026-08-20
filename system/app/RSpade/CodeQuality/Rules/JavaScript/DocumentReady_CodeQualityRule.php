<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\CodeQuality\Support\InitializationSuggestions;

class DocumentReady_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-READY-01';
    }
    
    public function get_name(): string
    {
        return 'JavaScript Document Ready Check';
    }
    
    public function get_description(): string
    {
        return 'Enforces use of ES6 class lifecycle methods instead of window.onload or jQuery ready';
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
     * Whether this rule is called during manifest scan
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * document ready patterns prevent the framework's auto-initialization from functioning correctly.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    /**
     * Process file during manifest update to extract document ready violations
     */
    public function on_manifest_file_update(string $file_path, string $contents, array $metadata = []): ?array
    {
        // Only process .js files
        if (pathinfo($file_path, PATHINFO_EXTENSION) !== 'js') {
            return null;
        }

        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return null;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return null;
        }

        $lines = explode("\n", $contents);
        $violations = [];

        // Also get sanitized content to skip comments
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $sanitized_lines = $sanitized_data['lines'];

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Skip comments using sanitized version
            if (isset($sanitized_lines[$line_num])) {
                $sanitized_trimmed = trim($sanitized_lines[$line_num]);
                if (empty($sanitized_trimmed)) {
                    continue; // Skip empty/comment lines
                }
            }

            // Check for window.onload patterns
            if (preg_match('/\bwindow\s*\.\s*onload\s*=/', $line)) {
                $violations[] = [
                    'type' => 'window_onload',
                    'line' => $line_number,
                    'code' => trim($line)
                ];
                break; // Only need first violation
            }

            // Check for various jQuery ready patterns and DOMContentLoaded
            // Patterns: $().ready, $(document).ready, $("document").ready, $('document').ready, $(function(), DOMContentLoaded
            $jquery_ready_patterns = [
                '/\$\s*\(\s*\)\s*\.\s*ready\s*\(/',                     // $().ready(
                '/\$\s*\(\s*document\s*\)\s*\.\s*ready\s*\(/',          // $(document).ready( with spaces
                '/\$\s*\(\s*["\']document["\']\s*\)\s*\.\s*ready\s*\(/', // $("document").ready( or $('document').ready(
                '/\$\s*\(\s*function\s*\(/',                             // $(function() - shorthand for $(document).ready
                '/jQuery\s*\(\s*document\s*\)\s*\.\s*ready\s*\(/',       // jQuery(document).ready(
                '/jQuery\s*\(\s*["\']document["\']\s*\)\s*\.\s*ready\s*\(/', // jQuery("document").ready( or jQuery('document').ready(
                '/jQuery\s*\(\s*function\s*\(/',                         // jQuery(function() - shorthand
                '/document\s*\.\s*addEventListener\s*\(\s*["\']DOMContentLoaded[\"\']/', // document.addEventListener("DOMContentLoaded" or 'DOMContentLoaded'
            ];

            foreach ($jquery_ready_patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $violations[] = [
                        'type' => 'jquery_ready',
                        'line' => $line_number,
                        'code' => trim($line)
                    ];
                    break; // Only report once per line
                }
            }

            // Stop after first violation
            if (!empty($violations)) {
                break;
            }
        }

        if (!empty($violations)) {
            return ['document_ready_violations' => $violations];
        }

        return null;
    }

    /**
     * Check JavaScript file for document ready violations stored in metadata
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Check for violations in code quality metadata
        if (isset($metadata['code_quality_metadata']['JS-READY-01']['document_ready_violations'])) {
            $violations = $metadata['code_quality_metadata']['JS-READY-01']['document_ready_violations'];

            // Get appropriate suggestion based on code location
            $suggestion = InitializationSuggestions::get_suggestion($file_path);

            // Throw on first violation
            foreach ($violations as $violation) {
                $type = $violation['type'];
                $line = $violation['line'];
                $code = $violation['code'];

                if ($type === 'window_onload') {
                    $error_message = "Code Quality Violation (JS-READY-01) - Prohibited Window Onload Pattern\n\n";
                    $error_message .= "window.onload is not allowed. Use ES6 class with lifecycle methods instead.\n\n";
                } else {
                    $error_message = "Code Quality Violation (JS-READY-01) - Prohibited jQuery Ready Pattern\n\n";
                    $error_message .= "jQuery ready/DOMContentLoaded patterns are not allowed. Use ES6 class with lifecycle methods instead.\n\n";
                }

                $error_message .= "File: {$file_path}\n";
                $error_message .= "Line: {$line}\n";
                $error_message .= "Code: {$code}\n\n";
                $error_message .= $suggestion;

                throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                    $error_message,
                    0,
                    null,
                    base_path($file_path),
                    $line
                );
            }
        }
    }
}