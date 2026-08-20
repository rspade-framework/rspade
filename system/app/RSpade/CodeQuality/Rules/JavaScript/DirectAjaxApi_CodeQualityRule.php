<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class DirectAjaxApi_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-AJAX-01';
    }

    public function get_name(): string
    {
        return 'Direct AJAX API Call Check';
    }

    public function get_description(): string
    {
        return "Detects direct $.ajax calls to /_ajax/ endpoints instead of using JS controller stubs";
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
     * Check JavaScript files for direct $.ajax calls to /_ajax/ endpoints
     * Instead of:
     *   await $.ajax({ url: '/_ajax/Controller/action', ... })
     * Should use:
     *   await Controller.action(params)
     * Or:
     *   await Ajax.call(Rsx.Route('Controller::action'), params)
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

        // Pattern to match $.ajax({ url: '/_ajax/Controller/action'
        // This handles both single-line and multi-line cases
        $full_content = implode("\n", $lines);

        // Match $.ajax({ with optional whitespace/newlines, then url: with quotes around /_ajax/
        // Capture controller and action names for suggestion
        $pattern = '/\$\.ajax\s*\(\s*\{[^}]*?url\s*:\s*[\'"](\/_ajax\/([A-Za-z_][A-Za-z0-9_]*)\/([A-Za-z_][A-Za-z0-9_]*))[^\'"]*[\'"]/s';

        if (preg_match_all($pattern, $full_content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $match) {
                $matched_text = $match[0];
                $offset = $match[1];
                $url = $matches[1][$index][0];
                $controller = $matches[2][$index][0];
                $action = $matches[3][$index][0];

                // Find line number
                $line_number = substr_count(substr($full_content, 0, $offset), "\n") + 1;

                // Get the actual line for display
                $line = $lines[$line_number - 1] ?? '';

                // Build suggestion
                $suggestion = "Instead of direct $.ajax() call to '{$url}', use:\n";
                $suggestion .= "  1. Preferred: await {$controller}.{$action}(params)\n";
                $suggestion .= "  2. Alternative: await Ajax.call('{$controller}', '{$action}', params)\n";
                $suggestion .= "The JS stub handles session expiry, notifications, and response unwrapping.";

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Direct $.ajax() call to internal API endpoint '{$url}' detected. Use JS controller stub instead.",
                    trim($line),
                    $suggestion,
                    'high'
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