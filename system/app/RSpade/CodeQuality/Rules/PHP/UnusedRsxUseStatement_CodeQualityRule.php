<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class UnusedRsxUseStatement_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-RSX-USE-01';
    }

    public function get_name(): string
    {
        return 'Remove Unnecessary Rsx Use Statements';
    }

    public function get_description(): string
    {
        return 'Removes unnecessary "use Rsx\\" statements from PHP files in app/RSpade since the autoloader handles these automatically';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'low';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only apply to files in app/RSpade directory
        if (!str_contains($file_path, '/app/RSpade/') && !str_starts_with($file_path, 'app/RSpade/')) {
            return;
        }

        // Don't modify this rule file itself
        if (str_contains($file_path, 'UnusedRsxUseStatement_CodeQualityRule')) {
            return;
        }

        // Read the actual file contents (not sanitized version)
        $actual_contents = file_get_contents($file_path);
        if ($actual_contents === false) {
            return;
        }

        // Split into lines
        $lines = explode("\n", $actual_contents);
        $modified = false;
        $new_lines = [];
        $found_class = false;

        foreach ($lines as $line) {
            // Check if we've reached a class declaration
            if (!$found_class && preg_match('/^\s*(abstract\s+)?class\s+/', $line)) {
                $found_class = true;
            }

            // Remove "use Rsx\" statements that appear before class declarations
            // Keep the line if it's after a class or if it's not a Rsx use statement
            if ($found_class || !preg_match('/^\s*use\s+Rsx\\\\/', $line)) {
                $new_lines[] = $line;
            } else {
                // Check for exception comment on the previous line
                $prev_line_index = count($new_lines) - 1;
                if ($prev_line_index >= 0 && str_contains($new_lines[$prev_line_index], '@PHP-RSX-USE-01-EXCEPTION')) {
                    // Keep the use statement if there's an exception comment
                    $new_lines[] = $line;
                } else {
                    // Skip this line (remove it)
                    $modified = true;
                }
            }
        }

        // If modifications were made, write the file back
        if ($modified) {
            $new_contents = implode("\n", $new_lines);

            // Only write if the contents actually changed
            if ($new_contents !== $actual_contents) {
                file_put_contents_safe($file_path, $new_contents);

                // Log the fix to console_debug if available
                if (function_exists('console_debug')) {
                    console_debug('CODE_QUALITY', 'Auto-fixed: Removed unnecessary Rsx use statements from', $file_path);
                }
            }
        }

        // Don't add any violations - this rule silently fixes issues
    }
}