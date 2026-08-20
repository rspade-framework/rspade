<?php

namespace App\RSpade\Core\Portal;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for building portal routes index from #[Portal_Route] attributes
 *
 * Similar to Route_ManifestSupport but for portal-specific routes.
 * Portal routes are stored separately in the manifest and handled by
 * the portal dispatcher.
 *
 * Usage in controllers:
 * ```php
 * #[Portal_Route('/dashboard')]
 * public static function index(Request $request, array $params = []) { ... }
 *
 * #[Portal_Route('/projects/:id', methods: ['GET'])]
 * public static function view(Request $request, array $params = []) { ... }
 * ```
 */
class Portal_Route_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Portal Routes';
    }

    /**
     * Process the manifest and build portal routes index
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function process(array &$manifest_data): void
    {
        // Initialize portal routes structures
        if (!isset($manifest_data['data']['portal_routes'])) {
            $manifest_data['data']['portal_routes'] = [];
        }
        if (!isset($manifest_data['data']['portal_routes_by_target'])) {
            $manifest_data['data']['portal_routes_by_target'] = [];
        }

        // Look for Portal_Route attributes
        $files = $manifest_data['data']['files'];
        $portal_route_classes = [];

        foreach ($files as $file => $metadata) {
            // Check public static method attributes for Portal_Route
            if (isset($metadata['public_static_methods'])) {
                foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
                    if (isset($method_data['attributes'])) {
                        foreach ($method_data['attributes'] as $attr_name => $attr_instances) {
                            // Check if this is a Portal_Route attribute
                            if (str_ends_with($attr_name, '\\Portal_Route') || $attr_name === 'Portal_Route') {
                                $portal_route_classes[] = [
                                    'file' => $file,
                                    'class' => $metadata['class'] ?? null,
                                    'fqcn' => $metadata['fqcn'] ?? null,
                                    'method' => $method_name,
                                    'type' => 'method',
                                    'instances' => $attr_instances,
                                ];
                            }
                        }
                    }
                }
            }
        }

        foreach ($portal_route_classes as $item) {
            if ($item['type'] === 'method') {
                foreach ($item['instances'] as $route_args) {
                    $pattern = $route_args[0] ?? ($route_args['pattern'] ?? null);
                    $methods = $route_args[1] ?? ($route_args['methods'] ?? ['GET']);
                    $name = $route_args[2] ?? ($route_args['name'] ?? null);

                    if ($pattern) {
                        // Ensure pattern starts with /
                        if ($pattern[0] !== '/') {
                            $pattern = '/' . $pattern;
                        }

                        // Type is always 'portal' for routes with #[Portal_Route] attribute
                        $type = 'portal';

                        // Extract Auth attributes for this method (portal-specific auth would use Portal_Auth or similar)
                        $require_attrs = [];
                        $file_metadata = $files[$item['file']] ?? null;
                        if ($file_metadata && isset($file_metadata['public_static_methods'][$item['method']]['attributes']['Portal_Auth'])) {
                            $require_attrs = $file_metadata['public_static_methods'][$item['method']]['attributes']['Portal_Auth'];
                        }

                        // Declarative auth gates, resolved against the PORTAL check
                        // registry: class-level #[Auth] then the method's own.
                        $auth_gates = Auth_ManifestSupport::merge_gate_lists(
                            $file_metadata['attributes'] ?? null,
                            $file_metadata['public_static_methods'][$item['method']]['attributes'] ?? null,
                            "{$item['fqcn']}::{$item['method']} in {$item['file']}"
                        );

                        // Check for duplicate portal route definition
                        if (isset($manifest_data['data']['portal_routes'][$pattern])) {
                            $existing = $manifest_data['data']['portal_routes'][$pattern];
                            $existing_location = "{$existing['class']}::{$existing['method']} in {$existing['file']}";

                            throw new \RuntimeException(
                                "Duplicate portal route definition: {$pattern}\n" .
                                "  Already defined: {$existing_location}\n" .
                                "  Conflicting: {$item['fqcn']}::{$item['method']} in {$item['file']}"
                            );
                        }

                        // Store route with flat structure (for portal dispatcher)
                        $route_data = [
                            'methods' => array_map('strtoupper', (array) $methods),
                            'type' => $type,
                            'class' => $item['fqcn'] ?? $item['class'],
                            'method' => $item['method'],
                            'name' => $name,
                            'file' => $item['file'],
                            'require' => $require_attrs,
                            'pattern' => $pattern,
                            'auth' => $auth_gates,
                        ];

                        $manifest_data['data']['portal_routes'][$pattern] = $route_data;

                        // Also store by target for URL generation
                        $target = $item['class'] . '::' . $item['method'];
                        if (!isset($manifest_data['data']['portal_routes_by_target'][$target])) {
                            $manifest_data['data']['portal_routes_by_target'][$target] = [];
                        }
                        $manifest_data['data']['portal_routes_by_target'][$target][] = $route_data;
                    }
                }
            }
        }

        // Sort routes alphabetically by path
        ksort($manifest_data['data']['portal_routes']);
        ksort($manifest_data['data']['portal_routes_by_target']);
    }
}
