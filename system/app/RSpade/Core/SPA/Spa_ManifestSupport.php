<?php

namespace App\RSpade\Core\SPA;

use RuntimeException;
use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for extracting Spa route metadata from Spa_Action classes
 * This runs after the primary manifest is built to add Spa routes to the unified routes index
 *
 * An SPA route row carries TWO gate lists, because two different surfaces answer for
 * one URL:
 *   'auth'        - the PHP bootstrap method's gates (class-level #[Auth] on the SPA
 *                   controller then the #[SPA] method's own). The server dispatcher
 *                   evaluates these before rendering the bootstrap.
 *   'auth_action' - the JS action's @auth(...) check names. These are the CLIENT
 *                   gate (Spa.dispatch resolves them against the render-time auth
 *                   snapshot); the server never renders a denial for them.
 * See php artisan rsx:man auth_gates.
 */
class Spa_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Spa Routes';
    }

    /**
     * Process the manifest and build Spa routes index
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function process(array &$manifest_data): void
    {
        // Initialize routes structures if not already set
        if (!isset($manifest_data['data']['routes'])) {
            $manifest_data['data']['routes'] = [];
        }
        if (!isset($manifest_data['data']['routes_by_target'])) {
            $manifest_data['data']['routes_by_target'] = [];
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

            // Skip if this is a portal SPA action (handled by Portal_Spa_ManifestSupport)
            if (!empty($route_info['is_portal_spa'])) {
                continue;
            }

            // Skip if no route decorator found
            if (empty($route_info['routes'])) {
                continue;
            }

            // Validate that @spa decorator is present
            if (empty($route_info['spa_controller']) || empty($route_info['spa_method'])) {
                throw new RuntimeException(
                    "Spa action '{$class_name}' is missing required @spa decorator.\n" .
                    "Add @spa('Controller_Class::method') to specify the PHP controller method that serves the Spa bootstrap.\n" .
                    "File: {$action_metadata['file']}"
                );
            }

            // Find the PHP controller file and metadata
            $php_controller_class = $route_info['spa_controller'];
            $php_controller_method = $route_info['spa_method'];
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
                    "Spa action '{$class_name}' references unknown controller '{$php_controller_class}'.\n" .
                    "The @spa decorator must reference a valid PHP controller class.\n" .
                    "File: {$action_metadata['file']}"
                );
            }

            // Server-side gates come from the PHP bootstrap declaration; the action's
            // @auth list rides alongside as the client gate.
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

                // The /api/vN namespace is reserved for #[Api_Endpoint] only.
                if (preg_match('#^/api/v[0-9]+(/|$)#', $route_pattern)) {
                    throw new RuntimeException(
                        "Reserved route pattern: {$route_pattern}\n" .
                        "  Spa action {$class_name} in {$action_metadata['file']}\n" .
                        "  /api/vN is reserved for #[Api_Endpoint]; SPA routes may not use it."
                    );
                }

                // Check for duplicate route definition (pattern must be unique across all route types)
                if (isset($manifest_data['data']['routes'][$route_pattern])) {
                    $existing = $manifest_data['data']['routes'][$route_pattern];
                    $existing_type = $existing['type'];
                    $existing_location = $existing_type === 'spa'
                        ? "Spa action {$existing['js_action_class']} in {$existing['file']}"
                        : "{$existing['class']}::{$existing['method']} in {$existing['file']}";

                    throw new RuntimeException(
                        "Duplicate route definition: {$route_pattern}\n" .
                        "  Already defined: {$existing_location}\n" .
                        "  Conflicting: Spa action {$class_name} in {$action_metadata['file']}"
                    );
                }

                // Store route with unified structure (for dispatcher)
                $route_data = [
                    'methods' => ['GET'],  // Spa routes are always GET
                    'type' => 'spa',
                    'class' => $php_controller_fqcn,
                    'method' => $php_controller_method,
                    'name' => null,
                    'file' => $php_controller_file,
                    'js_action_class' => $class_name,
                    'pattern' => $route_pattern,
                    'auth' => $bootstrap_gates,
                    'auth_action' => $route_info['auth'],
                ];

                $manifest_data['data']['routes'][$route_pattern] = $route_data;

                // Also store by target for URL generation (group multiple routes per action class)
                $target = $class_name; // For SPA, target is the JS action class name
                if (!isset($manifest_data['data']['routes_by_target'][$target])) {
                    $manifest_data['data']['routes_by_target'][$target] = [];
                }
                $manifest_data['data']['routes_by_target'][$target][] = $route_data;
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
            'spa_controller' => null,
            'spa_method' => null,
            'is_portal_spa' => false,
            'auth' => [],
        ];

        foreach ($decorators as $decorator) {
            [$name, $args] = $decorator;

            switch ($name) {
                case 'auth':
                    // @auth('a', 'b') - variadic check names, AND semantics. Every
                    // argument is collected; a repeated decorator merges (tolerant).
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
                    // @portal_spa decorator - handled by Portal_Spa_ManifestSupport, skip here
                    $config['is_portal_spa'] = true;
                    break;

                case 'spa':
                    // @spa('Controller::method') - args is array with single string
                    if (!empty($args[0])) {
                        $parts = explode('::', $args[0]);
                        if (count($parts) === 2) {
                            $config['spa_controller'] = $parts[0];
                            $config['spa_method'] = $parts[1];
                        }
                    }
                    break;
            }
        }

        return $config;
    }
}
