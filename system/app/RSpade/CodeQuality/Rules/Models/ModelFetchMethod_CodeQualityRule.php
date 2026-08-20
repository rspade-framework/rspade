<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Validates fetch() method implementation in Rsx_Model_Abstract subclasses
 *
 * Rules:
 * 1. fetch() must be static
 * 2. fetch() must take exactly one parameter: $id (or int $id)
 * 3. fetch() must NOT handle arrays - framework handles array splitting
 * 4. Rsx_Model_Abstract's fetch() must only throw an exception
 */
class ModelFetchMethod_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'MODEL-FETCH-01';
    }

    /**
     * Get the rule name
     */
    public function get_name(): string
    {
        return 'Model Fetch Method Validation';
    }

    /**
     * Get the rule description
     */
    public function get_description(): string
    {
        return 'Validates fetch() method implementation in Rsx_Model_Abstract subclasses';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Get default severity
     */
    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Run the rule check
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check PHP files
        if (!str_ends_with($file_path, '.php')) {
            return;
        }

        // Check if it extends Rsx_Model_Abstract or is Rsx_Model_Abstract itself
        $extends = $metadata['extends'] ?? null;
        $class_name = $metadata['class'] ?? null;

        if (!$class_name) {
            return;
        }

        $is_model = ($extends === 'Rsx_Model_Abstract' || $class_name === 'Rsx_Model_Abstract');
        if (!$is_model) {
            return;
        }

        $is_base_model = ($class_name === 'Rsx_Model_Abstract');

        // Check if fetch() method exists
        if (!isset($metadata['static_methods']['fetch'])) {
            // fetch() is optional - models can choose not to implement it
            return;
        }

        $lines = explode("\n", $contents);

        // Find the fetch method in the file
        $in_fetch = false;
        $fetch_line = 0;
        $brace_count = 0;
        $fetch_content = [];
        $fetch_signature = '';

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $line_num = $i + 1;

            // Look for fetch method
            if (!$in_fetch) {
                if (preg_match('/\b(public\s+)?static\s+function\s+fetch\s*\(/', $line)) {
                    $in_fetch = true;
                    $fetch_line = $line_num;
                    $fetch_signature = $line;

                    // Get full signature if it spans multiple lines
                    $temp_line = $line;
                    $j = $i;
                    while (!str_contains($temp_line, ')') && $j < count($lines) - 1) {
                        $j++;
                        $temp_line .= ' ' . trim($lines[$j]);
                    }
                    $fetch_signature = $temp_line;
                }
            } else {
                // Count braces to find end of method
                $fetch_content[] = $line;
                $brace_count += substr_count($line, '{') - substr_count($line, '}');

                if ($brace_count <= 0 && str_contains($line, '}')) {
                    $in_fetch = false;
                }
            }
        }

        if ($fetch_line === 0) {
            // Method exists in metadata but not found in file - shouldn't happen
            return;
        }

        // Rule 1: Check if fetch() is static
        if (!preg_match('/\bstatic\s+function\s+fetch/', $fetch_signature)) {
            $this->add_violation(
                $file_path,
                $fetch_line,
                "fetch() method must be static",
                trim($lines[$fetch_line - 1]),
                "Add 'static' keyword to the fetch() method declaration",
                'high'
            );
        }

        // Rule 2: Check parameters - must be exactly one: $id or int $id
        if (preg_match('/function\s+fetch\s*\((.*?)\)/', $fetch_signature, $matches)) {
            $params = trim($matches[1]);

            // Remove type hints and default values for analysis
            $param_parts = explode(',', $params);

            if (count($param_parts) !== 1) {
                $this->add_violation(
                    $file_path,
                    $fetch_line,
                    "fetch() must take exactly one parameter: \$id",
                    trim($lines[$fetch_line - 1]),
                    "Change fetch() signature to accept only one parameter named \$id",
                    'high'
                );
            } else {
                // Check that the parameter is $id (with optional int type hint)
                $param = trim($param_parts[0]);
                if (!preg_match('/^(\??int\s+)?\$id$/', $param)) {
                    $this->add_violation(
                        $file_path,
                        $fetch_line,
                        "fetch() parameter must be named \$id (optionally typed as int)",
                        trim($lines[$fetch_line - 1]),
                        "Rename the parameter to \$id",
                        'high'
                    );
                }
            }
        }

        // Rule 3: Check for is_array($id) pattern
        $fetch_body = implode("\n", $fetch_content);
        if (preg_match('/\bis_array\s*\(\s*\$id\s*\)/', $fetch_body)) {
            // Find the line number
            for ($i = 0; $i < count($fetch_content); $i++) {
                if (preg_match('/\bis_array\s*\(\s*\$id\s*\)/', $fetch_content[$i])) {
                    $array_check_line = $fetch_line + $i + 1;
                    $this->add_violation(
                        $file_path,
                        $array_check_line,
                        "fetch() must not handle arrays. The framework will split arrays and call fetch() for each ID individually.",
                        trim($lines[$array_check_line - 1]),
                        "Remove is_array(\$id) checks and only handle single IDs",
                        'high'
                    );
                    break;
                }
            }
        }

        // Rule 4: Special handling for Rsx_Model_Abstract base class
        if ($is_base_model) {
            // Check that it only throws an exception
            if (!preg_match('/throw\s+new\s+\\\\?RuntimeException/', $fetch_body)) {
                $this->add_violation(
                    $file_path,
                    $fetch_line,
                    "Rsx_Model_Abstract's fetch() must throw a RuntimeException to indicate it's abstract",
                    trim($lines[$fetch_line - 1]),
                    "Replace method body with: throw new \\RuntimeException(...)",
                    'critical'
                );
            }

            // Check for return statements (not in strings)
            // Remove string content to avoid false positives
            $fetch_body_no_strings = preg_replace('/(["\'])(?:[^\\\\]|\\\\.)*?\1/', '', $fetch_body);

            if (preg_match('/\breturn\b(?!\s*["\'])/', $fetch_body_no_strings)) {
                // Find the line number
                for ($i = 0; $i < count($fetch_content); $i++) {
                    $line_no_strings = preg_replace('/(["\'])(?:[^\\\\]|\\\\.)*?\1/', '', $fetch_content[$i]);
                    if (preg_match('/\breturn\b(?!\s*["\'])/', $line_no_strings)) {
                        $return_line = $fetch_line + $i + 1;
                        $this->add_violation(
                            $file_path,
                            $return_line,
                            "Rsx_Model_Abstract's fetch() must not have return statements - it should only throw an exception",
                            trim($lines[$return_line - 1]),
                            "Remove the return statement",
                            'critical'
                        );
                        break;
                    }
                }
            }
        }
    }
}