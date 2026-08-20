<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class GenericSuffix_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-MODULE-01';
    }
    
    public function get_name(): string
    {
        return 'Generic Suffix Naming Convention';
    }
    
    public function get_description(): string
    {
        return 'Classes with generic suffixes like "Module" or "Rule" must use more descriptive compound suffixes';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }
    
    public function get_default_severity(): string
    {
        return 'high';
    }
    
    /**
     * Check PHP files for classes with generic suffixes that should be more descriptive
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip SchemaQuality and CodeQuality directories - they have their own naming conventions
        if (str_contains($file_path, 'app/RSpade/SchemaQuality/') ||
            str_contains($file_path, 'app/RSpade/CodeQuality/')) {
            return;
        }

        // Skip -temp files
        if (str_contains($file_path, '-temp.')) {
            return;
        }

        // Get configured generic suffixes
        $generic_suffixes = config('rsx.code_quality.generic_suffix_replacements', []);
        if (empty($generic_suffixes)) {
            return;
        }
        
        // Get sanitized content
        $sanitized_data = FileSanitizer::sanitize_php($contents);
        $content = $sanitized_data['content'];
        
        // Extract namespace
        $namespace = null;
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $content, $matches)) {
            $namespace = $matches[1];
        }
        
        // Find class declarations
        // @META-INHERIT-01-EXCEPTION - Extracting class names and positions, not checking inheritance
        // This extracts class declarations from source code for naming convention checks
        preg_match_all('/^\s*(?:abstract\s+)?class\s+([A-Za-z0-9_]+)(?:\s+extends\s+([A-Za-z0-9_\\\\]+))?/m', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        
        foreach ($matches as $match) {
            $class_name = $match[1][0];
            $extends_class = isset($match[2]) ? $match[2][0] : null;
            $offset = $match[0][1];
            $is_abstract = str_starts_with(trim($match[0][0]), 'abstract');

            // Count line number
            $line_number = substr_count(substr($content, 0, $offset), "\n") + 1;

            // PRIORITY 1: Check abstract classes with pattern (Prefix)_(GenericSuffix)_Abstract
            // E.g., Schema_Rule_Abstract should be SchemaRule_Abstract
            if ($is_abstract) {
                foreach ($generic_suffixes as $suffix => $better_suffixes) {
                    // Check for pattern: Something_Rule_Abstract or Something_Module_Abstract
                    $pattern = '/^(.+)_(' . preg_quote($suffix, '/') . ')_Abstract$/';
                    if (preg_match($pattern, $class_name, $abstract_match)) {
                        $prefix = $abstract_match[1];
                        $generic_suffix = $abstract_match[2];

                        // Suggest combining prefix with suffix
                        $suggested_name = $prefix . $generic_suffix . '_Abstract';

                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "Abstract class '{$class_name}' uses generic suffix '{$generic_suffix}' that should be combined with prefix",
                            trim($match[0][0]),
                            "Abstract classes with generic suffixes like '{$generic_suffix}' should combine the prefix with the suffix to form a compound name.\n\n" .
                            "Current: {$class_name}\n" .
                            "Suggested: {$suggested_name}\n\n" .
                            "The suffix '{$generic_suffix}' by itself is too generic. Combine it with '{$prefix}' to create a more descriptive compound suffix.",
                            'high'
                        );
                        break;
                    }
                }
                continue; // Skip further checks for abstract classes
            }

            // PRIORITY 2: Check classes ending with compound suffixes (must extend correct abstract)
            // E.g., Blade_ManifestModule should extend ManifestModule_Abstract
            $found_compound_suffix = null;
            foreach ($generic_suffixes as $base_suffix => $better_suffixes) {
                foreach ($better_suffixes as $compound_suffix) {
                    $pattern = '/_' . preg_quote($compound_suffix, '/') . '$/i';
                    if (preg_match($pattern, $class_name)) {
                        $found_compound_suffix = $compound_suffix;
                        break 2;
                    }
                }
            }

            if ($found_compound_suffix) {
                // Check if it extends an abstract class
                if (!$extends_class) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Class '{$class_name}' ends with '{$found_compound_suffix}' but doesn't extend any abstract class",
                        trim($match[0][0]),
                        "Classes with compound suffixes must extend the corresponding abstract class.\n\n" .
                        "For a class ending in '{$found_compound_suffix}', it should extend '{$found_compound_suffix}_Abstract'.\n" .
                        "This ensures consistent interface and behavior across all types.",
                        'high'
                    );
                    continue;
                }

                // Check if the parent class matches the expected abstract class
                $expected_abstract = $found_compound_suffix . '_Abstract';

                // Remove namespace prefix if present
                $parent_class_name = $extends_class;
                if (str_contains($parent_class_name, '\\')) {
                    $parts = explode('\\', $parent_class_name);
                    $parent_class_name = end($parts);
                }

                if ($parent_class_name !== $expected_abstract) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Class '{$class_name}' extends '{$extends_class}' but should extend '{$expected_abstract}'",
                        trim($match[0][0]),
                        "Classes must extend the abstract class matching their suffix.\n\n" .
                        "Class suffix: {$found_compound_suffix}\n" .
                        "Expected parent: {$expected_abstract}\n" .
                        "Actual parent: {$parent_class_name}\n\n" .
                        "This ensures type safety and consistent behavior.",
                        'high'
                    );
                }
                continue; // Skip generic suffix checks for compound suffixes
            }

            // PRIORITY 3: Check for generic suffixes that need proper underscore separation
            // E.g., TestModule should be Test_Module
            foreach ($generic_suffixes as $suffix => $better_suffixes) {
                // Check if class name contains this suffix anywhere
                if (!str_contains($class_name, $suffix)) {
                    continue;
                }

                // Check if class name contains suffix but without proper underscores
                // E.g., TestModule should be Test_Module, MyRule should be My_Rule
                $pattern = '/[a-z]' . preg_quote($suffix, '/') . '|' . preg_quote($suffix, '/') . '[A-Z]/';
                if (preg_match($pattern, $class_name)) {
                    $suggested_name = preg_replace('/([a-z])' . preg_quote($suffix, '/') . '/', '$1_' . $suffix, $class_name);
                    $suggested_name = preg_replace('/' . preg_quote($suffix, '/') . '([A-Z])/', $suffix . '_$1', $suggested_name);

                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Class '{$class_name}' contains '{$suffix}' but lacks proper underscore separation",
                        trim($match[0][0]),
                        "Classes containing '{$suffix}' should use underscores for proper word separation.\n\n" .
                        "Current: {$class_name}\n" .
                        "Suggested: {$suggested_name}\n\n" .
                        "However, note that classes ending with just '_{$suffix}' are too generic.\n" .
                        "Consider a more specific suffix like '_{$better_suffixes[0]}' or '_{$better_suffixes[1]}'.",
                        'high'
                    );
                    break; // Only report one violation per class
                }

                // PRIORITY 4: Check if class ends with just "_{$suffix}" (too generic)
                $generic_pattern = '/_' . preg_quote($suffix, '/') . '$/i';
                if (preg_match($generic_pattern, $class_name)) {
                    // Extract the prefix before _{$suffix}
                    $prefix = preg_replace($generic_pattern, '', $class_name);

                    // Build example suggestions
                    $examples = "";
                    foreach ($better_suffixes as $better_suffix) {
                        $examples .= "  - {$prefix}_{$better_suffix}\n";
                    }

                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Class '{$class_name}' has generic '_{$suffix}' suffix without a specific type",
                        trim($match[0][0]),
                        "Classes ending with '_{$suffix}' are too generic. The suffix should describe the specific type.\n\n" .
                        "Current: {$class_name}\n" .
                        "Examples of better names:\n" .
                        $examples . "\n" .
                        "The suffix should indicate what kind of {$suffix} this is.",
                        'high'
                    );
                    break; // Only report one violation per class
                }
            }
        }
    }
}