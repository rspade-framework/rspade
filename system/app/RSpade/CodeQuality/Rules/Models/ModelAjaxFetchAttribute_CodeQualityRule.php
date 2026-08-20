<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Validates #[Ajax_Endpoint_Model_Fetch] attribute placement
 *
 * This attribute can ONLY be placed on:
 * 1. Methods marked with #[Relationship] - exposes relationship to JavaScript
 * 2. The static fetch($id) method - enables Model.fetch() in JavaScript
 *
 * For custom server-side methods that need JavaScript access:
 * - Create a JS class extending Base_{Model}
 * - Create an Ajax endpoint on an appropriate controller
 * - Add the method to the JS class calling the Ajax endpoint
 */
class ModelAjaxFetchAttribute_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'MODEL-AJAX-FETCH-01';
    }

    /**
     * Get the rule name
     */
    public function get_name(): string
    {
        return 'Ajax Endpoint Model Fetch Attribute Validation';
    }

    /**
     * Get the rule description
     */
    public function get_description(): string
    {
        return 'Validates #[Ajax_Endpoint_Model_Fetch] is only used on relationships or static fetch($id)';
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
        return 'critical';
    }

    /**
     * Whether this rule runs during manifest scan
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
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

        // Check if it's a model class (extends Rsx_Model_Abstract or related)
        $extends = $metadata['extends'] ?? null;
        $class_name = $metadata['class'] ?? null;

        if (!$class_name) {
            return;
        }

        // Check all methods for the attribute
        $all_methods = array_merge(
            $metadata['static_methods'] ?? [],
            $metadata['public_instance_methods'] ?? []
        );

        $lines = explode("\n", $contents);

        foreach ($all_methods as $method_name => $method_data) {
            // Check if method has Ajax_Endpoint_Model_Fetch attribute
            $attributes = $method_data['attributes'] ?? [];
            if (!isset($attributes['Ajax_Endpoint_Model_Fetch'])) {
                continue;
            }

            // Valid case 1: Method is static fetch($id)
            if ($method_name === 'fetch') {
                $is_static = isset($metadata['static_methods']['fetch']);
                if ($is_static) {
                    continue; // Valid use
                }
            }

            // Valid case 2: Method has #[Relationship] attribute
            if (isset($attributes['Relationship'])) {
                continue; // Valid use
            }

            // Invalid use - find line number
            $line_number = $this->_find_method_line($lines, $method_name);

            $this->add_violation(
                $file_path,
                $line_number,
                "#[Ajax_Endpoint_Model_Fetch] can only be applied to:\n" .
                "  1. Methods marked with #[Relationship] (exposes relationship to JavaScript)\n" .
                "  2. The static fetch(\$id) method (enables Model.fetch() in JavaScript)\n\n" .
                "For custom server-side methods that need JavaScript access:\n" .
                "  1. Create a JavaScript class with the same name as the PHP model, extending Base_{ModelName}\n" .
                "  2. Create an Ajax endpoint on an appropriate controller\n" .
                "  3. Add the method to the JS class and have it call the controller Ajax endpoint",
                "#[Ajax_Endpoint_Model_Fetch] on {$method_name}()",
                "Remove #[Ajax_Endpoint_Model_Fetch] and follow the custom method pattern above",
                'critical'
            );
        }
    }

    /**
     * Find the line number of a method declaration
     */
    private function _find_method_line(array $lines, string $method_name): int
    {
        $pattern = '/function\s+' . preg_quote($method_name, '/') . '\s*\(/';

        for ($i = 0; $i < count($lines); $i++) {
            if (preg_match($pattern, $lines[$i])) {
                return $i + 1;
            }
        }

        return 1; // Default to first line if not found
    }
}
