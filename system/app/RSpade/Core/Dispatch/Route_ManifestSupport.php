<?php

namespace App\RSpade\Core\Dispatch;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for building routes index from #[Route] attributes
 * This runs after the primary manifest is built to create routes index
 *
 * Each route row carries an 'auth' key: the declarative gate list (class-level
 * #[Auth] then method-level, additive) the dispatcher evaluates before the
 * controller runs. See php artisan rsx:man auth_gates.
 */
class Route_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Routes';
    }

    /**
     * Process the manifest and build routes index
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function process(array &$manifest_data): void
    {
        // Initialize routes structures
        if (!isset($manifest_data['data']['routes'])) {
            $manifest_data['data']['routes'] = [];
        }
        if (!isset($manifest_data['data']['routes_by_target'])) {
            $manifest_data['data']['routes_by_target'] = [];
        }

        // Look for Route attributes - must check all namespaces since Route is not a real class
        // PHP attributes without an import will use the current namespace
        $files = $manifest_data['data']['files'];
        $route_classes = [];

        foreach ($files as $file => $metadata) {
            // Check public static method attributes for any attribute ending with 'Route'
            if (isset($metadata['public_static_methods'])) {
                foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
                    if (isset($method_data['attributes'])) {
                        foreach ($method_data['attributes'] as $attr_name => $attr_instances) {
                            // Check if this is a Route attribute (ends with \Route or is just Route)
                            if (str_ends_with($attr_name, '\\Route') || $attr_name === 'Route') {
                                $route_classes[] = [
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

        foreach ($route_classes as $item) {
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

                        // The /api/vN namespace is reserved for #[Api_Endpoint] only.
                        if (preg_match('#^/api/v[0-9]+(/|$)#', $pattern)) {
                            throw new \RuntimeException(
                                "Reserved route pattern: {$pattern}\n" .
                                "  {$item['fqcn']}::{$item['method']} in {$item['file']}\n" .
                                "  /api/vN is reserved for #[Api_Endpoint]; use #[Api_Endpoint] instead of #[Route]."
                            );
                        }

                        // Type is always 'standard' for routes with #[Route] attribute
                        $type = 'standard';

                        // Declarative auth gates: class-level #[Auth] then the
                        // method's own (additive - gates only narrow).
                        $file_metadata = $files[$item['file']] ?? [];
                        $auth_gates = Auth_ManifestSupport::merge_gate_lists(
                            $file_metadata['attributes'] ?? null,
                            $file_metadata['public_static_methods'][$item['method']]['attributes'] ?? null,
                            "{$item['fqcn']}::{$item['method']} in {$item['file']}"
                        );

                        // Check for duplicate route definition (pattern must be unique across all route types)
                        if (isset($manifest_data['data']['routes'][$pattern])) {
                            $existing = $manifest_data['data']['routes'][$pattern];
                            $existing_type = $existing['type'];
                            $existing_location = $existing_type === 'spa'
                                ? "SPA action {$existing['js_action_class']} in {$existing['file']}"
                                : "{$existing['class']}::{$existing['method']} in {$existing['file']}";

                            throw new \RuntimeException(
                                "Duplicate route definition: {$pattern}\n" .
                                "  Already defined: {$existing_location}\n" .
                                "  Conflicting: {$item['fqcn']}::{$item['method']} in {$item['file']}"
                            );
                        }

                        // Store route with flat structure (for dispatcher)
                        $route_data = [
                            'methods' => array_map('strtoupper', (array) $methods),
                            'type' => $type,
                            'class' => $item['fqcn'] ?? $item['class'],
                            'method' => $item['method'],
                            'name' => $name,
                            'file' => $item['file'],
                            'pattern' => $pattern,
                            'auth' => $auth_gates,
                        ];

                        $manifest_data['data']['routes'][$pattern] = $route_data;

                        // Also store by target for URL generation (group multiple routes per controller method)
                        $target = $item['class'] . '::' . $item['method'];
                        if (!isset($manifest_data['data']['routes_by_target'][$target])) {
                            $manifest_data['data']['routes_by_target'][$target] = [];
                        }
                        $manifest_data['data']['routes_by_target'][$target][] = $route_data;
                    }
                }
            }
        }

        // Sort routes alphabetically by path to ensure deterministic behavior and prevent race condition bugs
        ksort($manifest_data['data']['routes']);
        ksort($manifest_data['data']['routes_by_target']);
    }
}
