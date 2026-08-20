<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * RedundantIndexActionRule - Checks for unnecessary 'index' action in Route calls
 *
 * This rule detects when Rsx::Route() or Rsx.Route() is called with 'index' as the
 * second parameter, which is redundant since 'index' is the default value.
 *
 * Example violations:
 * - Rsx::Route('Controller')     // PHP
 * - Rsx.Route('Controller::index')      // JavaScript
 *
 * Correct usage:
 * - Rsx::Route('Controller')              // PHP
 * - Rsx.Route('Controller')               // JavaScript
 */
class RedundantIndexAction_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'ROUTE-INDEX-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Redundant index Action in Route';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return "Detects unnecessary 'index' as second parameter in Route calls since it's the default";
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php', '*.js', '*.blade.php', '*.jqhtml'];
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
        return false; // Only run during rsx:check
    }

    /**
     * Get default severity for this rule
     */
    public function get_default_severity(): string
    {
        return 'low'; // This is a style/convention issue, not a functional problem
    }

    /**
     * Check a file for violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if exception comment is present
        if (strpos($contents, '@ROUTE-INDEX-01-EXCEPTION') !== false) {
            return;
        }

        // Pattern to match Rsx::Route() or Rsx.Route() calls with 'index' as second parameter
        // Matches both single and double quotes
        $pattern = '/(?:Rsx::Route|Rsx\.Route)\s*\(\s*[\'"]([^\'"]+)[\'"],\s*[\'"]index[\'"]\s*\)/';

        if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $match) {
                $full_match = $match[0];
                $offset = $match[1];
                $controller = $matches[1][$index][0];

                // Calculate line number
                $line_number = substr_count(substr($contents, 0, $offset), "\n") + 1;

                // Extract the line for snippet
                $lines = explode("\n", $contents);
                $code_snippet = isset($lines[$line_number - 1]) ? trim($lines[$line_number - 1]) : $full_match;

                // Build remediation message
                $is_php = str_ends_with($file_path, '.php') || str_ends_with($file_path, '.blade.php');
                $operator = $is_php ? '::' : '.';
                $correct_usage = "Rsx{$operator}Route('{$controller}')";

                $remediation = "The 'index' action is the default value and should be omitted.\n\n";
                $remediation .= "CURRENT (redundant):\n";
                $remediation .= "  {$full_match}\n\n";
                $remediation .= "CORRECTED (cleaner):\n";
                $remediation .= "  {$correct_usage}\n\n";
                $remediation .= "CONVENTION:\n";
                $remediation .= "The second parameter of Rsx{$operator}Route() defaults to 'index'.\n";
                $remediation .= "Only specify the action when it's NOT 'index'.\n\n";
                $remediation .= "EXAMPLES:\n";
                $remediation .= "  Rsx{$operator}Route('User_Controller')          // Goes to index action\n";
                $remediation .= "  Rsx{$operator}Route('User_Controller', 'edit')  // Goes to edit action\n";
                $remediation .= "  Rsx{$operator}Route('User_Controller', 'show')  // Goes to show action";

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Redundant 'index' action in Route call",
                    $code_snippet,
                    $remediation,
                    'low'
                );
            }
        }
    }
}