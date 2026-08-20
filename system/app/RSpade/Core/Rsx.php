<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 *
 * @ROUTE-EXISTS-01-EXCEPTION - This file contains documentation examples with fictional route names
 */

namespace App\RSpade\Core;

use RuntimeException;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Core RSX framework utility class
 *
 * Provides static utility methods for the RSX framework including
 * flash messages and other core functionality.
 */
class Rsx
{
    // Application mode constants
    public const MODE_DEVELOPMENT = 'development';
    public const MODE_DEBUG = 'debug';
    public const MODE_PRODUCTION = 'production';

    /**
     * Framework-reserved hash key naming the element to scroll to.
     *
     * Must stay identical to Rsx.HASH_ANCHOR_KEY in Core/Js/Rsx.js - PHP and JS
     * Route() are contractually the same function.
     *
     * See: php artisan rsx:man anchors
     */
    public const HASH_ANCHOR_KEY = 'at';

    /**
     * Cached application mode
     */
    private static ?string $_cached_mode = null;

    /**
     * Current controller being executed
     * @var string|null
     */
    protected static $current_controller = null;

    /**
     * Current action being executed
     * @var string|null
     */
    protected static $current_action = null;

    /**
     * Current request params
     * @var array|null
     */
    protected static $current_params = null;

    /**
     * Current route type ('spa' or 'standard')
     * @var string|null
     */
    protected static $current_route_type = null;

    /**
     * Set the current controller and action being executed
     *
     * @param string $controller_class The controller class name
     * @param string $action_method The action method name
     * @param array $params Optional request params to store
     * @param string|null $route_type Route type ('spa' or 'standard')
     */
    public static function _set_current_controller_action($controller_class, $action_method, array $params = [], $route_type = null)
    {
        // Extract just the class name without namespace
        $parts = explode('\\', $controller_class);
        $class_name = end($parts);

        static::$current_controller = $class_name;
        static::$current_action = $action_method;
        static::$current_params = $params;
        static::$current_route_type = $route_type;
    }

    /**
     * Get the current controller class name
     *
     * @return string|null The current controller class or null if not set
     */
    public static function get_current_controller()
    {
        return static::$current_controller;
    }

    /**
     * Get the current action method name
     *
     * @return string|null The current action method or null if not set
     */
    public static function get_current_action()
    {
        return static::$current_action;
    }

    /**
     * Get the current request params
     *
     * @return array|null The current request params or null if not set
     */
    public static function get_current_params()
    {
        return static::$current_params;
    }

    /**
     * Check if current route is a SPA route
     *
     * @return bool True if current route type is 'spa', false otherwise
     */
    public static function is_spa()
    {
        return static::$current_route_type === 'spa';
    }

    // =========================================================================
    // Application Mode Detection
    // =========================================================================

    /**
     * Get the current application mode
     *
     * @return string One of: development, debug, production
     */
    public static function get_mode(): string
    {
        if (self::$_cached_mode !== null) {
            return self::$_cached_mode;
        }

        $mode = env('RSX_MODE', self::MODE_DEVELOPMENT);

        // Normalize aliases
        if ($mode === 'dev') {
            $mode = self::MODE_DEVELOPMENT;
        } elseif ($mode === 'prod') {
            $mode = self::MODE_PRODUCTION;
        }

        // Validate
        if (!in_array($mode, [self::MODE_DEVELOPMENT, self::MODE_DEBUG, self::MODE_PRODUCTION], true)) {
            throw new \RuntimeException(
                "Invalid RSX_MODE '{$mode}'. Must be: development, debug, or production"
            );
        }

        self::$_cached_mode = $mode;

        return $mode;
    }

    /**
     * Check if running in development mode
     */
    public static function is_development(): bool
    {
        return self::get_mode() === self::MODE_DEVELOPMENT;
    }

    /**
     * Check if running in debug mode
     */
    public static function is_debug(): bool
    {
        return self::get_mode() === self::MODE_DEBUG;
    }

    /**
     * Check if running in production mode (debug or production)
     *
     * Returns true for both debug and production modes.
     * Use is_production() && !is_debug() for strictly production-only checks.
     */
    public static function is_production(): bool
    {
        return self::get_mode() !== self::MODE_DEVELOPMENT;
    }

