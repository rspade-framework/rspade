<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * MonoprogenicRule - Enforces single generation of concrete descendants
 *
 * Classes marked with #[Monoprogenic] attribute can only have one generation
 * of concrete (non-abstract) children. This prevents inheritance ambiguity
 * in reflection-based operations.
 *
 * Valid patterns:
 * - Monoprogenic (abstract) -> Child (concrete)
 * - Monoprogenic (abstract) -> Child (abstract) -> Grandchild (concrete)
 *
 * Invalid patterns:
 * - Monoprogenic (abstract) -> Child (concrete) -> Grandchild (concrete)
 *
 * This rule prevents situations where concrete classes extend other concrete
 * classes in a Monoprogenic hierarchy, which would create ambiguity about
 * which class should handle operations discovered through reflection (such as
 * routing, command discovery, or other framework operations).
 */
class Monoprogenic_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'MANIFEST-MONO-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Monoprogenic Inheritance Validation';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Enforces that #[Monoprogenic] classes only have one generation of concrete descendants';
    }

    /**
     * Get file patterns this rule applies to
     *
     * Note: This returns PHP files, but the rule actually checks relationships
     * across the entire manifest, not individual files
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * This rule runs during manifest scan to validate inheritance patterns
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
     * Check the manifest for Monoprogenic violations
     *
     * This method is called once per file during manifest scan, but we only
     * need to check the entire manifest once. We use a static flag to ensure
     * the check only runs once per manifest build.
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

        // Step 1: Find all classes with Monoprogenic attribute
        $monoprogenic_classes = [];
        foreach ($files as $file => $file_metadata) {
            // Skip .upstream files - they are framework files overridden by rsx/
            $extension = $file_metadata['extension'] ?? '';
            if ($extension === 'php.upstream') {
                continue;
            }

            if (isset($file_metadata['attributes']['Monoprogenic'])) {
                $fqcn = $file_metadata['fqcn'] ?? null;
                if ($fqcn) {
                    $monoprogenic_classes[$fqcn] = $file;
                }
            }
        }

        if (empty($monoprogenic_classes)) {
            return;
        }

        // Step 2: For each Monoprogenic class, check all its descendants
        foreach ($monoprogenic_classes as $monoprogenic_class => $monoprogenic_file) {
            $this->check_descendants($monoprogenic_class, $monoprogenic_file, $files);
        }
    }

    /**
     * Check all descendants of a Monoprogenic class for violations
     */
    private function check_descendants(string $monoprogenic_class, string $monoprogenic_file, array $files): void
    {
        // Find all classes that extend from any Monoprogenic class
        foreach ($files as $file => $metadata) {
            // Skip .upstream files - they are framework files overridden by rsx/
            $extension = $metadata['extension'] ?? '';
            if ($extension === 'php.upstream') {
                continue;
            }

            $fqcn = $metadata['fqcn'] ?? null;
            if (!$fqcn || !class_exists($fqcn)) {
                continue;
            }

            // Check if this class is a subclass of the Monoprogenic class
            if (!is_subclass_of($fqcn, $monoprogenic_class)) {
                continue;
            }

            // Check if the class is abstract
            if (\App\RSpade\Core\Manifest\Manifest::php_is_abstract($fqcn)) {
                continue; // Abstract classes are allowed at any level
            }

            // This is a concrete class that extends from Monoprogenic
            // Check if its direct parent is also concrete (violation)
            // Get the parent class name from manifest data
            $class_metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_fqcn($fqcn);
            $parent_class_name = $class_metadata['extends'] ?? null;

            if (!$parent_class_name) {
                continue;
            }

            // If parent is the Monoprogenic class itself, that's fine
            if ($parent_class_name === $monoprogenic_class) {
                continue;
            }

            // Check if parent is abstract
            if (!\App\RSpade\Core\Manifest\Manifest::php_is_abstract($parent_class_name)) {
                // VIOLATION: Concrete class extending another concrete class in Monoprogenic hierarchy
                $line_number = $this->find_class_line($file, $metadata['class'] ?? '');

                $this->add_violation(
                    $file,
                    $line_number,
                    sprintf(
                        "Class '%s' violates Monoprogenic inheritance pattern.\n\n" .
                        "This class is a concrete (non-abstract) class that extends another concrete class '%s'.\n" .
                        "The base class '%s' has the #[Monoprogenic] attribute, which means concrete descendants " .
                        "can only extend from it through abstract intermediary classes.\n\n" .
                        "Monoprogenic classes are used in reflection-based operations (like route discovery or " .
                        "command registration). Having concrete classes extend other concrete classes creates " .
                        "ambiguity about which class should handle the discovered operations.",
                        $metadata['class'] ?? $fqcn,
                        $this->get_short_name($parent_class_name),
                        $this->get_short_name($monoprogenic_class)
                    ),
                    "class " . ($metadata['class'] ?? '') . " extends",
                    sprintf(
                        "To fix this violation, choose one of these approaches:\n" .
                        "1. Make the parent class '%s' abstract\n" .
                        "2. Make this class '%s' extend directly from an abstract class\n" .
                        "3. Remove the concrete inheritance chain by not extending '%s'",
                        $this->get_short_name($parent_class_name),
                        $metadata['class'] ?? '',
                        $this->get_short_name($parent_class_name)
                    ),
                    'critical'
                );
            }
        }
    }

    /**
     * Find the line number where a class is declared
     */
    private function find_class_line(string $file_path, string $class_name): int
    {
        $absolute_path = base_path($file_path);
        if (!file_exists($absolute_path)) {
            return 1;
        }

        $contents = file_get_contents($absolute_path);
        $lines = explode("\n", $contents);

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:abstract\s+)?class\s+' . preg_quote($class_name) . '\b/', $line)) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * Get short class name from FQCN
     */
    private function get_short_name(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}