<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * DecoratorIdentifierParamRule - Prohibits identifier (class name) parameters in decorators
 *
 * JavaScript decorators cannot reliably use class name references as parameters because
 * the framework cannot guarantee class definition order in the compiled bundle output.
 * Class ordering is based on extends hierarchies and other factors, but decorator
 * parameter dependencies are not tracked.
 *
 * Use string literals instead of class references in decorator parameters.
 */
class DecoratorIdentifierParam_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'JS-DECORATOR-IDENT-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Decorator Identifier Parameter';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Prohibits identifier (class name) references as decorator parameters - use string literals instead';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    /**
     * This rule runs during manifest scan to catch issues early
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * This is a cross-file rule - needs full manifest context
     */
    public function is_incremental(): bool
    {
        return false;
    }

    /**
     * Check the manifest for decorator identifier parameter violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;

        // Only check once per manifest build
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        // Get all manifest files
        $files = \App\RSpade\Core\Manifest\Manifest::get_all();
        if (empty($files)) {
            return;
        }

        // Check all JavaScript files with decorators
        foreach ($files as $file => $file_metadata) {
            // Skip if not a JavaScript file
            if (($file_metadata['extension'] ?? '') !== 'js') {
                continue;
            }

            // Skip if no class (non-class JS files)
            if (!isset($file_metadata['class'])) {
                continue;
            }

            // Check class-level decorators
            if (!empty($file_metadata['decorators'])) {
                $this->check_decorators($file, $file_metadata['class'], $file_metadata['decorators'], 'class');
            }

            // Check method-level decorators
            // Method decorators are stored under each method in public_static_methods and public_instance_methods
            $all_methods = array_merge(
                $file_metadata['public_static_methods'] ?? [],
                $file_metadata['public_instance_methods'] ?? []
            );

            foreach ($all_methods as $method_name => $method_info) {
                if (!empty($method_info['decorators'])) {
                    $this->check_decorators(
                        $file,
                        $file_metadata['class'],
                        $method_info['decorators'],
                        'method',
                        $method_name,
                        $method_info['line'] ?? 1
                    );
                }
            }
        }
    }

    /**
     * Check a set of decorators for identifier parameters
     *
     * @param string $file File path
     * @param string $class_name Class name for context
     * @param array $decorators Decorators in compact format: [[name, [args]], ...]
     * @param string $context 'class' or 'method'
     * @param string|null $method_name Method name if context is 'method'
     * @param int $line Line number
     */
    private function check_decorators(
        string $file,
        string $class_name,
        array $decorators,
        string $context,
        ?string $method_name = null,
        int $line = 1
    ): void {
        foreach ($decorators as $decorator) {
            $decorator_name = $decorator[0] ?? 'unknown';
            $decorator_args = $decorator[1] ?? [];

            // Check each argument for identifier usage
            $this->check_args_for_identifiers(
                $file,
                $class_name,
                $decorator_name,
                $decorator_args,
                $context,
                $method_name,
                $line
            );
        }
    }

    /**
     * Recursively check decorator arguments for identifier values
     */
    private function check_args_for_identifiers(
        string $file,
        string $class_name,
        string $decorator_name,
        array $args,
        string $context,
        ?string $method_name,
        int $line,
        string $path = ''
    ): void {
        foreach ($args as $index => $arg) {
            $current_path = $path ? "{$path}[{$index}]" : "argument {$index}";

            // Check if this argument is an identifier
            if (is_array($arg) && isset($arg['identifier'])) {
                $identifier = $arg['identifier'];

                // Build context description
                if ($context === 'class') {
                    $location = "class '{$class_name}'";
                } else {
                    $location = "method '{$class_name}::{$method_name}()'";
                }

                $this->add_violation(
                    $file,
                    $line,
                    "Decorator @{$decorator_name} on {$location} uses identifier '{$identifier}' as a parameter. " .
                    "Class name references are not allowed in decorator parameters because the framework cannot " .
                    "guarantee the referenced class will be defined before this decorator is evaluated.",
                    "@{$decorator_name}(...{$identifier}...)",
                    "Use a string literal instead of the class reference:\n" .
                    "  - Change: @{$decorator_name}({$identifier})\n" .
                    "  - To:     @{$decorator_name}('{$identifier}')\n\n" .
                    "If you need the actual class at runtime, resolve it from the string:\n" .
                    "  const cls = Manifest.get_class_by_name('{$identifier}');",
                    'critical'
                );
            }

            // Recursively check nested arrays
            if (is_array($arg) && !isset($arg['identifier'])) {
                $this->check_args_for_identifiers(
                    $file,
                    $class_name,
                    $decorator_name,
                    $arg,
                    $context,
                    $method_name,
                    $line,
                    $current_path
                );
            }
        }
    }
}
