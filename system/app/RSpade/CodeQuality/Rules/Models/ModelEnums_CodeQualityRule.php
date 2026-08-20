<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class ModelEnums_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-ENUMS-01';
    }

    public function get_name(): string
    {
        return 'Model Enums Property';
    }

    public function get_description(): string
    {
        return 'Models extending Rsx_Model_Abstract must have a public static $enums property with proper structure';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'medium';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check PHP files in /rsx/ directory
        if (!str_contains($file_path, '/rsx/')) {
            return;
        }

        // Get class name from metadata
        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        // Check if this is a model (extends Rsx_Model_Abstract)
        if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return;
        }

        // Read original file content and remove single-line comments
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $full_path = str_starts_with($file_path, '/') ? $file_path : $base_path . '/' . $file_path;
        $original_contents = file_get_contents($full_path);

        // Remove single-line comments but keep line structure
        $lines = explode("\n", $original_contents);
        $processed_lines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//')) {
                $processed_lines[] = ''; // Keep empty line to preserve line numbers
            } else {
                $processed_lines[] = $line;
            }
        }
        $contents = implode("\n", $processed_lines);

        // Check for public static $enums property
        if (!preg_match('/public\s+static\s+\$enums\s*=\s*(\[.*?\])\s*;/s', $contents, $match)) {
            // Find class definition line
            $class_line = 1;
            foreach ($lines as $i => $line) {
                if (preg_match('/\bclass\s+' . preg_quote($class_name) . '\b/', $line)) {
                    $class_line = $i + 1;
                    break;
                }
            }

            $this->add_violation(
                $file_path,
                $class_line,
                "Model {$class_name} is missing public static \$enums property",
                $lines[$class_line - 1] ?? '',
                "Add: public static \$enums = [];\n\n" .
                "For models with enum fields (fields that reference lookup tables), use:\n" .
                "public static \$enums = [\n" .
                "    'status' => [\n" .
                "        1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active'],\n" .
                "        2 => ['constant' => 'STATUS_INACTIVE', 'label' => 'Inactive'],\n" .
                "    ]\n" .
                "];\n\n" .
                "Note: Top-level keys are column names. " .
                "Second-level keys must be integers. Third-level arrays must have 'constant' and 'label' fields.",
                'medium'
            );

            return;
        }

        // Check structure of $enums array
        $enums_content = $match[1];

        // Strip inline comments from $enums_content to avoid false matches
        // Removes // comments and /* */ comments
        $enums_content = preg_replace('#//[^\n]*#', '', $enums_content);
        $enums_content = preg_replace('#/\*.*?\*/#s', '', $enums_content);

        // If not empty array, validate structure
        if (trim($enums_content) !== '[]') {
            // Parse the enums array more carefully
            // We need to identify the structure:
            // 'field_name' => [ integer_value => ['constant' => ..., 'label' => ...], ... ]

            // Extract only TOP-LEVEL keys (field definitions) by tracking bracket depth
            // This prevents matching nested custom properties like 'permissions' => [...]
            $top_level_fields = [];
            $bracket_depth = 0;
            $in_string = false;
            $string_char = null;
            $i = 0;
            $len = strlen($enums_content);

            while ($i < $len) {
                $char = $enums_content[$i];

                // Track bracket depth when not in string
                if (!$in_string) {
                    if ($char === '[') {
                        $bracket_depth++;
                    } elseif ($char === ']') {
                        $bracket_depth--;
                    }
                }

                // Look for field definitions at depth 1 (inside the outer $enums = [...] bracket)
                // Pattern: 'field_name' => [
                // Check BEFORE updating in_string so we can detect the opening quote of keys
                if ($bracket_depth === 1 && !$in_string && ($char === "'" || $char === '"')) {
                    $quote = $char;
                    $key_start = $i + 1;
                    $j = $key_start;
                    // Find the closing quote
                    while ($j < $len && $enums_content[$j] !== $quote) {
                        $j++;
                    }
                    if ($j < $len) {
                        $key = substr($enums_content, $key_start, $j - $key_start);
                        // Check if followed by => [
                        $after_key = ltrim(substr($enums_content, $j + 1, 20));
                        if (str_starts_with($after_key, '=>')) {
                            $after_arrow = ltrim(substr($after_key, 2));
                            if (str_starts_with($after_arrow, '[')) {
                                $top_level_fields[] = $key;
                            }
                        }
                        $i = $j; // Skip past the key (closing quote)
                    }
                }
                // Track string boundaries for non-key strings (values, nested content)
                elseif (!$in_string && ($char === "'" || $char === '"')) {
                    $in_string = true;
                    $string_char = $char;
                } elseif ($in_string && $char === $string_char && ($i === 0 || $enums_content[$i - 1] !== '\\')) {
                    $in_string = false;
                    $string_char = null;
                }

                $i++;
            }

            // Now validate each top-level field
            foreach ($top_level_fields as $field) {

                    // No naming convention enforcement - any field name is allowed
                    // Only requirement: integer keys for values (checked below)
                    // Special handling for is_ prefix (boolean fields) is kept

                    // Now check the structure under this field
                    // We need to find the content of this particular field's array
                    // This is complex with regex, so we'll do a simpler check

                    // Find where this field's array starts in the content
                    // Use a more robust approach to extract the field content
                    $field_start_pattern = '/[\'"]' . preg_quote($field) . '[\'\"]\s*=>\s*\[/';
                    if (preg_match($field_start_pattern, $enums_content, $match, PREG_OFFSET_CAPTURE)) {
                        $start_pos = $match[0][1] + strlen($match[0][0]);

                        // Find the matching closing bracket
                        $bracket_count = 1;
                        $pos = $start_pos;
                        $field_content = '';
                        while ($bracket_count > 0 && $pos < strlen($enums_content)) {
                            $char = $enums_content[$pos];
                            if ($char === '[') {
                                $bracket_count++;
                            } elseif ($char === ']') {
                                $bracket_count--;
                                if ($bracket_count === 0) {
                                    break;
                                }
                            }
                            $field_content .= $char;
                            $pos++;
                        }

                        // Special validation for boolean fields starting with is_
                        if (str_starts_with($field, 'is_')) {
                            // Extract all integer keys from the field content
                            preg_match_all('/(\d+)\s*=>\s*\[/', $field_content, $key_matches);
                            $keys = array_map('intval', $key_matches[1]);
                            sort($keys);

                            // Boolean fields must have exactly keys 0 and 1
                            if ($keys !== [0, 1]) {
                                $line_number = 1;
                                foreach ($lines as $i => $line) {
                                    if ((str_contains($line, "'{$field}'") || str_contains($line, "\"{$field}\""))
                                        && str_contains($line, '=>')) {
                                        $line_number = $i + 1;
                                        break;
                                    }
                                }

                                $this->add_violation(
                                    $file_path,
                                    $line_number,
                                    "Boolean enum field '{$field}' must have exactly keys 0 and 1",
                                    $lines[$line_number - 1] ?? '',
                                    "Boolean enum fields starting with 'is_' must have exactly two values with keys 0 and 1.\n" .
                                    "Example:\n" .
                                    "'{$field}' => [\n" .
                                    "    true => ['label' => 'Yes'],\n" .
                                    "    false => ['label' => 'No']\n" .
                                    "]\n\n" .
                                    "Note: PHP converts true/false keys to 1/0, so in the actual array they will be 0 and 1.\n" .
                                    'Boolean fields do not use constants - just check if the field is truthy.',
                                    'medium'
                                );
                                continue; // Skip remaining validations for this field
                            }

                            // Check that boolean fields DON'T have 'constant' keys
                            $has_constant = str_contains($field_content, "'constant'") || str_contains($field_content, '"constant"');
                            if ($has_constant) {
                                $line_number = 1;
                                foreach ($lines as $i => $line) {
                                    if (str_contains($line, "'constant'") || str_contains($line, '"constant"')) {
                                        if (str_contains($lines[$i - 1] ?? '', $field) ||
                                            str_contains($lines[$i - 2] ?? '', $field) ||
                                            str_contains($lines[$i - 3] ?? '', $field)) {
                                            $line_number = $i + 1;
                                            break;
                                        }
                                    }
                                }

                                $this->add_violation(
                                    $file_path,
                                    $line_number,
                                    "Boolean enum field '{$field}' must not have 'constant' keys",
                                    $lines[$line_number - 1] ?? '',
                                    "Boolean fields starting with 'is_' should not define constants.\n" .
                                    "Remove the 'constant' keys and use only 'label':\n\n" .
                                    "'{$field}' => [\n" .
                                    "    1 => ['label' => 'Yes'],\n" .
                                    "    0 => ['label' => 'No']\n" .
                                    "]\n\n" .
                                    "To check boolean fields in code, simply use:\n" .
                                    "if (\$model->{$field}) { // truthy check }",
                                    'medium'
                                );
                            }

                            // Check that boolean fields have 'label' keys
                            $has_label = str_contains($field_content, "'label'") || str_contains($field_content, '"label"');
                            if (!$has_label) {
                                $line_number = 1;
                                foreach ($lines as $i => $line) {
                                    if ((str_contains($line, "'{$field}'") || str_contains($line, "\"{$field}\""))
                                        && str_contains($line, '=>')) {
                                        $line_number = $i + 2; // Point to content
                                        break;
                                    }
                                }

                                $this->add_violation(
                                    $file_path,
                                    $line_number,
                                    "Boolean enum field '{$field}' is missing 'label' keys",
                                    $lines[$line_number - 1] ?? '',
                                    "Boolean fields must have 'label' for display purposes:\n\n" .
                                    "'{$field}' => [\n" .
                                    "    1 => ['label' => 'Yes'],\n" .
                                    "    0 => ['label' => 'No']\n" .
                                    ']',
                                    'medium'
                                );
                            }

                            continue; // Skip remaining validations for boolean fields
                        }

                        // Check for integer keys at second level (non-boolean fields only)
                        // Should be patterns like: 1 => [...], 2 => [...],
                        if (!preg_match('/\d+\s*=>\s*\[/', $field_content)) {
                            // Find line number
                            $line_number = 1;
                            foreach ($lines as $i => $line) {
                                if (str_contains($line, "'{$field}'") || str_contains($line, "\"{$field}\"")) {
                                    $line_number = $i + 1;
                                    break;
                                }
                            }

                            $this->add_violation(
                                $file_path,
                                $line_number,
                                "Enum field '{$field}' must use integer keys for values",
                                $lines[$line_number - 1] ?? '',
                                "Use integer keys for enum values. Example:\n" .
                                "'{$field}' => [\n" .
                                "    1 => ['constant' => 'CONSTANT_NAME', 'label' => 'Display Name'],\n" .
                                "    2 => ['constant' => 'ANOTHER_NAME', 'label' => 'Another Display'],\n" .
                                ']',
                                'medium'
                            );
                        }

                        // Check for 'constant' and 'label' in the value arrays (non-boolean fields only)
                        if (!str_starts_with($field, 'is_')) {
                            $has_constant = str_contains($field_content, "'constant'") || str_contains($field_content, '"constant"');
                            $has_label = str_contains($field_content, "'label'") || str_contains($field_content, '"label"');

                            if (!$has_constant || !$has_label) {
                                $line_number = 1;
                                foreach ($lines as $i => $line) {
                                    if (str_contains($line, "'{$field}'") || str_contains($line, "\"{$field}\"")) {
                                        $line_number = $i + 2; // Point to content, not field name
                                        break;
                                    }
                                }

                                $missing = [];
                                if (!$has_constant) {
                                    $missing[] = "'constant'";
                                }
                                if (!$has_label) {
                                    $missing[] = "'label'";
                                }

                                $this->add_violation(
                                    $file_path,
                                    $line_number,
                                    "Enum field '{$field}' is missing required fields: " . implode(', ', $missing),
                                    $lines[$line_number - 1] ?? '',
                                    "Each enum value must have 'constant' and 'label' fields. Example:\n" .
                                    "'{$field}' => [\n" .
                                    "    1 => [\n" .
                                    "        'constant' => '" . strtoupper(str_replace('_id', '', $field)) . "_EXAMPLE',\n" .
                                    "        'label' => 'Example Label'\n" .
                                    "    ]\n" .
                                    "]\n\n" .
                                    "The 'constant' should be uppercase and unique within this class.",
                                    'medium'
                                );
                            }

                            // Check that constants are uppercase (non-boolean fields only)
                            if (preg_match_all("/['\"]constant['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $field_content, $constant_matches)) {
                                foreach ($constant_matches[1] as $constant) {
                                    if ($constant !== strtoupper($constant)) {
                                        $line_number = 1;
                                        foreach ($lines as $i => $line) {
                                            if (str_contains($line, $constant)) {
                                                $line_number = $i + 1;
                                                break;
                                            }
                                        }

                                        $this->add_violation(
                                            $file_path,
                                            $line_number,
                                            "Enum constant '{$constant}' must be uppercase",
                                            $lines[$line_number - 1] ?? '',
                                            "Change constant to '" . strtoupper($constant) . "'. " .
                                            'Constants should be uppercase and describe the value, typically starting with ' .
                                            'the field name prefix (e.g., ROLE_ for role_id field).',
                                            'medium'
                                        );
                                    }
                                }
                            }
                        } // End of non-boolean field checks
                    }
            }
        }
    }
}
