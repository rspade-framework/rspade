<?php

namespace App\RSpade\Integrations\Jqhtml;

use Exception;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for extracting jqhtml component metadata
 * This runs after the primary manifest is built to add jqhtml component metadata
 */
class Jqhtml_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Process the manifest and add jqhtml component metadata
     *
     * Doesn't actually read any files, just collates the data in the manifest
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function process(array &$manifest_data): void
    {
        // Initialize jqhtml key if it doesn't exist
        if (!isset($manifest_data['data']['jqhtml'])) {
            $manifest_data['data']['jqhtml'] = [];
        }

        $components = [];

        // Build map of component_name => js_file for classes extending Component
        $js_classes = [];
        try {
            $extending_components = Manifest::js_get_extending('Component');
            foreach ($extending_components as $component_info) {
                if (isset($component_info['class']) && isset($component_info['file'])) {
                    $js_classes[$component_info['class']] = $component_info['file'];
                }
            }
        } catch (Exception $e) {
            // Manifest not ready yet, skip
        }

        // Get all jqhtml template files from manifest and build component map
        if (isset($manifest_data['data']['files'])) {
            foreach ($manifest_data['data']['files'] as $file_path => $file_data) {
                if (isset($file_data['type']) && $file_data['type'] === 'jqhtml_template') {
                    if (isset($file_data['template_name'])) {
                        $component_name = $file_data['template_name'];

                        $components[$component_name] = [
                            'name' => $component_name,
                            'template_file' => $file_path,
                        ];

                        // Add JS file if component has a class
                        if (isset($js_classes[$component_name])) {
                            $components[$component_name]['js_file'] = $js_classes[$component_name];
                        }
                    }
                }
            }
        }

        // Store component map (associative array: component_name => metadata)
        $manifest_data['data']['jqhtml']['components'] = $components;
        $manifest_data['data']['jqhtml']['component_count'] = count($components);
    }

    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Jqhtml Component Metadata';
    }

    /**
     * Static method to get jqhtml components from cached manifest
     * This is called by JqhtmlBladeCompiler to get the list of components
     *
     * @return array List of jqhtml component names
     */
    public static function get_jqhtml_components(): array
    {
        try {
            // Get the full manifest data
            $manifest = Manifest::get_full_manifest();

            if (isset($manifest['data']['jqhtml']['components'])) {
                return $manifest['data']['jqhtml']['components'];
            }
        } catch (Exception $e) {
            // Manifest not ready, return empty
        }

        return [];
    }
}
