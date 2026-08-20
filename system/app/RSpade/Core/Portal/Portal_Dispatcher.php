<?php

namespace App\RSpade\Core\Portal;

use Illuminate\Http\Request;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Csp\Rsx_Csp;
use App\RSpade\Core\Dispatch\AssetHandler;
use App\RSpade\Core\Dispatch\RouteResolver;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;

/**
 * Portal_Dispatcher - Handles dispatch for client portal requests
 *
 * Similar to Dispatcher but for portal routes:
 * - Uses portal_routes from manifest instead of routes
 * - Uses Portal_Session instead of Session
 * - Calls portal.php hooks instead of main.php
 * - Strips portal prefix in development mode
 */
class Portal_Dispatcher
{
    /**
     * Check if URL is a portal request and should be handled by portal dispatcher
     *
     * Detection logic:
     * 1. If portal domain configured: check if request host matches
     * 2. If no domain: check if URL starts with portal prefix
     *
     * @param string $url Request URL
     * @param Request|null $request Optional request object
     * @return bool True if this is a portal request
     */
    public static function is_portal_request(string $url, ?Request $request = null): bool
    {
        return Rsx_Portal::is_portal_request();
    }

    /**
     * Get the normalized portal URL (with prefix stripped if applicable)
     *
     * @param string $url Original request URL
     * @return string URL normalized for portal route matching
     */
    public static function normalize_portal_url(string $url): string
    {
        // If not using dedicated domain, strip the prefix
        if (!Rsx_Portal::has_dedicated_domain()) {
            $prefix = Rsx_Portal::get_prefix();
            if (str_starts_with($url, $prefix)) {
                $url = substr($url, strlen($prefix)) ?: '/';
            }
        }

        return $url;
    }

    /**
     * Resolve a portal URL to its registered portal route (the portal-table mirror
     * of Dispatcher::resolve_url_to_route). Normalizes the portal prefix, then
     * matches against the portal route patterns. Returns the match array or null.
     *
     * Used by Login_Redirect's routability gate to confirm, in portal context,
     * that a return target is handled by a registered portal route.
     *
     * @param string $url The URL to resolve (portal-prefixed or normalized)
     * @param string $method HTTP method
     * @return array|null Route match (class/method/params/pattern) or null
     */
    public static function resolve_url_to_route(string $url, string $method = 'GET'): ?array
    {
        Manifest::init();

        return static::__find_portal_route(static::normalize_portal_url($url), $method);
    }

    /**
     * Dispatch a portal request to the appropriate handler
     *
     * @param string $url The URL to dispatch (will be normalized)
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array $extra_params Additional parameters to merge
     * @param Request|null $request Optional request object
     * @return mixed Response from handler, or null if no route found
     */
    public static function dispatch(string $url, string $method = 'GET', array $extra_params = [], ?Request $request = null)
    {
        $response = static::__dispatch($url, $method, $extra_params, $request);

        // Content-Security-Policy, portal realm. THIS is the portal's funnel - every return
        // path of __dispatch() (routed action, unauthorized screen, 404) passes through here,
        // exactly as Dispatcher::__transform_response is the staff one. The HTML-only rule and
        // the policy itself live once, in Rsx_Csp.
        Rsx_Csp::apply_to_response($response, 'portal');

        return $response;
    }

