<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class Assignment_Comparison_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'COMMON-ASSIGN-01';
    }

    public function get_name(): string
    {
        return 'Assignment vs Comparison Check';
    }

    public function get_description(): string
    {
        return 'Detects assignment operator (=) used where comparison (== or ===) expected';
    }

    public function get_file_patterns(): array
    {
        return ['*.php', '*.js', '*.jsx', '*.ts', '*.tsx'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check if file is in allowed directories using same logic as rsx:check command
     */
    private function is_file_in_allowed_directories(string $file_path): bool
    {
        // Get scan directories from config
        $scan_directories = config('rsx.manifest.scan_directories', []);
        $relative_path = str_replace(base_path() . '/', '', $file_path);

        // Special case: Allow Console Command files
        if (str_starts_with($relative_path, 'app/Console/Commands/')) {
            return true;
        }

        // Check against configured scan directories
        foreach ($scan_directories as $scan_path) {
            // Skip specific file entries in scan_directories
            if (str_contains($scan_path, '.')) {
                // This is a specific file, check exact match
                if ($relative_path === $scan_path) {
                    return true;
                }
            } else {
                // This is a directory, check if file is within it
                if (str_starts_with($relative_path, rtrim($scan_path, '/') . '/') ||
                    rtrim($relative_path, '/') === rtrim($scan_path, '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Use the same directory filtering logic as rsx:check command
        if (!$this->is_file_in_allowed_directories($file_path)) {
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

        // Determine file type
        $is_php = str_ends_with($file_path, '.php');
        $is_js = str_ends_with($file_path, '.js') || str_ends_with($file_path, '.jsx') ||
                 str_ends_with($file_path, '.ts') || str_ends_with($file_path, '.tsx');

        if (!$is_php && !$is_js) {
            return;
        }

        // Use original file content directly (no sanitization)
        $original_content = file_get_contents($file_path);
        $lines = explode("\n", $original_content);

        // Process each line individually
        // Note: We're letting multi-line conditions slide for simplicity - this catches 99% of violations
        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            // Skip lines that are just comments (start with //)
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//')) {
                continue;
            }

            // Check for single = in if statement condition (must have complete condition on same line)
            if (preg_match('/\bif\s*\(([^)]+)\)/', $line, $match)) {
                $condition = $match[1];

                // Skip if there's a // comment before the if statement
                if (preg_match('/\/\/.*\bif\s*\(/', $line)) {
                    continue;
                }

                // Remove quoted strings to avoid false positives from regex patterns
                // This prevents flagging preg_match('/pattern=value/', $var)
                $condition_no_quotes = preg_replace('/([\'"])[^\\1]*?\\1/', '""', $condition);

                // Check for single = that's not part of ==, ===, !=, !==, <=, >=
                // Must have non-equals char before and after the single =
                if (preg_match('/[^=!<>]\s*=\s*[^=]/', $condition_no_quotes)) {
                    // Double-check it's not a comparison operator
                    if (!preg_match('/(?:==|===|!=|!==|<=|>=)/', $condition_no_quotes)) {
                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "Assignment operator (=) used in if statement where comparison expected.",
                            trim($line),
                            "Assignment and truthiness checks must be on separate lines. " .
                            "The pattern 'if (\$var = function())' is not acceptable in RSpade code. " .
                            "Split into two lines: '\$var = function(); if (\$var) { ... }'. " .
                            "If you meant comparison, use == or === instead of =. " .
                            "This rule enforces code clarity by separating assignment from condition evaluation.",
                            'high'
                        );
                    }
                }
            }

            // Skip while statements - assignment in while is acceptable
            // The pattern while ($var = function()) is allowed for iteration
            // Check for single = in for loop condition (middle part)
            if (preg_match('/\bfor\s*\(([^;]*);([^;]*);([^)]*)\)/', $line, $match)) {
                $condition = $match[2]; // The middle part is the condition

                // Skip if there's a // comment before the for statement
                if (preg_match('/\/\/.*\bfor\s*\(/', $line)) {
                    continue;
                }

                // Remove quoted strings to avoid false positives
                $condition_no_quotes = preg_replace('/([\'"])[^\\1]*?\\1/', '""', $condition);

                if (trim($condition_no_quotes) && preg_match('/[^=!<>]\s*=\s*[^=]/', $condition_no_quotes)) {
                    if (!preg_match('/(?:==|===|!=|!==|<=|>=)/', $condition_no_quotes)) {
                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "Assignment operator (=) used in for loop condition where comparison expected.",
                            trim($line),
                            "Use == or === for comparison in the for loop condition (second part). " .
                            "Assignment in the condition will always evaluate to the assigned value, not perform a comparison. " .
                            "Example: change 'for(i=0; i=5; i++)' to 'for(i=0; i==5; i++)'.",
                            'high'
                        );
                    }
                }
            }

            // Skip do...while statements - assignment in while is acceptable
            // The pattern } while ($var = function()) is allowed for iteration
            // PHP-specific: Check for single = in elseif statement
            if ($is_php && preg_match('/\belseif\s*\(([^)]+)\)/', $line, $match)) {
                $condition = $match[1];

                // Skip if there's a // comment before the elseif
                if (preg_match('/\/\/.*\belseif\s*\(/', $line)) {
                    continue;
                }

                // Remove quoted strings to avoid false positives
                $condition_no_quotes = preg_replace('/([\'"])[^\\1]*?\\1/', '""', $condition);

                if (preg_match('/[^=!<>]\s*=\s*[^=]/', $condition_no_quotes)) {
                    if (!preg_match('/(?:==|===|!=|!==|<=|>=)/', $condition_no_quotes)) {
                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "Assignment operator (=) used in elseif statement where comparison expected.",
                            trim($line),
                            "Use == or === for comparison in elseif statements. " .
                            "Assignment in an elseif condition will execute the assignment and evaluate the assigned value, not perform a comparison. " .
                            "Example: change 'elseif(x = 5)' to 'elseif(x == 5)' or 'elseif(x === 5)'.",
                            'high'
                        );
                    }
                }
            }
        }
    }
}