<?php

namespace App\RSpade\CodeQuality\Rules\Blade;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class InlineScript_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'BLADE-SCRIPT-01';
    }

    public function get_name(): string
    {
        return 'Blade Inline Script Check';
    }

    public function get_description(): string
    {
        return 'Enforces no inline JavaScript in blade views - all JavaScript must be in separate ES6 class files';
    }

    public function get_file_patterns(): array
    {
        return ['*.blade.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * This rule should run during manifest scan to provide immediate feedback
     *
     * IMPORTANT: This method should ALWAYS return false unless explicitly requested
     * by the framework developer. Manifest-time checks are reserved for critical
     * framework convention violations that need immediate developer attention.
     *
     * Rules executed during manifest scan will run on every file change in development,
     * potentially impacting performance. Only enable this for rules that:
     * - Enforce critical framework conventions that would break the application
     * - Need to provide immediate feedback before code execution
     * - Have been specifically requested to run at manifest-time by framework maintainers
     *
     * DEFAULT: Always return false unless you have explicit permission to do otherwise.
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * inline scripts in Blade files violate critical framework architecture patterns.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    /**
     * Process file during manifest update to extract inline script violations
     */
    public function on_manifest_file_update(string $file_path, string $contents, array $metadata = []): ?array
    {
        // Skip layouts (they can have script tags for loading external scripts)
        if (str_contains($file_path, 'layout') || str_contains($file_path, 'Layout')) {
            return null;
        }

        $lines = explode("\n", $contents);
        $violations = [];

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Check for <script> tags
            if (preg_match('/<script\b[^>]*>(?!.*src=)/i', $line)) {
                $violations[] = [
                    'type' => 'inline_script',
                    'line' => $line_number,
                    'code' => trim($line)
                ];
                break; // Only need to find first violation
            }

            // Check for jQuery ready patterns
            if (preg_match('/\$\s*\(\s*document\s*\)\s*\.\s*ready\s*\(/', $line) ||
                preg_match('/\$\s*\(\s*function\s*\(/', $line) ||
                preg_match('/jQuery\s*\(\s*document\s*\)\s*\.\s*ready\s*\(/', $line)) {

                $violations[] = [
                    'type' => 'jquery_ready',
                    'line' => $line_number,
                    'code' => trim($line)
                ];
                break; // Only need to find first violation
            }
        }

        if (!empty($violations)) {
            return ['inline_script_violations' => $violations];
        }

        return null;
    }

    /**
     * Check blade file for inline script violations stored in metadata
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip layouts
        if (str_contains($file_path, 'layout') || str_contains($file_path, 'Layout')) {
            return;
        }

        // Check for violations in code quality metadata
        if (isset($metadata['code_quality_metadata']['BLADE-SCRIPT-01']['inline_script_violations'])) {
            $violations = $metadata['code_quality_metadata']['BLADE-SCRIPT-01']['inline_script_violations'];

            // Throw on first violation
            foreach ($violations as $violation) {
                $rsx_id = $this->extract_rsx_id($contents);

                if ($violation['type'] === 'inline_script') {
                    $error_message = "Code Quality Violation (BLADE-SCRIPT-01) - Inline Script in Blade View\n\n";
                    $error_message .= "CRITICAL: Inline <script> tags are not allowed in blade views\n\n";
                    $error_message .= "File: {$file_path}\n";
                    $error_message .= "Line: {$violation['line']}\n";
                    $error_message .= "Code: {$violation['code']}\n\n";
                    $error_message .= $this->get_detailed_remediation($file_path, $rsx_id);
                } else {
                    $error_message = "Code Quality Violation (BLADE-SCRIPT-01) - jQuery Ready Pattern in Blade View\n\n";
                    $error_message .= "CRITICAL: jQuery ready patterns are not allowed - use ES6 class with on_app_ready()\n\n";
                    $error_message .= "File: {$file_path}\n";
                    $error_message .= "Line: {$violation['line']}\n";
                    $error_message .= "Code: {$violation['code']}\n\n";
                    $error_message .= $this->get_detailed_remediation($file_path, $rsx_id);
                }

                throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                    $error_message,
                    0,
                    null,
                    base_path($file_path),
                    $violation['line']
                );
            }
        }
    }

    /**
     * Extract RSX ID from blade file content
     */
    private function extract_rsx_id(string $contents): ?string
    {
        if (preg_match('/@rsx_id\s*\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Get detailed remediation instructions
     */
    private function get_detailed_remediation(string $file_path, ?string $rsx_id): string
    {
        // Determine the JS filename and class name
        $path_parts = pathinfo($file_path);
        $blade_name = str_replace('.blade', '', $path_parts['filename']);

        // If we have an RSX ID, use it as the class name
        if ($rsx_id) {
            $class_name = str_replace('.', '_', $rsx_id);
            $js_filename = strtolower(str_replace('_', '_', $class_name)) . '.js';
        } else {
            // Fallback to blade filename
            $class_name = ucfirst($blade_name);
            $js_filename = $blade_name . '.js';
        }

        $js_path = dirname($file_path) . '/' . $js_filename;

        return "FRAMEWORK CONVENTION: JavaScript for blade views must be in separate ES6 class files.

REQUIRED STEPS:
1. Create a JavaScript file: {$js_path}
2. Name the ES6 class exactly: {$class_name}
3. Use the on_app_ready() lifecycle method for initialization
4. Check for the view's presence using the RSX ID class selector

EXAMPLE IMPLEMENTATION for {$js_filename}:

/**
 * JavaScript for {$class_name} view
 */
class {$class_name} {
    /**
     * Initialize when app is ready
     * This method is automatically called by RSX framework
     * No manual registration is required
     */
    static on_app_ready() {
        // CRITICAL: Only initialize if we're on this specific view
        // The RSX framework adds the RSX ID as a class to the body tag
        if (!\$('.{$class_name}').exists()) {
            return;
        }

        console.log('{$class_name} view initialized');

        // Add your view-specific JavaScript here
        // Example: bind events, initialize components, etc.

        // If you need to bind events to dynamically loaded components:
        \$('#load-component-btn').on('click', function() {
            \$('#dynamic-component-target').component('User_Card', {
                data: {
                    name: 'Dynamic User',
                    email: 'dynamic@example.com'
                }
            });
        });
    }
}

KEY CONVENTIONS:
- Class name MUST match the RSX ID (with dots replaced by underscores)
- MUST use static on_app_ready() method (called automatically by framework)
- MUST check for view presence using \$('.{$class_name}').exists()
- MUST return early if view is not present (prevents code from running on wrong pages)
- NO manual registration needed - framework auto-discovers and calls on_app_ready()
- NO $(document).ready() or jQuery ready patterns allowed
- NO window.onload or DOMContentLoaded events allowed

WHY THIS MATTERS:
- Separation of concerns: HTML structure separate from behavior
- Framework integration: Automatic lifecycle management
- Performance: JavaScript only loads and runs where needed
- Maintainability: Clear file organization and naming conventions
- LLM-friendly: Predictable patterns that AI assistants can follow

The RSX framework will automatically:
1. Discover your ES6 class via the manifest
2. Call on_app_ready() after all components are initialized
3. Ensure proper execution order through lifecycle phases";
    }
}