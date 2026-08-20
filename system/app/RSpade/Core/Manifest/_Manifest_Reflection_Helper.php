<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;

/**
 * _Manifest_Reflection_Helper - View, attribute, and route resolution
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Reflection_Helper
{
    /**
    * Find a view by ID
    */
    public static function find_view(string $id): string
    {
        $files = Manifest::get_all();
        $matches = [];

        // Find all files with matching ID (only check Blade views)
        foreach ($files as $file => $metadata) {
            if (isset($metadata['id']) && $metadata['id'] === $id && str_ends_with($file, '.blade.php')) {
                $matches[] = $file;
            }
        }

        // Check results
        if (count($matches) === 0) {
            throw new \RuntimeException("View not found in manifest: {$id}");
        }

        if (count($matches) > 1) {
            $file_list = implode("\n  - ", $matches);

            throw new \RuntimeException(
                "Duplicate view ID detected: {$id}\n" .
"Found in multiple files:\n  - {$file_list}\n" .
'View IDs must be unique across all Blade files.'
            );
        }

        return $matches[0];
    }

    /**
    * Find a view by RSX ID (path-agnostic identifier)
    */
    public static function find_view_by_rsx_id(string $id): string
    {
        // This method now properly checks for duplicates
        return Manifest::find_view($id);
    }

    /**
    * Get path for a file by its filename only (quick and dirty lookup)
    *
    * This is a convenience method for finding files when you know the filename is unique.
    * Only works for files in the /rsx directory. Fatal errors if:
    * - File not found in manifest
    * - Multiple files with the same name exist
    * - File is outside /rsx directory
    *
    * @param string $filename Just the filename with extension (e.g., "Counter_Widget.jqhtml")
    * @return string The relative path to the file (e.g., "rsx/app/demo/components/Counter_Widget.jqhtml")
    * @throws RuntimeException If file not found, multiple matches, or outside /rsx
    */
    public static function get_path_by_filename(string $filename): string
    {
        $files = Manifest::get_all();
        $matches = [];

        foreach ($files as $path => $metadata) {
            // Only consider files in /rsx directory
            if (!str_starts_with($path, 'rsx/')) {
                continue;
            }

            // Extract just the filename from the path
            $file_basename = basename($path);

            if ($file_basename === $filename) {
                $matches[] = $path;
            }
        }

        if (empty($matches)) {
            throw new \RuntimeException(
                "Fatal: File not found in manifest: {$filename}\n" .
'This method only searches files in the /rsx directory.'
            );
        }

        if (count($matches) > 1) {
            throw new \RuntimeException(
                "Fatal: Multiple files with name '{$filename}' found in manifest:\n" .
'  - ' . implode("\n  - ", $matches) . "\n" .
'This method requires unique filenames.'
            );
        }

        return $matches[0];
    }

    /**
    * Get all classes with a specific attribute
    */
    public static function get_with_attribute(string $attribute_class): array
    {
        $files = Manifest::get_all();
        $results = [];

        foreach ($files as $file => $metadata) {
            // Check class attributes
            if (isset($metadata['attributes'][$attribute_class])) {
                $results[] = [
                    'file' => $file,
                    'class' => $metadata['class'] ?? null,
                    'fqcn' => $metadata['fqcn'] ?? null,
                    'type' => 'class',
                    'instances' => $metadata['attributes'][$attribute_class],
                ];
            }

            // Check public static method attributes (PHP files)
            if (isset($metadata['public_static_methods'])) {
                foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
                    if (isset($method_data['attributes'][$attribute_class])) {
                        $results[] = [
                            'file' => $file,
                            'class' => $metadata['class'] ?? null,
                            'fqcn' => $metadata['fqcn'] ?? null,
                            'method' => $method_name,
                            'type' => 'method',
                            'instances' => $method_data['attributes'][$attribute_class],
                        ];
                    }
                }
            }

            // Check regular method attributes (JS files may have these)
            if (isset($metadata['methods'])) {
                foreach ($metadata['methods'] as $method_name => $method_data) {
                    if (isset($method_data['attributes'][$attribute_class])) {
                        $results[] = [
                            'file' => $file,
                            'class' => $metadata['class'] ?? null,
                            'fqcn' => $metadata['fqcn'] ?? null,
                            'method' => $method_name,
                            'type' => 'method',
                            'instances' => $method_data['attributes'][$attribute_class],
                        ];
                    }
                }
            }
        }

        // Sort alphabetically by class name to ensure deterministic behavior and prevent race condition bugs
        usort($results, function ($a, $b) {
            return strcmp($a['class'] ?? '', $b['class'] ?? '');
        });

        return $results;
    }

    /**
    * Get all routes from the manifest
    *
    * Returns unified route structure: $routes[$pattern] => route_data
    * where route_data contains:
    *   - methods: ['GET', 'POST']
    *   - type: 'spa' | 'standard'
    *   - class: Full class name
    *   - method: Method name
    *   - file: File path
    *   - require: Auth requirements
    *   - js_action_class: (SPA routes only) JavaScript action class
    */
    public static function get_routes(): array
    {
        Manifest::init();

        return Manifest::$data['data']['routes'] ?? [];
    }

    /**
    * Check if metadata represents a controller class
    * @param array $metadata File metadata
    * @return bool True if class extends Rsx_Controller_Abstract
    */
    public static function _is_controller_class(array $metadata): bool
    {
        $extends = $metadata['extends'] ?? '';

        if ($extends === 'Rsx_Controller_Abstract') {
            return true;
        }

        // Check parent hierarchy
        $current_class = $extends;
        $max_depth = 10;

        while ($current_class && $max_depth-- > 0) {
            try {
                $parent_metadata = Manifest::php_get_metadata_by_class($current_class);
                if (($parent_metadata['extends'] ?? '') === 'Rsx_Controller_Abstract') {
                    return true;
                }
                $current_class = $parent_metadata['extends'] ?? '';
            } catch (\RuntimeException $e) {
                // Check FQCN match
                if ($current_class === 'Rsx_Controller_Abstract' ||
                $current_class === 'App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract') {
                    return true;
                }
                break;
            }
        }

        return false;
    }

}
