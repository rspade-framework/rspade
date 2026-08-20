<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * This rule enforces the #[Relationship] attribute pattern for model relationships.
 * It checks that:
 * 1. Simple relationships have the #[Relationship] attribute
 * 2. Complex relationships (multiple return statements) are flagged for refactoring
 * 3. Methods with #[Relationship] are actual Laravel relationships
 */
class ModelRelations_CodeQualityRule extends CodeQualityRule_Abstract
{
    // Approved Laravel relationship methods
    protected $approved_relations = [
        'hasMany',
        'hasOne',
        'belongsTo',
        'morphOne',
        'morphMany',
        'morphTo',
        'belongsToMany',
        'morphToMany',
        'morphedByMany',
    ];

    public function get_id(): string
    {
        return 'MODEL-REL-01';
    }

    public function get_name(): string
    {
        return 'Model Relationship Attributes';
    }

    public function get_description(): string
    {
        return 'Model relationships must have #[Relationship] attributes and follow simple patterns';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
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
        try {
            if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
                return;
            }
        } catch (\Exception $e) {
            // Class not found in manifest, skip
            return;
        }

        // Read original file content
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $full_path = str_starts_with($file_path, '/') ? $file_path : $base_path . '/' . $file_path;
        $original_contents = file_get_contents($full_path);

        // Get lines for line number tracking
        $lines = explode("\n", $original_contents);

        // Get manifest metadata for this file to check for attributes
        $manifest_metadata = [];
        try {
            $manifest = Manifest::get_full_manifest();

            // Convert absolute path to relative if needed
            $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
            $manifest_path = $file_path;
            if (str_starts_with($manifest_path, $base_path . '/')) {
                $manifest_path = substr($manifest_path, strlen($base_path) + 1);
            }

            if (isset($manifest['data']['files'][$manifest_path])) {
                $manifest_metadata = $manifest['data']['files'][$manifest_path];
            }
        } catch (\Exception $e) {
            // Manifest not available, skip attribute checking
        }

        // Track methods we've analyzed
        $relationship_methods = [];
        $complex_relationships = [];

        // Track multi-line comment state
        $in_multiline_comment = false;

        // Parse each method to find relationships
        foreach ($lines as $i => $line) {
            // Track multi-line comment state
            // Check for comment start (but not if it's closed on same line)
            if (preg_match('#/\*#', $line) && !preg_match('#/\*.*\*/#', $line)) {
                $in_multiline_comment = true;
            }
            // Check for comment end
            if (preg_match('#\*/#', $line)) {
                $in_multiline_comment = false;
                continue; // Skip the closing line too
            }
            // Skip lines inside multi-line comments
            if ($in_multiline_comment) {
                continue;
            }

            // Look for public non-static function declarations
            if (preg_match('/public\s+(?!static\s+)function\s+(\w+)\s*\(/', $line, $func_match)) {
                $method_name = $func_match[1];
                $method_start_line = $i + 1;

                // Skip magic methods and Laravel lifecycle methods
                if (str_starts_with($method_name, '__') ||
                    in_array($method_name, ['boot', 'booted', 'booting'])) {
                    continue;
                }

                // Find the method body (from { to })
                $brace_count = 0;
                $in_method = false;
                $method_body = '';
                $method_lines = [];

                for ($j = $i; $j < count($lines); $j++) {
                    $current_line = $lines[$j];

                    // Track opening braces
                    if (strpos($current_line, '{') !== false) {
                        $brace_count += substr_count($current_line, '{');
                        $in_method = true;
                    }

                    if ($in_method) {
                        $method_body .= $current_line . "\n";
                        $method_lines[] = $j + 1; // Store 1-based line numbers
                    }

                    // Track closing braces
                    if (strpos($current_line, '}') !== false) {
                        $brace_count -= substr_count($current_line, '}');
                        if ($brace_count <= 0 && $in_method) {
                            break;
                        }
                    }
                }

                // Count relationship return statements
                $relationship_count = 0;
                $relationship_types = [];

                foreach ($this->approved_relations as $relation_type) {
                    $pattern = '/return\s+\$this->' . preg_quote($relation_type) . '\s*\(/';
                    $matches = preg_match_all($pattern, $method_body);
                    if ($matches > 0) {
                        $relationship_count += $matches;
                        $relationship_types[] = $relation_type;
                    }
                }

                // Determine if this is a relationship method
                if ($relationship_count > 0) {
                    if ($relationship_count > 1) {
                        // Complex relationship with multiple return statements
                        $complex_relationships[$method_name] = [
                            'line' => $method_start_line,
                            'count' => $relationship_count,
                            'types' => $relationship_types
                        ];
                    } else {
                        // Simple relationship
                        $relationship_methods[$method_name] = [
                            'line' => $method_start_line,
                            'type' => $relationship_types[0]
                        ];
                    }
                }
            }
        }

        // Check for complex relationships (violation)
        foreach ($complex_relationships as $method_name => $info) {
            $this->add_violation(
                $file_path,
                $info['line'],
                "Method '{$method_name}' is a complex relationship with {$info['count']} return statements",
                $lines[$info['line'] - 1] ?? '',
                "Split into separate methods, each returning a single relationship",
                'high'
            );
        }

        // Check simple relationships for #[Relationship] attribute
        foreach ($relationship_methods as $method_name => $info) {
            $has_attribute = false;

            // Check in manifest metadata for the attribute
            if (isset($manifest_metadata['public_instance_methods'][$method_name]['attributes']['Relationship'])) {
                $has_attribute = true;
            }

            if (!$has_attribute) {
                $this->add_violation(
                    $file_path,
                    $info['line'],
                    "Relationship method '{$method_name}' is missing #[Relationship] attribute",
                    $lines[$info['line'] - 1] ?? '',
                    "Add #[Relationship] attribute above the method declaration",
                    'medium'
                );
            }
        }

        // Check for methods with #[Relationship] that aren't actually relationships
        if (isset($manifest_metadata['public_instance_methods'])) {
            foreach ($manifest_metadata['public_instance_methods'] as $method_name => $method_data) {
                // Check if has Relationship attribute
                if (isset($method_data['attributes']['Relationship'])) {
                    // Check if it's actually a relationship
                    if (!isset($relationship_methods[$method_name]) &&
                        !isset($complex_relationships[$method_name])) {

                        // Find the line number for this method
                        $method_line = 1;
                        foreach ($lines as $i => $line) {
                            if (preg_match('/function\s+' . preg_quote($method_name) . '\s*\(/', $line)) {
                                $method_line = $i + 1;
                                break;
                            }
                        }

                        $this->add_violation(
                            $file_path,
                            $method_line,
                            "Method '{$method_name}' has #[Relationship] attribute but is not a Laravel relationship",
                            $lines[$method_line - 1] ?? '',
                            "Remove #[Relationship] attribute as this method doesn't return a relationship",
                            'high'
                        );
                    }
                }
            }
        }
    }
}
