<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class SubclassNaming_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'FILE-SUBCLASS-01';
    }
    
    public function get_name(): string
    {
        return 'Subclass Naming Convention';
    }
    
    public function get_description(): string
    {
        return 'Ensures subclasses end with the same suffix as their parent class';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.php', '*.js'];
    }
    
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip -temp files
        if (str_contains($file_path, '-temp.')) {
            return;
        }

        // Only check files in ./rsx and ./app/RSpade
        $is_rsx = str_contains($file_path, '/rsx/');
        $is_rspade = str_contains($file_path, '/app/RSpade/');

        if (!$is_rsx && !$is_rspade) {
            return;
        }

        // If in app/RSpade, check if it's in an allowed subdirectory
        if ($is_rspade && !$this->is_in_allowed_rspade_directory($file_path)) {
            return;
        }

        // Get class and extends information from metadata
        if (!isset($metadata['class']) || !isset($metadata['extends'])) {
            return;
        }

        $class_name = $metadata['class'];
        $parent_class = $metadata['extends'];

        // Skip if no parent class
        if (empty($parent_class)) {
            return;
        }

        // Get suffix exempt classes from config
        $suffix_exempt_classes = config('rsx.code_quality.suffix_exempt_classes', []);
        
        // Strip FQCN prefix from parent class if present
        $parent_class_simple = ltrim($parent_class, '\\');
        if (str_contains($parent_class_simple, '\\')) {
            $parts = explode('\\', $parent_class_simple);
            $parent_class_simple = end($parts);
        } else {
            $parent_class_simple = $parent_class_simple;
        }

        // Check if parent class is in the suffix exempt list
        // If it is, this child class doesn't need to follow suffix convention
        // But any classes extending THIS class will need to follow convention
        if (in_array($parent_class_simple, $suffix_exempt_classes)) {
            // Don't check suffix for direct children of exempt classes
            return;
        }
        
        // Skip if extending built-in PHP classes or Laravel framework classes
        $built_in_classes = ['Exception', 'RuntimeException', 'InvalidArgumentException', 'LogicException',
                            'BadMethodCallException', 'DomainException', 'LengthException', 'OutOfBoundsException',
                            'OutOfRangeException', 'OverflowException', 'RangeException', 'UnderflowException',
                            'UnexpectedValueException', 'ErrorException', 'Error', 'TypeError', 'ParseError',
                            'AssertionError', 'ArithmeticError', 'DivisionByZeroError',
                            'BaseController', 'Controller', 'ServiceProvider', 'Command', 'Model',
                            'Seeder', 'Factory', 'Migration', 'Request', 'Resource', 'Policy'];
        if (in_array($parent_class_simple, $built_in_classes)) {
            return;
        }

        // Also check if the parent of parent is exempt - for deeper inheritance
        // E.g., if Widget extends Component, and Dynamic_Widget extends Widget
        // Dynamic_Widget should match Widget's suffix
        $parent_of_parent = $this->get_parent_class($parent_class_simple);
        if ($parent_of_parent && !in_array($parent_of_parent, $suffix_exempt_classes)) {
            // Parent's parent is not exempt, so check suffix based on parent
            // Continue with normal suffix checking
        }
        
        
        // Extract the suffix from parent class (use original for suffix extraction)
        $suffix = $this->extract_suffix($parent_class);
        if (empty($suffix)) {
            // This is a violation - parent class name is malformed
            $this->add_violation(
                $file_path,
                0,
                "Cannot extract suffix from parent class '$parent_class' - parent class name may be malformed",
                "class $class_name extends $parent_class",
                $this->get_parent_class_suffix_error($parent_class),
                'high'
            );
            return;
        }

        // Check if child class is abstract based on metadata or class name
        $child_is_abstract = isset($metadata['is_abstract']) ? $metadata['is_abstract'] : str_ends_with($class_name, '_Abstract');

        // CRITICAL LOGIC: If parent suffix contains "Abstract" and child is NOT abstract,
        // remove "Abstract" from the expected suffix
        // Example: AbstractRule (parent) -> *_Rule (child, not *_AbstractRule)
        if (!$child_is_abstract && str_contains($suffix, 'Abstract')) {
            // Remove "Abstract" from suffix for non-abstract children
            $suffix = str_replace('Abstract', '', $suffix);
            // Clean up any double underscores or leading/trailing underscores
            $suffix = trim($suffix, '_');
            if (empty($suffix)) {
                // If suffix becomes empty after removing Abstract, skip validation
                // This handles edge cases like a class named just "Abstract"
                return;
            }
        }

        // Special handling for abstract classes
        if ($child_is_abstract) {
            // This is an abstract class - it should have the parent suffix as second-to-last term
            $result = $this->check_abstract_class_naming($class_name, $suffix);
            if (!$result['valid']) {
                $this->add_violation(
                    $file_path,
                    0,
                    $result['message'],
                    "class $class_name extends $parent_class",
                    $result['remediation'],
                    'high'
                );
            }
            return; // Don't check filename for abstract classes - different rules apply
        }
        
        // Check if child class suffix contains parent suffix (compound suffix issue)
        $child_suffix = $this->extract_child_suffix($class_name);
        $compound_suffix_issue = false;
        if ($child_suffix && $child_suffix !== $suffix && str_ends_with($child_suffix, $suffix)) {
            // Child has compound suffix like ServiceProvider when parent is Provider
            $compound_suffix_issue = true;
        }
        
        // Check 1: Class name must end with appropriate suffix
        $class_name_valid = $this->check_class_name_suffix($class_name, $suffix, $is_rsx);
        
        if (!$class_name_valid || $compound_suffix_issue) {
            // Determine expected suffix based on location and Rsx prefix
            $expected_suffix = $this->get_expected_suffix($suffix, $is_rsx);
            $this->add_violation(
                $file_path,
                0,
                "Class '$class_name' extends '$parent_class' but doesn't end with '_{$expected_suffix}'",
                "class $class_name extends $parent_class",
                $this->get_class_name_remediation($class_name, $parent_class, $suffix, $is_rsx, $compound_suffix_issue, $child_suffix),
                'high'
            );
            return; // Don't check filename if class name is wrong
        }
        
        // Check 2: Filename must follow convention (only if class name is valid)
        $filename = basename($file_path);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        if (!$this->check_filename_convention($filename, $class_name, $suffix, $extension)) {
            $expected_suffix = $this->get_expected_suffix($suffix, $is_rsx);
            $this->add_violation(
                $file_path,
                0,
                "Filename '$filename' doesn't follow naming convention for class '$class_name'",
                "File: $filename",
                $this->get_filename_remediation($class_name, $filename, $expected_suffix, $extension, $is_rsx),
                'medium'
            );
        }
    }
    
    private function extract_suffix(string $parent_class): string
    {
        // Strip FQCN prefix if present
        $parent_class = ltrim($parent_class, '\\');
        if (str_contains($parent_class, '\\')) {
            $parts = explode('\\', $parent_class);
            $parent_class = end($parts);
        }

        // Split by underscores
        $parts = explode('_', $parent_class);

        // If no underscores, check for special cases
        if (count($parts) === 1) {
            // For single-word classes, return the whole name as suffix
            // This includes cases like "AbstractRule", "BaseController", etc.
            return $parent_class;
        }
        
        // Find the last part that is NOT "Abstract"
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ($parts[$i] !== 'Abstract') {
                // If this is a multi-part suffix like ManifestBundle_Abstract
                // we need to get everything from this point backwards until we hit a proper boundary
                
                // Special case: if the parent ends with _Abstract, get everything before it
                if (str_ends_with($parent_class, '_Abstract')) {
                    $pos = strrpos($parent_class, '_Abstract');
                    $before_abstract = substr($parent_class, 0, $pos);
                    // Get the last "word" which could be multi-part
                    $before_parts = explode('_', $before_abstract);
                    
                    // If it's something like Manifest_Bundle_Abstract, suffix is "Bundle"
                    // If it's something like ManifestBundle_Abstract, suffix is "ManifestBundle"
                    if (count($before_parts) > 0) {
                        return $before_parts[count($before_parts) - 1];
                    }
                }
                
                return $parts[$i];
            }
        }
        
        // If we couldn't extract a suffix, return empty string
        // This will trigger a violation in the check method
        return '';
    }
    
    private function check_class_name_suffix(string $class_name, string $suffix, bool $is_rsx): bool
    {
        // Special case: Allow class name to be the same as the suffix
        // e.g., Main extending Main_Abstract
        if ($class_name === $suffix) {
            return true;
        }
        
        // Special handling for Rsx-prefixed suffixes
        if (str_starts_with($suffix, 'Rsx')) {
            // Remove 'Rsx' prefix to get the base suffix
            $base_suffix = substr($suffix, 3);
            
            if ($is_rsx) {
                // In /rsx/ directory: child class should use suffix WITHOUT 'Rsx'
                // e.g., Demo_Bundle extends Rsx_Bundle_Abstract [OK]
                return str_ends_with($class_name, '_' . $base_suffix);
            } else {
                // In /app/RSpade/ directory: child class can use suffix WITH or WITHOUT 'Rsx'
                // e.g., Cool_Rule extends RsxRule [OK]  OR  Cool_RsxRule extends RsxRule [OK]
                return str_ends_with($class_name, '_' . $suffix) || 
                       str_ends_with($class_name, '_' . $base_suffix);
            }
        }
        
        // Standard suffix handling (non-Rsx prefixed)
        // Class name must end with _{Suffix}
        $expected_ending = '_' . $suffix;
        return str_ends_with($class_name, $expected_ending);
    }
    
    private function check_filename_convention(string $filename, string $class_name, string $suffix, string $extension): bool
    {
        $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        
        // Special case: When class name equals suffix (e.g., Main extending Main_Abstract)
        // Allow either exact match (Main.php) or lowercase (main.php)
        if ($class_name === $suffix) {
            if ($filename_without_ext === $class_name || $filename_without_ext === strtolower($class_name)) {
                return true;
            }
        }
        
        // Two valid patterns:
        // 1. Exact match to class name: User_Model.php
        if ($filename_without_ext === $class_name) {
            return true;
        }
        
        // 2. Ends with underscore + lowercase suffix: anything_model.php
        // For Rsx-prefixed suffixes, use the base suffix (without Rsx) in lowercase
        $actual_suffix = str_starts_with($suffix, 'Rsx') ? substr($suffix, 3) : $suffix;
        $lowercase_suffix = strtolower($actual_suffix);
        if (str_ends_with($filename_without_ext, '_' . $lowercase_suffix)) {
            return true;
        }
        
        return false;
    }
    
    private function extract_child_suffix(string $class_name): string
    {
        $parts = explode('_', $class_name);
        if (count($parts) > 1) {
            return $parts[count($parts) - 1];
        }
        return '';
    }
    
    private function get_class_name_remediation(string $class_name, string $parent_class, string $suffix, bool $is_rsx, bool $compound_suffix_issue = false, string $child_suffix = ''): string
    {
        // Check if this is about Abstract suffix handling
        $is_abstract_suffix_issue = str_contains($parent_class, 'Abstract') && !str_ends_with($class_name, '_Abstract');

        // Try to suggest a better class name
        $suggested_class = $this->suggest_class_name($class_name, $suffix, $is_rsx, $compound_suffix_issue, $child_suffix);
        $expected_suffix = $this->get_expected_suffix($suffix, $is_rsx);
        
        // Check if this involves Laravel classes
        $laravel_classes = ['BaseController', 'Controller', 'ServiceProvider', 'Command', 'Model', 
                           'Seeder', 'Factory', 'Migration', 'Request', 'Resource', 'Policy'];
        $is_laravel_involved = false;
        foreach ($laravel_classes as $laravel_class) {
            if (str_contains($parent_class, $laravel_class) || str_contains($class_name, $laravel_class)) {
                $is_laravel_involved = true;
                break;
            }
        }
        
        $compound_suffix_section = '';
        if ($compound_suffix_issue && $child_suffix) {
            $compound_suffix_section = "\n\nCOMPOUND SUFFIX DETECTED:\nYour class uses '$child_suffix' when the parent uses '$suffix'.\nThis creates ambiguity. The suffix should be split with underscores.\nFor example: 'ServiceProvider' should become 'Service_Provider'\n";
        }
        
        $laravel_section = '';
        if ($is_laravel_involved) {
            $laravel_section = "\n\nLARAVEL CLASS DETECTED:\nEven though this involves Laravel framework classes, the RSX naming convention STILL APPLIES.\nRSX enforces its own conventions uniformly across all code.\nLaravel's PascalCase conventions are overridden by RSX's underscore notation.\n";
        }
        
        $abstract_handling_note = '';
        if ($is_abstract_suffix_issue) {
            $abstract_handling_note = "\n\nABSTRACT SUFFIX HANDLING:\n" .
                "When a parent class contains 'Abstract' in its name (like '$parent_class'),\n" .
                "non-abstract child classes should use the suffix WITHOUT 'Abstract'.\n" .
                "This is because concrete implementations should not have 'Abstract' in their names.\n";
        }

        return "CLASS NAMING CONVENTION VIOLATION" . $compound_suffix_section . $laravel_section . $abstract_handling_note . "

Class '$class_name' extends '$parent_class' but doesn't follow RSX naming conventions.

REQUIRED SUFFIX: '$expected_suffix'
All classes extending '$parent_class' must end with '_{$expected_suffix}'

RSX NAMING PATTERN:
RSpade uses underscore notation for class names, separating major conceptual parts:
[OK] CORRECT: User_Downloads_Model, Site_User_Model, Php_ManifestModule
[ERROR] WRONG: UserDownloadsModel, SiteUserModel, PhpManifestModule

SUFFIX CONVENTION:
The suffix (last part after underscore) can be multi-word without underscores to describe the class type:
- 'Model' suffix for database models
- 'Controller' suffix for controllers  
- 'ManifestModule' suffix for manifest module implementations
- 'BundleProcessor' suffix for bundle processors
These multi-word suffixes act as informal type declarations (e.g., Php_ManifestModule indicates a PHP implementation of a manifest module).

RSX PREFIX SPECIAL RULE:
- In /rsx/ directory: If parent class has 'Rsx' prefix (e.g., Rsx_Bundle_Abstract), child uses suffix WITHOUT 'Rsx' (e.g., Demo_Bundle)
- In /app/RSpade/ directory: Child can use suffix WITH or WITHOUT 'Rsx' prefix (e.g., Cool_Rule or Cool_RsxRule extending RsxRule)

SUGGESTED CLASS NAME: $suggested_class

The filename should also end with:
- For files in /rsx: underscore + suffix in lowercase (e.g., user_downloads_model.php)
- For files in /app/RSpade: suffix matching class name case (e.g., User_Downloads_Model.php)

REMEDIATION STEPS:
1. Rename class from '$class_name' to '$suggested_class'
2. Update filename to match convention
3. Update all references to this class throughout the codebase

WHY THIS MATTERS:
- Enables automatic class discovery and loading
- Makes inheritance relationships immediately clear
- Maintains consistency across the entire codebase
- Supports framework introspection capabilities";
    }
    
    private function get_filename_remediation(string $class_name, string $filename, string $suffix, string $extension, bool $is_rsx): string
    {
        // Suggest filenames based on directory
        $lowercase_suggestion = strtolower(str_replace('_', '_', $class_name)) . ".$extension";
        $exact_suggestion = $class_name . ".$extension";
        
        $recommended = $is_rsx ? $lowercase_suggestion : $exact_suggestion;
        
        return "FILENAME CONVENTION VIOLATION

Filename '$filename' doesn't follow naming convention for class '$class_name'

VALID FILENAME PATTERNS:
1. Underscore + lowercase suffix: *_" . strtolower($suffix) . ".$extension
2. Exact class name match: $class_name.$extension

CURRENT FILE: $filename
CURRENT CLASS: $class_name

RECOMMENDED FIX:
- For /rsx directory: $lowercase_suggestion
- For /app/RSpade directory: $exact_suggestion

Note: Both patterns are valid in either directory, but these are the conventions.

EXAMPLES OF VALID FILENAMES:
- user_model.$extension (underscore + lowercase suffix)
- site_user_model.$extension (underscore + lowercase suffix)
- $class_name.$extension (exact match)

WHY THIS MATTERS:
- Enables predictable file discovery
- Maintains consistency with directory conventions
- Supports autoloading mechanisms";
    }
    
    private function suggest_class_name(string $current_name, string $suffix, bool $is_rsx, bool $compound_suffix_issue = false, string $child_suffix = ''): string
    {
        // Handle compound suffix issue specially
        if ($compound_suffix_issue && $child_suffix) {
            // Split the compound suffix with underscores
            // E.g., ServiceProvider -> Service_Provider
            $split_suffix = $this->split_compound_suffix($child_suffix, $suffix);
            if ($split_suffix) {
                // Replace the compound suffix with the split version
                $base = substr($current_name, 0, -strlen($child_suffix));
                return $base . $split_suffix;
            }
        }
        
        // Determine the suffix to use based on location and Rsx prefix
        $target_suffix = $this->get_expected_suffix($suffix, $is_rsx);
        
        // If it already ends with the target suffix (but without underscore), add underscore
        if (str_ends_with($current_name, $target_suffix) && !str_ends_with($current_name, '_' . $target_suffix)) {
            $without_suffix = substr($current_name, 0, -strlen($target_suffix));
            
            // Convert camelCase to snake_case if needed
            $snake_case = preg_replace('/([a-z])([A-Z])/', '$1_$2', $without_suffix);
            return $snake_case . '_' . $target_suffix;
        }
        
        // Otherwise, just append _Suffix
        // Convert the current name to proper underscore notation first
        $snake_case = preg_replace('/([a-z])([A-Z])/', '$1_$2', $current_name);
        return $snake_case . '_' . $target_suffix;
    }
    
    private function get_expected_suffix(string $suffix, bool $is_rsx): string
    {
        // For Rsx-prefixed suffixes in /rsx/ directory, use suffix without 'Rsx'
        if (str_starts_with($suffix, 'Rsx') && $is_rsx) {
            return substr($suffix, 3);
        }
        // Otherwise use the full suffix
        return $suffix;
    }
    
    private function get_parent_class_suffix_error(string $parent_class): string
    {
        return "PARENT CLASS SUFFIX EXTRACTION ERROR

Unable to extract a valid suffix from parent class '$parent_class'.

This is an unexpected situation that indicates either:
1. The parent class name has an unusual format that the rule doesn't handle
2. The naming rule logic needs to be updated to handle this case

EXPECTED PARENT CLASS FORMATS:
- Classes with underscores: Last part after underscore is the suffix (e.g., 'Rsx_Model_Abstract' -> suffix 'Model')
- Classes ending with 'Abstract': Remove 'Abstract' to get suffix (e.g., 'RuleAbstract' -> suffix 'Rule')
- Classes ending with '_Abstract': Part before '_Abstract' is suffix (e.g., 'Model_Abstract' -> suffix 'Model')
- Multi-word suffixes: 'ManifestModule_Abstract' -> suffix 'ManifestModule'

PLEASE REVIEW:
1. Check if the parent class name follows RSX naming conventions
2. If the parent class name is valid but unusual, the SubclassNamingRule may need updating
3. Consider renaming the parent class to follow standard patterns

This violation indicates a framework-level issue that needs attention from the development team.";
    }
    
    private function split_compound_suffix(string $compound, string $parent_suffix): string
    {
        // If compound ends with parent suffix, split it
        if (str_ends_with($compound, $parent_suffix)) {
            $prefix = substr($compound, 0, -strlen($parent_suffix));
            if ($prefix) {
                return $prefix . '_' . $parent_suffix;
            }
        }
        return '';
    }
    
    private function check_abstract_class_naming(string $class_name, string $parent_suffix): array
    {
        // Strip Base prefix from parent suffix if present
        if (str_starts_with($parent_suffix, 'Base')) {
            $parent_suffix = substr($parent_suffix, 4);
        }
        
        // Get the parts of the abstract class name
        $parts = explode('_', $class_name);
        
        // Must have at least 2 parts (Something_Abstract)
        if (count($parts) < 2) {
            return [
                'valid' => false,
                'message' => "Abstract class '$class_name' doesn't follow underscore notation",
                'remediation' => "Abstract classes must use underscore notation and end with '_Abstract'.\nExample: User_Controller_Abstract, Rsx_Model_Abstract"
            ];
        }
        
        // Last part must be 'Abstract'
        if ($parts[count($parts) - 1] !== 'Abstract') {
            return [
                'valid' => false,
                'message' => "Class '$class_name' appears to be abstract but doesn't end with '_Abstract'",
                'remediation' => "All abstract classes must end with '_Abstract'.\nSuggested: " . implode('_', array_slice($parts, 0, -1)) . "_Abstract"
            ];
        }
        
        // If only 2 parts (Something_Abstract), that's valid for root abstracts
        if (count($parts) == 2) {
            return ['valid' => true];
        }
        
        // For multi-part names, check that second-to-last term matches parent suffix
        $second_to_last = $parts[count($parts) - 2];
        if ($second_to_last !== $parent_suffix) {
            // Build suggested name
            $suggested_parts = array_slice($parts, 0, -2); // Everything except last 2 parts
            $suggested_parts[] = $parent_suffix;
            $suggested_parts[] = 'Abstract';
            $suggested_name = implode('_', $suggested_parts);
            
            return [
                'valid' => false,
                'message' => "Abstract class '$class_name' doesn't properly indicate it extends a '$parent_suffix' type",
                'remediation' => "ABSTRACT CLASS NAMING CONVENTION\n\n" .
                                "Abstract classes must:\n" .
                                "1. End with '_Abstract'\n" .
                                "2. Have the parent type as the second-to-last term\n\n" .
                                "Current: $class_name\n" .
                                "Expected pattern: *_{$parent_suffix}_Abstract\n" .
                                "Suggested: $suggested_name\n\n" .
                                "This makes the inheritance chain clear:\n" .
                                "- Parent provides: $parent_suffix functionality\n" .
                                "- This class: Abstract extension of $parent_suffix\n\n" .
                                "Note: If the parent class starts with 'Base' (e.g., BaseController),\n" .
                                "we strip 'Base' to get the actual type (Controller)."
            ];
        }
        
        return ['valid' => true];
    }

    /**
     * Get the parent class of a given class from manifest or other sources
     */
    private function get_parent_class(string $class_name): ?string
    {
        // Try to get from manifest
        try {
            $metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_class($class_name);

            if (!empty($metadata) && isset($metadata['extends'])) {
                $parent = $metadata['extends'];
                // Strip namespace if present
                if (str_contains($parent, '\\')) {
                    $parts = explode('\\', $parent);
                    return end($parts);
                }
                return $parent;
            }
        } catch (\RuntimeException $e) {
            // Class not in manifest (e.g., framework classes like DatabaseSessionHandler)
            // Return null since we can't check parent of external classes
            return null;
        }

        return null;
    }

    /**
     * Check if a file in /app/RSpade/ is in an allowed subdirectory
     * Based on scan_directories configuration
     */
    private function is_in_allowed_rspade_directory(string $file_path): bool
    {
        // Get allowed subdirectories from config
        $scan_directories = config('rsx.manifest.scan_directories', []);

        // Extract allowed RSpade subdirectories
        $allowed_subdirs = [];
        foreach ($scan_directories as $scan_dir) {
            if (str_starts_with($scan_dir, 'app/RSpade/')) {
                $subdir = substr($scan_dir, strlen('app/RSpade/'));
                if ($subdir) {
                    $allowed_subdirs[] = $subdir;
                }
            }
        }

        // Check if file is in any allowed subdirectory
        foreach ($allowed_subdirs as $subdir) {
            if (str_contains($file_path, '/app/RSpade/' . $subdir . '/') ||
                str_contains($file_path, '/app/RSpade/' . $subdir)) {
                return true;
            }
        }

        return false;
    }
}