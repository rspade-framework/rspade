<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Enforces that concrete classes must define static properties that their abstract parent declares without values
 *
 * When an abstract class declares a static property without assigning a value (e.g., public static $enums;),
 * all concrete subclasses that directly extend this abstract class MUST define that property.
 * This ensures critical static properties are not accidentally omitted in implementations.
 */
class RequireStaticProperty_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-STATIC-PROP-01';
    }

    public function get_name(): string
    {
        return 'RequireStaticProperty Enforcement';
    }

    public function get_description(): string
    {
        return 'Ensures concrete classes define static properties required by their abstract parent classes';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * This rule runs during manifest scan to check static property requirements
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Main check method - processes all PHP classes from manifest
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only process during manifest-time when we have all files
        static $already_run = false;
        if ($already_run) {
            return;
        }

        // On the first PHP file, process all files
        if (!empty($metadata) && $metadata['extension'] === 'php') {
            $this->process_all_files();
            $already_run = true;
        }
    }

    private function process_all_files(): void
    {
        // Get all PHP classes from manifest
        $files = Manifest::get_all();

        // First pass: Find all abstract classes with static properties without values
        $abstract_requirements = [];

        foreach ($files as $file_path => $file_metadata) {
            // Skip non-PHP files
            if (($file_metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Skip files without classes
            if (empty($file_metadata['class'])) {
                continue;
            }

            // Only process abstract classes
            if (empty($file_metadata['abstract']) || !$file_metadata['abstract']) {
                continue;
            }

            $class_name = $file_metadata['class'];

            // Check for static properties without values
            if (!empty($file_metadata['static_properties'])) {
                foreach ($file_metadata['static_properties'] as $property_name => $property_info) {
                    // If property has no value, it's a requirement for subclasses
                    if (!$property_info['has_value']) {
                        if (!isset($abstract_requirements[$class_name])) {
                            $abstract_requirements[$class_name] = [];
                        }
                        $abstract_requirements[$class_name][$property_name] = [
                            'comment' => $property_info['comment'],
                            'visibility' => $property_info['visibility'],
                            'file' => $file_path,
                        ];
                    }
                }
            }
        }

        // Second pass: Check concrete classes that directly extend abstract classes
        foreach ($files as $file_path => $file_metadata) {
            // Skip non-PHP files
            if (($file_metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Skip files without classes
            if (empty($file_metadata['class'])) {
                continue;
            }

            // Skip abstract classes - we only check concrete classes
            if (!empty($file_metadata['abstract']) && $file_metadata['abstract']) {
                continue;
            }

            $class_name = $file_metadata['class'];

            // Check if this class extends an abstract class
            if (empty($file_metadata['extends'])) {
                continue;
            }

            $parent_class = $file_metadata['extends'];

            // Check if the parent class is abstract and has requirements
            if (!isset($abstract_requirements[$parent_class])) {
                continue;
            }

            // Get the parent metadata to verify it's actually abstract
            $parent_metadata = Manifest::php_get_metadata_by_class($parent_class);
            if (!$parent_metadata || empty($parent_metadata['abstract'])) {
                continue;
            }

            // Check each required static property
            foreach ($abstract_requirements[$parent_class] as $property_name => $requirement) {
                // Check if the concrete class defines this static property
                $property_defined = false;

                // Check in the concrete class's static properties
                if (!empty($file_metadata['static_properties']) && isset($file_metadata['static_properties'][$property_name])) {
                    $property_defined = true;
                }

                // If property is not defined, report violation
                if (!$property_defined) {
                    $line = 0;
                    $code_snippet = '';

                    // Try to find the class declaration line
                    if (file_exists($file_path)) {
                        $file_contents = file_get_contents($file_path);
                        if (preg_match('/class\s+' . preg_quote($class_name, '/') . '\s/m', $file_contents, $matches, PREG_OFFSET_CAPTURE)) {
                            $offset = $matches[0][1];
                            $line = substr_count(substr($file_contents, 0, $offset), "\n") + 1;
                            $lines = explode("\n", $file_contents);
                            if ($line > 0 && $line <= count($lines)) {
                                $code_snippet = trim($lines[$line - 1]);
                            }
                        }
                    }

                    // Build the error message
                    $message = "Concrete class {$class_name} must define static property \${$property_name} as required by abstract parent class {$parent_class}. ";

                    // Add comment documentation if available
                    if ($requirement['comment'] !== true && !empty($requirement['comment'])) {
                        $message .= "Property documentation: \"{$requirement['comment']}\". ";
                    }

                    // Build the suggestion
                    $suggestion = "Add '{$requirement['visibility']} static \${$property_name};' to class {$class_name}. ";
                    $suggestion .= "Alternatively, if this property should not be required for all subclasses, ";
                    $suggestion .= "modify the parent class {$parent_class} to assign a default value: ";
                    $suggestion .= "'{$requirement['visibility']} static \${$property_name} = null;' to exclude subclasses from this requirement.";

                    $this->add_violation(
                        $file_path,
                        $line,
                        $message,
                        $code_snippet,
                        $suggestion,
                        'critical'
                    );
                }
            }
        }
    }
}