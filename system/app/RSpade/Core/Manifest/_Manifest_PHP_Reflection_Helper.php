<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;

/**
 * _Manifest_PHP_Reflection_Helper - PHP class reflection operations
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_PHP_Reflection_Helper
{
    /**
    * Find a PHP class by name
    */
    public static function php_find_class(string $class_name): string
    {
        Manifest::init();

        if (!isset(Manifest::$data['data']['php_classes'][$class_name])) {
            throw new \RuntimeException("PHP class not found in manifest: {$class_name}");
        }

        return Manifest::$data['data']['php_classes'][$class_name];
    }

    /**
    * Find a PHP class by fully qualified name
    */
    public static function find_php_fqcn(string $fqcn): string
    {
        $files = Manifest::get_all();

        foreach ($files as $file => $metadata) {
            if (isset($metadata['fqcn']) && $metadata['fqcn'] === $fqcn) {
                return $file;
            }
        }

        throw new \RuntimeException("PHP class with FQCN not found in manifest: {$fqcn}");
    }

    /**
    * Get manifest metadata by PHP class name
    * This is a convenience method that finds the class and returns its metadata
    */
    public static function php_get_metadata_by_class(string $class_name): array
    {
        $file = Manifest::php_find_class($class_name);

        return Manifest::get_file($file);
    }

    /**
    * Get manifest metadata by PHP fully qualified class name
    * This is a convenience method that finds the class and returns its metadata
    */
    public static function php_get_metadata_by_fqcn(string $fqcn): array
    {
        $file = Manifest::find_php_fqcn($fqcn);

        return Manifest::get_file($file);
    }

    /**
    * Get all classes extending a parent (filters out abstract classes by default)
    * Returns array of class metadata indexed by class name
    */
    public static function php_get_extending(string $parentclass): array
    {
        // Get concrete subclasses only (abstract filtered out by default)
        $subclasses = self::php_get_subclasses_of($parentclass, true);

        $classpile = [];
        foreach ($subclasses as $classname) {
            // Get the file path from php_classes index, then get metadata from files
            if (isset(Manifest::$data['data']['php_classes'][$classname])) {
                $file_path = Manifest::$data['data']['php_classes'][$classname];
                $classpile[$classname] = Manifest::$data['data']['files'][$file_path];
            }
        }

        return $classpile;
    }

    /**
    * Check if a class is a subclass of another by traversing the inheritance chain
    *
    * @param string $subclass The child class name (simple name, not FQCN)
    * @param string $superclass The parent class name to check for (simple name, not FQCN)
    * @return bool True if subclass extends superclass (directly or indirectly), false otherwise
    */
    public static function php_is_subclass_of(string $subclass, string $superclass): bool
    {
        // Strip namespace if FQCN was passed (contains backslash)
        if (strpos($subclass, '\\') !== false) {
            // Get the class name after the last backslash
            $parts = explode('\\', $subclass);
            $subclass = end($parts);
        }

        if (strpos($superclass, '\\') !== false) {
            // Get the class name after the last backslash
            $parts = explode('\\', $superclass);
            $superclass = end($parts);
        }

        $files = Manifest::get_all();
        $current_class = $subclass;
        $visited = []; // Prevent infinite loops in case of circular inheritance

        while ($current_class) {
            // Prevent infinite loops
            if (in_array($current_class, $visited)) {
                return false;
            }

            $visited[] = $current_class;

            // Find the current class in the manifest
            if (!isset(Manifest::$data['data']['php_classes'][$current_class])) {
                return false;
            }

            // Get file metadata
            $file_path = Manifest::$data['data']['php_classes'][$current_class];
            $metadata = Manifest::$data['data']['files'][$file_path];

            if (empty($metadata['extends'])) {
                return false;
            }

            if ($metadata['extends'] == $superclass) {
                return true;
            }

            // TODO: Maybe use native reflection if base class does not exist in the manifest past this point>

            // Move up the chain to the parent class
            $current_class = $metadata['extends'];
        }

        return false;
    }

    /**
    * Check if a PHP class is abstract
    *
    * @param string $class_name The class name to check (simple name, not FQCN)
    * @return bool True if the class is abstract, false if concrete or not found
    */
    public static function php_is_abstract(string $class_name): bool
    {
        // Ensure manifest is loaded
        Manifest::init();

        // Strip namespace if FQCN was passed
        if (strpos($class_name, '\\') !== false) {
            $parts = explode('\\', $class_name);
            $class_name = end($parts);
        }

        // Return false if class not in manifest
        if (!isset(Manifest::$data['data']['php_classes'][$class_name])) {
            return false;
        }

        // Get file metadata and check the abstract property
        $file_path = Manifest::$data['data']['php_classes'][$class_name];
        $metadata = Manifest::$data['data']['files'][$file_path];

        return $metadata['abstract'] ?? false;
    }

    /**
    * Get the full inheritance lineage (ancestry) of a PHP class
    *
    * Returns an array of parent class names from immediate parent to top-level ancestor.
    * Example: For class C extends B extends A, returns ['B', 'A']
    *
    * @param string $class_name The class name (FQCN or simple name)
    * @return array Array of parent class simple names in order from immediate parent to root
    */
    public static function php_get_lineage(string $class_name): array
    {
        // Ensure manifest is loaded
        Manifest::init();

        // Strip namespace if FQCN was passed
        if (strpos($class_name, '\\') !== false) {
            $parts = explode('\\', $class_name);
            $class_name = end($parts);
        }

        $lineage = [];
        $current_class = $class_name;
        $visited = []; // Prevent infinite loops

        while ($current_class) {
            // Prevent infinite loops in circular inheritance
            if (in_array($current_class, $visited)) {
                break;
            }

            $visited[] = $current_class;

            // Find current class in manifest
            if (!isset(Manifest::$data['data']['php_classes'][$current_class])) {
                break;
            }

            $file_path = Manifest::$data['data']['php_classes'][$current_class];
            $metadata = Manifest::$data['data']['files'][$file_path];

            if (!$metadata) {
                break;
            }

            $extends = $metadata['extends'] ?? null;

            if (!$extends) {
                break;
            }

            // Add parent to lineage (simple name)
            $lineage[] = $extends;

            // Move up the chain
            $current_class = $extends;
        }

        return $lineage;
    }

    /**
    * Get all direct subclasses of a given PHP class using the pre-built index
    *
    * @param string $class_name The parent class name (simple name, not FQCN)
    * @param bool $concrete_only Whether to filter out abstract classes (default: true)
    * @return array Array of subclass names, or empty array if class not found or has no children
    */
    public static function php_get_subclasses_of(string $class_name, bool $concrete_only = true): array
    {
        // Strip namespace if FQCN was passed
        if (strpos($class_name, '\\') !== false) {
            $parts = explode('\\', $class_name);
            $class_name = end($parts);
        }

        // Return empty array if class not in subclass_index
        if (!isset(Manifest::$data['data']['php_subclass_index'][$class_name])) {
            return [];
        }

        $subclasses = Manifest::$data['data']['php_subclass_index'][$class_name];

        // If not filtering for concrete classes, return all subclasses
        if (!$concrete_only) {
            return $subclasses;
        }

        // Filter out abstract classes
        $concrete_subclasses = [];
        foreach ($subclasses as $subclass) {
            // Get file path and metadata
            if (!isset(Manifest::$data['data']['php_classes'][$subclass])) {
                shouldnt_happen(
                    "Fatal: PHP class '{$subclass}' found in subclass index but not in php_classes.\n" .
"This indicates a major data integrity issue with the manifest.\n" .
'Try running: php artisan rsx:manifest:build --clean'
                );
            }

            $file_path = Manifest::$data['data']['php_classes'][$subclass];
            $metadata = Manifest::$data['data']['files'][$file_path];

            // Check if abstract property exists in manifest data
            if (!isset($metadata['abstract'])) {
                shouldnt_happen(
                    "Fatal: Abstract property missing for PHP class '{$subclass}' in manifest data.\n" .
"This indicates a major data integrity issue with the manifest.\n" .
'Try running: php artisan rsx:manifest:build --clean'
                );
            }

            // Include only non-abstract classes
            if (!$metadata['abstract']) {
                $concrete_subclasses[] = $subclass;
            }
        }

        return $concrete_subclasses;
    }

    /**
    * Check if a class name corresponds to a PHP model class (exists in models index)
    *
    * This is used by the JS model system to recognize PHP model class names that may
    * appear in JS inheritance chains but don't exist as JS classes in the manifest.
    * PHP models like "Project_Model" generate JS stubs during bundle compilation.
    *
    * @param string $class_name The class name to check
    * @return bool True if this is a PHP model class name
    */
    public static function is_php_model_class(string $class_name): bool
    {
        return isset(Manifest::$data['data']['models'][$class_name]);
    }

    /**
     * Normalize class name to simple name (strip namespace qualifiers)
     *
     * Since RSX enforces unique simple class names across the codebase,
     * we normalize all class references to simple names for consistent
     * comparison and storage. FQCNs are only needed at actual class loading time.
     *
     * Examples:
     *   \Rsx\Lib\DataGrid → DataGrid
     *   Rsx\Lib\DataGrid → DataGrid
     *   DataGrid → DataGrid
     *
     * @param string $class_name Class name in any format (with or without namespace)
     * @return string Simple class name without namespace
     */
    public static function _normalize_class_name(string $class_name): string
    {
        // Strip leading backslash
        $class_name = ltrim($class_name, '\\');

        // Extract just the class name (last part after final backslash)
        $parts = explode('\\', $class_name);

        return end($parts);
    }

    /**
    * Load a class and all its parent classes from manifest data
    *
    * This utility method ensures a class and its entire parent hierarchy
    * are loaded before doing reflection or other operations.
    * Classes are loaded in dependency order (parents first).
    * Used by stub generators and reflection extraction.
    *
    * @param string $fqcn Fully qualified class name to load
    * @param array $manifest_data The manifest data array
    * @return void
    * @throws \RuntimeException if class or parent cannot be loaded
    */
    public static function _load_class_hierarchy(string $fqcn, array $manifest_data): void
    {
        // Already loaded? Nothing to do
        if (class_exists($fqcn, false) || interface_exists($fqcn, false) || trait_exists($fqcn, false)) {
            return;
        }

        // Build list of classes to load in hierarchy order
        $hierarchy = [];
        $current_fqcn = $fqcn;

        while ($current_fqcn) {
            // Find this class in the manifest
            $found = false;
            foreach ($manifest_data['data']['files'] as $file_path => $metadata) {
                if (isset($metadata['fqcn']) && $metadata['fqcn'] === $current_fqcn) {
                    // Add to front of hierarchy (parents first)
                    array_unshift($hierarchy, [
                        'fqcn' => $current_fqcn,
                        'file' => $file_path,
                        'extends' => $metadata['extends'] ?? null,
                    ]);
                    $found = true;

                    // Move to parent class
                    // extends is always stored as simple class name (normalized by parser)
                    if (isset($metadata['extends'])) {
                        $parent_simple_name = Manifest::_normalize_class_name($metadata['extends']);

                        // Look for this class by simple name in manifest
                        // Only check PHP files (those with fqcn key)
                        $parent_fqcn = null;
                        foreach ($manifest_data['data']['files'] as $parent_file => $parent_meta) {
                            if (isset($parent_meta['class']) && $parent_meta['class'] === $parent_simple_name && isset($parent_meta['fqcn'])) {
                                $parent_fqcn = $parent_meta['fqcn'];
                                break;
                            }
                        }
                        $current_fqcn = $parent_fqcn;
                    } else {
                        $current_fqcn = null;
                    }
                    break;
                }
            }

            if (!$found && $current_fqcn) {
                // Check if it's a built-in or framework class that can be autoloaded
                // Try to autoload it
                if (class_exists($current_fqcn, true) ||
                interface_exists($current_fqcn, true) ||
                trait_exists($current_fqcn, true)) {
                    // Framework or built-in class, stop here
                    break;
                }

                // If still not found, it's a fatal error
                shouldnt_happen("Parent class {$current_fqcn} not found in manifest or autoloader for {$fqcn}");
            }
        }

        // Load classes in order (parents first)
        foreach ($hierarchy as $class_info) {
            if (!class_exists($class_info['fqcn'], false) &&
            !interface_exists($class_info['fqcn'], false) &&
            !trait_exists($class_info['fqcn'], false)) {
                $full_path = base_path($class_info['file']);
                if (!file_exists($full_path)) {
                    shouldnt_happen("Class file not found: {$full_path} for {$class_info['fqcn']}");
                }

                // This includes the file.
                // A side effect of this include is this line also lints the file.  Past this point, we can assume all php
                // files (well, class files) have valid syntax.
                include_once $full_path;

                // Verify the class loaded successfully
                if (!class_exists($class_info['fqcn'], false) &&
                !interface_exists($class_info['fqcn'], false) &&
                !trait_exists($class_info['fqcn'], false)) {
                    shouldnt_happen("Failed to load class {$class_info['fqcn']} from {$full_path}");
                }
            }
        }
    }

}
