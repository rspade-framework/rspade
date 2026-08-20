<?php

namespace App\RSpade\Core\Portal;

use RuntimeException;
use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for extracting Portal Spa route metadata from Spa_Action classes
 *
 * Similar to Spa_ManifestSupport but for portal-specific SPA routes.
 * Portal SPA actions use the @portal_spa() decorator instead of @spa()
 * and their routes are registered in portal_routes instead of routes.
 *
 * Usage in JS action:
 * ```javascript
 * @route('/dashboard')
 * @layout('Portal_Layout')
 * @portal_spa('Portal_Spa_Controller::index')
 * class Portal_Dashboard_Action extends Spa_Action {
 *     // ...
 * }
 * ```
 */
class Portal_Spa_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Portal Spa Routes';
    }

    /**
     * Process the manifest and build Portal Spa routes index
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function process(array &$manifest_data): void
    {
        // Initialize portal routes structures if not already set
        if (!isset($manifest_data['data']['portal_routes'])) {
            $manifest_data['data']['portal_routes'] = [];
        }
        if (!isset($manifest_data['data']['portal_routes_by_target'])) {
            $manifest_data['data']['portal_routes_by_target'] = [];
        }

        // Get all files to look up PHP controller metadata
        $files = $manifest_data['data']['files'];

        // Get all JavaScript classes extending Spa_Action
        $action_classes = Manifest::js_get_extending('Spa_Action');

        foreach ($action_classes as $class_name => $action_metadata) {
            // Extract decorator metadata
            $decorators = $action_metadata['decorators'] ?? [];

            // Parse decorators into route configuration
            $route_info = static::_parse_decorators($decorators);

            // Skip if no @portal_spa decorator (this is a regular SPA action)
            if (empty($route_info['portal_spa_controller'])) {
                continue;
            }

            // Skip if no route decorator found
            if (empty($route_info['routes'])) {
                continue;
            }

            // Find the PHP controller file and metadata
            $php_controller_class = $route_info['portal_spa_controller'];
            $php_controller_method = $route_info['portal_spa_method'];
            $php_controller_file = null;
            $php_controller_fqcn = null;

            // Search for the controller in the manifest
            foreach ($files as $file => $metadata) {
                if (($metadata['class'] ?? null) === $php_controller_class || ($metadata['fqcn'] ?? null) === $php_controller_class) {
                    $php_controller_file = $file;
                    $php_controller_fqcn = $metadata['fqcn'] ?? $metadata['class'];
                    break;
                }
            }

            if (!$php_controller_file) {
                throw new RuntimeException(
                    "Portal Spa action '{$class_name}' references unknown controller '{$php_controller_class}'.\n" .
                    "The @portal_spa decorator must reference a valid PHP controller class.\n" .
                    "File: {$action_metadata['file']}"
                );
            }

            // Server-side gates come from the PHP bootstrap declaration; the action's
            // @auth list rides alongside as the client gate. Both resolve against the
            // PORTAL check registry.
            $php_controller_metadata = $files[$php_controller_file] ?? [];
            $bootstrap_gates = Auth_ManifestSupport::merge_gate_lists(
                $php_controller_metadata['attributes'] ?? null,
                $php_controller_metadata['public_static_methods'][$php_controller_method]['attributes'] ?? null,
                "{$php_controller_class}::{$php_controller_method} in {$php_controller_file}"
            );

            // Build complete route metadata for each route pattern
            foreach ($route_info['routes'] as $route_pattern) {
                // Ensure pattern starts with /
                if ($route_pattern[0] !== '/') {
                    $route_pattern = '/' . $route_pattern;
                }

                // Check for duplicate portal route definition
                if (isset($manifest_data['data']['portal_routes'][$route_pattern])) {
                    $existing = $manifest_data['data']['portal_routes'][$route_pattern];
                    $existing_type = $existing['type'] ?? 'portal';
                    $existing_location = $existing_type === 'portal_spa'
                        ? "Portal Spa action {$existing['js_action_class']} in {$existing['file']}"
                        : "{$existing['class']}::{$existing['method']} in {$existing['file']}";

                    throw new RuntimeException(
                        "Duplicate portal route definition: {$route_pattern}\n" .
                        "  Already defined: {$existing_location}\n" .
                        "  Conflicting: Portal Spa action {$class_name} in {$action_metadata['file']}"
                    );
                }

                // Store route with unified structure (for portal dispatcher)
                $route_data = [
                    'methods' => ['GET'],  // Spa routes are always GET
                    'type' => 'portal_spa',
                    'class' => $php_controller_fqcn,
                    'method' => $php_controller_method,
                    'name' => null,
                    'file' => $php_controller_file,
                    'require' => [],
                    'js_action_class' => $class_name,
                    'pattern' => $route_pattern,
                    'auth' => $bootstrap_gates,
                    'auth_action' => $route_info['auth'],
                ];

                $manifest_data['data']['portal_routes'][$route_pattern] = $route_data;

                // Also store by target for URL generation (group multiple routes per action class)
                $target = $class_name; // For SPA, target is the JS action class name
                if (!isset($manifest_data['data']['portal_routes_by_target'][$target])) {
                    $manifest_data['data']['portal_routes_by_target'][$target] = [];
                }
                $manifest_data['data']['portal_routes_by_target'][$target][] = $route_data;
            }
        }
    }

    /**
     * Parse decorator metadata into route configuration
     *
     * @param array $decorators Array of decorator data from manifest
     * @return array Parsed route configuration
     */
    private static function _parse_decorators(array $decorators): array
    {
        $config = [
            'routes' => [],
            'layout' => null,
            'portal_spa_controller' => null,
            'portal_spa_method' => null,
            'auth' => [],
        ];

        foreach ($decorators as $decorator) {
            [$name, $args] = $decorator;

            switch ($name) {
                case 'auth':
                    // @auth('a', 'b') - variadic check names, AND semantics.
                    foreach ($args as $check_name) {
                        if (is_string($check_name) && $check_name !== ''
                            && !in_array($check_name, $config['auth'], true)) {
                            $config['auth'][] = $check_name;
                        }
                    }
                    break;

                case 'route':
                    // @route('/path') - args is array with single string
                    if (!empty($args[0])) {
                        $config['routes'][] = $args[0];
                    }
                    break;

                case 'layout':
                    // @layout('Layout_Name') - args is array with single string
                    if (!empty($args[0])) {
                        $config['layout'] = $args[0];
                    }
                    break;

                case 'portal_spa':
                    // @portal_spa('Controller::method') - args is array with single string
                    if (!empty($args[0])) {
                        $parts = explode('::', $args[0]);
                        if (count($parts) === 2) {
                            $config['portal_spa_controller'] = $parts[0];
                            $config['portal_spa_method'] = $parts[1];
                        }
                    }
                    break;
            }
        }

        return $config;
    }
}