    /**
     * Clear the cached mode (for use after .env changes)
     */
    public static function clear_mode_cache(): void
    {
        self::$_cached_mode = null;
    }

    /**
     * Testing seam: force the cached mode without touching .env.
     *
     * Lets unit tests exercise per-mode policy (the Manifest _should_* helpers, the
     * PHP console_debug gate) without mutating RSX_MODE process-globally. Restore
     * with clear_mode_cache() (re-reads RSX_MODE from env on the next get_mode()).
     */
    public static function _testing_set_mode(string $mode): void
    {
        if (!in_array($mode, [self::MODE_DEVELOPMENT, self::MODE_DEBUG, self::MODE_PRODUCTION], true)) {
            throw new \RuntimeException("Invalid test mode '{$mode}'");
        }
        self::$_cached_mode = $mode;
    }

    /**
     * Get human-readable mode label
     */
    public static function get_mode_label(): string
    {
        return match (self::get_mode()) {
            self::MODE_DEVELOPMENT => 'Development',
            self::MODE_DEBUG => 'Debug',
            self::MODE_PRODUCTION => 'Production',
        };
    }

    // =========================================================================
    // Hostname & Site Environment Detection
    // =========================================================================

    private static $_cached_hostname = null;

    /**
     * Get the hostname of the application. APP_URL is the single source of truth
     * (the $HOSTNAME token in it is already resolved to the OS hostname at boot -
     * see Rsx_App_Url).
     *
     * Resolution:
     * - Web mode (non-production): the request hostname (HTTP_HOST), port stripped.
     * - Production web mode: the request hostname must match the APP_URL host
     *   (exact, OR request host ends with ".<app_url_host>"); fatal on mismatch.
     * - CLI mode: the APP_URL host.
     */
    public static function get_hostname(): string
    {
        if (self::$_cached_hostname !== null) {
            return self::$_cached_hostname;
        }

        // Web mode: the request host is the authority.
        if (php_sapi_name() !== 'cli' && isset($_SERVER['HTTP_HOST'])) {
            $request_host = strtolower($_SERVER['HTTP_HOST']);

            // Strip port if present
            if (str_contains($request_host, ':')) {
                $request_host = explode(':', $request_host)[0];
            }

            // Production validation: request hostname must match the APP_URL host.
            if (app()->environment('production')) {
                $app_host = self::__app_url_host();
                if ($app_host === null) {
                    shouldnt_happen('APP_URL must be set in .env for production environments');
                }

                // Request host must equal the APP_URL host, or be a sub-host of it.
                if ($request_host !== $app_host && !str_ends_with($request_host, '.' . $app_host)) {
                    shouldnt_happen("Request hostname '{$request_host}' does not match APP_URL host '{$app_host}'");
                }
            }

            self::$_cached_hostname = $request_host;
            return $request_host;
        }

        // CLI mode: derive from APP_URL.
        $app_host = self::__app_url_host();
        if ($app_host === null) {
            shouldnt_happen('Cannot determine hostname: APP_URL is not set (or has no host). Set APP_URL in .env');
        }

        self::$_cached_hostname = $app_host;
        return $app_host;
    }

    /**
     * The application host AS BROWSED, INCLUDING a non-default port ("localhost:8080").
     *
     * get_hostname() is the IDENTITY of the box and deliberately strips the port -
     * it answers "which host am I", and is_dev_site() and friends compare against it.
     * This answers a different question: "what authority does a URL built for this
     * browser need". A development container published on a port other than 80/443
     * is reached at http://localhost:8080, so a URL built from the bare hostname
     * points at the wrong port and silently fails to connect (this is exactly how the
     * realtime socket breaks: wss://localhost/ws instead of ws://localhost:8080/ws).
     *
     * Resolution mirrors get_hostname(): the request authority in web mode - including
     * its production host validation, which is performed by the get_hostname() call -
     * and the APP_URL authority in CLI. Default ports are never appended, so an
     * ordinary https deployment produces the identical string it always did.
     *
     * The composition itself lives in compose_authority(), which is pure and unit
     * tested; this method is the impure seam that reads the request superglobals
     * (the PHP test runner is CLI, where that branch is unreachable).
     */
    public static function get_http_host(): string
    {
        $host = self::get_hostname();

        if (php_sapi_name() !== 'cli' && isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            return self::compose_authority($host, (string) $_SERVER['HTTP_HOST'], self::__request_scheme());
        }

        // CLI: the APP_URL authority, port included when it declares a non-default one.
        $app_url = trim((string) env('APP_URL'));

        return self::compose_authority(
            $host,
            (string) (parse_url($app_url, PHP_URL_HOST) ?: $host)
                . (parse_url($app_url, PHP_URL_PORT) ? ':' . (int) parse_url($app_url, PHP_URL_PORT) : ''),
            strtolower((string) parse_url($app_url, PHP_URL_SCHEME)) === 'http' ? 'http' : 'https'
        );
    }

