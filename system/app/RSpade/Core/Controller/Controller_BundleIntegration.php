<?php

namespace App\RSpade\Core\Controller;

use App\RSpade\Core\Bundle\BundleIntegration_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Controller integration for RSX framework
 *
 * Handles generation of JavaScript stub files for controllers with Ajax_Endpoint methods,
 * enabling clean Ajax.call() syntax from JavaScript.
 */
class Controller_BundleIntegration extends BundleIntegration_Abstract
{
    /**
     * Get the integration's unique identifier
     *
     * @return string Integration identifier
     */
    public static function get_name(): string
    {
        return 'controller';
    }

    /**
     * Get file extensions handled by this integration
     *
     * Controllers are PHP files, but we don't need to register
     * extensions as the PHP files are already handled by the core.
     *
     * @return array Empty array as no special extensions needed
     */
    public static function get_file_extensions(): array
    {
        return [];
    }

    /**
     * Generate JavaScript stub files for controllers with Ajax_Endpoint methods
     *
     * Called during manifest building (Phase 5) to generate JavaScript
     * stub files that provide IDE autocomplete and runtime functionality
     * for PHP controllers with Ajax_Endpoint annotated methods.
     *
     * @param array &$manifest_data The complete manifest data (passed by reference)
     * @return void
     */
    public static function generate_manifest_stubs(array &$manifest_data): void
    {
        $stub_dir = storage_path('rsx-build/js-stubs');

        // Create directory if it doesn't exist
        if (!is_dir($stub_dir)) {
            mkdir($stub_dir, 0755, true);
        }

        // Track generated stub files for cleanup
        $generated_stubs = [];

        // Process each file
        foreach ($manifest_data['data']['files'] as $file_path => &$metadata) {
            // Skip non-PHP files
            if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                continue;
            }

            // Skip files without classes or public static methods
            if (!isset($metadata['class']) || !isset($metadata['public_static_methods'])) {
                continue;
            }

            // Check if this is a controller (extends Rsx_Controller_Abstract)
            $class_name = $metadata['class'] ?? '';
            if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Controller_Abstract')) {
                continue;
            }

            // Check if this controller has any Ajax_Endpoint methods
            $api_methods = [];
            foreach ($metadata['public_static_methods'] as $method_name => $method_info) {
                if (!isset($method_info['attributes'])) {
                    continue;
                }

                // Check for Ajax_Endpoint attribute
                $has_api_internal = false;
                foreach ($method_info['attributes'] as $attr_name => $attr_data) {
                    if ($attr_name === 'Ajax_Endpoint' ||
                        basename(str_replace('\\', '/', $attr_name)) === 'Ajax_Endpoint') {
                        $has_api_internal = true;
                        break;
                    }
                }

                if ($has_api_internal && isset($method_info['static']) && $method_info['static']) {
                    $api_methods[$method_name] = [
                        'name' => $method_name,
                    ];
                }
            }

            // If no API methods, remove js_stub property if it exists and skip
            if (empty($api_methods)) {
                if (isset($metadata['js_stub'])) {
                    unset($metadata['js_stub']);
                }
                continue;
            }

            // Generate stub filename and paths
            $controller_name = $metadata['class'];
            $stub_filename = static::_sanitize_stub_filename($controller_name) . '.js';
            $stub_relative_path = 'storage/rsx-build/js-stubs/' . $stub_filename;
            $stub_full_path = rsx_project_file_path($stub_relative_path);

            // Check if stub needs regeneration
            $needs_regeneration = true;
            if (file_exists($stub_full_path)) {
                // Get mtime of source PHP file
                $source_mtime = $metadata['mtime'] ?? 0;
                $stub_mtime = filemtime($stub_full_path);

                // Only regenerate if source is newer than stub
                if ($stub_mtime >= $source_mtime) {
                    // Also check if the API methods signature has changed
                    // by comparing a hash of the methods
                    $api_methods_hash = static::_api_methods_hash($api_methods);
                    $old_api_hash = $metadata['api_methods_hash'] ?? '';

                    if ($api_methods_hash === $old_api_hash) {
                        $needs_regeneration = false;
                    }
                }
            }

