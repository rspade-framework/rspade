<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;

/**
 * _Manifest_Builder_Helper - Index generation and autoloader building
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Builder_Helper
{
    /**
    * Build the autoloader class map for simplified class name resolution
    * Maps simple class names to their fully qualified class names
    * @return array Map of simple names to arrays of FQCNs
    */
    public static function _build_autoloader_class_map(): array
    {
        $class_map = [];

        // First, collect classes from the manifest files
        foreach (Manifest::$data['data']['files'] as $file_path => $file_data) {
            if (isset($file_data['class']) && isset($file_data['namespace'])) {
                $simple_name = $file_data['class'];
                $fqcn = $file_data['namespace'] . '\\' . $simple_name;

                if (!isset($class_map[$simple_name])) {
                    $class_map[$simple_name] = [];
                }
                $class_map[$simple_name][] = $fqcn;
            }
        }

        // Second, scan app/RSpade directory for additional classes
        $rspade_path = base_path('app/RSpade');
        if (is_dir($rspade_path)) {
            $rspade_classes = Manifest::_scan_directory_for_classes($rspade_path);
            foreach ($rspade_classes as $simple_name => $fqcns) {
                foreach ($fqcns as $fqcn) {
                    if (!isset($class_map[$simple_name])) {
                        $class_map[$simple_name] = [];
                    }
                    // Only add if not already present
                    if (!in_array($fqcn, $class_map[$simple_name])) {
                        $class_map[$simple_name][] = $fqcn;
                    }
                }
            }
        }

        return $class_map;
    }

    /**
    * Collate files by class names and build inheritance indices
    *
    * This method creates two types of indices for both JavaScript and PHP classes:
    *
    * 1. Class indices (js_classes, php_classes):
    *    Maps class names to their file metadata for O(1) lookups by class name
    *
    * 2. Subclass indices (js_subclass_index, php_subclass_index):
    *    Maps each parent class name to an array of its direct subclasses
    *    Example: Rsx_Controller_Abstract => ['Demo_Index_Controller', 'Backend_Controller', ...]
    *
    * The subclass indices enable efficient inheritance checking without iterating
    * through the entire manifest. Instead of O(n) complexity for finding subclasses,
    * we get O(1) direct subclass lookups.
    *
    * @return void
    */
    public static function _collate_files_by_classes()
    {
        foreach (['js', 'php'] as $ext) {
            Manifest::$data['data'][$ext . '_classes'] = [];

            // Step 1: Index files by class name for quick lookups
            // This creates a map of className => filename (not full metadata to save space)
            // NOTE: Class override detection (rsx/ vs app/RSpade/) happens earlier in _check_unique_base_class_names()
            foreach (Manifest::$data['data']['files'] as $file => $filedata) {
                if (isset($filedata['extension']) && $filedata['extension'] == $ext && !empty($filedata['class'])) {
                    $class_name = $filedata['class'];

                    // Duplicates should have been caught by _check_unique_base_class_names()
                    // but check here as a safety net
                    if (isset(Manifest::$data['data'][$ext . '_classes'][$class_name])) {
                        $existing_file = Manifest::$data['data'][$ext . '_classes'][$class_name];
                        throw new \RuntimeException(
                            "Duplicate {$ext} class detected: {$class_name}\n" .
                            "Found in:\n  - {$existing_file}\n  - {$file}\n" .
                            "Class names must be unique across the codebase."
                        );
                    }

                    Manifest::$data['data'][$ext . '_classes'][$class_name] = $file;
                }
            }

            // Step 2: Build parent chain index for each class
            // This traverses up the inheritance tree and collects all parent classes
            Manifest::$data['data'][$ext . '_subclass_index'] = [];
            foreach (Manifest::$data['data'][$ext . '_classes'] as $class => $file_path) {
                // Get metadata from files array
                $classdata = Manifest::$data['data']['files'][$file_path];

                // Walk up the parent chain until we reach the root
                do {
                    $extends = !empty($classdata['extends']) ? $classdata['extends'] : null;
                    if (!empty($extends)) {
                        // Initialize the parent chain array if needed
                        if (empty(Manifest::$data['data'][$ext . '_subclass_index'][$extends])) {
                            Manifest::$data['data'][$ext . '_subclass_index'][$extends] = [];
                        }
                        // Add this parent to the chain
                        Manifest::$data['data'][$ext . '_subclass_index'][$extends][] = $class;
                        // Move up to the parent's metadata (if it exists in manifest)
                        if (!empty(Manifest::$data['data'][$ext . '_classes'][$extends])) {
                            $parent_file = Manifest::$data['data'][$ext . '_classes'][$extends];
                            $classdata = Manifest::$data['data']['files'][$parent_file];
                        } else {
                            // Parent not in manifest (e.g., Laravel framework class), stop here
                            $classdata = null;
                        }
                    } else {
                        // No parent, we've reached the root
                        $classdata = null;
                    }
                } while (!empty($classdata));
            }
        }
    }

    /**
     * Build event handler index from OnEvent attributes
     *
     * Scans all PHP files for methods with #[OnEvent] attributes and builds
     * an index of event_name => [handlers] for fast event dispatching.
     *
     * Index structure:
     * ['event_handlers'] => [
     *     'event.name' => [
     *         ['class' => 'Class_Name', 'method' => 'method_name', 'priority' => 100],
     *         ['class' => 'Other_Class', 'method' => 'other_method', 'priority' => 200],
     *     ]
     * ]
     *
     * Handlers are sorted by priority (lower numbers execute first).
     *
     * @return void
     */
    public static function _build_event_handler_index()
    {
        Manifest::$data['data']['event_handlers'] = [];

        // Scan all PHP files for OnEvent attributes
        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            // Only process PHP files
            if (($metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Skip files without a class
            if (empty($metadata['class'])) {
                continue;
            }

            $class_name = $metadata['class'];

            // Check public static methods for OnEvent attributes
            if (isset($metadata['public_static_methods'])) {
                foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
                    // Check if method has OnEvent attribute
                    if (isset($method_data['attributes']['OnEvent'])) {
                        $on_event_attrs = $method_data['attributes']['OnEvent'];

                        // Process each OnEvent attribute instance (a method can have multiple)
                        foreach ($on_event_attrs as $attr_args) {
                            // Extract event name - could be positional or named
                            $event_name = $attr_args[0] ?? $attr_args['event'] ?? null;
                            if (!$event_name) {
                                continue; // Skip invalid attribute
                            }

                            // Extract priority - could be positional or named, defaults to 100
                            $priority = $attr_args[1] ?? $attr_args['priority'] ?? 100;

                            // Initialize event array if needed
                            if (!isset(Manifest::$data['data']['event_handlers'][$event_name])) {
                                Manifest::$data['data']['event_handlers'][$event_name] = [];
                            }

                            // Add handler to event
                            Manifest::$data['data']['event_handlers'][$event_name][] = [
                                'class' => $class_name,
                                'method' => $method_name,
                                'priority' => $priority,
                                'file' => $file_path,
                            ];
                        }
                    }
                }
            }
        }

        // Sort each event's handlers by priority (lower = earlier)
        foreach (Manifest::$data['data']['event_handlers'] as $event_name => &$handlers) {
            usort($handlers, function ($a, $b) {
                return $a['priority'] <=> $b['priority'];
            });
        }
    }

    /**
     * Build index of classless PHP files
     *
     * Creates a simple array of file paths for all PHP files in the manifest
     * that do not contain a class. These files typically contain helper functions,
     * constants, or other procedural code that needs to be loaded during post_init().
     *
     * Stored in manifest data at: $data['data']['classless_php_files']
     */
    public static function _build_classless_php_files_index()
    {
        Manifest::$data['data']['classless_php_files'] = [];

        // Scan all PHP files
        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            // Only process PHP files
            if (($metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Skip files that have a class
            if (!empty($metadata['class'])) {
                continue;
            }

            // Add file path to classless index
            Manifest::$data['data']['classless_php_files'][] = $file_path;
        }
    }

}