    /**
     * Pure composition core: given the resolved host, the raw authority it came from
     * (which may carry a port) and the scheme the BROWSER used, produce the authority
     * a URL must spell. A port that is the default for the scheme is never spelled.
     *
     * Public so the port logic is unit-testable: the request branch of get_http_host()
     * cannot run under the CLI test runner (same constraint as
     * Rsx_Env_Hostname_Guard::find_mismatch).
     */
    public static function compose_authority(string $host, string $raw_authority, string $scheme): string
    {
        $port = self::__parse_port($raw_authority);

        if ($port === null || self::__is_default_port($scheme, $port)) {
            return $host;
        }

        return $host . ':' . $port;
    }

    /**
     * The scheme the BROWSER used. X-Forwarded-Proto wins when present (an upstream
     * SSL terminator speaks plain http to us while the browser is on https).
     */
    private static function __request_scheme(): string
    {
        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwarded === 'https' || $forwarded === 'http') {
            return $forwarded;
        }

        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));

        return ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }

    /**
     * The port of a raw authority ("host:8080", "[::1]:8080"), or null when it
     * declares none. Read from the authority rather than SERVER_PORT: the browser's
     * authority is what a URL must match, and behind an SSL terminator SERVER_PORT is
     * the internal port, not the one the browser used.
     */
    private static function __parse_port(string $raw_authority): ?int
    {
        $raw = strtolower(trim($raw_authority));
        if ($raw === '') {
            return null;
        }

        // IPv6 literal: the port follows the closing bracket ("[::1]:8080").
        if ($raw[0] === '[') {
            $close = strpos($raw, ']');
            if ($close === false || !isset($raw[$close + 1]) || $raw[$close + 1] !== ':') {
                return null;
            }
            $port = (int) substr($raw, $close + 2);
        } else {
            if (!str_contains($raw, ':')) {
                return null;
            }
            $port = (int) explode(':', $raw)[1];
        }

        return $port > 0 ? $port : null;
    }

    /**
     * Whether $port is the default for $scheme (and therefore never spelled in a URL).
     */
    private static function __is_default_port(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }

    /**
     * Parse the lowercased host out of APP_URL, or null when APP_URL is empty or
     * has no parseable host. The $HOSTNAME token is already resolved at boot.
     */
    private static function __app_url_host(): ?string
    {
        $app_url = trim((string) env('APP_URL'));
        if ($app_url === '') {
            return null;
        }

        $host = parse_url($app_url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    /**
     * Check if this is a dev site (hostname contains .dev. anywhere)
     *
     * Dev sites suppress production behaviors: unconstrained email delivery,
     * SMS sending, external API calls. Email is gated through catchall/whitelist.
     */
    public static function is_dev_site(): bool
    {
        try {
            $hostname = self::get_hostname();
            return str_contains($hostname, '.dev.');
        } catch (\Exception $e) {
            // If hostname can't be determined, assume dev for safety
            return true;
        }
    }

    /**
     * Whether this is running inside an RSPADE CONTAINER - the one the framework's
     * own tooling was written for.
     *
     * NOT the same question as "am I in Docker". /.dockerenv answers that, and it
     * is not enough: somebody running RSpade in a container they built themselves
     * satisfies it while having no supervisor, none of the service names the
     * framework drives, and a different data-directory layout. Operations that
     * depend on those things - stopping MySQL to snapshot its datadir, restarting
     * a named service - must refuse there rather than half-work.
     *
     * The marker is written by the framework's own Dockerfile. Its absence is not
     * an error in itself; it simply means container-specific operations are not
     * available and should say so.
     */
    public static function is_rspade_container(): bool
    {
        return is_file('/.rspade_container');
    }

    /**
     * Whether this is the RSpade DEVELOPMENT container specifically.
     *
     * Distinct from is_rspade_container(), and the distinction decides what
     * happens to a migration snapshot: both targets take one, but only the
     * development container discards it once the migration succeeds. On a
     * production container that snapshot is the last copy of the database as it
     * was a minute ago, which is precisely the thing an operator would want to
     * still have.
     */
    public static function is_rspade_dev_container(): bool
    {
        return is_file('/.rspade_container_dev');
    }

    /**
     * Check if this is a DEBUG SITE - a host the operator has declared as their
     * own, where developer backdoors are permitted: login credential auto-fill,
     * debug tools, test data access. Every debug site is also a dev site.
     *
     * The host is matched against rsx.development.debug_domain_suffix
     * (RSPADE_DEBUG_DOMAIN_SUFFIX in .env). A host qualifies when it EQUALS the
     * suffix or ends with '.' . suffix - so "dev.example.com" declares both
     * dev.example.com and app.dev.example.com, and never notdev.example.com.
     *
     * NO SUFFIX DECLARED MEANS NO DEBUG SITE ANYWHERE. That is the shipped
     * default and the reason this is configuration rather than a hardcoded
     * domain: a framework that ships a debug host baked in is a framework whose
     * backdoors are one DNS entry away from anybody. Declare it only for hosts
     * you control.
     */
    public static function is_debug_site(): bool
    {
        try {
            $suffix = strtolower(trim((string) config('rsx.development.debug_domain_suffix', '')));
            if ($suffix === '') {
                return false;
            }

            $hostname = strtolower(self::get_hostname());

            return $hostname === $suffix || str_ends_with($hostname, '.' . $suffix);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear the current controller and action tracking
     */
    public static function _clear_current_controller_action()
    {
        static::$current_controller = null;
        static::$current_action = null;
        static::$current_params = null;
        static::$current_route_type = null;
    }

    // Flash alert methods have been removed - use Flash class instead:
    // Flash_Alert::success($message)
    // Flash_Alert::error($message)
    // Flash_Alert::info($message)
    // Flash_Alert::warning($message)
    //
    // See: /system/app/RSpade/Core/Flash/Flash.php
    // See: /system/app/RSpade/Core/Flash/CLAUDE.md

    /**
     * Generate URL for a controller route
     *
     * This method generates URLs for controller actions by looking up route patterns
     * and replacing parameters. It handles both regular routes and Ajax endpoints.
     *
     * Placeholder Routes:
     * When the action starts with '#' (e.g., '#index', '#show'), it indicates a placeholder/unimplemented
     * route for scaffolding purposes. These skip validation and return "#" to allow incremental
     * development without requiring all controllers to exist.
     *
     * Usage examples:
     * ```php
     * // Controller route (defaults to 'index' method)
     * $url = Rsx::Route('Frontend_Index_Controller');
     * // Returns: /dashboard
     *
     * // Controller route with explicit method
     * $url = Rsx::Route('Frontend_Client_View_Controller::view', 123);
     * // Returns: /clients/view/123
     *
     * // SPA action route
     * $url = Rsx::Route('Contacts_Index_Action');
     * // Returns: /contacts
     *
     * // Route with integer parameter (sets 'id')
     * $url = Rsx::Route('Contacts_View_Action', 123);
     * // Returns: /contacts/123
     *
     * // Route with named parameters (array)
     * $url = Rsx::Route('Contacts_View_Action', ['id' => 'C001']);
     * // Returns: /contacts/C001
     *
     * // Route with required and query parameters
     * $url = Rsx::Route('Contacts_View_Action', [
     *     'id' => 'C001',
     *     'tab' => 'history'
     * ]);
     * // Returns: /contacts/C001?tab=history
     *
     * // Placeholder route for scaffolding (doesn't need to exist)
     * $url = Rsx::Route('Future_Feature_Controller::#index');
     * // Returns: #
     * ```
     *
     * @param string $action Controller class, SPA action, or "Class::method". Defaults to 'index' method if not specified.
     * @param int|array|\stdClass|null $params Route parameters. Integer sets 'id', array/object provides named params.
     * @return string The generated URL
     * @throws RuntimeException If class doesn't exist, isn't a controller/action, method doesn't exist, or lacks Route attribute
     */
    public static function Route($action, $params = null)
    {
        // Parse action into class_name and action_name
        // Format: "Controller_Name" or "Controller_Name::method_name" or "Spa_Action_Name"
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

        // Placeholder route: action starts with # means unimplemented/scaffolding
        // Skip all validation and return placeholder
        if (str_starts_with($action_name, '#')) {
            return '#';
        }

        // Try to find the class in the manifest
        try {
            $metadata = Manifest::php_get_metadata_by_class($class_name);
        } catch (RuntimeException $e) {
            // Not found as PHP class - might be a SPA action, try that instead
            return static::_try_spa_action_route($class_name, $params_array);
        }

        // Verify it extends Rsx_Controller_Abstract
        $extends = $metadata['extends'] ?? '';
        $is_controller = false;

        if ($extends === 'Rsx_Controller_Abstract') {
            $is_controller = true;
        } else {
            // Check if it extends a class that extends Rsx_Controller_Abstract
            $current_class = $extends;
            $max_depth = 10;

            while ($current_class && $max_depth-- > 0) {
                try {
                    $parent_metadata = Manifest::php_get_metadata_by_class($current_class);
                    if (($parent_metadata['extends'] ?? '') === 'Rsx_Controller_Abstract') {
                        $is_controller = true;
                        break;
                    }
                    $current_class = $parent_metadata['extends'] ?? '';
                } catch (RuntimeException $e) {
                    // Check if parent is the abstract controller with FQCN
                    if ($current_class === 'Rsx_Controller_Abstract' ||
                        $current_class === 'App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract') {
                        $is_controller = true;
                    }
                    break;
                }
            }
        }

        if (!$is_controller) {
            throw new Rsx_Caller_Exception("Class {$class_name} must extend Rsx_Controller_Abstract");
        }

        // Check if method exists and has Route attribute
        if (!isset($metadata['public_static_methods'][$action_name])) {
            throw new Rsx_Caller_Exception("Method {$action_name} not found in class {$class_name}");
        }

        $method_info = $metadata['public_static_methods'][$action_name];

        // All methods in public_static_methods are guaranteed to be static
        // No need to check - but we assert for safety
        if (!isset($method_info['static']) || !$method_info['static']) {
            shouldnt_happen("Method {$class_name}::{$action_name} in public_static_methods is not static - extraction bug");
        }

        // Check for Ajax_Endpoint attribute
        $has_ajax_endpoint = false;

        if (isset($method_info['attributes'])) {
            foreach ($method_info['attributes'] as $attr_name => $attr_instances) {
                if ($attr_name === 'Ajax_Endpoint' || str_ends_with($attr_name, '\\Ajax_Endpoint')) {
                    $has_ajax_endpoint = true;
                    break;
                }
            }
        }

        // If has Ajax_Endpoint, return AJAX route URL (no param substitution)
        if ($has_ajax_endpoint) {
            $ajax_url = '/_ajax/' . urlencode($class_name) . '/' . urlencode($action_name);
            // Add query params if provided
            if (!empty($params_array)) {
                $ajax_url .= '?' . http_build_query($params_array);
            }
            return $ajax_url;
        }

        // Look up routes in manifest using routes_by_target
        $target = $class_name . '::' . $action_name;
        $manifest = Manifest::get_full_manifest();

        if (!isset($manifest['data']['routes_by_target'][$target])) {
            // Not a controller method with Route - check if it's a SPA action class
            return static::_try_spa_action_route($class_name, $params_array);
        }

        $routes = $manifest['data']['routes_by_target'][$target];

        // Select best matching route based on provided parameters
        $selected_route = static::_select_best_route($routes, $params_array);

        if (!$selected_route) {
            throw new Rsx_Caller_Exception(
                "No suitable route found for {$class_name}::{$action_name} with provided parameters. " .
                "Available routes: " . implode(', ', array_column($routes, 'pattern'))
            );
        }

        // Generate URL from selected pattern
        return static::_generate_url_from_pattern($selected_route['pattern'], $params_array, $class_name, $action_name);
    }

    /**
     * Try to generate URL for a SPA action class
     * Called when class lookup fails for controller - checks if it's a JavaScript SPA action
     *
     * @param string $class_name The class name (might be a JS SPA action)
     * @param array $params_array Parameters for URL generation
     * @return string The generated URL
     * @throws Rsx_Caller_Exception If not a valid SPA action or route not found
     */
    protected static function _try_spa_action_route(string $class_name, array $params_array): string
    {
        // Check if this is a JavaScript class that extends Spa_Action
        try {
            $is_spa_action = Manifest::js_is_subclass_of($class_name, 'Spa_Action');
        } catch (\RuntimeException $e) {
            // Not a JS class or not found
            throw new Rsx_Caller_Exception("Class {$class_name} must extend Rsx_Controller_Abstract or Spa_Action");
        }

        if (!$is_spa_action) {
            throw new Rsx_Caller_Exception("JavaScript class {$class_name} must extend Spa_Action to generate routes");
        }

        // Look up routes in manifest using routes_by_target
        $manifest = Manifest::get_full_manifest();

        if (!isset($manifest['data']['routes_by_target'][$class_name])) {
            throw new Rsx_Caller_Exception("SPA action {$class_name} has no registered routes in manifest");
        }

        $routes = $manifest['data']['routes_by_target'][$class_name];

        // Select best matching route based on provided parameters
        $selected_route = static::_select_best_route($routes, $params_array);

        if (!$selected_route) {
            throw new Rsx_Caller_Exception(
                "No suitable route found for SPA action {$class_name} with provided parameters. " .
                "Available routes: " . implode(', ', array_column($routes, 'pattern'))
            );
        }

        // Generate URL from selected pattern
        return static::_generate_url_from_pattern($selected_route['pattern'], $params_array, $class_name, '(SPA action)');
    }

    /**
     * Select the best matching route from available routes based on provided parameters
     *
     * Selection algorithm:
     * 1. Filter routes where all required parameters can be satisfied by provided params
     * 2. Among satisfiable routes, prioritize those with MORE parameters (more specific)
     * 3. If tie, any route works (deterministic by using first match)
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

        // Return the route with the most parameters
        return $satisfiable[0]['route'];
    }

    /**
     * Generate URL from route pattern by replacing parameters
     *
     * @param string $pattern The route pattern (e.g., '/users/:id/view')
     * @param array $params Parameters to fill into the route
     * @param string $class_name Controller class name (for error messages)
     * @param string $action_name Action name (for error messages)
     * @return string The generated URL
     * @throws RuntimeException If required parameters are missing
     */
    protected static function _generate_url_from_pattern($pattern, $params, $class_name, $action_name)
    {
        // Extract required parameters from the pattern
        $required_params = [];
        if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $pattern, $matches)) {
            $required_params = $matches[1];
        }

        // Check for required parameters
        $missing = [];
        foreach ($required_params as $required) {
            if (!array_key_exists($required, $params)) {
                $missing[] = $required;
            }
        }

        if (!empty($missing)) {
            throw new RuntimeException(
                "Required parameters [" . implode(', ', $missing) . "] are missing for route " .
                "{$pattern} on {$class_name}::{$action_name}"
            );
        }

        // Build the URL by replacing parameters
        $url = $pattern;
        $used_params = [];

        foreach ($required_params as $param_name) {
            $value = $params[$param_name];
            // URL encode the value
            $encoded_value = urlencode($value);
            $url = str_replace(':' . $param_name, $encoded_value, $url);
            $used_params[$param_name] = true;
        }

        // Collect any extra parameters for query string
        $query_params = [];
        $anchor = null;
        foreach ($params as $key => $value) {
            if (isset($used_params[$key])) {
                continue;
            }

            // The reserved anchor key rides the hash, not the query string, so
            // Rsx::Route('Action', ['at' => 'install']) deep-links to a section.
            // See: php artisan rsx:man anchors
            if ($key === self::HASH_ANCHOR_KEY) {
                $anchor = $value;
                continue;
            }

            $query_params[$key] = $value;
        }

        // Append query string if there are extra parameters
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        // Anchor last - a fragment always terminates the URL
        if ($anchor !== null && $anchor !== '') {
            $url .= '#' . self::HASH_ANCHOR_KEY . '=' . rawurlencode($anchor);
        }

        return $url;
    }

    /**
     * Trigger a filter event - data passes through chain of handlers
     *
     * Each handler receives the result of the previous handler and returns
     * the modified data. Use for transforming data through a pipeline.
     *
     * Example:
     * ```php
     * $params = Rsx::trigger_filter('file.upload.params', $params);
     * ```
     *
     * @param string $event Event name
     * @param mixed $data Data to pass through filter chain
     * @return mixed Transformed data
     */
    public static function trigger_filter(string $event, $data)
    {
        $handlers = Event_Registry::get_handlers($event);

        foreach ($handlers as $handler) {
            $data = $handler($data);
        }

        return $data;
    }

    /**
     * Trigger a gate event - first non-true return value halts execution
     *
     * Use for authorization, validation, and permission checks.
     * First handler that returns non-true halts the chain and returns that value.
     *
     * Example:
     * ```php
     * $result = Rsx::trigger_gate('file.upload.authorize', ['request' => $request]);
     * if ($result !== true) {
     *     return $result; // Handler returned error response
     * }
     * ```
     *
     * @param string $event Event name
     * @param mixed $data Data to pass to handlers
     * @return true|mixed Returns true if all handlers pass, or first non-true result
     */
    public static function trigger_gate(string $event, $data)
    {
        $handlers = Event_Registry::get_handlers($event);

        foreach ($handlers as $handler) {
            $result = $handler($data);
            if ($result !== true) {
                return $result; // First denial/error wins
            }
        }

        return true; // All handlers passed
    }

    /**
     * Trigger an action event - fire all handlers, ignore return values
     *
     * Use for logging, notifications, and other side effects.
     * All handlers are called regardless of their return values.
     *
     * Example:
     * ```php
     * Rsx::trigger_action('file.upload.complete', [
     *     'attachment' => $attachment,
     *     'request' => $request
     * ]);
     * ```
     *
     * @param string $event Event name
     * @param mixed $data Data to pass to handlers
     * @return void
     */
    public static function trigger_action(string $event, $data): void
    {
        $handlers = Event_Registry::get_handlers($event);

        foreach ($handlers as $handler) {
            $handler($data);
        }
    }

    /**
     * Trigger a resolve event - first handler that returns a non-null value wins.
     *
     * This is the "pluggable converter" primitive: a chain of handlers each get the
     * SAME input data (unlike trigger_filter, the value is not threaded handler-to-handler)
     * and the FIRST one to return anything other than null takes over. Handlers are visited
     * in priority order (lower priority number first).
     *
     * DECLINE vs INTERCEPT - the whole point of this primitive:
     * - A handler that returns NULL DECLINES ("not my job, keep asking"). The chain
     *   continues to the next handler.
     * - A handler that returns a NON-NULL value INTERCEPTS: it has produced the result,
     *   the chain stops, and that value is returned.
     *
     * TERMINAL DEFAULT - if EVERY handler declines (or there are no handlers), this returns
     * null. That is the signal to the CALLER to run its own built-in framework default
     * (the terminal branch of the pipeline). trigger_resolve itself never produces a default;
     * it only lets an app override or extend a framework behavior. Callers own the shape and
     * validation of a non-null result (e.g. document.extract_text enforces its own contract
     * and fails loud on garbage).
     *
     * Example - an app converter overriding a framework document pipeline:
     * ```php
     * // App handler, manifest-discovered:
     * #[OnEvent('document.extract_text')]
     * public static function extract_special($data) {
     *     if ($data['mime'] !== 'application/x-special') return null; // decline
     *     return Special_Lib::to_text($data['path']);                 // intercept
     * }
     *
     * // Framework caller:
     * $result = Rsx::trigger_resolve('document.extract_text', $payload);
     * if ($result === null) {
     *     // no app handler claimed it - run the framework default pipeline
     * }
     * ```
     *
     * @param string $event Event name
     * @param mixed $data Data passed unchanged to every handler until one intercepts
     * @return mixed The first non-null handler result, or null if all handlers declined
     */
    public static function trigger_resolve(string $event, $data)
    {
        $handlers = Event_Registry::get_handlers($event);

        foreach ($handlers as $handler) {
            $result = $handler($data);
            if ($result !== null) {
                return $result; // First interception wins
            }
        }

        return null; // All handlers declined - caller runs its terminal default
    }
}
