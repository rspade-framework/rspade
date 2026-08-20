<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

/**
 * Detect fallback data assignments in catch blocks within on_load() methods
 *
 * This is a CRITICAL violation of the fail-loud principle. When a network error
 * occurs during data loading, the application should SHOW THE ERROR, not silently
 * substitute hardcoded values that will cause bizarre undefined behavior.
 *
 * FORBIDDEN PATTERN:
 *   async on_load() {
 *       try {
 *           this.data.roles = await Controller.get_roles();
 *       } catch (e) {
 *           this.data.roles = [{id: 1, name: 'Manager'}];  // VIOLATION
 *       }
 *   }
 *
 * CORRECT PATTERN:
 *   async on_load() {
 *       try {
 *           this.data.roles = await Controller.get_roles();
 *       } catch (e) {
 *           this.data.error_data = e;  // Surface the error
 *       }
 *   }
 *
 * This is like putting black tape over a car's check engine light. The error
 * is hidden, never noticed, never fixed. The application shows "working" UI
 * but the data is stale/wrong/invented.
 */
class JqhtmlCatchFallback_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Allowed property names for this.data.X = in catch blocks
     */
    private const ALLOWED_ERROR_PROPERTIES = [
        'error',
        'error_data',
        'error_message',
        'load_error',
        'loading_error',
        'fetch_error',
    ];

    /**
     * Properties that can be set to boolean/null values in catch blocks
     * These are state flags, not data
     */
    private const ALLOWED_STATE_PROPERTIES = [
        'loading',
        'loaded',
        'is_loading',
        'is_loaded',
        'fetching',
        'refreshing',
    ];

    public function get_id(): string
    {
        return 'JS-CATCH-FALLBACK-01';
    }

    public function get_name(): string
    {
        return 'Catch Block Fallback Data Assignment';
    }

    public function get_description(): string
    {
        return 'Prohibits assigning fallback data in catch blocks - errors must be surfaced, not hidden';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    /**
     * Run during manifest build for immediate feedback
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if not a JavaScript file
        if (!isset($metadata['extension']) || $metadata['extension'] !== 'js') {
            return;
        }

        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Check if this is a Component subclass (Component, Spa_Action, Spa_Layout, or their children)
        $extends = $metadata['extends'] ?? null;
        if (!in_array($extends, ['Component', 'Spa_Action', 'Spa_Layout'])) {
            return;
        }

        // Check for file-level exception
        if (str_contains($contents, '@' . $this->get_id() . '-EXCEPTION')) {
            return;
        }

        // Get sanitized content (comments removed)
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $sanitized_content = $sanitized_data['content'];

        // Get class name
        $class_name = $metadata['class'] ?? 'Unknown';

        // Find the on_load method
        if (!preg_match('/(?:async\s+)?on_load\s*\([^)]*\)\s*\{/i', $sanitized_content, $method_match, PREG_OFFSET_CAPTURE)) {
            return; // No on_load method
        }

        $method_start = $method_match[0][1];

        // Extract the on_load method body
        $method_body = $this->extract_brace_block($sanitized_content, $method_start);
        if (empty($method_body)) {
            return;
        }

        // Find catch blocks within on_load
        $this->check_catch_blocks($file_path, $contents, $method_body, $method_start, $class_name);
    }

    /**
     * Find and check all catch blocks within the on_load method
     */
    private function check_catch_blocks(string $file_path, string $original_contents, string $method_body, int $method_offset, string $class_name): void
    {
        // Find all catch blocks
        $offset = 0;
        while (preg_match('/\bcatch\s*\([^)]*\)\s*\{/', $method_body, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $catch_start = $match[0][1];
            $catch_body = $this->extract_brace_block($method_body, $catch_start);

            if (!empty($catch_body)) {
                $this->check_catch_body($file_path, $original_contents, $catch_body, $method_offset + $catch_start, $class_name);
            }

            // Move past this catch block
            $offset = $catch_start + strlen($match[0][0]);
        }
    }

    /**
     * Check a catch block body for forbidden data assignments
     */
    private function check_catch_body(string $file_path, string $original_contents, string $catch_body, int $catch_offset, string $class_name): void
    {
        $lines = explode("\n", $catch_body);
        $original_lines = explode("\n", $original_contents);

        // Calculate line offset from file start
        $line_offset = substr_count(substr($original_contents, 0, $catch_offset), "\n");

        foreach ($lines as $line_num => $line) {
            $actual_line_number = $line_offset + $line_num + 1;
            $trimmed = trim($line);

            // Skip empty lines
            if (empty($trimmed)) {
                continue;
            }

            // Check for this.data.X = patterns
            if (preg_match('/\bthis\.data\.(\w+)\s*=\s*(.+)/', $line, $matches)) {
                $property_name = $matches[1];
                $value_part = trim($matches[2]);

                // Allow error-related properties
                if (in_array($property_name, self::ALLOWED_ERROR_PROPERTIES)) {
                    continue;
                }

                // Allow any property to be set to null (clearing data is OK)
                if (preg_match('/^null\s*[;,]?\s*$/', $value_part)) {
                    continue;
                }

                // Allow state properties when set to boolean
                if (in_array($property_name, self::ALLOWED_STATE_PROPERTIES)) {
                    if (preg_match('/^(true|false)\s*[;,]?\s*$/', $value_part)) {
                        continue;
                    }
                }

                // Check for line-level exception in original file
                if ($this->line_has_exception($original_lines, $actual_line_number)) {
                    continue;
                }

                // Get the actual line from original content for display
                $display_line = isset($original_lines[$actual_line_number - 1])
                    ? trim($original_lines[$actual_line_number - 1])
                    : trim($line);

                $this->add_violation(
                    $file_path,
                    $actual_line_number,
                    "CRITICAL: Fallback data assigned in catch block. Setting 'this.data.{$property_name}' hides the error instead of surfacing it.",
                    $display_line,
                    $this->build_suggestion($property_name, $class_name),
                    'critical'
                );
            }

            // Also check for this.data = (full object assignment)
            if (preg_match('/\bthis\.data\s*=\s*\{/', $line)) {
                // Check for line-level exception in original file
                if ($this->line_has_exception($original_lines, $actual_line_number)) {
                    continue;
                }

                $display_line = isset($original_lines[$actual_line_number - 1])
                    ? trim($original_lines[$actual_line_number - 1])
                    : trim($line);

                $this->add_violation(
                    $file_path,
                    $actual_line_number,
                    "CRITICAL: Fallback data object assigned in catch block. This hides the error instead of surfacing it.",
                    $display_line,
                    $this->build_suggestion('...', $class_name),
                    'critical'
                );
            }
        }
    }

    /**
     * Extract a brace-delimited block starting at the given position
     */
    private function extract_brace_block(string $content, int $start_pos): string
    {
        // Find the opening brace
        $brace_pos = strpos($content, '{', $start_pos);
        if ($brace_pos === false) {
            return '';
        }

        $brace_count = 0;
        $pos = $brace_pos;
        $length = strlen($content);

        while ($pos < $length) {
            $char = $content[$pos];

            if ($char === '{') {
                $brace_count++;
            } elseif ($char === '}') {
                $brace_count--;
                if ($brace_count === 0) {
                    return substr($content, $brace_pos, $pos - $brace_pos + 1);
                }
            }
            $pos++;
        }

        return '';
    }

    /**
     * Check if a line has an exception comment
     */
    private function line_has_exception(array $lines, int $line_num): bool
    {
        $line_index = $line_num - 1;

        // Check current line
        if (isset($lines[$line_index]) && str_contains($lines[$line_index], '@' . $this->get_id() . '-EXCEPTION')) {
            return true;
        }

        // Check previous line
        if ($line_index > 0 && isset($lines[$line_index - 1]) && str_contains($lines[$line_index - 1], '@' . $this->get_id() . '-EXCEPTION')) {
            return true;
        }

        return false;
    }

    /**
     * Build an extremely clear suggestion about why this is wrong
     */
    private function build_suggestion(string $property_name, string $class_name): string
    {
        $lines = [];
        $lines[] = "================================================================================";
        $lines[] = "BLACK TAPE OVER THE CHECK ENGINE LIGHT";
        $lines[] = "================================================================================";
        $lines[] = "";
        $lines[] = "This code HIDES network errors by substituting hardcoded fallback data.";
        $lines[] = "When the server fails, users see 'working' UI with WRONG DATA.";
        $lines[] = "";
        $lines[] = "WHAT HAPPENS:";
        $lines[] = "  1. Server returns error (500, timeout, network failure)";
        $lines[] = "  2. Your code catches the error and assigns fake data";
        $lines[] = "  3. User sees UI that looks normal but contains INVENTED values";
        $lines[] = "  4. User makes decisions based on WRONG information";
        $lines[] = "  5. Error is never noticed, never fixed, never logged properly";
        $lines[] = "  6. Application behavior becomes NON-DETERMINISTIC";
        $lines[] = "";
        $lines[] = "WHY THIS IS CATASTROPHIC:";
        $lines[] = "  - Enumerated values (roles, statuses, types) MUST have ONE source of truth";
        $lines[] = "  - Hardcoded fallbacks create SHADOW DATA that diverges from the database";
        $lines[] = "  - Errors should be VISIBLE so they can be FIXED";
        $lines[] = "  - Silent failures are WORSE than loud failures";
        $lines[] = "";
        $lines[] = "WRONG (your code):";
        $lines[] = "  async on_load() {";
        $lines[] = "      try {";
        $lines[] = "          this.data.{$property_name} = await Controller.get_data();";
        $lines[] = "      } catch (e) {";
        $lines[] = "          console.error('Failed:', e);";
        $lines[] = "          this.data.{$property_name} = [{...hardcoded fallback...}];  // DISASTER";
        $lines[] = "      }";
        $lines[] = "  }";
        $lines[] = "";
        $lines[] = "CORRECT:";
        $lines[] = "  async on_load() {";
        $lines[] = "      try {";
        $lines[] = "          this.data.{$property_name} = await Controller.get_data();";
        $lines[] = "      } catch (e) {";
        $lines[] = "          this.data.error_data = e;  // SURFACE THE ERROR";
        $lines[] = "      }";
        $lines[] = "  }";
        $lines[] = "";
        $lines[] = "Template shows error state when this.data.error_data is set:";
        $lines[] = "  <% if (this.data.error_data) { %>";
        $lines[] = "      <Universal_Error_Page_Component \$error_data=this.data.error_data />";
        $lines[] = "  <% } else { %>";
        $lines[] = "      ... normal content ...";
        $lines[] = "  <% } %>";
        $lines[] = "";
        $lines[] = "================================================================================";
        $lines[] = "FIX: Remove the fallback data. Assign this.data.error_data = e instead.";
        $lines[] = "================================================================================";

        return implode("\n", $lines);
    }
}
