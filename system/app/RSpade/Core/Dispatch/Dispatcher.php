<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Dispatch;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use RuntimeException;
use Throwable;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Csp\Rsx_Csp;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Dispatch\AssetHandler;
use App\RSpade\Core\Dispatch\RouteResolver;
use App\RSpade\Core\Env\Rsx_Env_Hostname_Guard;
use App\RSpade\Core\Env\Rsx_First_User_Setup;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Portal\Portal_Dispatcher;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Flash\Flash_Alert;

/**
 * Dispatcher - Step 3 of RSX Request Processing
 *
 * CALLED BY: RsxController::handle() with URL, method, and parameters
 *
 * WHAT IT DOES:
 * 1. Initializes manifest (Manifest::init() handles loading/rebuilding as needed)
 * 2. Checks if request is for static asset (serves directly if so)
 * 3. Finds matching route using RouteResolver pattern matching
 * 4. Loads and validates the handler class
 * 5. Processes attributes (middleware, cache, rate limits, etc.) via AttributeProcessor
 * 6. Calls optional pre_dispatch hook on handler
 * 7. Executes the handler's action method
 * 8. Builds appropriate response (JSON, HTML, redirect, etc.)
 * 9. Runs post-processing for caching and other attributes
 *
 * FINAL STEPS:
 * → For assets: Returns file response directly to client
 * → For routes: Returns processed response (JSON/HTML/etc.) to client
 * → For errors: Returns 404/500 error response to client
 */
class Dispatcher
{
    // Manifest is now a static class - no instance needed

    /**
     * @var array Handler type priorities
     */
    protected static $handler_priorities = [
        'controllers' => 1,
        'api' => 2,
        'files' => 3,
        'custom' => 4,
    ];

