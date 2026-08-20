<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * LifecycleMethodsStaticRule - Ensures RSX lifecycle methods are static
 *
 * This rule checks JavaScript ES6 classes for RSX framework lifecycle methods
 * and ensures they are declared as static. These methods are called by the
 * framework at specific initialization phases and must be static to work correctly.
 */
class LifecycleMethodsStatic_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * RSX lifecycle methods that must be static
     * These are called by the framework during initialization phases
     */
    private const LIFECYCLE_METHODS = [
        '_on_framework_core_define',
        '_on_framework_modules_define',
        '_on_framework_core_init',
        'on_app_modules_define',
        'on_app_define',
        '_on_framework_modules_init',
        'on_app_modules_init',
        'on_app_init',
        'on_app_ready',
    ];

    public function get_id(): string
    {
        return 'JS-LIFECYCLE-01';
    }

    public function get_name(): string
    {
        return 'RSX Lifecycle Methods Must Be Static';
    }

    public function get_description(): string
    {
        return 'Ensures RSX framework lifecycle methods (on_app_ready, etc.) are declared as static in ES6 classes';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Whether this rule is called during manifest scan
     *
     * IMPORTANT: This method should ALWAYS return false unless explicitly requested
     * by the framework developer. Manifest-time checks are reserved for critical
     * framework convention violations that need immediate developer attention.
     *
     * Rules executed during manifest scan will run on every file change in development,
     * potentially impacting performance. Only enable this for rules that:
     * - Enforce critical framework conventions that would break the application
     * - Need to provide immediate feedback before code execution
     * - Have been specifically requested to run at manifest-time by framework maintainers
     *
     * DEFAULT: Always return false unless you have explicit permission to do otherwise.
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * lifecycle methods must be static for the framework's auto-initialization to function correctly.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    /**
     * Check JavaScript file for lifecycle methods that aren't static
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if not a JavaScript file with a class
        if (!isset($metadata['class'])) {
            return;
        }

        // Get class name
        $class_name = $metadata['class'];

        // Only check classes that extend Component
        if (!\App\RSpade\Core\Manifest\Manifest::js_is_subclass_of($class_name, 'Component')) {
            return;
        }

        // Check regular (non-static) methods for lifecycle methods that should be static
        if (isset($metadata['public_instance_methods']) && is_array($metadata['public_instance_methods'])) {
            foreach ($metadata['public_instance_methods'] as $method_name => $method_info) {
                if (in_array($method_name, self::LIFECYCLE_METHODS, true)) {
                    // This lifecycle method is not static - violation!
                    // Find the line number by searching for the method definition
                    $line_number = $this->find_method_line($contents, $method_name);
                    $code_line = $this->extract_code_line($contents, $line_number);

                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "RSX lifecycle method '{$method_name}' must be declared as static",
                        $code_line,
                        $this->get_remediation($method_name, $class_name, $code_line),
                        'high'
                    );
                }
            }
        }
    }

    /**
     * Find the line number where a method is defined
     */
    private function find_method_line(string $contents, string $method_name): int
    {
        $lines = explode("\n", $contents);
        foreach ($lines as $line_num => $line) {
            // Look for method definition (with or without async)
            if (preg_match('/\b(async\s+)?' . preg_quote($method_name, '/') . '\s*\(/', $line)) {
                return $line_num + 1;
            }
        }
        return 1; // Default to line 1 if not found
    }

    /**
     * Extract code line for a given line number
     */
    private function extract_code_line(string $contents, int $line_number): string
    {
        $lines = explode("\n", $contents);
        if (isset($lines[$line_number - 1])) {
            return trim($lines[$line_number - 1]);
        }
        return '';
    }

    /**
     * Get remediation instructions for non-static lifecycle method
     */
    private function get_remediation(string $method, ?string $class_name, string $current_line): string
    {
        $is_async = strpos($current_line, 'async') !== false;
        $static_version = $is_async ? "static async {$method}()" : "static {$method}()";
        $class_info = $class_name ? " in class {$class_name}" : "";

        $phase_description = $this->get_phase_description($method);

        return "FRAMEWORK CONVENTION: RSX lifecycle method '{$method}'{$class_info} must be static.

PROBLEM:
The method is currently defined as an instance method, but the RSX framework
calls these methods statically during application initialization.

SOLUTION:
Change the method declaration to: {$static_version}

CURRENT (INCORRECT):
{$current_line}

CORRECTED:
{$static_version} {
    // Your initialization code here
}

WHY THIS MATTERS:
- The RSX framework calls lifecycle methods statically on classes
- Instance methods cannot be called without instantiating the class
- The framework does not instantiate classes during initialization
- Using instance methods will cause the initialization to fail silently

LIFECYCLE PHASE:
This method runs during: {$phase_description}

ALL RSX LIFECYCLE METHODS (must be static):
- _on_framework_core_define() - Framework core modules define phase
- _on_framework_modules_define() - Framework extension modules define
- _on_framework_core_init() - Framework core initialization
- on_app_modules_define() - Application modules define phase
- on_app_define() - Application-wide define phase
- _on_framework_modules_init() - Framework modules initialization
- on_app_modules_init() - Application modules initialization
- on_app_init() - Application initialization
- on_app_ready() - Application ready (DOM loaded, components initialized)

Methods prefixed with underscore (_) are internal framework methods.
Application code should typically only use the non-underscore methods.";
    }

    /**
     * Get description of when this lifecycle phase runs
     */
    private function get_phase_description(string $method): string
    {
        $descriptions = [
            '_on_framework_core_define' => 'Framework core module definition (internal)',
            '_on_framework_modules_define' => 'Framework extension module definition (internal)',
            '_on_framework_core_init' => 'Framework core initialization (internal)',
            'on_app_modules_define' => 'Application module definition phase',
            'on_app_define' => 'Application-wide definition phase',
            '_on_framework_modules_init' => 'Framework module initialization (internal)',
            'on_app_modules_init' => 'Application module initialization',
            'on_app_init' => 'Application initialization',
            'on_app_ready' => 'Application ready - DOM loaded, components initialized',
        ];

        return $descriptions[$method] ?? 'Unknown phase';
    }
}