            // Store the API methods hash for future comparisons
            $metadata['api_methods_hash'] = static::_api_methods_hash($api_methods);

            if ($needs_regeneration) {
                // Generate stub content
                $stub_content = static::_generate_stub_content($controller_name, $api_methods);

                // Write stub file
                file_put_contents_safe($stub_full_path, $stub_content);
            }

            $generated_stubs[] = $stub_filename;

            // Add js_stub property to manifest data (relative path)
            $metadata['js_stub'] = $stub_relative_path;

            // Add the stub file itself to the manifest
            // This is critical because storage/rsx-build/js-stubs is not in scan directories
            $stat = stat($stub_full_path);
            $manifest_data['data']['files'][$stub_relative_path] = [
                'file' => $stub_relative_path,
                'hash' => sha1_file($stub_full_path),
                'mtime' => $stat['mtime'],
                'size' => $stat['size'],
                'extension' => 'js',
                'class' => $controller_name,
                'is_stub' => true,  // Mark this as a generated stub
                'source_controller' => $file_path,  // Reference to the source controller
            ];
        }

        // Clean up orphaned stub files (both from disk and manifest)
        $existing_stubs = glob($stub_dir . '/*.js');
        foreach ($existing_stubs as $existing_stub) {
            $filename = basename($existing_stub);
            if (!in_array($filename, $generated_stubs)) {
                // Remove from disk (check exists to avoid Windows errors)
                if (file_exists($existing_stub)) {
                    unlink($existing_stub);
                }

                // Remove from manifest
                $stub_relative_path = 'storage/rsx-build/js-stubs/' . $filename;
                if (isset($manifest_data['data']['files'][$stub_relative_path])) {
                    unset($manifest_data['data']['files'][$stub_relative_path]);
                }
            }
        }
    }


    /**
     * Hash identifying the exact stub content a controller should have
     *
     * Folds in a fingerprint of THIS file so an edit to the stub template regenerates
     * every stub on the next manifest build. Without it, stubs are only rebuilt when a
     * controller's own method list changes and a template change never reaches disk.
     */
    private static function _api_methods_hash(array $api_methods): string
    {
        static $generator_fingerprint = null;

        if ($generator_fingerprint === null) {
            $generator_fingerprint = md5_file(__FILE__);
        }

        return md5($generator_fingerprint . '|' . json_encode($api_methods));
    }

    /**
     * Sanitize controller name for use as filename
     */
    private static function _sanitize_stub_filename(string $controller_name): string
    {
        // Replace any non-alphanumeric characters (except underscores) with underscores
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $controller_name);
    }

    /**
     * Generate JavaScript stub content for a controller
     */
    private static function _generate_stub_content(string $controller_name, array $api_methods): string
    {
        $content = "/**\n";
        $content .= " * Auto-generated JavaScript stub for {$controller_name}\n";
        $content .= " * DO NOT EDIT - This file is automatically regenerated\n";
        $content .= " */\n\n";

        $content .= "class {$controller_name} {\n";

        foreach ($api_methods as $method_name => $method_info) {
            $content .= "    /**\n";
            $content .= "     * Call {$controller_name}::{$method_name} via Ajax.call()\n";
            $content .= "     * @param {Object} params - Parameters object to pass to the method\n";
            $content .= "     * @returns {Promise<*>}\n";
            $content .= "     */\n";
            // NOT async: an async wrapper would return an adopting promise, breaking the
            // promise-identity gate Ajax uses to detect its own unhandled rejections.
            $content .= "    static {$method_name}(params = {}) {\n";
            $content .= "        return Ajax.call('/_ajax/{$controller_name}/{$method_name}', params);\n";
            $content .= "    }\n\n";
        }

        $content .= "}\n\n";

        // Add path properties after class definition
        $content .= "// Path properties for type-safe routing\n";
        foreach ($api_methods as $method_name => $method_info) {
            $content .= "{$controller_name}.{$method_name}.path = '/_ajax/{$controller_name}/{$method_name}';\n";
        }

        return $content;
    }
}
