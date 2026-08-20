<?php
/**
 * @ROUTE-EXISTS-01-EXCEPTION - This file contains documentation examples with fictional route names
 */

namespace App\RSpade\Core\Portal;

use RuntimeException;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Portal utility class
 *
 * Provides static utility methods for the client portal including
 * route generation and portal context detection.
 *
 * Key differences from Rsx:
 * - Route() prepends portal domain/prefix
 * - Portal-specific context detection
 * - Simpler (no event system, fewer utilities)
 */
class Rsx_Portal
{
    /**
     * URL prefix for portal when no dedicated domain configured
     */
    public const URL_PREFIX = '/_portal';

    /**
     * Whether we're currently in a portal request context
     * @var bool|null
     */
    protected static ?bool $_is_portal_request = null;

    /**
     * Current portal controller being executed
     * @var string|null
     */
    protected static ?string $current_controller = null;

    /**
     * Current portal action being executed
     * @var string|null
     */
    protected static ?string $current_action = null;

    /**
     * Current portal route type ('spa' or 'standard')
     * @var string|null
     */
    protected static ?string $current_route_type = null;

    // =========================================================================
    // Configuration Methods
    // =========================================================================

    /**
     * Get the configured portal domain
     *
     * @return string|null Domain if configured, null otherwise
     */
    public static function get_domain(): ?string
    {
        return config('rsx.portal.domain');
    }

    /**
     * Get the portal URL prefix (used when no domain configured)
     *
     * @return string The prefix, defaults to '/_portal'
     */
    public static function get_prefix(): string
    {
        return config('rsx.portal.prefix', self::URL_PREFIX);
    }

    /**
     * Check if portal is using a dedicated domain (vs URL prefix)
     *
     * @return bool True if dedicated domain is configured
     */
    public static function has_dedicated_domain(): bool
    {
        return !empty(self::get_domain());
    }

    // =========================================================================
    // Portal Request Context Detection
    // =========================================================================

    /**
     * Check if current request is a portal request
     *
     * Detection logic:
     * 1. If portal domain configured: check if request host matches
     * 2. If no domain: check if URL starts with portal prefix
     *
     * @return bool True if this is a portal request
     */
    public static function is_portal_request(): bool
    {
        if (self::$_is_portal_request !== null) {
            return self::$_is_portal_request;
        }

        // CLI mode: default to false (can be overridden)
        if (php_sapi_name() === 'cli') {
            self::$_is_portal_request = false;

            return false;
        }

        $portal_domain = self::get_domain();

        if (!empty($portal_domain)) {
            // Domain-based detection
            $request_host = $_SERVER['HTTP_HOST'] ?? '';
            self::$_is_portal_request = (strtolower($request_host) === strtolower($portal_domain));
        } else {
            // Prefix-based detection
            $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
            $prefix = self::get_prefix();
            self::$_is_portal_request = str_starts_with($request_uri, $prefix . '/') ||
                                        $request_uri === $prefix;
        }

        return self::$_is_portal_request;
    }

    /**
     * Manually set whether this is a portal request (for testing/CLI)
     *
     * @param bool $is_portal
     * @return void
     */
    public static function set_portal_request(bool $is_portal): void
    {
        self::$_is_portal_request = $is_portal;
    }

    /**
     * Get the current request URL with portal prefix stripped (if applicable)
     *
     * @return string The normalized path
     */
    public static function get_normalized_path(): string
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Remove query string
        $path = parse_url($request_uri, PHP_URL_PATH) ?? '/';

