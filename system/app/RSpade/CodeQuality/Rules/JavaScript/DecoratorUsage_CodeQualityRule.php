<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Check decorator usage and enforce whitelisting rules
 * - Functions/methods marked with @decorator become whitelisted
 * - Global functions can only use @decorator
 * - Static and instance methods can only use whitelisted decorators
 * - Checks for duplicate global function names
 * - Checks for duplicate global const names
 * - Checks for conflicts between global function and const names
 */
class DecoratorUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    private array $decorator_whitelist = [];
    private array $all_global_functions = [];
    private array $all_global_constants = [];
    private array $all_global_names = []; // Combined functions and constants for conflict checking

    public function get_id(): string
    {
        return 'JS-DECORATOR-01';
    }

    public function get_name(): string
    {
        return 'JavaScript Decorator Usage Check';
    }

    public function get_description(): string
    {
        return 'Validates JavaScript decorator usage and whitelisting (static and instance methods)';
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

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only process during manifest-time when we have all files
        // This rule needs to scan all files first to build the whitelist
        // So we run it once at the end of manifest building
        static $already_run = false;
        if ($already_run) {
            return;
        }

        // On the first JavaScript file, process all files
        if (!empty($metadata) && $metadata['extension'] === 'js') {
            $this->process_all_files();
            $already_run = true;
        }
    }

    private function process_all_files(): void
    {
        // Get all files from manifest
        $files = Manifest::get_all();

        // Step 1: Build decorator whitelist and collect all global functions
        foreach ($files as $path => $metadata) {
            // Skip non-JavaScript files
            if (($metadata['extension'] ?? '') !== 'js') {
                continue;
            }

            // Collect global function names for uniqueness check
            if (!empty($metadata['global_function_names'])) {
                foreach ($metadata['global_function_names'] as $func_name) {
                    if (isset($this->all_global_functions[$func_name])) {
                        // Duplicate function name
                        $existing_file = $this->all_global_functions[$func_name];
                        $this->throw_duplicate_global($func_name, 'function', $existing_file, $path);
                    }
                    $this->all_global_functions[$func_name] = $path;

                    // Check for conflict with const names
                    if (isset($this->all_global_constants[$func_name])) {
                        $existing_file = $this->all_global_constants[$func_name];
                        $this->throw_name_conflict($func_name, 'function', $path, 'const', $existing_file);
                    }
                    $this->all_global_names[$func_name] = ['type' => 'function', 'file' => $path];
                }
            }

            // Collect global const names for uniqueness check
            if (!empty($metadata['global_const_names'])) {
                foreach ($metadata['global_const_names'] as $const_name) {
                    if (isset($this->all_global_constants[$const_name])) {
                        // Duplicate const name
                        $existing_file = $this->all_global_constants[$const_name];
                        $this->throw_duplicate_global($const_name, 'const', $existing_file, $path);
                    }
                    $this->all_global_constants[$const_name] = $path;

                    // Check for conflict with function names
                    if (isset($this->all_global_functions[$const_name])) {
                        $existing_file = $this->all_global_functions[$const_name];
                        $this->throw_name_conflict($const_name, 'const', $path, 'function', $existing_file);
                    }
                    $this->all_global_names[$const_name] = ['type' => 'const', 'file' => $path];
                }
            }

            // Check global functions for @decorator
            if (!empty($metadata['global_functions_with_decorators'])) {
                foreach ($metadata['global_functions_with_decorators'] as $func_name => $func_data) {
                    $decorators = $func_data['decorators'] ?? [];
                    $line = $func_data['line'] ?? 0;

                    foreach ($decorators as $decorator) {
                        $decorator_name = $decorator['name'] ?? $decorator[0] ?? 'unknown';

                        if ($decorator_name === 'decorator') {
                            $this->decorator_whitelist[$func_name] = true;
                        } else {
                            // Global function with non-@decorator decorator
                            $this->throw_global_function_decorator($func_name, $path, $line, $decorator_name);
                        }
                    }
                }
            }

            // Check static methods for @decorator
            if (!empty($metadata['public_static_methods'])) {
                foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
                    // Check for decorators in compact format
                    if (!empty($metadata['method_decorators'][$method_name])) {
                        $compact_decorators = $metadata['method_decorators'][$method_name];
                        foreach ($compact_decorators as $decorator_data) {
                            $decorator_name = $decorator_data[0] ?? 'unknown';
                            if ($decorator_name === 'decorator') {
                                $this->decorator_whitelist[$method_name] = true;
                            }
                        }
                    }
                }
            }

            // Check instance methods for @decorator
            if (!empty($metadata['public_instance_methods'])) {
                foreach ($metadata['public_instance_methods'] as $method_name => $method_data) {
                    // Check for decorators in compact format
                    if (!empty($metadata['method_decorators'][$method_name])) {
                        $compact_decorators = $metadata['method_decorators'][$method_name];
                        foreach ($compact_decorators as $decorator_data) {
                            $decorator_name = $decorator_data[0] ?? 'unknown';
                            if ($decorator_name === 'decorator') {
                                $this->decorator_whitelist[$method_name] = true;
                            }
                        }
                    }
                }
            }
        }

        // Step 2: Validate static and instance method decorators against whitelist
        foreach ($files as $path => $metadata) {
            // Skip non-JavaScript files
            if (($metadata['extension'] ?? '') !== 'js') {
                continue;
            }

            $class_name = $metadata['class'] ?? 'Unknown';

            // Check static methods
            if (!empty($metadata['public_static_methods'])) {
                foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
                    // Check for decorators in compact format
                    if (!empty($metadata['method_decorators'][$method_name])) {
                        $compact_decorators = $metadata['method_decorators'][$method_name];
                        foreach ($compact_decorators as $decorator_data) {
                            $decorator_name = $decorator_data[0] ?? 'unknown';

                            if ($decorator_name !== 'decorator' && !isset($this->decorator_whitelist[$decorator_name])) {
                                $this->throw_unwhitelisted_decorator($decorator_name, $class_name, $method_name, $path);
                            }
                        }
                    }
                }
            }

            // Check instance methods
            if (!empty($metadata['public_instance_methods'])) {
                foreach ($metadata['public_instance_methods'] as $method_name => $method_data) {
                    // Check for decorators in compact format
                    if (!empty($metadata['method_decorators'][$method_name])) {
                        $compact_decorators = $metadata['method_decorators'][$method_name];
                        foreach ($compact_decorators as $decorator_data) {
                            $decorator_name = $decorator_data[0] ?? 'unknown';

                            if ($decorator_name !== 'decorator' && !isset($this->decorator_whitelist[$decorator_name])) {
                                $this->throw_unwhitelisted_decorator($decorator_name, $class_name, $method_name, $path);
                            }
                        }
                    }
                }
            }
        }
    }

    // Note: Duplicate/conflict checking methods removed
    // This functionality is now handled by BundleCompiler::_check_js_naming_conflicts()
    // which checks only the files being bundled, not all files in the project

    private function throw_global_function_decorator(string $func_name, string $path, int $line, string $decorator_name): void
    {
        $error_message = "==========================================\n";
        $error_message .= "FATAL: Global function cannot use decorator\n";
        $error_message .= "==========================================\n\n";
        $error_message .= "File: {$path}\n";
        $error_message .= "Line: {$line}\n";
        $error_message .= "Function: {$func_name}\n";
        $error_message .= "Decorator: @{$decorator_name}\n\n";
        $error_message .= "Global functions may only use the @decorator marker.\n\n";
        $error_message .= "PROBLEM:\n";
        $error_message .= "The function '{$func_name}' has decorator '@{$decorator_name}'.\n";
        $error_message .= "Only the '@decorator' marker is allowed on global functions.\n\n";
        $error_message .= "INCORRECT:\n";
        $error_message .= "  @memoize\n";
        $error_message .= "  function myFunction() { ... }\n\n";
        $error_message .= "CORRECT:\n";
        $error_message .= "  @decorator\n";
        $error_message .= "  function myDecorator(target, key, descriptor) { ... }\n\n";
        $error_message .= "WHY THIS RESTRICTION:\n";
        $error_message .= "- Global functions are processed differently than class methods\n";
        $error_message .= "- The @decorator marker identifies decorator implementations\n";
        $error_message .= "- Other decorators can only be used on static class methods\n\n";
        $error_message .= "FIX OPTIONS:\n";
        $error_message .= "1. Remove the '@{$decorator_name}' decorator\n";
        $error_message .= "2. Move the function into a class as a static method\n";
        $error_message .= "3. If this IS a decorator implementation, use '@decorator' instead\n";
        $error_message .= "==========================================";

        throw new YoureDoingItWrongException($error_message);
    }

    private function throw_unwhitelisted_decorator(string $decorator_name, string $class_name, string $method_name, string $path): void
    {
        $error_message = "==========================================\n";
        $error_message .= "FATAL: Decorator not whitelisted\n";
        $error_message .= "==========================================\n\n";
        $error_message .= "File: {$path}\n";
        $error_message .= "Class: {$class_name}\n";
        $error_message .= "Method: {$method_name}\n";
        $error_message .= "Decorator: @{$decorator_name}\n\n";
        $error_message .= "The decorator '@{$decorator_name}' is not whitelisted.\n\n";
        $error_message .= "PROBLEM:\n";
        $error_message .= "Only decorators marked with @decorator can be used.\n";
        $error_message .= "The '{$decorator_name}' function/method needs @decorator marker.\n\n";
        $error_message .= "EXAMPLE OF WHITELISTING:\n";
        $error_message .= "  // Mark the decorator implementation:\n";
        $error_message .= "  @decorator\n";
        $error_message .= "  function {$decorator_name}(target, key, descriptor) {\n";
        $error_message .= "      // Decorator implementation\n";
        $error_message .= "      return descriptor;\n";
        $error_message .= "  }\n\n";
        $error_message .= "  // Or in a class:\n";
        $error_message .= "  class Decorators {\n";
        $error_message .= "      @decorator\n";
        $error_message .= "      static {$decorator_name}(target, key, descriptor) {\n";
        $error_message .= "          return descriptor;\n";
        $error_message .= "      }\n";
        $error_message .= "  }\n\n";
        $error_message .= "WHY THIS MATTERS:\n";
        $error_message .= "- Decorators must be explicitly whitelisted\n";
        $error_message .= "- This prevents typos and undefined decorators\n";
        $error_message .= "- Ensures decorators are properly implemented\n\n";
        $error_message .= "FIX:\n";
        $error_message .= "Add @decorator to the '{$decorator_name}' function/method definition.\n";
        $error_message .= "==========================================";

        throw new YoureDoingItWrongException($error_message);
    }
}