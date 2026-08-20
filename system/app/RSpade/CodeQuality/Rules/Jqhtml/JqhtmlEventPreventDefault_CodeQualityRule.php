<?php

namespace App\RSpade\CodeQuality\Rules\Jqhtml;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Rule: JQHTML-EVENT-01
 *
 * Detects incorrect usage of event.preventDefault() in JQHTML component event handlers.
 * JQHTML @event attributes don't pass DOM event objects - they call methods directly
 * and preventDefault is handled automatically by the framework.
 */
class JqhtmlEventPreventDefault_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JQHTML-EVENT-01';
    }

    public function get_name(): string
    {
        return 'JQHTML Event preventDefault Usage';
    }

    public function get_description(): string
    {
        return 'Detects incorrect usage of event.preventDefault() in JQHTML component event handlers. ' .
               "JQHTML @event attributes don't pass DOM event objects - they call methods directly " .
               'and preventDefault is handled automatically by the framework.';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false;
    }

    /**
     * Check JavaScript files for JQHTML event handler violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check JavaScript files
        if (!str_ends_with($file_path, '.js')) {
            return;
        }

        // Get JavaScript class from manifest metadata
        $js_classes = [];
        if (isset($metadata['class']) && isset($metadata['extension']) && $metadata['extension'] === 'js') {
            $js_classes = [$metadata['class']];
        }

        // If no classes in metadata, nothing to check
        if (empty($js_classes)) {
            return;
        }

        // Find the first class that extends Component
        $class_name = null;
        foreach ($js_classes as $js_class) {
            if (Manifest::js_is_subclass_of($js_class, 'Component')) {
                $class_name = $js_class;
                break;
            }
        }

        if (!$class_name) {
            return; // No JQHTML components found
        }

        // Look for corresponding .jqhtml template file
        $dir = dirname($file_path);
        $possible_templates = [
            $dir . '/' . $class_name . '.jqhtml',
            // Convert CamelCase to snake_case for template name
            $dir . '/' . $this->to_snake_case($class_name) . '.jqhtml',
            // Check without _component suffix if present
            $dir . '/' . str_replace('_component', '', $this->to_snake_case($class_name)) . '.jqhtml',
        ];

        $template_content = null;
        $template_path = null;
        foreach ($possible_templates as $template_file) {
            if (file_exists($template_file)) {
                $template_content = file_get_contents($template_file);
                $template_path = $template_file;
                break;
            }
        }

        if (!$template_content) {
            // No template found, can't verify usage
            return;
        }

        // Find all methods that use event.preventDefault()
        $lines = explode("\n", $contents);
        $in_method = false;
        $method_name = null;
        $method_start_line = 0;
        $method_param = null;
        $brace_count = 0;

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Check for method definition with single parameter
            if (preg_match('/^\s*(\w+)\s*\(\s*(\w+)\s*\)\s*{/', $line, $method_match)) {
                $in_method = true;
                $method_name = $method_match[1];
                $method_param = $method_match[2];
                $method_start_line = $line_number;
                $brace_count = 1;
            } elseif ($in_method) {
                // Count braces to track method boundaries
                $brace_count += substr_count($line, '{');
                $brace_count -= substr_count($line, '}');

                if ($brace_count <= 0) {
                    $in_method = false;
                    $method_name = null;
                    $method_param = null;
                    continue;
                }

                // Check if this line calls preventDefault on the parameter
                if ($method_param && preg_match('/\b' . preg_quote($method_param, '/') . '\s*\.\s*preventDefault\s*\(/', $line)) {
                    // Found preventDefault usage, now check if this method is used in template
                    if ($this->method_used_in_template($template_content, $method_name)) {
                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "JQHTML event handlers should not use event.preventDefault() - Method '{$method_name}()' is called from JQHTML template but tries to use event.preventDefault(). " .
                            "JQHTML automatically handles preventDefault and doesn't pass event objects.",
                            trim($line),
                            "Remove '{$method_param}' parameter and '{$method_param}.preventDefault()' call",
                            'high'
                        );
                    }
                }
            }
        }
    }

    /**
     * Check if a method is referenced in JQHTML template event attributes
     */
    private function method_used_in_template(string $template_content, string $method_name): bool
    {
        // Look for @event=this.method_name or @event=method_name patterns
        // Common events: @click, @change, @submit, @keyup, @keydown, @focus, @blur
        $pattern = '/@\w+\s*=\s*(?:this\.)?' . preg_quote($method_name, '/') . '\b/';

        return preg_match($pattern, $template_content) > 0;
    }

    /**
     * Convert CamelCase/PascalCase to snake_case
     */
    private function to_snake_case(string $str): string
    {
        $str = preg_replace('/([a-z])([A-Z])/', '$1_$2', $str);

        return strtolower($str);
    }
}