        // If using prefix mode and path starts with prefix, strip it
        if (!self::has_dedicated_domain()) {
            $prefix = self::get_prefix();
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }
        }

        return $path;
    }

    // =========================================================================
    // Route Generation
    // =========================================================================

    /**
     * Generate URL for a portal route
     *
     * Similar to Rsx::Route() but:
     * - Returns URLs with portal domain or prefix
     * - Only works with routes that have #[Portal_Route] attribute
     *
     * Usage examples:
     * ```php
     * // Portal action route
     * $url = Rsx_Portal::Route('Portal_Dashboard_Action');
     * // Development: /_portal/dashboard
     * // Production: https://portal.example.com/dashboard
     *
     * // Route with integer parameter
     * $url = Rsx_Portal::Route('Portal_Project_View_Action', 123);
     * // Development: /_portal/projects/123
     *
     * // Placeholder route
     * $url = Rsx_Portal::Route('Future_Portal_Feature::#index');
     * // Returns: #
     * ```
     *
     * @param string $action Controller class, SPA action, or "Class::method"
     * @param int|array|\stdClass|null $params Route parameters
     * @return string The generated URL (may include portal domain/prefix)
     */
    public static function Route($action, $params = null): string
    {
        // Parse action into class_name and action_name
        if (str_contains($action, '::')) {
            [$class_name, $action_name] = explode('::', $action, 2);
        } else {
            $class_name = $action;
            $action_name = 'index';
        }

        // Normalize params to array
        $params_array = [];
        if (is_int($params)) {
            $params_array = ['id' => $params];
        } elseif (is_array($params)) {
            $params_array = $params;
        } elseif ($params instanceof \stdClass) {
            $params_array = (array) $params;
        } elseif ($params !== null) {
            throw new RuntimeException("Params must be integer, array, stdClass, or null");
        }

        // Placeholder route
        if (str_starts_with($action_name, '#')) {
            return '#';
        }

        // Look up routes in manifest using portal_routes_by_target
        $manifest = Manifest::get_full_manifest();

        // Build target - always include ::method for consistency with manifest keys
        $target = $class_name . '::' . $action_name;

        // First try direct target lookup (Class::method)
        if (!isset($manifest['data']['portal_routes_by_target'][$target])) {
            // Allow shorthand: Route('MyController') implies Route('MyController::index')
            if ($action_name === 'index' && isset($manifest['data']['portal_routes_by_target'][$class_name])) {
                $target = $class_name;
            } else {
                throw new Rsx_Caller_Exception(
                    "Portal route not found for {$action}. " .
                    "Ensure the class has a #[Portal_Route] attribute."
                );
            }
        }

        $routes = $manifest['data']['portal_routes_by_target'][$target];

        // Select best matching route
        $selected_route = self::_select_best_route($routes, $params_array);

        if (!$selected_route) {
            throw new Rsx_Caller_Exception(
                "No suitable portal route found for {$action} with provided parameters. " .
                "Available routes: " . implode(', ', array_column($routes, 'pattern'))
            );
        }

        // Generate base URL from pattern
        $path = self::_generate_url_from_pattern($selected_route['pattern'], $params_array);

        // Apply portal prefix/domain
        return self::_apply_portal_base($path);
    }

    /**
     * Generate absolute URL for a portal route
     *
     * @param string $action Controller class, SPA action, or "Class::method"
     * @param int|array|\stdClass|null $params Route parameters
     * @return string Full URL including protocol and domain
     */
    public static function url($action, $params = null): string
    {
        $path = self::Route($action, $params);

        // If already has domain, return as-is
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Build absolute URL
        $portal_domain = self::get_domain();

        if (!empty($portal_domain)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

            return $protocol . '://' . $portal_domain . $path;
        }

        // No portal domain, use current host with path
        return url($path);
    }

    /**
     * Apply portal domain or prefix to a path
     *
     * @param string $path The route path (e.g., '/dashboard')
     * @return string Path with portal prefix, or full URL if domain configured
     */
    protected static function _apply_portal_base(string $path): string
    {
        // If using dedicated domain in production, keep path as-is
        // The domain will be handled at routing level
        if (self::has_dedicated_domain()) {
            // In production, the path is relative to portal domain
            // The caller should use url() if they need full URL
            return $path;
        }

        // Development mode: prepend prefix
        $prefix = self::get_prefix();

        return $prefix . $path;
    }

    /**
     * Select the best matching route from available routes
     *
     * @param array $routes Array of route data from manifest
     * @param array $params_array Provided parameters
     * @return array|null Selected route data or null if none match
     */
    protected static function _select_best_route(array $routes, array $params_array): ?array
    {
        $satisfiable = [];

        foreach ($routes as $route) {
            $pattern = $route['pattern'];

            // Extract required parameters from pattern
            $required_params = [];
            if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $pattern, $matches)) {
                $required_params = $matches[1];
            }

            // Check if all required parameters are provided
            $can_satisfy = true;
            foreach ($required_params as $required) {
                if (!array_key_exists($required, $params_array)) {
                    $can_satisfy = false;
                    break;
                }
            }

            if ($can_satisfy) {
                $satisfiable[] = [
                    'route' => $route,
                    'param_count' => count($required_params),
                ];
            }
        }

        if (empty($satisfiable)) {
            return null;
        }

        // Sort by parameter count descending (most parameters first)
        usort($satisfiable, function ($a, $b) {
            return $b['param_count'] <=> $a['param_count'];
        });

        return $satisfiable[0]['route'];
    }

    /**
     * Generate URL from route pattern by replacing parameters
     *
     * @param string $pattern The route pattern
     * @param array $params Parameters to fill in
     * @return string The generated URL
     */
    protected static function _generate_url_from_pattern(string $pattern, array $params): string
    {
        // Extract required parameters from the pattern
        $required_params = [];
        if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $pattern, $matches)) {
            $required_params = $matches[1];
        }

        // Build the URL by replacing parameters
        $url = $pattern;
        $used_params = [];

        foreach ($required_params as $param_name) {
            if (!array_key_exists($param_name, $params)) {
                throw new RuntimeException("Required parameter '{$param_name}' missing for route {$pattern}");
            }
            $value = $params[$param_name];
            $encoded_value = urlencode((string) $value);
            $url = str_replace(':' . $param_name, $encoded_value, $url);
            $used_params[$param_name] = true;
        }

        // Collect extra parameters for query string
        $query_params = [];
        foreach ($params as $key => $value) {
            if (!isset($used_params[$key])) {
                $query_params[$key] = $value;
            }
        }

        // Append query string if there are extra parameters
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        return $url;
    }

    // =========================================================================
    // Controller/Action Tracking
    // =========================================================================

    /**
     * Set the current portal controller and action being executed
     *
     * @param string $controller_class The controller class name
     * @param string $action_method The action method name
     * @param string|null $route_type Route type ('spa' or 'standard')
     */
    public static function _set_current_controller_action(
        string $controller_class,
        string $action_method,
        ?string $route_type = null
    ): void {
        // Extract just the class name without namespace
        $parts = explode('\\', $controller_class);
        $class_name = end($parts);

        static::$current_controller = $class_name;
        static::$current_action = $action_method;
        static::$current_route_type = $route_type;
    }

    /**
     * Get the current portal controller class name
     *
     * @return string|null
     */
    public static function get_current_controller(): ?string
    {
        return static::$current_controller;
    }

    /**
     * Get the current portal action method name
     *
     * @return string|null
     */
    public static function get_current_action(): ?string
    {
        return static::$current_action;
    }

    /**
     * Check if current portal route is a SPA route
     *
     * @return bool
     */
    public static function is_spa(): bool
    {
        return static::$current_route_type === 'spa' || static::$current_route_type === 'portal_spa';
    }

    /**
     * Clear the current controller and action tracking
     */
    public static function _clear_current_controller_action(): void
    {
        static::$current_controller = null;
        static::$current_action = null;
        static::$current_route_type = null;
    }

    /**
     * Clear all cached state (for testing)
     */
    public static function _clear_cache(): void
    {
        static::$_is_portal_request = null;
        static::$current_controller = null;
        static::$current_action = null;
        static::$current_route_type = null;
    }
}
