<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\Js_CodeQuality_Rpc;

/**
 * JavaScript 'this' Usage Rule
 *
 * PHILOSOPHY: Enforce clear 'this' patterns in anonymous functions and static methods.
 *
 * RULES:
 * 1. Anonymous functions: MUST use 'const $element = $(this)' or 'const that = this' as first line
 * 2. Static methods: MUST NOT use naked 'this' - use Class_Name or 'const CurrentClass = this'
 * 3. Instance methods: EXEMPT - can use 'this' directly (no aliasing required)
 * 4. Arrow functions: EXEMPT - they inherit 'this' context
 * 5. Constructors: EXEMPT - 'this' allowed directly for property assignment
 *
 * PATTERNS:
 * - jQuery callback: const $element = $(this)        // Variable must start with $
 * - Anonymous function: const that = this            // Instance context aliasing
 * - Static (exact): Use Class_Name                   // When you need exact class
 * - Static (polymorphic): const CurrentClass = this  // When inherited classes need different behavior
 *
 * INSTANCE METHODS POLICY:
 * Instance methods (on_ready, on_load, etc.) can use 'this' directly.
 * This rule only enforces aliasing for anonymous functions and prohibits naked 'this' in static methods.
 */
class ThisUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-THIS-01';
    }

    public function get_name(): string
    {
        return "JavaScript 'this' Usage Check";
    }

    public function get_description(): string
    {
        return "Enforces clear 'this' patterns: jQuery callbacks use '\$element = \$(this)', instance methods use 'that = this'";
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
     * Check JavaScript file for improper 'this' usage
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Only check JavaScript files that contain ES6 classes
        if (!isset($metadata['class'])) {
            return; // Not a class file
        }

        // Get violations from AST parser
        $violations = $this->parse_with_acorn($file_path);

        if (empty($violations)) {
            return;
        }

        // Process violations
        foreach ($violations as $violation) {
            $this->add_violation(
                $file_path,
                $violation['line'],
                $violation['message'],
                $violation['codeSnippet'],
                $violation['remediation'],
                $this->get_default_severity()
            );
        }
    }

    /**
     * Analyze JavaScript file for 'this' usage violations via RPC server
     */
    private function parse_with_acorn(string $file_path): array
    {
        // Setup cache directory
        $cache_dir = storage_path('rsx-tmp/cache/code-quality/js-this');
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        // Cache based on file modification time
        $cache_key = md5($file_path) . '-' . filemtime($file_path);
        $cache_file = $cache_dir . '/' . $cache_key . '.json';

        // Check cache first
        if (file_exists($cache_file)) {
            $cached = json_decode(file_get_contents($cache_file), true);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Clean old cache files for this source file
        $pattern = $cache_dir . '/' . md5($file_path) . '-*.json';
        foreach (glob($pattern) as $old_cache) {
            if ($old_cache !== $cache_file) {
                unlink($old_cache);
            }
        }

        // Analyze via RPC server (lazy starts if not running)
        $violations = Js_CodeQuality_Rpc::analyze_this($file_path);

        // Cache the result
        file_put_contents_safe($cache_file, json_encode($violations));

        return $violations;
    }
}