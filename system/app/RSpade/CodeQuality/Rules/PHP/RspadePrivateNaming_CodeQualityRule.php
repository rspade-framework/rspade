<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class RspadePrivateNaming_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-RSPADE-01';
    }
    
    public function get_name(): string
    {
        return 'RSpade Private Method Naming Convention';
    }
    
    public function get_description(): string
    {
        return 'Enforces double underscore prefix for private/protected static methods in RSpade framework';
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
        // Only apply to files in app/RSpade directory
        if (!str_contains($file_path, '/app/RSpade/') && !str_contains($file_path, 'app/RSpade/')) {
            return;
        }

        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }

        // Skip CodeQuality and SchemaQuality directories - they have their own conventions
        if (str_contains($file_path, '/CodeQuality/') || str_contains($file_path, '/SchemaQuality/')) {
            return;
        }

        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);

        // Get sanitized content with comments removed
        $sanitized_data = FileSanitizer::sanitize_php($contents);
        $sanitized_lines = $sanitized_data['lines'];

        // Extract class name from the file
        $class_name = null;
        $namespace = null;
        foreach ($sanitized_lines as $line) {
            if (preg_match('/^namespace\s+([^;]+);/', $line, $m)) {
                $namespace = $m[1];
            }
            if (preg_match('/^(?:abstract\s+)?class\s+(\w+)/', $line, $m)) {
                $class_name = $m[1];
                break;
            }
        }

        // If we can't find a class, skip
        if (!$class_name || !$namespace) {
            return;
        }

        $full_class_name = $namespace . '\\' . $class_name;

        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            $line_number = $line_num + 1;

            // Skip if the line is empty in sanitized version
            if (trim($sanitized_line) === '') {
                continue;
            }

            // Check for protected static function or private static function
            if (preg_match('/\b(protected|private)\s+static\s+function\s+([a-zA-Z][a-zA-Z0-9_]*)\s*\(/', $sanitized_line, $matches)) {
                $visibility = $matches[1];
                $function_name = $matches[2];

                // Check if function name doesn't start with double underscore
                if (!str_starts_with($function_name, '__')) {
                    // Only judge methods the outer named class actually declares. A
                    // protected/private static method matched here can belong to an
                    // anonymous or nested class in the file body (e.g. a test fixture
                    // `new class extends ... { protected static function booted() {} }`);
                    // the rule reflects only $full_class_name, so it cannot attribute or
                    // exempt such a method correctly (it would mis-reflect the outer class).
                    // Skip anything the outer class does not itself declare.
                    if (!$this->outer_class_declares($full_class_name, $function_name)) {
                        continue;
                    }

                    // For private methods, ALWAYS require double underscore
                    if ($visibility === 'private') {
                        $original_line = $original_lines[$line_num] ?? $sanitized_line;

                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "Private static method '{$function_name}' in RSpade framework must start with double underscore.",
                            trim($original_line),
                            "Add double underscore prefix to the method name (change '{$function_name}' to '__{$function_name}'). " .
                            "All private static methods in the app/RSpade directory must start with double underscores " .
                            "to clearly indicate they are internal-only methods. After renaming, refactor all references to this method " .
                            "throughout the class to use the new name.",
                            'medium'
                        );
                    }
                    // For protected methods, check if they're overriding a parent method from outside App/RSpade
                    elseif ($visibility === 'protected') {
                        // Use reflection to check parent class hierarchy
                        if ($this->is_overriding_external_method($full_class_name, $function_name)) {
                            // This method overrides a parent method from outside App/RSpade, so it's exempt
                            continue;
                        }

                        $original_line = $original_lines[$line_num] ?? $sanitized_line;

                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "Protected static method '{$function_name}' in RSpade framework must start with double underscore.",
                            trim($original_line),
                            "Add double underscore prefix to the method name (change '{$function_name}' to '__{$function_name}'). " .
                            "Protected static methods in the app/RSpade directory must start with double underscores " .
                            "unless they override a parent class method from outside App/RSpade. After renaming, refactor all references " .
                            "to this method throughout the class to use the new name.",
                            'medium'
                        );
                    }
                }
            }
        }
    }

    /**
     * Check if a method is overriding a parent method from outside App/RSpade
     *
     * @PHP-REFLECT-02-EXCEPTION: This method needs ReflectionClass to check parent methods
     * from Laravel framework classes which are not tracked in the manifest. The manifest
     * only contains RSX application classes, not vendor dependencies, and we need to check
     * if specific methods exist in parent classes using hasMethod().
     *
     * @param string $class_name Fully qualified class name
     * @param string $method_name Method name to check
     * @return bool True if the method overrides a parent method from outside App/RSpade
     */
    private function is_overriding_external_method(string $class_name, string $method_name): bool
    {
        try {
            // Check if class exists
            if (!class_exists($class_name)) {
                return false;
            }

            $reflection = new \ReflectionClass($class_name);

            // Walk up the parent class hierarchy
            $parent = $reflection->getParentClass();
            while ($parent !== false) {
                $parent_name = $parent->getName();

                // Check if this parent class has the method
                if ($parent->hasMethod($method_name)) {
                    // If the parent class is outside App/RSpade, this method is exempt
                    if (!str_starts_with($parent_name, 'App\\RSpade\\')) {
                        return true;
                    }
                }

                // Move up to the next parent
                $parent = $parent->getParentClass();
            }

            return false;
        } catch (\ReflectionException $e) {
            // If we can't reflect the class, be conservative and don't flag it
            return false;
        }
    }

    /**
     * Whether the outer named class of the file itself DECLARES this method.
     *
     * A line-based scan matches `protected/private static function` lines that may
     * belong to an anonymous or nested class in the body (which the rule reflects as
     * the file's outer named class, mis-attributing the method). Only judge methods
     * the outer class actually declares; a method it merely inherits, or one declared
     * by a nested/anonymous class, is skipped.
     *
     * @PHP-REFLECT-02-EXCEPTION: needs ReflectionClass to resolve the declaring class
     * of a method against the file's outer class; the manifest does not track this.
     */
    private function outer_class_declares(string $class_name, string $method_name): bool
    {
        try {
            if (!class_exists($class_name)) {
                return false;
            }

            $reflection = new \ReflectionClass($class_name);

            if (!$reflection->hasMethod($method_name)) {
                return false;
            }

            return $reflection->getMethod($method_name)->getDeclaringClass()->getName() === $class_name;
        } catch (\ReflectionException $e) {
            return false;
        }
    }
}