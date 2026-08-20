<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * InstanceMethodsRule - Enforces static-only classes unless marked Instantiatable
 *
 * By default, classes should use static methods only (namespace pattern).
 * Classes can have instance methods only if:
 * 1. The class itself has #[Instantiatable] attribute (PHP) or @Instantiatable decorator (JS)
 * 2. Any ancestor class has the Instantiatable attribute/decorator
 *
 * This enforces the framework's static-first architecture while allowing
 * instance methods for specific use cases (UI components, ORM models, etc.)
 */
class InstanceMethods_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'MANIFEST-INST-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Instance Methods Validation';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Enforces static-only classes unless class or ancestor has Instantiatable attribute/decorator';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php', '*.js'];
    }

    /**
     * This rule runs during manifest scan
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
     * Check the manifest for instance method violations
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

        // Check PHP classes
        $this->check_php_classes($files);

        // Check JavaScript classes
        $this->check_javascript_classes($files);

        // Check JS model monoprogenic enforcement
        $this->check_js_model_monoprogenic($files);
    }

    /**
     * HACK #3 - JS Model Monoprogenic Enforcement
     *
     * Ensures that JS model classes cannot be extended. Only Base_ stubs can be extended,
     * not the PHP model class names directly. This prevents broken inheritance chains.
     *
     * Monoprogenic = can only produce one level of offspring
     */
    private function check_js_model_monoprogenic(array $files): void
    {
        foreach ($files as $file => $metadata) {
            // Skip if not a JavaScript class
            if (!isset($metadata['class']) || ($metadata['extension'] ?? '') !== 'js') {
                continue;
            }

            // Skip if no parent class
            $parent_class = $metadata['extends'] ?? null;
            if (!$parent_class) {
                continue;
            }

            // Check if parent is a PHP model class name (not Base_)
            if (\App\RSpade\Core\Manifest\Manifest::is_php_model_class($parent_class)) {
                $this->add_violation(
                    $file,
                    1,
                    "Class '{$metadata['class']}' extends PHP model '{$parent_class}' directly. " .
                    "JS model classes are monoprogenic - they cannot be extended further.",
                    "extends {$parent_class}",
                    "JavaScript model classes must extend the Base_ stub instead:\n" .
                    "  - Change: class {$metadata['class']} extends Base_{$parent_class}\n" .
                    "  - The Base_ stub is auto-generated and properly inherits from Rsx_Js_Model",
                    'critical'
                );
            }
        }
    }

    /**
     * Check PHP classes for instance method violations
     */
    private function check_php_classes(array $files): void
    {
        foreach ($files as $file => $metadata) {
            // Skip if not a PHP class
            if (!isset($metadata['class']) || !isset($metadata['fqcn'])) {
                continue;
            }

            // Skip if no public instance methods
            if (empty($metadata['public_instance_methods'])) {
                continue;
            }

            // Check if this class is allowed to have instance methods
            if ($this->is_php_class_instantiatable($metadata['fqcn'], $files)) {
                continue;
            }

            // Found violation - class has instance methods but is not instantiatable
            // Build remediation text based on whether class has parent
            $parent_class = $metadata['extends'] ?? null;
            $is_framework_file = str_starts_with($file, 'app/RSpade/');

            if ($parent_class) {
                $suggestion = "RSpade discourages creating classes solely to perform operations (utility/service pattern). " .
                    "Classes should be static unless each instance represents a distinct entity in the system. " .
                    "Recommended fixes:\n" .
                    "1. Convert all methods to static if this is a utility/service class\n" .
                    "2. If instances truly represent distinct objects (components, models, etc.):\n" .
                    "   - STRONGLY RECOMMENDED: Add #[Instantiatable] to the parent class '{$parent_class}'\n" .
                    "   - Only add #[Instantiatable] to this class if '{$parent_class}' should NOT be instantiatable";

                // Add note for framework files
                if ($is_framework_file) {
                    $suggestion .= "\n   - NOTE: If '{$parent_class}' is a Laravel/vendor class, add #[Instantiatable] to THIS class instead";
                }
            } else {
                $suggestion = "RSpade discourages creating classes solely to perform operations (utility/service pattern). " .
                    "Classes should be static unless each instance represents a distinct entity in the system. " .
                    "Recommended fixes:\n" .
                    "1. Convert all methods to static if this is a utility/service class\n" .
                    "2. If instances truly represent distinct objects (components, models, etc.):\n" .
                    "   - Add #[Instantiatable] to this class";
            }

            foreach ($metadata['public_instance_methods'] as $method_name => $method_info) {
                $line = $method_info['line'] ?? 1;
                $this->add_violation(
                    $file,
                    $line,
                    "Instance method '{$method_name}' found in class '{$metadata['class']}'. RSpade classes should use static methods unless instances represent distinct objects (like UI components or ORM records).",
                    "public function {$method_name}(...)",
                    $suggestion,
                    'medium'
                );
            }
        }
    }

    /**
     * Check JavaScript classes for instance method violations
     */
    private function check_javascript_classes(array $files): void
    {
        foreach ($files as $file => $metadata) {
            // Skip if not a JavaScript class
            if (!isset($metadata['class']) || ($metadata['extension'] ?? '') !== 'js') {
                continue;
            }

            // Skip if no public instance methods
            if (empty($metadata['public_instance_methods'])) {
                continue;
            }

            // Check if this class is allowed to have instance methods
            if ($this->is_js_class_instantiatable($metadata['class'], $files)) {
                continue;
            }

            // Found violation - class has instance methods but is not instantiatable
            // Build remediation text based on whether class has parent
            $parent_class = $metadata['extends'] ?? null;

            if ($parent_class) {
                $suggestion = "RSpade discourages creating classes solely to perform operations (utility/service pattern). " .
                    "Classes should be static unless each instance represents a distinct entity in the system. " .
                    "Recommended fixes:\n" .
                    "1. Convert all methods to static if this is a utility/service class\n" .
                    "2. If instances truly represent distinct objects (components, models, etc.):\n" .
                    "   - STRONGLY RECOMMENDED: Add @Instantiatable to the parent class '{$parent_class}' JSDoc\n" .
                    "   - Only add @Instantiatable to this class's JSDoc if '{$parent_class}' should NOT be instantiatable";
            } else {
                $suggestion = "RSpade discourages creating classes solely to perform operations (utility/service pattern). " .
                    "Classes should be static unless each instance represents a distinct entity in the system. " .
                    "Recommended fixes:\n" .
                    "1. Convert all methods to static if this is a utility/service class\n" .
                    "2. If instances truly represent distinct objects (components, models, etc.):\n" .
                    "   - Add @Instantiatable to this class's JSDoc";
            }

            foreach ($metadata['public_instance_methods'] as $method_name => $method_info) {
                $line = $method_info['line'] ?? 1;
                $this->add_violation(
                    $file,
                    $line,
                    "Instance method '{$method_name}' found in class '{$metadata['class']}'. RSpade classes should use static methods unless instances represent distinct objects (like UI components or ORM records).",
                    "{$method_name}(...)",
                    $suggestion,
                    'medium'
                );
            }
        }
    }

    /**
     * Check if a PHP class is instantiatable (has Instantiatable attribute in ancestry)
     */
    private function is_php_class_instantiatable(string $fqcn, array $files): bool
    {
        // Check the class itself
        try {
            $metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_fqcn($fqcn);
            if (isset($metadata['attributes']['Instantiatable'])) {
                return true;
            }

            // Walk up the parent chain using manifest data
            $current_fqcn = $fqcn;
            $checked = []; // Prevent infinite loops

            while (true) {
                if (isset($checked[$current_fqcn])) {
                    break; // Already checked this class
                }
                $checked[$current_fqcn] = true;

                try {
                    $current_metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_fqcn($current_fqcn);
                    $parent_class = $current_metadata['extends'] ?? null;

                    if (!$parent_class) {
                        break; // No more parents
                    }

                    // Get parent FQCN if we only have simple name
                    $parent_fqcn = $current_metadata['extends_fqcn'] ?? $parent_class;

                    // Check if parent has Instantiatable
                    try {
                        $parent_metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_fqcn($parent_fqcn);
                        if (isset($parent_metadata['attributes']['Instantiatable'])) {
                            return true;
                        }
                        $current_fqcn = $parent_fqcn; // Continue up the chain
                    } catch (\Exception $e) {
                        // Parent not in manifest, stop here
                        break;
                    }
                } catch (\Exception $e) {
                    // Can't get metadata, stop
                    break;
                }
            }
        } catch (\Exception $e) {
            // Class not in manifest
            return false;
        }

        return false;
    }

    /**
     * Check if a JavaScript class is instantiatable (has Instantiatable decorator in ancestry)
     */
    private function is_js_class_instantiatable(string $class_name, array $files): bool
    {
        // HACK #2 - JS Model instantiatable bypass: If this is a PHP model class name or
        // a Base_ stub for a PHP model, it's automatically instantiatable because model
        // stubs are generated with @Instantiatable during bundle compilation.
        $model_check_name = $class_name;
        if (str_starts_with($class_name, 'Base_')) {
            $model_check_name = substr($class_name, 5); // Remove "Base_" prefix
        }
        if (\App\RSpade\Core\Manifest\Manifest::is_php_model_class($model_check_name)) {
            return true;
        }

        // Find the class metadata
        $class_metadata = null;
        foreach ($files as $file => $metadata) {
            if (($metadata['class'] ?? '') === $class_name && ($metadata['extension'] ?? '') === 'js') {
                $class_metadata = $metadata;
                break;
            }
        }

        if (!$class_metadata) {
            return false;
        }

        // Check the class itself
        // Decorators are in compact format: [[name, [args]], ...]
        if (isset($class_metadata['decorators'])) {
            foreach ($class_metadata['decorators'] as $decorator) {
                if (($decorator[0] ?? '') === 'Instantiatable') {
                    return true;
                }
            }
        }

        // Walk up the parent chain using manifest data
        $current_class = $class_name;
        $checked = []; // Prevent infinite loops

        while (true) {
            if (isset($checked[$current_class])) {
                break; // Already checked this class
            }
            $checked[$current_class] = true;

            // Find current class metadata
            $current_metadata = null;
            foreach ($files as $file => $metadata) {
                if (($metadata['class'] ?? '') === $current_class && ($metadata['extension'] ?? '') === 'js') {
                    $current_metadata = $metadata;
                    break;
                }
            }

            if (!$current_metadata) {
                break; // Class not found
            }

            $parent_class = $current_metadata['extends'] ?? null;
            if (!$parent_class) {
                break; // No more parents
            }

            // Check if parent has Instantiatable
            foreach ($files as $file => $metadata) {
                if (($metadata['class'] ?? '') === $parent_class && ($metadata['extension'] ?? '') === 'js') {
                    // Check parent decorators in compact format
                    if (isset($metadata['decorators'])) {
                        foreach ($metadata['decorators'] as $decorator) {
                            if (($decorator[0] ?? '') === 'Instantiatable') {
                                return true;
                            }
                        }
                    }
                    $current_class = $parent_class; // Continue up the chain
                    break;
                }
            }
        }

        return false;
    }
}
