<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;

/**
 * _Manifest_JS_Reflection_Helper - JavaScript class reflection operations
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_JS_Reflection_Helper
{
    /**
    * Find a JavaScript class
    */
    public static function js_find_class(string $class_name): string
    {
        Manifest::init();

        if (!isset(Manifest::$data['data']['js_classes'][$class_name])) {
            throw new \RuntimeException("JavaScript class not found in manifest: {$class_name}");
        }

        return Manifest::$data['data']['js_classes'][$class_name];
    }

    /**
    * Get all JavaScript classes extending a parent
    * Returns array of class metadata indexed by class name
    */
    public static function js_get_extending(string $parentclass): array
    {
        // Get all subclasses (JavaScript has no abstract concept)
        $subclasses = self::js_get_subclasses_of($parentclass);

        $classpile = [];
        foreach ($subclasses as $classname) {
            // Get the file path from js_classes index, then get metadata from files
            if (isset(Manifest::$data['data']['js_classes'][$classname])) {
                $file_path = Manifest::$data['data']['js_classes'][$classname];
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
    public static function js_is_subclass_of(string $subclass, string $superclass): bool
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

            // HACK #1 - JS Model shortcut: When checking against Rsx_Js_Model, if we encounter
            // a PHP model class name (like "Project_Model"), we know it's a model that will have
            // a generated Base_ stub extending Rsx_Js_Model. Return true immediately.
            if ($superclass === 'Rsx_Js_Model' && Manifest::is_php_model_class($current_class)) {
                return true;
            }

            // Find the current class in the manifest
            if (!isset(Manifest::$data['data']['js_classes'][$current_class])) {
                return false;
            }

            // Get file metadata
            $file_path = Manifest::$data['data']['js_classes'][$current_class];
            $metadata = Manifest::$data['data']['files'][$file_path];

            if (empty($metadata['extends'])) {
                return false;
            }

            if ($metadata['extends'] == $superclass) {
                return true;
            }

            // Move up the chain to the parent class
            $current_class = $metadata['extends'];
        }

        return false;
    }

    /**
    * Get the complete inheritance chain for a JavaScript class
    * Returns array of parent class names in order from immediate parent to root
    *
    * @param string $class_name The class name to get lineage for
    * @return array Array of parent class names (empty if class not found or has no parents)
    * Example: If A extends B and B extends C, js_get_lineage('A') returns ['B', 'C']
    */
    public static function js_get_lineage(string $class_name): array
    {
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

            // Find the current class in manifest
            if (!isset(Manifest::$data['data']['js_classes'][$current_class])) {
                break;
            }

            // Get the file path from js_classes index, then get metadata from files
            $file_path = Manifest::$data['data']['js_classes'][$current_class];
            $metadata = Manifest::$data['data']['files'][$file_path];
            $extends = $metadata['extends'] ?? null;

            if (!$extends) {
                break;
            }

            // Add parent to lineage
            $lineage[] = $extends;

            // Move up the chain
            $current_class = $extends;
        }

        return $lineage;
    }

    /**
    * Get all direct subclasses of a given JavaScript class using the pre-built index
    *
    * @param string $class_name The parent class name
    * @return array Array of subclass names, or empty array if class not found or has no children
    */
    public static function js_get_subclasses_of(string $class_name): array
    {
        // Return empty array if class not in subclass_index
        if (!isset(Manifest::$data['data']['js_subclass_index'][$class_name])) {
            return [];
        }

        return Manifest::$data['data']['js_subclass_index'][$class_name];
    }

}