    /**
     * Resolve a portal request to a response. Every return path funnels back through
     * dispatch() above, which is where cross-cutting response shaping belongs.
     *
     * @param string $url
     * @param string $method
     * @param array $extra_params
     * @param Request|null $request
     * @return mixed
     */
    protected static function __dispatch(string $url, string $method = 'GET', array $extra_params = [], ?Request $request = null)
    {
        console_debug('PORTAL', "Portal dispatch started for: {$method} {$url}");

        // Initialize manifest (needed to find Portal_Main below; idempotent)
        Manifest::init();

        // Portal_Main::init() - the FIRST application code to run in a portal
        // request, and the documented place to declare the portal's site
        // (Portal_Session::set_site_id). Everything after this point can ask for a
        // site: dev auth, CSRF, the #[Auth] gates, Flash_Alert, the rsxapp payload.
        static::__call_portal_init();

        // Handle dev auth for rsx:debug testing (development only)
        static::__handle_dev_auth($request ?? request(), $url);

        // Normalize the URL (strip prefix if needed)
        $normalized_url = static::normalize_portal_url($url);
        console_debug('PORTAL', "Normalized URL: {$normalized_url}");

        $request = $request ?? request();

        // CSRF enforcement. IDENTICAL to the main Dispatcher's call, because there is
        // one session per browser and therefore one csrf_token: POST-only, gated on
        // the browser having a session at all, allowed when there is nothing to forge
        // against. Covers portal AJAX, uploads, and native #[Portal_Route(POST)].
        // Assets are GET-only.
        if ($method === 'POST') {
            \App\RSpade\Core\Session\Rsx_Csrf::enforce($request);
        }

        // Check if this is an asset request
        if (AssetHandler::is_asset_request($normalized_url)) {
            console_debug('PORTAL', "Serving static asset: {$normalized_url}");

            return AssetHandler::serve($normalized_url, $request);
        }

        // HEAD requests should be treated as GET
        $original_method = $method;
        $route_method = ($method === 'HEAD') ? 'GET' : $method;

        if ($method === 'HEAD' && $request) {
            $request->setMethod('GET');
        }

        // Find matching portal route
        console_debug('PORTAL', "Looking for portal route: {$normalized_url}, method: {$route_method}");
        $route_match = static::__find_portal_route($normalized_url, $route_method);

        if (!$route_match) {
            console_debug('PORTAL', "No portal route found for: {$normalized_url}");

            // Call unhandled_route hook from Portal_Main
            $unhandled_response = static::__call_portal_unhandled_route($request, $extra_params);
            if ($unhandled_response !== null) {
                return $unhandled_response;
            }

            // Portal_Main::unhandled_route had first refusal; the terminal outcome is
            // ours to render (the portal twin of the main dispatcher's 404).
            return Error_Screens::not_found($request);
        }

        console_debug('PORTAL', "Found portal route: {$route_match['class']}::{$route_match['method']}");

        // --- Declarative #[Auth] gates (portal realm) ---
        // The portal twin of the main Dispatcher's seam: gates run before
        // Portal_Main::pre_dispatch, the controller's pre_dispatch, and the action.
        // Names resolve against Portal_Permission - the realms never blur.
        //
        // Denial produces the portal UX: no portal session -> the portal login route
        // with the requested deep URL threaded through Login_Redirect (the same
        // capture Portal_Main::pre_dispatch performs today); authenticated but denied
        // -> a genuine 403 (re-authenticating cannot fix it). Both come out of
        // Error_Screens::unauthorized(), which owns that split for every realm and
        // reads the PORTAL session in portal context.
        //
        // A route declaring no gates passes through untouched. See: rsx:man auth_gates
        $route_gates = $route_match['auth'] ?? [];
        if (!empty($route_gates)) {
            $gate_surface = $route_match['class'] . '::' . $route_match['method'];

            if (!Auth_Gates::gates_pass_at_seam($route_gates, Auth_Gates::REALM_PORTAL, $gate_surface)) {
                console_debug('PORTAL', 'Auth gates denied:', $gate_surface);

                return Error_Screens::unauthorized($request, Auth_Gates::REALM_PORTAL);
            }
        }

        // Merge the GET query string into the params handed to pre_dispatch and the
        // action, matching the main Dispatcher and the documented $params contract
        // ("$params contains GET query string parameters and URL route parameters").
        // Precedence: extra < query < URL route params. POST data is NOT included.
        $get_params = $request ? $request->query->all() : [];
        $action_params = array_merge($extra_params, $get_params, $route_match['params']);

        // Call pre_dispatch hooks with handler info
        $pre_dispatch_params = array_merge($action_params, [
            '_handler' => $route_match['class'] . '::' . $route_match['method'],
            '_class' => $route_match['class'],
            '_method' => $route_match['method'],
        ]);
        $pre_dispatch_response = static::__call_portal_pre_dispatch($request, $pre_dispatch_params);
        if ($pre_dispatch_response !== null) {
            return $pre_dispatch_response;
        }

        // Track current controller/action for portal context
        Rsx_Portal::_set_current_controller_action(
            $route_match['class'],
            $route_match['method'],
            $route_match['type'] ?? 'standard'
        );

        // Load controller class (autoloaded by framework)
        $controller_class = $route_match['class'];

        // Call controller pre_dispatch if it exists
        if (method_exists($controller_class, 'pre_dispatch')) {
            $controller_response = $controller_class::pre_dispatch($request, $action_params);
            if ($controller_response !== null) {
                return $controller_response;
            }
        }

        // Execute the action
        $action_method = $route_match['method'];
        if (!method_exists($controller_class, $action_method)) {
            throw new \RuntimeException("Portal action method not found: {$controller_class}::{$action_method}");
        }

        console_debug('PORTAL', "Executing: {$controller_class}::{$action_method}");
        $result = $controller_class::$action_method($request, $action_params);

        // rsx.post_dispatch - fired immediately after the handler returns and before
        // response shaping, on the SUCCESS path only (never after an exception). Handlers
        // run INLINE and may throw; that is the point (the Turnstile completeness guard is
        // one). Handlers must not mutate 'result'. NOTE: $action_params is GET + route
        // params only - the POST body is never merged into it, so a handler reading
        // submitted fields must read them off the Request.
        // See: php artisan rsx:man event_hooks
        \App\RSpade\Core\Rsx::trigger_action('rsx.post_dispatch', [
            'request' => $request,
            'params' => $action_params,
            'result' => $result,
        ]);

        // Build response
        return static::__build_response($result, $original_method, $request);
    }

