<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * FieldAliasingRule - Enforces fetch() anti-aliasing policy
 *
 * fetch() exists for SECURITY (removing private data), not aliasing.
 *
 * VALID PATTERNS:
 *   1. Model method with MATCHING name:
 *      'full_name' => $model->full_name()
 *      'unread_count' => $this->unread_count()
 *
 *   2. Conditional with matching property/method or literals:
 *      'foo' => $condition ? $model->foo : null
 *      'secret' => $user->is_admin ? $model->secret : '[REDACTED]'
 *
 * INVALID PATTERNS:
 *   1. Any property alias (key != property):
 *      'type_label' => $model->type_id__label  // BAD
 *
 *   2. Method call with mismatched name:
 *      'addr' => $model->formatted_address()  // BAD - name must match
 *
 *   3. Redundant explicit assignments (unnecessary):
 *      'id' => $model->id  // Already in toArray()
 *
 * Applies to:
 * - Model fetch() methods with #[Ajax_Endpoint_Model_Fetch] attribute
 *
 * NOT checked (controllers are an escape hatch for custom responses):
 * - Controller methods with #[Ajax_Endpoint] attribute
 */
class FieldAliasing_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'PHP-ALIAS-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Fetch Anti-Aliasing Policy';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Enforces fetch() anti-aliasing policy - fetch() is for security, not aliasing';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Whether this rule is called during manifest scan
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Immediate feedback on aliasing violations
    }

    /**
     * Get default severity for this rule
     */
    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check a file for violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Read original file content for exception checking
        $original_contents = file_get_contents($file_path);

        // Skip if file-level exception comment is present
        if (strpos($original_contents, '@' . $this->get_id() . '-EXCEPTION') !== false) {
            return;
        }

        // Skip archived files
        if (str_contains($file_path, '/archive/') || str_contains($file_path, '/archived/')) {
            return;
        }

        // Only check models - controllers are an escape hatch for custom responses
        $class_name = $metadata['class'] ?? null;

        if (!$class_name || !\App\RSpade\Core\Manifest\Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return;
        }

        // Check fetch() method with #[Ajax_Endpoint_Model_Fetch]
        $methods_to_check = $this->get_model_fetch_methods($metadata);

        if (empty($methods_to_check)) {
            return;
        }

        // Check each relevant method
        foreach ($methods_to_check as $method_name => $method_info) {
            $method_body = $this->extract_method_body($contents, $method_name);
            if (!$method_body) {
                continue;
            }

            $method_line = $method_info['line'] ?? 1;

            // Check if method has exception comment
            if ($this->method_has_exception($original_contents, $method_name, $method_line)) {
                continue;
            }

            $this->check_method_body($file_path, $method_body, $method_name, $method_line, $original_contents);
        }
    }

    /**
     * Get fetch() methods with #[Ajax_Endpoint_Model_Fetch] attribute from model
     */
    private function get_model_fetch_methods(array $metadata): array
    {
        $methods = $metadata['public_static_methods'] ?? [];
        $result = [];

        if (!isset($methods['fetch'])) {
            return $result;
        }

        $fetch_info = $methods['fetch'];
        $attributes = $fetch_info['attributes'] ?? [];

        foreach ($attributes as $attr_name => $attr_data) {
            $short_name = basename(str_replace('\\', '/', $attr_name));
            if ($short_name === 'Ajax_Endpoint_Model_Fetch') {
                $result['fetch'] = $fetch_info;
                break;
            }
        }

        return $result;
    }

    /**
     * Check method body for aliasing violations
     */
    private function check_method_body(string $file_path, string $method_body, string $method_name, int $method_start_line, string $original_contents): void
    {
        $lines = explode("\n", $method_body);
        $original_lines = explode("\n", $original_contents);

        foreach ($lines as $offset => $line) {
            $actual_line_num = $method_start_line + $offset;

            // Check for line-level exception
            if ($this->line_has_exception($original_lines, $actual_line_num)) {
                continue;
            }

            // Pattern: 'key' => ... (array construction) OR $data['key'] = ... (element assignment)
            // We need to analyze what's on the right side
            $key = null;
            $value_part = null;

            // Array construction: 'key' => value
            if (preg_match("/['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\\s*=>/", $line, $key_match)) {
                $key = $key_match[1];
                $arrow_pos = strpos($line, '=>');
                $value_part = trim(substr($line, $arrow_pos + 2));
            }
            // Element assignment: $data['key'] = value
            elseif (preg_match("/\\$[a-zA-Z_][a-zA-Z0-9_]*\\[['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\\]\\s*=/", $line, $key_match)) {
                $key = $key_match[1];
                // Find the = that's NOT part of => or ==
                if (preg_match("/\\]\\s*=(?![>=])/", $line, $eq_match, PREG_OFFSET_CAPTURE)) {
                    $eq_pos = $eq_match[0][1] + strlen($eq_match[0][0]) - 1;
                    $value_part = trim(substr($line, $eq_pos + 1));
                }
            }

            if ($key === null || $value_part === null) {
                continue;
            }

            // Check for ternary operator
            if ($this->is_ternary_expression($value_part)) {
                $this->check_ternary($file_path, $actual_line_num, $line, $key, $value_part);
                continue;
            }

            // Check for method call: $var->method() or $this->method()
            if (preg_match('/\$([a-zA-Z_][a-zA-Z0-9_]*)->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $value_part, $method_match)) {
                $method_called = $method_match[2];

                if ($key !== $method_called) {
                    $this->add_violation(
                        $file_path,
                        $actual_line_num,
                        "Method call key must match method name: '{$key}' != '{$method_called}()'",
                        trim($line),
                        $this->build_method_mismatch_suggestion($key, $method_called),
                        'high'
                    );
                }
                continue;
            }

            // Check for property access: $var->property or $var['property']
            $property = null;

            // Object property: $var->prop
            if (preg_match('/\$([a-zA-Z_][a-zA-Z0-9_]*)->([a-zA-Z_][a-zA-Z0-9_]*)(?:\s*[,;\]\)]|$)/', $value_part, $prop_match)) {
                $property = $prop_match[2];
            }
            // Array access: $var['prop']
            elseif (preg_match('/\$([a-zA-Z_][a-zA-Z0-9_]*)\[[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\]/', $value_part, $arr_match)) {
                $property = $arr_match[2];
            }

            if ($property !== null) {
                if ($key === $property) {
                    // Redundant assignment - already in toArray()
                    $this->add_violation(
                        $file_path,
                        $actual_line_num,
                        "Redundant assignment: '{$key}' is already included by toArray()",
                        trim($line),
                        $this->build_redundant_suggestion($key),
                        'medium'
                    );
                } else {
                    // Aliasing - key != property
                    $this->add_violation(
                        $file_path,
                        $actual_line_num,
                        "Field aliasing prohibited: '{$key}' != '{$property}'",
                        trim($line),
                        $this->build_alias_suggestion($key, $property),
                        'high'
                    );
                }
            }
        }
    }

    /**
     * Check if expression contains a ternary operator (not inside a string)
     */
    private function is_ternary_expression(string $value): bool
    {
        // Remove string contents to avoid false positives
        $no_strings = preg_replace('/(["\'])(?:[^\\\\]|\\\\.)*?\1/', '', $value);
        return str_contains($no_strings, '?') && str_contains($no_strings, ':');
    }

    /**
     * Check ternary expression for valid patterns
     */
    private function check_ternary(string $file_path, int $line_num, string $line, string $key, string $value_part): void
    {
        // Extract the true and false branches
        // This is simplified - a full parser would be needed for nested ternaries
        $no_strings = preg_replace('/(["\'])(?:[^\\\\]|\\\\.)*?\1/', '""', $value_part);

        // Find the ? and : positions
        $q_pos = strpos($no_strings, '?');
        $c_pos = strpos($no_strings, ':');

        if ($q_pos === false || $c_pos === false || $c_pos < $q_pos) {
            return; // Can't parse
        }

        $true_branch = trim(substr($value_part, $q_pos + 1, $c_pos - $q_pos - 1));
        $false_branch = trim(substr($value_part, $c_pos + 1));

        // Remove trailing punctuation from false branch
        $false_branch = rtrim($false_branch, ',;)');

        // Check each branch - must be either:
        // 1. A literal (string, number, null, true, false)
        // 2. A property/method access with matching key name

        $true_valid = $this->is_valid_ternary_branch($key, $true_branch);
        $false_valid = $this->is_valid_ternary_branch($key, $false_branch);

        if (!$true_valid || !$false_valid) {
            $this->add_violation(
                $file_path,
                $line_num,
                "Ternary branches must use matching property/method name or literals",
                trim($line),
                $this->build_ternary_suggestion($key),
                'high'
            );
        }
    }

    /**
     * Check if a ternary branch is valid
     */
    private function is_valid_ternary_branch(string $key, string $branch): bool
    {
        $branch = trim($branch);

        // Literal values are always valid
        if ($this->is_literal($branch)) {
            return true;
        }

        // Method call: $var->method() - method must match key
        if (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $branch, $m)) {
            return $m[1] === $key;
        }

        // Property access: $var->prop - prop must match key
        if (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*->([a-zA-Z_][a-zA-Z0-9_]*)(?:\s*$|[^(])/', $branch, $m)) {
            return $m[1] === $key;
        }

        // Array access: $var['prop'] - prop must match key
        if (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\[[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\]/', $branch, $m)) {
            return $m[1] === $key;
        }

        // Other expressions (function calls, etc.) - can't validate easily, allow
        return true;
    }

    /**
     * Check if value is a literal
     */
    private function is_literal(string $value): bool
    {
        $value = trim($value);

        // null, true, false
        if (in_array(strtolower($value), ['null', 'true', 'false'])) {
            return true;
        }

        // Number
        if (is_numeric($value)) {
            return true;
        }

        // String literal
        if (preg_match('/^(["\']).*\1$/', $value)) {
            return true;
        }

        // Empty array
        if ($value === '[]') {
            return true;
        }

        return false;
    }

    /**
     * Check if method has exception comment in docblock
     */
    private function method_has_exception(string $contents, string $method_name, int $method_line): bool
    {
        $lines = explode("\n", $contents);

        // Check the 15 lines before the method definition for exception
        $start_line = max(0, $method_line - 16);
        $end_line = $method_line - 1;

        for ($i = $start_line; $i <= $end_line && $i < count($lines); $i++) {
            $line = $lines[$i];
            if (str_contains($line, '@' . $this->get_id() . '-EXCEPTION')) {
                return true;
            }
            // Stop if we hit another method definition
            if (preg_match('/^\s*public\s+static\s+function\s+/', $line) && !str_contains($line, $method_name)) {
                break;
            }
        }

        return false;
    }

    /**
     * Check if a specific line has an exception comment
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
     * Extract method body from file contents
     */
    private function extract_method_body(string $contents, string $method_name): ?string
    {
        // Pattern to match method definition
        $pattern = '/public\s+static\s+function\s+' . preg_quote($method_name, '/') . '\s*\([^)]*\)[^{]*\{/s';

        if (!preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start_pos = $matches[0][1] + strlen($matches[0][0]) - 1;
        $brace_count = 1;
        $pos = $start_pos + 1;
        $length = strlen($contents);

        while ($pos < $length && $brace_count > 0) {
            $char = $contents[$pos];
            if ($char === '{') {
                $brace_count++;
            } elseif ($char === '}') {
                $brace_count--;
            }
            $pos++;
        }

        return substr($contents, $start_pos, $pos - $start_pos);
    }

    /**
     * Build suggestion for method name mismatch
     */
    private function build_method_mismatch_suggestion(string $key, string $method): string
    {
        return implode("\n", [
            "PROBLEM: Method call key doesn't match method name.",
            "",
            "fetch() anti-aliasing policy requires method keys to match method names.",
            "This ensures a single source of truth and consistent naming across PHP/JS.",
            "",
            "FIX: Use the method name as the key:",
            "",
            "    // WRONG",
            "    '{$key}' => \$model->{$method}(),",
            "",
            "    // CORRECT",
            "    '{$method}' => \$model->{$method}(),",
            "",
            "See: php artisan rsx:man model_fetch",
        ]);
    }

    /**
     * Build suggestion for redundant assignment
     */
    private function build_redundant_suggestion(string $key): string
    {
        return implode("\n", [
            "PROBLEM: Redundant explicit assignment.",
            "",
            "This field is already included automatically by toArray().",
            "Explicit assignment is unnecessary and adds maintenance burden.",
            "",
            "FIX: Remove this line - the field is already in the output.",
            "",
            "    // UNNECESSARY - remove this line",
            "    '{$key}' => \$model->{$key},",
            "",
            "toArray() automatically includes all model fields, enum properties,",
            "and the __MODEL marker for JavaScript hydration.",
            "",
            "See: php artisan rsx:man model_fetch",
        ]);
    }

    /**
     * Build suggestion for property aliasing
     */
    private function build_alias_suggestion(string $key, string $property): string
    {
        return implode("\n", [
            "PROBLEM: Field aliasing is prohibited.",
            "",
            "fetch() exists for SECURITY (removing private data), not aliasing.",
            "Aliasing breaks grep searches and obscures data sources.",
            "",
            "OPTIONS:",
            "",
            "1. Use the original property name:",
            "    '{$property}' => \$model->{$property},",
            "",
            "2. If this is a computed value, create a model method:",
            "    // In model:",
            "    public function {$key}() { return ...; }",
            "",
            "    // In fetch:",
            "    '{$key}' => \$model->{$key}(),",
            "",
            "3. If this is an enum property, use the full BEM-style name:",
            "    // Instead of 'type_label', use 'type_id__label'",
            "",
            "See: php artisan rsx:man model_fetch",
        ]);
    }

    /**
     * Build suggestion for ternary violations
     */
    private function build_ternary_suggestion(string $key): string
    {
        return implode("\n", [
            "PROBLEM: Ternary branches must use matching names or literals.",
            "",
            "Conditional assignments in fetch() are allowed, but both branches",
            "must use the same property/method name as the key, or be literals.",
            "",
            "VALID patterns:",
            "    '{$key}' => \$condition ? \$model->{$key} : null,",
            "    '{$key}' => \$model->can_see() ? \$model->{$key} : '[HIDDEN]',",
            "",
            "INVALID patterns:",
            "    '{$key}' => \$condition ? \$model->other_field : null,",
            "",
            "See: php artisan rsx:man model_fetch",
        ]);
    }
}
