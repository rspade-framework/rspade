<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class JsLegacyFunction_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-LEGACY-FUNC-01';
    }

    public function get_name(): string
    {
        return 'JavaScript Legacy Function Comment Check';
    }

    public function get_description(): string
    {
        return 'Prohibits functions with "legacy" in block comments - enforces no backwards compatibility principle';
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
     * Check for block comments containing "legacy" directly before function definitions
     * Enforces RSX principle of no backwards compatibility functions
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip node_modules directories
        if (str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }

        $lines = explode("\n", $contents);
        $in_block_comment = false;
        $block_comment_content = '';
        $block_comment_start_line = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $line_number = $i + 1;
            $trimmed_line = trim($line);

            // Track block comment state
            if (str_contains($trimmed_line, '/*')) {
                $in_block_comment = true;
                $block_comment_start_line = $line_number;
                $block_comment_content = $line;

                // Handle single-line block comments
                if (str_contains($trimmed_line, '*/')) {
                    $in_block_comment = false;
                    $this->check_block_comment_for_legacy($file_path, $block_comment_content, $block_comment_start_line, $lines, $i);
                    $block_comment_content = '';
                }
                continue;
            }

            if ($in_block_comment) {
                $block_comment_content .= "\n" . $line;

                if (str_contains($trimmed_line, '*/')) {
                    $in_block_comment = false;
                    $this->check_block_comment_for_legacy($file_path, $block_comment_content, $block_comment_start_line, $lines, $i);
                    $block_comment_content = '';
                }
                continue;
            }
        }
    }

    /**
     * Check if a block comment contains "legacy" and is followed by a function
     */
    private function check_block_comment_for_legacy(string $file_path, string $comment_content, int $comment_start_line, array $lines, int $comment_end_index): void
    {
        // Check if comment contains "legacy" (case insensitive)
        if (!preg_match('/\blegacy\b/i', $comment_content)) {
            return;
        }

        // Look for function definition in the next few lines after comment
        for ($j = $comment_end_index + 1; $j < min($comment_end_index + 5, count($lines)); $j++) {
            $next_line = trim($lines[$j]);

            // Skip empty lines and single-line comments
            if (empty($next_line) || str_starts_with($next_line, '//')) {
                continue;
            }

            // Check if this line contains a JavaScript function definition
            if (preg_match('/^(static\s+)?function\s+\w+\s*\(/i', $next_line) ||                     // function name()
                preg_match('/^\w+\s*:\s*function\s*\(/i', $next_line) ||                            // name: function()
                preg_match('/^(static\s+)?\w+\s*\(/i', $next_line) ||                               // static name() or name() (ES6 class method)
                preg_match('/^(const|let|var)\s+\w+\s*=\s*(async\s+)?function/i', $next_line) ||    // const name = function
                preg_match('/^(const|let|var)\s+\w+\s*=\s*(async\s+)?\(/i', $next_line) ||          // const name = () =>
                preg_match('/^\w+\s*=\s*(async\s+)?function/i', $next_line) ||                      // name = function
                preg_match('/^\w+\s*=\s*(async\s+)?\(/i', $next_line)) {                            // name = () =>

                $this->add_violation(
                    $file_path,
                    $comment_start_line,
                    "CRITICAL BACKWARDS COMPATIBILITY VIOLATION: JavaScript function with 'legacy' comment detected

RSX Framework Principle: NO BACKWARDS COMPATIBILITY FUNCTIONS ALLOWED

The presence of 'legacy' in function comments indicates backwards compatibility code, which violates the core RSX principle of 'one way to do things'. Legacy functions create:

1. MULTIPLE CODE PATHS for the same functionality
2. MAINTENANCE BURDEN keeping old interfaces working
3. CONFUSION about which method to use
4. TECHNICAL DEBT that accumulates over time
5. DEBUGGING NIGHTMARES when behavior differs between paths

RSX enforces FAIL LOUD architecture - when things change, they should break clearly, not fall back to old implementations.",
                    trim($comment_content),
                    "IMMEDIATE ACTION REQUIRED:

1. REMOVE the legacy function entirely
2. UPDATE all callers to use the current implementation
3. DO NOT maintain backwards compatibility
4. If migration is needed, create a one-time migration script that:
   - Identifies all usage of the old function
   - Updates code to use new function
   - Removes old function after migration is complete

For AI/LLM agents: This is a CRITICAL violation. You MUST:
- Search for ALL callers of this legacy function
- Report findings to user before making changes
- Remove the legacy function completely
- Update all code to use the current implementation
- NEVER maintain dual implementations for compatibility

Remember: There is only ONE way to do things in RSX. Legacy functions violate this fundamental principle.",
                    'critical'
                );
                return;
            }

            // If we hit a non-function code line, stop looking
            break;
        }
    }
}