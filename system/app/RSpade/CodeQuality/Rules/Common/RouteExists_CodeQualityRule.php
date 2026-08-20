<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * RouteExistsRule - Validates that Rsx::Route() calls reference existing routes
 *
 * This rule checks both PHP and JavaScript files for Route() calls with literal
 * string parameters and validates that the referenced controller and method
 * combination actually exists as a route in the manifest.
 *
 * Example violations:
 * - Rsx::Route('NonExistent_Controller')
 * - Route('Some_Controller', 'missing_method')
 *
 * The rule only checks when both parameters are string literals, not variables.
 */
class RouteExists_CodeQualityRule extends CodeQualityRule_Abstract
{

    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'ROUTE-EXISTS-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Route Target Exists Validation';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Validates that Rsx::Route() calls reference controller methods that actually exist as routes';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php', '*.js', '*.blade.php'];
    }

    /**
     * Whether this rule is called during manifest scan
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
     */
    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check, not during manifest build
    }

    /**
     * Get default severity for this rule
     */
    public function get_default_severity(): string
    {
        return 'high';
    }


    /**
     * Check if a route exists using the same logic as Rsx::Route()
     */
    private function route_exists(string $controller, string $method): bool
    {
        // First check if this is a SPA action (JavaScript class extending Spa_Action)
        if ($this->is_spa_action($controller)) {
            return true;
        }

        // Otherwise check PHP routes
        try {
            // Use the same validation logic as Rsx::Route()
            // If this doesn't throw an exception, the route exists
            \App\RSpade\Core\Rsx::Route($controller . '::' . $method);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a controller name is actually a SPA action class
     */
    private function is_spa_action(string $class_name): bool
    {
        try {
            // Use manifest to check if this JavaScript class extends Spa_Action
            return \App\RSpade\Core\Manifest\Manifest::js_is_subclass_of($class_name, 'Spa_Action');
        } catch (\Exception $e) {
            // If manifest not available, class doesn't exist, or any error, return false
            return false;
        }
    }


    /**
     * Check a file for violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if exception comment is present
        if (strpos($contents, '@ROUTE-EXISTS-01-EXCEPTION') !== false) {
            return;
        }

        // Get original file content for extracting actual controller/method names
        // (The $contents parameter may be sanitized with strings replaced by spaces)
        $original_contents = file_get_contents($file_path);

        // Pattern to match Rsx::Route and Rsx.Route calls (NOT plain Route())
        // Matches both single and double parameter versions:
        // - Rsx::Route('Controller')           // PHP, defaults to 'index'
        // - Rsx::Route('Controller::method')  // PHP
        // - Rsx.Route('Controller')            // JavaScript, defaults to 'index'
        // - Rsx.Route('Controller::method')   // JavaScript

        // Pattern for two parameters
        $pattern_two_params = '/(?:Rsx::Route|Rsx\.Route)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/';

        // Pattern for single parameter (defaults to 'index')
        $pattern_one_param = '/(?:Rsx::Route|Rsx\.Route)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/';

        // First check two-parameter calls
        if (preg_match_all($pattern_two_params, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            // Also match against original content to get real controller/method names
            preg_match_all($pattern_two_params, $original_contents, $original_matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $index => $match) {
                $full_match = $match[0];
                $offset = $match[1];
                // Extract controller and method from ORIGINAL content, not sanitized
                $controller = $original_matches[1][$index][0] ?? $matches[1][$index][0];
                $method = $original_matches[2][$index][0] ?? $matches[2][$index][0];

                // Skip if contains template variables like {$variable}
                if (str_contains($controller, '{$') || str_contains($controller, '${') ||
                    str_contains($method, '{$') || str_contains($method, '${')) {
                    continue;
                }

                // Skip if method starts with '#' - indicates unimplemented route
                if (str_starts_with($method, '#')) {
                    continue;
                }

                // Skip if this route exists
                if ($this->route_exists($controller, $method)) {
                    continue;
                }

                // Calculate line number
                $line_number = substr_count(substr($contents, 0, $offset), "\n") + 1;

                // Extract the line for snippet - use ORIGINAL file content, not sanitized
                $original_lines = explode("\n", $original_contents);
                $code_snippet = isset($original_lines[$line_number - 1]) ? trim($original_lines[$line_number - 1]) : $full_match;

                // Build suggestion
                $suggestion = $this->build_suggestion($controller, $method);

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Route target does not exist: {$controller}::{$method}",
                    $code_snippet,
                    $suggestion,
                    'high'
                );
            }
        }

        // Then check single-parameter calls (avoiding overlap with two-parameter calls)
        if (preg_match_all($pattern_one_param, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            // Also match against original content to get real controller names
            preg_match_all($pattern_one_param, $original_contents, $original_matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $index => $match) {
                $full_match = $match[0];
                $offset = $match[1];

                // Skip if this is actually a two-parameter call (has a comma after the first param)
                $after_match_pos = $offset + strlen($full_match);
                $chars_after = substr($contents, $after_match_pos, 10);
                if (preg_match('/^\s*,/', $chars_after)) {
                    continue; // This is a two-parameter call, already handled above
                }

                // Extract controller from ORIGINAL content, not sanitized
                $controller_string = $original_matches[1][$index][0] ?? $matches[1][$index][0];

                // Check if using Controller::method syntax
                if (str_contains($controller_string, '::')) {
                    [$controller, $method] = explode('::', $controller_string, 2);
                } else {
                    $controller = $controller_string;
                    $method = 'index'; // Default to 'index'
                }

                // Skip if contains template variables like {$variable}
                if (str_contains($controller, '{$') || str_contains($controller, '${') ||
                    str_contains($method, '{$') || str_contains($method, '${')) {
                    continue;
                }

                // Skip if method starts with '#' - indicates unimplemented route
                if (str_starts_with($method, '#')) {
                    continue;
                }

                // Skip if this route exists
                if ($this->route_exists($controller, $method)) {
                    continue;
                }

                // Calculate line number
                $line_number = substr_count(substr($contents, 0, $offset), "\n") + 1;

                // Extract the line for snippet - use ORIGINAL file content, not sanitized
                $original_lines = explode("\n", $original_contents);
                $code_snippet = isset($original_lines[$line_number - 1]) ? trim($original_lines[$line_number - 1]) : $full_match;

                // Build suggestion
                $suggestion = $this->build_suggestion($controller, $method);

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Route target does not exist: {$controller}::{$method}",
                    $code_snippet,
                    $suggestion,
                    'high'
                );
            }
        }
    }

    /**
     * Build suggestion for fixing the violation
     */
    private function build_suggestion(string $controller, string $method): string
    {
        $suggestions = [];

        // Simple suggestion since we're using the same validation as Rsx::Route()
        $suggestions[] = "Route target does not exist: {$controller}::{$method}";
        $suggestions[] = "\nTo fix this issue:";
        $suggestions[] = "1. Correct the controller/method names if they're typos";
        $suggestions[] = "2. Implement the missing route if it's a new feature:";
        $suggestions[] = "   - Create the controller if it doesn't exist";
        $suggestions[] = "   - Add the method with a #[Route] attribute";
        $suggestions[] = "3. Use '#' prefix for unimplemented routes (recommended):";
        $suggestions[] = "   - Use Rsx::Route('Controller::#index') for unimplemented index methods";
        $suggestions[] = "   - Use Rsx::Route('Controller::#method_name') for other unimplemented methods";
        $suggestions[] = "   - Routes with '#' prefix will generate '#' URLs and bypass this validation";
        $suggestions[] = "   - Example: Rsx::Route('Backend_Users_Controller::#index')";

        return implode("\n", $suggestions);
    }
}