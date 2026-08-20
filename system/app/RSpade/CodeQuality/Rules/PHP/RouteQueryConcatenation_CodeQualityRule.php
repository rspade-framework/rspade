<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

// @ROUTE-EXISTS-01-EXCEPTION

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * RouteQueryConcatenation_CodeQualityRule - Detect query string concatenation onto Rsx::Route()
 *
 * This rule detects patterns where query strings are concatenated onto Rsx::Route() calls:
 * - Rsx::Route(...) . '?param=value'
 * - Rsx::Route(...) . "?param=value"
 *
 * Query parameters should be passed as the third argument to Rsx::Route() as an array.
 */
class RouteQueryConcatenation_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique identifier for this rule
     *
     * @return string
     */
    public function get_id(): string
    {
        return 'PHP-ROUTE-QUERY-01';
    }

    /**
     * Get the default severity level
     *
     * @return string One of: critical, high, medium, low, convention
     */
    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Get the file patterns this rule applies to
     *
     * @return array
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Get the display name for this rule
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'Route Query String Concatenation Detection';
    }

    /**
     * Get the description of what this rule checks
     *
     * @return string
     */
    public function get_description(): string
    {
        return 'Detects query strings concatenated onto Rsx::Route() calls and suggests using the third parameter array instead';
    }

    /**
     * Check if this rule should be called during manifest scan
     *
     * This rule runs at manifest-time to catch incorrect Rsx::Route() usage
     * immediately, preventing routes with malformed query strings from being used.
     *
     * @return bool
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Check the file contents for violations
     *
     * @param string $file_path The path to the file being checked
     * @param string $contents The contents of the file
     * @param array $metadata Additional metadata about the file
     * @return void
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in rsx/ directory
        if (!str_contains($file_path, '/rsx/')) {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $line_num => $line) {
            // Pattern to match: Rsx::Route(...) . '?' or Rsx::Route(...) . "?"
            // This regex matches:
            // - Rsx::Route
            // - Opening parenthesis
            // - Any content (including nested parentheses)
            // - Closing parenthesis
            // - Optional whitespace
            // - Concatenation operator (.)
            // - Optional whitespace
            // - Single or double quote
            // - Question mark

            // Use a simpler pattern that looks for the specific sequence
            // We need to match Rsx::Route(...) followed by . '? or . "?

            // First, check if line contains both "Rsx::Route" and concatenation with query
            if (!str_contains($line, 'Rsx::Route')) {
                continue;
            }

            // Look for the pattern: Rsx::Route(...) . '?' or Rsx::Route(...) . "?"
            // This is a complex pattern because we need to match nested parentheses

            // Strategy: Find all occurrences of Rsx::Route on the line, then check what follows
            $pattern = '/Rsx::Route\s*\([^)]*(?:\([^)]*\)[^)]*)*\)\s*\.\s*[\'"][?]/';

            if (preg_match($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                $matched_text = $matches[0][0];
                $position = $matches[0][1];

                // Add violation
                $this->add_violation(
                    $file_path,
                    $line_num + 1,
                    "Query string concatenated onto Rsx::Route() call",
                    $line,
                    "Query parameters should be passed as the third argument to Rsx::Route() as an array.\n\n" .
                    "WRONG:\n" .
                    "  Rsx::Route('Controller::method') . '?param=value&other=test'\n\n" .
                    "CORRECT:\n" .
                    "  Rsx::Route('Controller::method', ['param' => 'value', 'other' => 'test'])\n\n" .
                    "The framework will automatically URL-encode the parameters and construct the query string properly."
                );
            }
        }
    }
}