    /**
     * Dispatch a request to the appropriate handler
     *
     * @param string $url The URL to dispatch
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array $extra_params Additional parameters to merge
     * @param Request|null $request Optional request object
     * @return mixed Response from handler, or null if no route found
     * @throws Exception
     */
    public static function dispatch($url, $method = 'GET', $extra_params = [], ?Request $request = null)
    {
        // CRITICAL: No try/catch - let errors fail loud per coding conventions
        // Laravel's exception handler will handle all exceptions properly

        console_debug('BENCHMARK', "Dispatch started for: {$method} {$url}");

        // Initialize manifest (handles all loading/rebuilding logic)
        console_debug('BENCHMARK', 'Initializing manifest');
        Manifest::init();
        console_debug('BENCHMARK', 'Manifest initialized');

        // Validate Route attributes are not on classes (development mode only)
        static::__validate_route_attributes();

        // Dev-mode tripwire: fail loud if a pasted/mismatched .env declares an
        // APP_URL host that differs from the host this request is actually browsed
        // under. Runs before the portal delegation so portal requests are covered
        // too; loopback requests exempt.
        Rsx_Env_Hostname_Guard::check();

        // First-run setup: with no credential records at all, offer to create the
        // first account rather than serving a login form nobody can get past.
        // Development mode only, and unreachable once one account exists.
        Rsx_First_User_Setup::check();

        $request = $request ?? request();

        // Check if this is a portal request - delegate to Portal_Dispatcher
        if (Rsx_Portal::is_portal_request()) {
            console_debug('DISPATCH', 'Portal request detected, delegating to Portal_Dispatcher');
            return Portal_Dispatcher::dispatch($url, $method, $extra_params, $request);
        }

        // Check if this is an external API request (/api/vN/...) - delegate to
        // Api_Dispatcher. This branch precedes the asset/FPC/HEAD-rewrite logic below,
        // so HEAD reaches the API dispatcher raw (it 405s it) and API responses never
        // acquire the FPC header.
        if (Api_Dispatcher::is_api_request($url)) {
            console_debug('DISPATCH', 'API request detected, delegating to Api_Dispatcher');
            return Api_Dispatcher::dispatch($url, $method, $extra_params, $request);
        }

        // CSRF enforcement (staff session space). POST-only, placed AFTER the
        // bearer-API branch (so the cookie-less external API stays exempt) and
        // BEFORE route handling, so this one seam covers /_ajax/:ctrl/:action,
        // /_ajax/_batch, /_upload, and native #[Route(POST)]. Session-gated:
        // allowed when there is no session to forge against. Assets are GET-only,
        // so they are unaffected.
        if ($method === 'POST') {
            \App\RSpade\Core\Session\Rsx_Csrf::enforce($request);
        }

        // Custom session is handled by Session::init() in RsxAuth

        // Establish the rsx:debug / Playwright development identity, if the request
        // carries a valid signed dev-auth header. This MUST precede route dispatch:
        // the declarative #[Auth] gates below run before any application code, so an
        // identity established later (as this backdoor used to be, inside the app's
        // Main::pre_dispatch) would be invisible to every gate and every debug run of
        // a gated page would deny. Placed AFTER CSRF enforcement so a dev-auth POST
        // keeps its existing session-less CSRF treatment. Portal requests are already
        // served by Portal_Dispatcher::__handle_dev_auth (the twin of this).
        static::__handle_dev_auth($request);

        // Check if this is an asset request
        console_debug('BENCHMARK', 'Checking for static asset');
        if (AssetHandler::is_asset_request($url)) {
            console_debug('BENCHMARK', "Serving static asset: {$url}");

            return AssetHandler::serve($url, $request);
        }
        console_debug('BENCHMARK', 'Not a static asset, continuing route dispatch');

        // HEAD requests should be treated as GET for route matching and handlers
        // Store the original method for response transformation
        $original_method = $method;
        $route_method = ($method === 'HEAD') ? 'GET' : $method;

        // Make the request appear as GET to all handlers if it's HEAD
        if ($method === 'HEAD' && $request) {
            $request->setMethod('GET');
        }

        // Find matching route
        \Log::debug("Dispatcher: Looking for route: $url, method: $route_method");
        console_debug('DISPATCH', 'Looking for route:', $url, 'method:', $route_method);
        console_debug('BENCHMARK', 'Finding matching route');
        $route_match = static::__find_route($url, $route_method);
        console_debug('BENCHMARK', 'Route search complete');

        if (!$route_match) {
            \Log::debug("Dispatcher: No route found for: $url");
            console_debug('DISPATCH', 'No route found for:', $url);

            // Check if this matches the default route pattern: /_/{controller}/{action}
            if (preg_match('#^/_/([A-Za-z_][A-Za-z0-9_]*)/([A-Za-z_][A-Za-z0-9_]*)/?$#', $url, $matches)) {
                $controller_name = $matches[1];
                $action_name = $matches[2];

                console_debug('DISPATCH', 'Matched default route pattern:', $controller_name, '::', $action_name);

                // First try to find as PHP controller
                try {
                    $metadata = Manifest::php_get_metadata_by_class($controller_name);
                    $controller_fqcn = $metadata['fqcn'];

                    // Verify it extends Rsx_Controller_Abstract
                    if (!Manifest::php_is_subclass_of($controller_name, 'Rsx_Controller_Abstract')) {
                        console_debug('DISPATCH', 'Class does not extend Rsx_Controller_Abstract:', $controller_name);

                        return static::__transform_response(Error_Screens::not_found($request), $original_method);
                    }

                    // Verify the method exists and has a Route attribute
                    if (!isset($metadata['public_static_methods'][$action_name])) {
                        console_debug('DISPATCH', 'Method not found:', $action_name);

                        return static::__transform_response(Error_Screens::not_found($request), $original_method);
                    }

                    $method_data = $metadata['public_static_methods'][$action_name];
                    if (!isset($method_data['attributes']['Route'])) {
                        console_debug('DISPATCH', 'Method does not have Route attribute:', $action_name);

                        return static::__transform_response(Error_Screens::not_found($request), $original_method);
                    }

                    // For POST requests: execute the action
                    if ($route_method === 'POST') {
                        // Collect parameters from GET query string only (not POST data)
                        $params = array_merge($extra_params, $request->query->all());

                        // Create synthetic route match. This path bypasses the route
                        // table, so the gate list is read straight from the auth
                        // surface index (same merged list the row would carry).
                        $route_match = [
                            'class' => $controller_fqcn,
                            'method' => $action_name,
                            'params' => $params,
                            'pattern' => "/_/{$controller_name}/{$action_name}",
                            'auth' => Auth_Gates::surface_gates($controller_name . '::' . $action_name),
                        ];

                        // Continue with normal dispatch (will handle auth, pre_dispatch, etc.)
                        // Fall through to normal route handling below
                    } else {
                        // For GET requests: redirect to the proper route
                        $params = array_merge($extra_params, $request->query->all());

                        // Generate proper URL using Rsx::Route (signature: "Controller::method", $params)
                        $proper_url = Rsx::Route($controller_name . '::' . $action_name, $params);

                        console_debug('DISPATCH', 'Redirecting GET to proper route:', $proper_url);

                        return redirect($proper_url, 302);
                    }
                } catch (\RuntimeException $e) {
                    console_debug('DISPATCH', 'Not a PHP controller, checking if SPA action:', $controller_name);

                    // Not found as PHP controller - check if it's a SPA action
                    try {
                        $is_spa_action = Manifest::js_is_subclass_of($controller_name, 'Spa_Action');

                        if ($is_spa_action) {
                            console_debug('DISPATCH', 'Found SPA action class:', $controller_name);

                            // Get the file path for this JS class
                            $file_path = Manifest::js_find_class($controller_name);

                            // Get file metadata which contains decorator information
                            $file_data = Manifest::get_file($file_path);

                            if (!$file_data) {
                                console_debug('DISPATCH', 'SPA action metadata not found:', $controller_name);

                                return static::__transform_response(Error_Screens::not_found($request), $original_method);
                            }

                            // Extract route pattern from @route() decorator
                            // Format: [[0 => 'route', 1 => ['/contacts']], ...]
                            $route_pattern = null;
                            if (isset($file_data['decorators']) && is_array($file_data['decorators'])) {
                                foreach ($file_data['decorators'] as $decorator) {
                                    if (isset($decorator[0]) && $decorator[0] === 'route') {
                                        if (isset($decorator[1][0])) {
                                            $route_pattern = $decorator[1][0];
                                            break;
                                        }
                                    }
                                }
                            }

                            if ($route_pattern) {
                                // Generate proper URL for the SPA action
                                // Note: SPA actions use class name only (action_name is ignored for SPA routes)
                                $params = array_merge($extra_params, $request->query->all());
                                $proper_url = Rsx::Route($controller_name, $params);

                                console_debug('DISPATCH', 'Redirecting to SPA action route:', $proper_url);

                                return redirect($proper_url, 302);
                            } else {
                                console_debug('DISPATCH', 'SPA action missing @route() decorator:', $controller_name);
                            }
                        }
                    } catch (\RuntimeException $spa_e) {
                        console_debug('DISPATCH', 'Not a SPA action either:', $controller_name);
                    }

                    return static::__transform_response(Error_Screens::not_found($request), $original_method);
                }
            }

            if (!$route_match) {
                // No route found - try Main pre_dispatch and unhandled_route hooks
                $params = array_merge(request()->all(), $extra_params);

                // First try Main pre_dispatch
                $main_classes = Manifest::php_get_extending('Main_Abstract');
                foreach ($main_classes as $main_class) {
                    if (isset($main_class['fqcn']) && $main_class['fqcn']) {
                        $main_class_name = $main_class['fqcn'];
                        Debugger::console_debug('[DISPATCH]', 'Main::pre_dispatch');
                        $result = $main_class_name::pre_dispatch($request, $params);
                        if ($result !== null) {
                            $response = static::__build_response($result);

                            return static::__transform_response($response, $original_method);
                        }
                    }
                }

                // Then try unhandled_route hook
                foreach ($main_classes as $main_class) {
                    if (isset($main_class['fqcn']) && $main_class['fqcn']) {
                        $main_class_name = $main_class['fqcn'];
                        $result = $main_class_name::unhandled_route($request, $params);
                        if ($result !== null) {
                            $response = static::__build_response($result);

                            return static::__transform_response($response, $original_method);
                        }
                    }
                }

                // Nothing claimed this URL. Main::pre_dispatch and
                // Main::unhandled_route both had first refusal above; the terminal
                // outcome is ours to render, not Laravel's unthemed default.
                return static::__transform_response(Error_Screens::not_found($request), $original_method);
            }
        }

        // Extract route information
        $handler_class = $route_match['class'];
        $handler_method = $route_match['method'];
        $params = $route_match['params'] ?? [];

        // Merge parameters with correct priority order:
        // 1. Extra parameters (usually empty, lowest priority)
        // 2. GET parameters (from query string)
        // 3. URL route parameters (extracted from route pattern like :id)
        // Note: POST parameters are NOT included - controller $params only contains GET and route params
        $get_params = $request->query->all();
        $params = array_merge($extra_params, $get_params, $params);

        // Add special parameters
        $params['_method'] = $method;
        $params['_route'] = $route_match['pattern'] ?? $url;
        $params['_handler'] = $handler_class;

        Debugger::console_debug('DISPATCH', 'Matched route to ' . $handler_class . '::' . $handler_method . ' params: ' . json_encode($params));

        // --- FPC Detection ---
        // Check if route has #[FPC] attribute and conditions are met for caching
        $has_fpc = false;
        try {
            $fpc_metadata = Manifest::php_get_metadata_by_fqcn($handler_class);
            $fpc_method_data = $fpc_metadata['public_static_methods'][$handler_method] ?? null;

            if ($fpc_method_data && isset($fpc_method_data['attributes']['FPC'])) {
                // FPC only active for unauthenticated GET requests without POST/FILE data
                if ($route_method === 'GET' && empty($_POST) && empty($_FILES)) {
                    \App\RSpade\Core\Session\Session::init();

                    if (!\App\RSpade\Core\Session\Session::is_logged_in()) {
                        $has_fpc = true;

                        // Blank all cookies except session to prevent tainted output
                        foreach ($_COOKIE as $key => $value) {
                            if ($key !== 'rsx') {
                                unset($_COOKIE[$key]);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            console_debug('FPC', 'Metadata lookup failed: ' . $e->getMessage());
        }
        // --- End FPC Detection ---

        // Set current controller and action in Rsx for tracking
        $route_type = $route_match['type'] ?? 'standard';
        \App\RSpade\Core\Rsx::_set_current_controller_action($handler_class, $handler_method, $params, $route_type);

        // Load and validate handler class
        static::__load_handler_class($handler_class);

        // --- Declarative #[Auth] gates ---
        // The framework authorization seam for #[Route] / #[SPA] surfaces: every gate
        // the matched route declares must pass BEFORE any application code runs -
        // Main::pre_dispatch, the controller's pre_dispatch, and the action all come
        // after. Denial reuses the existing unauthorized channel, so the split is
        // unchanged: no session -> 302 to login with the intended URL captured;
        // authenticated but denied -> 403.
        //
        // A surface declaring no gates passes through untouched. Closed-by-default
        // (every surface must declare one) is a manifest-build rule, not a runtime
        // one. See: php artisan rsx:man auth_gates
        $route_gates = $route_match['auth'] ?? [];
        if (!empty($route_gates)) {
            $gate_surface = $handler_class . '::' . $handler_method;

            if (!Auth_Gates::gates_pass_at_seam($route_gates, Auth_Gates::active_realm(), $gate_surface)) {
                console_debug('DISPATCH', 'Auth gates denied:', $gate_surface);

                $response = static::__build_response(response_unauthorized());

                return static::__transform_response($response, $original_method);
            }
        }

        // Call pre_dispatch hooks
        $pre_dispatch_result = static::__call_pre_dispatch($handler_class, $handler_method, $params, $request);
        if ($pre_dispatch_result !== null) {
            // Pre-dispatch returned a response, build and return it
            $response = static::__build_response($pre_dispatch_result);

            return static::__transform_response($response, $original_method);
        }

        // Call the action method
        $result = static::__call_action($handler_class, $handler_method, $params, $request);

        // rsx.post_dispatch - fired immediately after the handler returns and before
        // response shaping, on the SUCCESS path only (never after an exception). Handlers
        // run INLINE and may throw; that is the point (the Turnstile completeness guard is
        // one). Handlers must not mutate 'result'. NOTE: $params here is GET + route params
        // only - the POST body is never merged into it, so a handler reading submitted
        // fields must read them off the Request.
        // See: php artisan rsx:man event_hooks
        \App\RSpade\Core\Rsx::trigger_action('rsx.post_dispatch', [
            'request' => $request,
            'params' => $params,
            'result' => $result,
        ]);

        // Convert result to response
        $response = static::__build_response($result);

        // Add FPC header if conditions were met — signals the FPC proxy to cache this response
        if ($has_fpc && $response instanceof \Symfony\Component\HttpFoundation\Response) {
            $response->headers->set('X-RSpade-FPC', '1');
            $response->headers->remove('Set-Cookie');
        }

        // Apply response transformations (HEAD body stripping, etc.)
        return static::__transform_response($response, $original_method, $request);
    }

    /**
     * Establish the staff development identity from signed dev-auth headers.
     *
     * The staff twin of Portal_Dispatcher::__handle_dev_auth: rsx:debug (and the
     * Playwright harness) sends X-Dev-Auth-User-Id plus an HMAC of the request URI +
     * user id + realm, signed with APP_KEY. Development environments only; an absent
     * or invalid token grants no authentication override - but a token that is PRESENT and
     * rejected names its failure through console_debug('AUTH', ...), because a rejected token
     * is always a bug and never a legitimate anonymous request.
     *
     * This lives in the framework dispatcher rather than the application's
     * Main::pre_dispatch because it must run BEFORE the declarative #[Auth] gate
     * evaluation - a gate asking "is this caller logged in" has to see the identity
     * the harness is asserting.
     *
     * @param Request $request
     * @return void
     */
    protected static function __handle_dev_auth(Request $request): void
    {
        if (!in_array(app()->environment(), ['local', 'development', 'testing'], true)) {
            return;
        }

        $dev_auth_user_id = $request->header('X-Dev-Auth-User-Id');
        $dev_auth_token = $request->header('X-Dev-Auth-Token');

        if (!$dev_auth_user_id || !$dev_auth_token) {
            return;
        }

        // Both headers are present from here on: a rejection is always a bug, never a
        // legitimate anonymous request, so every failure path names itself.
        $app_key = config('app.key');
        if (!$app_key) {
            console_debug('AUTH', 'DEV AUTH REJECTED: APP_KEY not configured - rendering anonymous');
            return;
        }

        // Must match Route_Debug_Command::generate_dev_auth_token byte for byte.
        $payload = json_encode([
            'url' => $request->getRequestUri(),
            'user_id' => (int) $dev_auth_user_id,
            'portal' => false,
        ]);

        if (!hash_equals(hash_hmac('sha256', $payload, $app_key), $dev_auth_token)) {
            console_debug('AUTH', 'DEV AUTH REJECTED: signature mismatch for URI ' . $request->getRequestUri()
                . ' user ' . (int) $dev_auth_user_id . ' - rendering anonymous');
            return;
        }

        $user = \Login_User_Model::find((int) $dev_auth_user_id);
        if (!$user) {
            console_debug('AUTH', 'DEV AUTH REJECTED: login user not found: ' . (int) $dev_auth_user_id
                . ' - rendering anonymous');
            return;
        }

        // A harness login is not a real login, so it must not stamp last_login (same principle
        // as an impersonation identity swap).
        \App\RSpade\Core\Auth\RsxAuth::login($user, touch_last_login: false);

        console_debug('AUTH', "DEV AUTH: Authenticated as user {$user->id} via signed X-Dev-Auth-Token");
    }

    /**
     * Resolve a URL to a route (public interface for code quality checks)
     *
     * @param string $url The URL to resolve (with or without query string)
     * @param string $method HTTP method (default GET)
     * @return array|null Array with 'class', 'method', 'params', 'pattern' or null
     */
    public static function resolve_url_to_route($url, $method = 'GET')
    {
        // Initialize manifest if needed
        Manifest::init();

        // Use internal method
        return static::__find_route($url, $method);
    }

    /**
     * Find a matching route for the URL and method
     *
     * @param string $url
     * @param string $method
     * @return array|null Route match with class, method, params
     */
    protected static function __find_route($url, $method)
    {
        // Get routes from manifest
        $routes = Manifest::get_routes();

        if (empty($routes)) {
            \Log::debug('Manifest::get_routes() returned empty array');
            console_debug('DISPATCH', 'Warning: got 0 routes from Manifest::get_routes()');
            return null;
        }

        \Log::debug('Manifest has ' . count($routes) . ' routes');

        // Get all patterns and sort by priority
        $patterns = array_keys($routes);
        $patterns = RouteResolver::sort_by_priority($patterns);

        // Try to match each pattern
        foreach ($patterns as $pattern) {
            $route = $routes[$pattern];

            // Check if HTTP method is supported
            if (!in_array($method, $route['methods'])) {
                continue;
            }

            // Try to match the URL
            $params = RouteResolver::match_with_query($url, $pattern);

            if ($params !== false) {
                // Found a match - verify the method has the required attribute
                $class_fqcn = $route['class'];
                $method_name = $route['method'];

                // Get method metadata from manifest
                $class_metadata = Manifest::php_get_metadata_by_fqcn($class_fqcn);
                $method_metadata = $class_metadata['public_static_methods'][$method_name] ?? null;

                if (!$method_metadata) {
                    throw new \RuntimeException(
                        "Route method not found in manifest: {$class_fqcn}::{$method_name}\n" .
                        "Pattern: {$pattern}"
                    );
                }

                // Check for Route or SPA attribute
                $attributes = $method_metadata['attributes'] ?? [];
                $has_route = false;

                foreach ($attributes as $attr_name => $attr_instances) {
                    if (str_ends_with($attr_name, '\\Route') || $attr_name === 'Route' ||
                        str_ends_with($attr_name, '\\SPA') || $attr_name === 'SPA') {
                        $has_route = true;
                        break;
                    }
                }

                if (!$has_route) {
                    // #[Api_Endpoint] routes are dispatched by Api_Dispatcher (branched
                    // earlier on the /api/vN/ path); a type-'api' route reaching the main
                    // dispatcher is a defect and fails loud here.
                    throw new \RuntimeException(
                        "Route method {$class_fqcn}::{$method_name} is missing required #[Route] or #[SPA] attribute.\n" .
                        "Pattern: {$pattern}\n" .
                        "File: {$route['file']}"
                    );
                }

                // Return route with params. 'auth' is the declarative gate list the
                // manifest baked onto the row (class-level #[Auth] merged with the
                // method's own); the dispatcher evaluates it before pre_dispatch.
                return [
                    'type' => $route['type'],
                    'pattern' => $pattern,
                    'class' => $route['class'],
                    'method' => $route['method'],
                    'params' => $params,
                    'file' => $route['file'] ?? null,
                    'auth' => $route['auth'] ?? [],
                ];
            }
        }

        // No match found
        return null;
    }

    /**
     * Load and validate handler class
     *
     * @param string $class_name
     * @throws Exception
     */
    protected static function __load_handler_class($class_name)
    {
        // Use Manifest to verify the class exists
        // Check if this is already a FQCN (contains backslash)
        if (strpos($class_name, '\\') !== false) {
            // It's a FQCN, use php_get_metadata_by_fqcn
            try {
                $metadata = Manifest::php_get_metadata_by_fqcn($class_name);
                $fqcn = $metadata['fqcn'];
            } catch (RuntimeException $e) {
                throw new Exception("Handler class not found in manifest: {$class_name}");
            }
        } else {
            // It's a simple name, try different approaches
            try {
                $metadata = Manifest::php_get_metadata_by_class($class_name);
                // Class exists in manifest, trigger autoloading by referencing the FQCN
                $fqcn = $metadata['fqcn'];
                // The autoloader will handle loading when we reference the class
            } catch (RuntimeException $e) {
                // Try with Rsx namespace prefix
                try {
                    $metadata = Manifest::php_get_metadata_by_class('Rsx\\' . $class_name);
                    $fqcn = $metadata['fqcn'];
                } catch (RuntimeException $e2) {
                    throw new Exception("Handler class not found in manifest: {$class_name}");
                }
            }
        }
    }

    /**
     * Call pre_dispatch hook
     *
     * @param string $class_name
     * @param string $method_name
     * @param array $params
     * @param Request|null $request
     * @return mixed|null Returns non-null to halt dispatch with that response
     */
    protected static function __call_pre_dispatch($class_name, $method_name, &$params, ?Request $request = null)
    {
        $request = $request ?? request();

        // First, call pre_dispatch on Main classes (if any exist)
        $main_classes = Manifest::php_get_extending('Main_Abstract');
        foreach ($main_classes as $main_class) {
            if (isset($main_class['fqcn']) && $main_class['fqcn']) {
                $main_class_name = $main_class['fqcn'];
                $result = $main_class_name::pre_dispatch($request, $params);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // Then call pre_dispatch on the controller itself
        // Note: Controller pre_dispatch is handled in call_action for instance controllers
        // Only handle static pre_dispatch here for non-controller classes.
        //
        // NO try/catch: a static pre_dispatch that THROWS is denying access (or
        // failing for a real reason). Swallowing the exception and returning null
        // means "hook passed, proceed to the action" - it would downgrade an auth
        // denial to a log line and dispatch the request AS IF AUTHORIZED. Let the
        // exception bubble to the framework exception handler.
        if (!static::__is_controller($class_name)) {
            $reflection = new ReflectionClass($class_name);

            if ($reflection->hasMethod('pre_dispatch')) {
                $pre_dispatch = $reflection->getMethod('pre_dispatch');

                if ($pre_dispatch->isStatic() && $pre_dispatch->isPublic()) {
                    $result = $pre_dispatch->invoke(null, $request, $params);

                    // If pre_dispatch returns non-null, return that value
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Call the action method
     *
     * @param string $class_name
     * @param string $method_name
     * @param array $params
     * @param array $attributes
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    protected static function __call_action($class_name, $method_name, $params, ?Request $request = null)
    {
        $request = $request ?? request();
        $reflection = new ReflectionClass($class_name);

        if (!$reflection->hasMethod($method_name)) {
            throw new Exception("Method not found: {$class_name}::{$method_name}");
        }

        $method = $reflection->getMethod($method_name);

        if (!$method->isPublic()) {
            throw new Exception("Method not public: {$class_name}::{$method_name}");
        }

        // NOTE: Do NOT call _set_current_controller_action here - it's already been set
        // earlier in the dispatch flow with the correct route type. Calling it again
        // would overwrite the route type with null.

        // Check if this is a controller (all methods are static)
        if (static::__is_controller($class_name)) {
            // Rsx_Controller_Abstract declares pre_dispatch, so every controller has it
            $result = $class_name::pre_dispatch($request, $params);
            if ($result !== null) {
                // Don't clear tracking - view might need it during rendering
                return $result;
            }

            // Call the action method statically with request and params
            $result = $class_name::$method_name($request, $params);

            // Don't clear tracking - view needs it during rendering
            // The values will be overwritten on the next request
            return $result;
        }

        // For other handlers, check if static
        if (!$method->isStatic()) {
            // Create instance for non-static methods
            $instance = app()->make($class_name);
            $result = $method->invoke($instance, $request, $params);

            // Don't clear tracking - view needs it during rendering
            return $result;
        }

        // Call static method
        $result = $method->invoke(null, $request, $params);

        // Don't clear tracking - view needs it during rendering
        return $result;
    }

    /**
     * Check if class is a controller
     *
     * @param string $class_name
     * @return bool
     */
    protected static function __is_controller($class_name)
    {
        // Manifest is a guaranteed-present core class; php_is_subclass_of resolves
        // through isset()-guarded manifest data and returns false for an unknown
        // class rather than throwing (No Defensive Coding for Core Classes).
        return \App\RSpade\Core\Manifest\Manifest::php_is_subclass_of(
            $class_name,
            'App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract'
        );
    }

    /**
     * Build response from handler result
     *
     * @param mixed $result
     * @return Response
     */
    protected static function __build_response($result)
    {
        // Handle special RSX response types
        if ($result instanceof \App\RSpade\Core\Response\Rsx_Response_Abstract) {
            return static::__handle_special_response($result);
        }

        // If already a Response object (check Symfony base class to catch all response types)
        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        // Handle View objects
        if ($result instanceof \Illuminate\View\View || $result instanceof \Illuminate\Contracts\View\View) {
            return response($result);
        }

        // Handle array responses with type hints
        if (is_array($result) && isset($result['type'])) {
            return static::__build_typed_response($result);
        }

        // Default: return as JSON
        return response()->json($result);
    }

    /**
     * Build response from typed result array
     *
     * @param array $result
     * @return Response
     */
    protected static function __build_typed_response($result)
    {
        $type = $result['type'];
        $status = $result['status'] ?? 200;
        $headers = $result['headers'] ?? [];

        switch ($type) {
            case 'view':
                return response()
                    ->view($result['view'], $result['data'] ?? [], $status)
                    ->withHeaders($headers);

            case 'json':
                return response()->json($result['data'] ?? $result, $status, $headers);

            case 'redirect':
                $status = $result['status'] ?? 302;

                return response('', $status)->header('Location', $result['url']);

            case 'file':
                $response = response()->file($result['path'], $headers);

                if (isset($result['name'])) {
                    $disposition = $result['disposition'] ?? 'attachment';
                    $response->header('Content-Disposition', "{$disposition}; filename=\"" . $result['name'] . '"');
                }

                return $response;

            case 'error':
                abort($result['code'] ?? 500, $result['message'] ?? 'Server Error');

                // no break
            case 'empty':
                return response('', $status, $headers);

            case 'stream':
                return response()->stream($result['callback'], $status, $headers);

            default:
                // Unknown type, return as JSON
                return response()->json($result, $status, $headers);
        }
    }

    /**
     * Handle special RSX response types for HTTP requests
     *
     * @param \App\RSpade\Core\Response\Rsx_Response_Abstract $response
     * @return \Illuminate\Http\Response
     */
    protected static function __handle_special_response(\App\RSpade\Core\Response\Rsx_Response_Abstract $response)
    {
        $type = $response->get_type();
        $reason = $response->get_reason();
        $details = $response->get_details();

        // Handle fatal error - always throw exception
        if ($type === 'fatal') {
            $message = $reason;
            if (!empty($details)) {
                $message .= ' - ' . json_encode($details);
            }

            throw new Exception($message);
        }

        // Handle authentication required and unauthorized (full-page path only; the
        // ajax/API path is handled separately by Ajax::_handle_special_response).
        //
        // Both route to Error_Screens::unauthorized(), which OWNS the split: no
        // session -> the login route with the intended URL threaded through
        // Login_Redirect; authenticated -> a themed 403. The reason is flashed here
        // (not there) because the flash is only ever read by the login page the
        // unidentified caller is about to land on.
        if ($type === \App\RSpade\Core\Ajax\Ajax::ERROR_AUTH_REQUIRED
            || $type === \App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED) {
            if ($reason && !Session::is_logged_in()) {
                Flash_Alert::error($reason);
            }

            return Error_Screens::unauthorized(request());
        }

        // Handle validation and not found errors
        if ($type === \App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION || $type === \App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND) {
            // Only redirect if this was a POST request
            if (request()->isMethod('POST')) {
                Flash_Alert::error($reason);

                // Redirect to same URL as GET request
                return redirect(request()->url());
            }

            // Not a POST request, throw exception
            throw new Exception($reason);
        }

        // Unknown response type
        throw new Exception("Unknown RSX response type: {$type}");
    }

    /**
     * Handle 404 not found
     *
     * @param string $url
     * @param string $method
     * @return Response
     */
    protected static function __handle_not_found($url, $method)
    {
        Log::warning("Route not found: {$method} {$url}");

        // Try to find a custom 404 handler
        $custom_404 = static::__find_route('/404', 'GET');

        if ($custom_404) {
            try {
                $result = static::__call_action($custom_404['class'], $custom_404['method'], ['url' => $url, 'method' => $method]);

                return static::__build_response($result);
            } catch (Throwable $e) {
                Log::error('Custom 404 handler failed: ' . $e->getMessage());
            }
        }

        // Default 404 response
        abort(404, "Route not found: {$url}");
    }

    /**
     * Transform response based on original request method
     *
     * This centralized method handles all response transformations
     * including stripping body for HEAD requests
     *
     * @param mixed $response The response to transform
     * @param string $original_method The original HTTP method
     * @param Request|null $request The request (needed to restore HEAD on file/stream responses)
     * @return mixed The transformed response
     */
    protected static function __transform_response($response, $original_method, ?Request $request = null)
    {
        // Session cookie handling moved to custom Session class

        // If this was originally a HEAD request, strip the body.
        if ($original_method === 'HEAD' && $response instanceof \Symfony\Component\HttpFoundation\Response) {
            // A BinaryFileResponse / StreamedResponse FORBIDS setContent() (it throws a
            // LogicException - the body is a file handle / callback, not a settable string).
            // These types omit the body themselves when the request method is HEAD
            // (BinaryFileResponse zeroes its read length in prepare()), so restore HEAD on the
            // request - which was rewritten to GET above so handlers saw GET - and let the
            // response strip its own body while keeping the headers. A plain Response has its
            // string body cleared directly.
            if ($response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
                || $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
                if ($request) {
                    $request->setMethod('HEAD');
                }
            } else {
                $response->setContent('');
            }
        }

        // Content-Security-Policy. THIS is the seam - every dispatched staff response passes
        // through here, so one call covers native routes, SPA bootstraps, error screens and
        // pre_dispatch redirects alike. (Responses that never reach the dispatcher - vendor
        // error pages, ignition - carry no policy; out of scope by design.)
        Rsx_Csp::apply_to_response($response, 'staff');

        // Future transformations can be added here

        return $response;
    }

    /**
     * Set custom handler priorities
     *
     * @param array $priorities
     */
    public static function set_handler_priorities($priorities)
    {
        static::$handler_priorities = $priorities;
    }

    /**
     * Get current handler priorities
     *
     * @return array
     */
    public static function get_handler_priorities()
    {
        return static::$handler_priorities;
    }

    /**
     * Validate that Route attributes are not placed on classes
     * Only runs in non-production mode
     *
     * @throws RuntimeException if Route attributes found on classes
     */
    protected static function __validate_route_attributes()
    {
        // Only validate in non-production mode
        if (app()->environment('production')) {
            return;
        }

        // Get all manifest entries
        $manifest = Manifest::get_all();

        $errors = [];

        // Check each file for class-level Route attributes
        foreach ($manifest as $file_path => $metadata) {
            // Skip non-PHP files (controllers are PHP files)
            if (!isset($metadata['type']) || ($metadata['type'] !== 'php' && $metadata['type'] !== 'controller')) {
                continue;
            }

            // Check if this file has class-level attributes
            // Attributes are stored with simple names (e.g., "Route" not "App\RSpade\Core\Attributes\Route")
            if (isset($metadata['attributes']) && is_array($metadata['attributes']) && !empty($metadata['attributes'])) {
                foreach ($metadata['attributes'] as $attr_name => $attr_data) {
                    // Check for Route-related attributes (simple names)
                    if ($attr_name === 'Route' || $attr_name === 'Get' || $attr_name === 'Post' ||
                        $attr_name === 'Put' || $attr_name === 'Delete' || $attr_name === 'Patch') {
                        $class_name = $metadata['class'] ?? 'Unknown';
                        $errors[] = [
                            'file' => $file_path,
                            'class' => $class_name,
                            'attribute' => $attr_name,
                        ];
                    }
                }
            }
        }

        // If errors found, throw fatal error with detailed message
        if (!empty($errors)) {
            $error_msg = "Route attributes should be assigned to static methods in a controller class, not as an attribute on the class itself.\n\n";
            $error_msg .= "The following classes have Route attributes incorrectly placed on the class:\n\n";

            foreach ($errors as $error) {
                $error_msg .= "  File: {$error['file']}\n";
                $error_msg .= "  Class: {$error['class']}\n";
                $error_msg .= "  Attribute: #{$error['attribute']}\n\n";
            }

            $error_msg .= "To fix this, move the Route attribute to a static method within the class.\n";
            $error_msg .= "Example:\n";
            $error_msg .= "  class My_Controller extends Rsx_Controller_Abstract {\n";
            $error_msg .= "      #[Route('/path', methods: ['GET'])]\n";
            $error_msg .= "      public static function index(Request \$request, array \$params = []) {\n";
            $error_msg .= "          // ...\n";
            $error_msg .= "      }\n";
            $error_msg .= "  }\n";

            throw new RuntimeException($error_msg);
        }
    }
}
