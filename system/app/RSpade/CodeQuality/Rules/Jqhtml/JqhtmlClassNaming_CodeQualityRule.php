<?php

namespace App\RSpade\CodeQuality\Rules\Jqhtml;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * JQHTML Class Naming Rule
 *
 * Combined rule for efficient single-pass checking of jqhtml class naming:
 *
 * 1. Component names must start with uppercase (library requirement)
 * 2. Redundant class attributes on Define tags (component name auto-added)
 * 3. BEM child elements must use PascalCase prefix, not kebab-case
 *
 * For example, with <Define:My_Component>:
 *   - SCSS: .My_Component { &__element { ... } } compiles to .My_Component__element
 *   - HTML must use: class="My_Component__element"
 *   - NOT: class="my-component__element" (styles won't apply)
 */
class JqhtmlClassNaming_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique identifier for this rule
     */
    public function get_id(): string
    {
        return 'JQHTML-CLASS-01';
    }

    /**
     * Get the human-readable name of this rule
     */
    public function get_name(): string
    {
        return 'JQHTML Class Naming';
    }

    /**
     * Get the description of what this rule checks
     */
    public function get_description(): string
    {
        return 'Validates component naming, redundant class attributes, and BEM child class naming in jqhtml templates';
    }

    /**
     * Get file patterns this rule should check
     */
    public function get_file_patterns(): array
    {
        return ['*.jqhtml', '*.js'];
    }

    /**
     * This rule runs during manifest scan for immediate feedback
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Check the file for violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (str_ends_with($file_path, '.jqhtml')) {
            $this->check_jqhtml_file($file_path, $contents);
        }

        if (str_ends_with($file_path, '.js')) {
            $this->check_javascript_file($file_path, $contents, $metadata);
        }
    }

    /**
     * Check jqhtml template files - single pass for all checks
     */
    private function check_jqhtml_file(string $file_path, string $contents): void
    {
        $lines = explode("\n", $contents);

        // First, find the component name from the Define tag
        $component_name = null;
        $component_line_number = null;

        foreach ($lines as $idx => $line) {
            // Match <Define:ComponentName> or <Define:ComponentName ...>
            if (preg_match('/<Define:([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $matches)) {
                $component_name = $matches[1];
                $component_line_number = $idx + 1;
                break;
            }
        }

        // If no component name found, bail gracefully
        if ($component_name === null) {
            return;
        }

        $kebab_case = $this->to_kebab_case($component_name);
        $underscore_lower = strtolower($component_name);

        // Check 1: Component name must start with uppercase
        if (!ctype_upper($component_name[0])) {
            $this->add_violation(
                $file_path,
                $component_line_number,
                "JQHTML component name '{$component_name}' must start with an uppercase letter",
                trim($lines[$component_line_number - 1]),
                "Change '{$component_name}' to '" . ucfirst($component_name) . "'. " .
                "This is a hard requirement of the jqhtml library - component names MUST start with an uppercase letter.",
                'critical'
            );
        }

        // Now scan all lines for class attribute issues
        $line_number = 0;
        foreach ($lines as $line) {
            $line_number++;

            // Check 2: Redundant class on Define tag
            if (preg_match('/<Define:' . preg_quote($component_name, '/') . '\s+[^>]*class=["\']([^"\']*)["\']/', $line, $matches)) {
                $class_attribute = $matches[1];
                $this->check_redundant_define_class($file_path, $line_number, $line, $component_name, $class_attribute, $kebab_case);
            }

            // Check 3: BEM child classes using wrong case
            // Find all class="..." attributes in this line
            if (preg_match_all('/class=["\']([^"\']*)["\']/', $line, $all_class_matches)) {
                foreach ($all_class_matches[1] as $class_attribute) {
                    $this->check_bem_child_classes($file_path, $line_number, $line, $component_name, $class_attribute, $kebab_case, $underscore_lower);
                }
            }
        }
    }

    /**
     * Check for redundant class names on the Define tag
     */
    private function check_redundant_define_class(
        string $file_path,
        int $line_number,
        string $line,
        string $component_name,
        string $class_attribute,
        string $kebab_case
    ): void {
        $classes = preg_split('/\s+/', trim($class_attribute));

        foreach ($classes as $class) {
            $class = trim($class);
            if (empty($class)) {
                continue;
            }

            // Exact match with component name
            if ($class === $component_name) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Redundant class=\"{$component_name}\" on component {$component_name}. " .
                    "The component automatically gets class=\"{$component_name}\" assigned to it.",
                    trim($line),
                    $this->build_redundant_class_suggestion($component_name, $class_attribute, $class),
                    'medium'
                );
            }
            // Kebab-case equivalent (only for multi-word components)
            elseif (strpos($component_name, '_') !== false
                    && strtolower($class) === $kebab_case
                    && !$this->is_known_framework_class($class)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Unnecessary class=\"{$class}\" on component {$component_name}. " .
                    "The component automatically gets class=\"{$component_name}\" assigned to it. " .
                    "Use .{$component_name} in CSS/JS selectors instead of .{$class}",
                    trim($line),
                    $this->build_redundant_class_suggestion($component_name, $class_attribute, $class),
                    'medium'
                );
            }
        }
    }

    /**
     * Check for BEM child classes using wrong case prefix
     *
     * Detects patterns like:
     *   my-component__element  (should be My_Component__element)
     *   my-component--modifier (should be My_Component--modifier)
     *   my_component__element  (should be My_Component__element)
     */
    private function check_bem_child_classes(
        string $file_path,
        int $line_number,
        string $line,
        string $component_name,
        string $class_attribute,
        string $kebab_case,
        string $underscore_lower
    ): void {
        // Only check multi-word component names (with underscores)
        if (strpos($component_name, '_') === false) {
            return;
        }

        $classes = preg_split('/\s+/', trim($class_attribute));

        foreach ($classes as $class) {
            $class = trim($class);
            if (empty($class)) {
                continue;
            }

            // Skip if class correctly starts with component name (PascalCase)
            if (strpos($class, $component_name . '__') === 0 || strpos($class, $component_name . '--') === 0) {
                continue;
            }

            // Check for kebab-case prefix with BEM separator (__ or --)
            // Pattern: my-component__something or my-component--something
            if (preg_match('/^' . preg_quote($kebab_case, '/') . '(__|--)(.+)$/', $class, $matches)) {
                $separator = $matches[1];
                $suffix = $matches[2];
                $correct_class = $component_name . $separator . $suffix;

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "BEM class \"{$class}\" uses kebab-case prefix. " .
                    "SCSS compiles .{$component_name} { &{$separator}{$suffix} } to .{$correct_class}, so HTML must match.",
                    trim($line),
                    $this->build_bem_suggestion($component_name, $class, $correct_class),
                    'high'
                );
                continue;
            }

            // Check for lowercase underscore prefix with BEM separator
            // Pattern: my_component__something or my_component--something
            if (preg_match('/^' . preg_quote($underscore_lower, '/') . '(__|--)(.+)$/', $class, $matches)) {
                $separator = $matches[1];
                $suffix = $matches[2];
                $correct_class = $component_name . $separator . $suffix;

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "BEM class \"{$class}\" uses lowercase prefix. " .
                    "SCSS compiles .{$component_name} { &{$separator}{$suffix} } to .{$correct_class}, so HTML must match.",
                    trim($line),
                    $this->build_bem_suggestion($component_name, $class, $correct_class),
                    'high'
                );
            }
        }
    }

    /**
     * Check JavaScript files for Component subclasses
     */
    private function check_javascript_file(string $file_path, string $contents, array $metadata = []): void
    {
        $lines = explode("\n", $contents);

        // Get JavaScript class from manifest metadata
        $js_classes = [];
        if (isset($metadata['class']) && isset($metadata['extension']) && $metadata['extension'] === 'js') {
            $js_classes = [$metadata['class']];
        }

        // Check class definitions from metadata
        if (!empty($js_classes)) {
            $class_definitions = [];
            foreach ($js_classes as $class_name) {
                foreach ($lines as $idx => $line) {
                    if (preg_match('/class\s+' . preg_quote($class_name, '/') . '\s+/', $line)) {
                        $class_definitions[$class_name] = $idx + 1;
                        break;
                    }
                }
            }

            foreach ($class_definitions as $class_name => $line_num) {
                if (Manifest::js_is_subclass_of($class_name, 'Component')) {
                    if (!ctype_upper($class_name[0])) {
                        $this->add_violation(
                            $file_path,
                            $line_num,
                            "JQHTML component class '{$class_name}' must start with an uppercase letter",
                            trim($lines[$line_num - 1]),
                            "Change '{$class_name}' to '" . ucfirst($class_name) . "'. " .
                            "This is a hard requirement of the jqhtml library - component names MUST start with an uppercase letter.",
                            'critical'
                        );
                    }
                }
            }
        }

        // Check for component registration patterns
        $line_number = 0;
        foreach ($lines as $line) {
            $line_number++;

            if (preg_match('/jqhtml\.component\([\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/', $line, $matches)) {
                $component_name = $matches[1];

                if (!ctype_upper($component_name[0])) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "JQHTML component registration '{$component_name}' must use uppercase name",
                        trim($line),
                        "Change '{$component_name}' to '" . ucfirst($component_name) . "'. " .
                        "This is a hard requirement of the jqhtml library - component names MUST start with an uppercase letter.",
                        'critical'
                    );
                }
            }
        }
    }

    /**
     * Convert PascalCase_With_Underscores to kebab-case
     */
    private function to_kebab_case(string $component_name): string
    {
        return strtolower(str_replace('_', '-', $component_name));
    }

    /**
     * Check if a class name is a known CSS framework class
     */
    private function is_known_framework_class(string $class): bool
    {
        $bootstrap_prefixes = [
            'card-', 'btn-', 'nav-', 'navbar-', 'form-', 'input-', 'list-',
            'table-', 'modal-', 'alert-', 'badge-', 'dropdown-', 'breadcrumb-',
            'pagination-', 'progress-', 'spinner-', 'toast-', 'tooltip-',
            'popover-', 'carousel-', 'accordion-', 'offcanvas-', 'placeholder-',
        ];

        foreach ($bootstrap_prefixes as $prefix) {
            if (strpos($class, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build suggestion for redundant class
     */
    private function build_redundant_class_suggestion(string $component_name, string $class_attribute, string $redundant_class): string
    {
        $cleaned = $this->remove_class_from_attribute($class_attribute, $redundant_class);

        $lines = [];
        $lines[] = "Remove '{$redundant_class}' from the class attribute. Component names are";
        $lines[] = "automatically added to the rendered element's class list by the jqhtml framework.";
        $lines[] = "";
        $lines[] = "CURRENT:";
        $lines[] = "  <Define:{$component_name} class=\"{$class_attribute}\">";
        $lines[] = "";
        if (empty($cleaned)) {
            $lines[] = "CORRECTED:";
            $lines[] = "  <Define:{$component_name}>";
        } else {
            $lines[] = "CORRECTED:";
            $lines[] = "  <Define:{$component_name} class=\"{$cleaned}\">";
        }

        return implode("\n", $lines);
    }

    /**
     * Build suggestion for BEM class naming
     */
    private function build_bem_suggestion(string $component_name, string $wrong_class, string $correct_class): string
    {
        $lines = [];
        $lines[] = "BEM child element classes must use the component's exact PascalCase name as prefix.";
        $lines[] = "";
        $lines[] = "SCSS nesting like:";
        $lines[] = "  .{$component_name} {";
        $lines[] = "      &__element { ... }";
        $lines[] = "  }";
        $lines[] = "";
        $lines[] = "Compiles to: .{$component_name}__element";
        $lines[] = "";
        $lines[] = "So HTML must match:";
        $lines[] = "  WRONG:   class=\"{$wrong_class}\"";
        $lines[] = "  CORRECT: class=\"{$correct_class}\"";
        $lines[] = "";
        $lines[] = "This is a common mistake when following general web conventions where BEM uses";
        $lines[] = "kebab-case. In RSX, component class names are PascalCase, so BEM children must also be.";

        return implode("\n", $lines);
    }

    /**
     * Remove a class from the class attribute string
     */
    private function remove_class_from_attribute(string $class_attribute, string $class_to_remove): string
    {
        $cleaned = preg_replace('/\b' . preg_quote($class_to_remove, '/') . '\b\s*/', '', $class_attribute);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        return trim($cleaned);
    }
}