    /**
     * Find matching portal route
     *
     * @param string $url URL to match
     * @param string $method HTTP method
     * @return array|null Route match data or null
     */
    protected static function __find_portal_route(string $url, string $method): ?array
    {
        $manifest = Manifest::get_full_manifest();
        $portal_routes = $manifest['data']['portal_routes'] ?? [];

        if (empty($portal_routes)) {
            return null;
        }

        // Sort routes for deterministic matching
        $sorted_routes = [];
        foreach ($portal_routes as $pattern => $route_data) {
            $sorted_routes[] = array_merge($route_data, ['pattern' => $pattern]);
        }

        // Sort by specificity (longer patterns first, static before dynamic)
        // Catch-all routes (/*) should always be matched last
        usort($sorted_routes, function ($a, $b) {
            // Catch-all routes should be last
            $a_catchall = str_contains($a['pattern'], '*');
            $b_catchall = str_contains($b['pattern'], '*');

            if ($a_catchall !== $b_catchall) {
                return $a_catchall ? 1 : -1; // Catch-all routes last
            }

            // Count path segments
            $a_segments = count(explode('/', trim($a['pattern'], '/')));
            $b_segments = count(explode('/', trim($b['pattern'], '/')));

            if ($a_segments !== $b_segments) {
                return $b_segments <=> $a_segments; // More segments first
            }

            // Count dynamic segments (: params and {} params)
            $a_dynamic = substr_count($a['pattern'], ':') + substr_count($a['pattern'], '{');
            $b_dynamic = substr_count($b['pattern'], ':') + substr_count($b['pattern'], '{');

            return $a_dynamic <=> $b_dynamic; // Fewer dynamic first
        });

        // Try to match each route
        foreach ($sorted_routes as $route_data) {
            $pattern = $route_data['pattern'];
            $route_methods = $route_data['methods'] ?? ['GET'];

            // Check HTTP method
            if (!in_array($method, $route_methods)) {
                continue;
            }

            // Try to match the pattern
            $params = RouteResolver::match($url, $pattern);

            if ($params !== false) {
                return [
                    'class' => $route_data['class'],
                    'method' => $route_data['method'],
                    'params' => $params,
                    'pattern' => $pattern,
                    'type' => $route_data['type'] ?? 'portal',
                    'require' => $route_data['require'] ?? [],
                    // Declarative gate list baked onto the row by the manifest
                    // (class-level #[Auth] merged with the method's own).
                    'auth' => $route_data['auth'] ?? [],
                ];
            }
        }

        return null;
    }

    /**
     * Call Portal_Main::init(), once per process.
     *
     * The portal twin of the staff Main::init() the framework provider runs at
     * boot. It cannot ride that same seam: the provider boots for EVERY request,
     * and Portal_Main is portal-only application code (declaring a portal site on
     * a staff request would be nonsense). So the portal's entry point runs it, at
     * the top of the one function every portal request passes through.
     *
     * Once per process, not once per dispatch: init() is bootstrap, and a nested
     * dispatch (an error page, an internal re-entry) is the same request.
     *
     * @return void
     */
    protected static function __call_portal_init(): void
    {
        static $called = false;

        if ($called) {
            return;
        }
        $called = true;

        // Find classes extending Portal_Main_Abstract via manifest
        $portal_main_classes = Manifest::php_get_extending('Portal_Main_Abstract');

        foreach ($portal_main_classes as $portal_main_class) {
            if (isset($portal_main_class['fqcn']) && $portal_main_class['fqcn']) {
                $class_name = $portal_main_class['fqcn'];
                if (method_exists($class_name, 'init')) {
                    console_debug('PORTAL', "Calling {$class_name}::init");
                    $class_name::init();
                }
            }
        }
    }

    /**
     * Call Portal_Main::pre_dispatch if it exists
     *
     * @param Request $request
     * @param array $params
     * @return mixed|null Response or null to continue
     */
    protected static function __call_portal_pre_dispatch(Request $request, array $params)
    {
        // Find classes extending Portal_Main_Abstract via manifest
        $portal_main_classes = Manifest::php_get_extending('Portal_Main_Abstract');

        foreach ($portal_main_classes as $portal_main_class) {
            if (isset($portal_main_class['fqcn']) && $portal_main_class['fqcn']) {
                $class_name = $portal_main_class['fqcn'];
                if (method_exists($class_name, 'pre_dispatch')) {
                    console_debug('PORTAL', "Calling {$class_name}::pre_dispatch");
                    $result = $class_name::pre_dispatch($request, $params);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Call Portal_Main::unhandled_route if it exists
     *
     * @param Request $request
     * @param array $params
     * @return mixed|null Response or null for default 404
     */
    protected static function __call_portal_unhandled_route(Request $request, array $params)
    {
        // Find classes extending Portal_Main_Abstract via manifest
        $portal_main_classes = Manifest::php_get_extending('Portal_Main_Abstract');

        foreach ($portal_main_classes as $portal_main_class) {
            if (isset($portal_main_class['fqcn']) && $portal_main_class['fqcn']) {
                $class_name = $portal_main_class['fqcn'];
                if (method_exists($class_name, 'unhandled_route')) {
                    console_debug('PORTAL', "Calling {$class_name}::unhandled_route");
                    $result = $class_name::unhandled_route($request, $params);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Build appropriate response from handler result
     *
     * @param mixed $result Handler result
     * @param string $method Original HTTP method
     * @param Request $request
     * @return mixed
     */
    protected static function __build_response($result, string $method, Request $request)
    {
        // If already a Response, return as-is
        if ($result instanceof \Illuminate\Http\Response ||
            $result instanceof \Symfony\Component\HttpFoundation\Response) {
            // For HEAD requests, strip the body
            if ($method === 'HEAD') {
                $result->setContent('');
            }

            return $result;
        }

        // If a View object, render it to a response
        if ($result instanceof \Illuminate\Contracts\View\View) {
            $response = response($result->render());

            if ($method === 'HEAD') {
                $response->setContent('');
            }

            return $response;
        }

        // If array, return as JSON
        if (is_array($result)) {
            $response = response()->json($result);

            if ($method === 'HEAD') {
                $response->setContent('');
            }

            return $response;
        }

        // If string, return as HTML
        if (is_string($result)) {
            $response = response($result);

            if ($method === 'HEAD') {
                $response->setContent('');
            }

            return $response;
        }

        // If null, let caller handle (likely 404)
        return $result;
    }

    /**
     * Handle dev auth headers for rsx:debug testing
     *
     * This allows rsx:debug to authenticate as any portal user in development.
     * The token is validated using APP_KEY to ensure only authorized requests.
     *
     * @param Request $request
     * @param string $url
     * @return void
     */
    protected static function __handle_dev_auth(Request $request, string $url): void
    {
        // Only in non-production environments
        if (app()->environment('production')) {
            return;
        }

        // Check for portal dev auth header
        $portal_user_id = $request->header('X-Dev-Auth-Portal-User-Id');
        if (!$portal_user_id) {
            return;
        }

        $token = $request->header('X-Dev-Auth-Token');
        if (!$token) {
            console_debug('PORTAL', 'Dev auth: Missing token header');
            return;
        }

        // Validate the token
        $app_key = config('app.key');
        if (!$app_key) {
            console_debug('PORTAL', 'Dev auth: APP_KEY not configured');
            return;
        }

        // Normalize URL for token validation (strip /_portal prefix)
        $normalized_url = static::normalize_portal_url($url);

        // Recreate the expected token payload
        $expected_payload = json_encode([
            'url' => $normalized_url,
            'user_id' => (int) $portal_user_id,
            'portal' => true,
        ]);

        $expected_token = hash_hmac('sha256', $expected_payload, $app_key);

        if (!hash_equals($expected_token, $token)) {
            console_debug('PORTAL', 'Dev auth: Token validation failed');
            return;
        }

        // Token is valid - authenticate as the portal user
        $portal_user = Portal_User_Model::find((int) $portal_user_id);
        if (!$portal_user) {
            console_debug('PORTAL', "Dev auth: Portal user not found: {$portal_user_id}");
            return;
        }

        // The harness does NOT declare a site of its own: it browses the portal the
        // application serves, so the site is whatever the application declared
        // (Portal_Main::init, which ran just above). A user from another tenant
        // cannot be signed into THIS portal - say so instead of minting a session
        // whose row and user disagree about the site.
        $declared_site_id = Portal_Session::get_site_id();

        if ((int) $portal_user->site_id !== $declared_site_id) {
            throw new \RuntimeException(
                "Dev auth: portal user {$portal_user_id} belongs to site {$portal_user->site_id}, but this portal "
                . "request serves site {$declared_site_id}. Pick a portal user on the served site, or point the "
                . 'application\'s Portal_Session::set_site_id() declaration at the other tenant.'
            );
        }

        Portal_Session::set_portal_user_id((int) $portal_user_id);

        console_debug('PORTAL', "Dev auth: Logged in as portal user {$portal_user_id}");
    }
}
