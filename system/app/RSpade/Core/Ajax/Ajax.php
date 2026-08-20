<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Ajax;

use Exception;
use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Exceptions\AjaxAuthRequiredException;
use App\RSpade\Core\Ajax\Exceptions\AjaxFatalErrorException;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Ajax\Exceptions\AjaxNotFoundException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Response\Rsx_Response_Abstract;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Ajax - Unified AJAX handling system
 *
 * Consolidates all AJAX-related functionality:
 * - Internal server-side API calls (internal method)
 * - Browser AJAX request handling (handle_browser_request method)
 * - Response mode management for error handlers
 * - Future: External HTTP calls, WebSocket handling
 */
class Ajax
{
    // Error code constants
    const ERROR_VALIDATION = 'validation';
    const ERROR_NOT_FOUND = 'not_found';
    const ERROR_UNAUTHORIZED = 'unauthorized';
    const ERROR_AUTH_REQUIRED = 'auth_required';
    const ERROR_FATAL = 'fatal';
    const ERROR_GENERIC = 'generic';
    const ERROR_SERVER = 'server_error';      // Client-generated (HTTP 500)
    const ERROR_NETWORK = 'network_error';    // Client-generated (connection failed)

    /**
     * Flag to indicate AJAX response mode for error handlers
     */
    protected static bool $ajax_response_mode = false;

    /**
     * True while an internal() invocation is on the stack (a batch sub-call, or any
     * programmatic server-side call). Nesting-safe: each invocation saves and restores it.
     *
     * Framework-internal. It exists so a check that must STOP the request can shape its
     * failure for the caller it actually has: an internal call has no HTTP response of its
     * own to render, so it must raise the coded exception this entry point contracts for
     * rather than an HttpResponseException nobody up the stack is listening for.
     */
    private static bool $_in_internal_call = false;

    /**
     * Is an Ajax::internal() invocation currently on the stack?
     *
     * Framework-internal (Rsx_Turnstile reads it to pick its failure shape).
     */
    public static function _is_internal_call(): bool
    {
        return static::$_in_internal_call;
    }

    /**
     * The upload size ceiling as a string you can put in front of a user: "100 MB".
     *
     * The PHP mirror of Ajax.max_file_size_human(), for Blade and any server-rendered
     * label. Identical rounding, identical output - a notice rendered server-side and the
     * same notice rendered by a component must not disagree by a decimal place.
     *
     * Every hardcoded "Max size 25MB" in a template is a number that will be wrong the day
     * the limit changes, and nobody will notice, because nothing breaks - the label just
     * quietly lies. This reads config('rsx.files.max_file_size'), the same value /_upload
     * enforces, so the notice and the enforcement cannot drift apart.
     *
     *     <div class="form-text">Max size {{ Ajax::max_file_size_human() }}.</div>
     *
     * @return string e.g. "100 MB", "1.2 GB", "12 GB", or "Unlimited" when uncapped
     */
    public static function max_file_size_human(): string
    {
        return static::bytes_to_size_label((int) config('rsx.files.max_file_size', 0));
    }

    /**
     * The label formatter behind max_file_size_human(), exposed for an app that caps
     * tighter than the framework and wants its own number rendered the same way.
     *
     * Rounding is chosen for a LABEL, not for accuracy: whole MB up to 1 GB, one decimal
     * from 1 GB to 10 GB (so 1.2 GB does not read as "1 GB"), whole GB above that. Below
     * 1 MB it falls back to whole KB rather than rounding to a meaningless "0 MB".
     *
     * Distinct from bytes_to_human(), deliberately: that one is a faithful 2-decimal
     * rendering of an ACTUAL size ("1.22 GB"), which is what you want when reporting the
     * file someone just uploaded. This one is a round number for a limit.
     *
     * @param int $bytes 0 (or anything non-positive) means no ceiling
     * @return string
     */
    public static function bytes_to_size_label(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'Unlimited';
        }

        $kb = 1024;
        $mb = $kb * 1024;
        $gb = $mb * 1024;

        if ($bytes < $mb) {
            return round($bytes / $kb) . ' KB';
        }
        if ($bytes < $gb) {
            return round($bytes / $mb) . ' MB';
        }
        if ($bytes < 10 * $gb) {
            // One decimal, trailing .0 dropped: "1.2 GB", but "2 GB" not "2.0 GB".
            return (float) number_format($bytes / $gb, 1, '.', '') . ' GB';
        }

        return round($bytes / $gb) . ' GB';
    }

    /**
     * Get default message for error code
     *
     * @param string $error_code One of the ERROR_* constants
     * @return string Default user-friendly message
     */
    public static function get_default_message(string $error_code): string
    {
        return match ($error_code) {
            self::ERROR_VALIDATION => 'Please correct the errors below',
            self::ERROR_NOT_FOUND => 'The requested record was not found',
            self::ERROR_UNAUTHORIZED => 'You do not have permission to perform this action',
            self::ERROR_AUTH_REQUIRED => 'Please log in to continue',
            self::ERROR_FATAL => 'A fatal error has occurred',
            self::ERROR_SERVER => 'A server error occurred. Please try again.',
            self::ERROR_NETWORK => 'Could not connect to server. Please check your connection.',
            self::ERROR_GENERIC => 'An error has occurred',
            default => 'An error has occurred',
        };
    }

    /**
     * Call an internal API method directly from PHP code
     *
     * Used for server-side code to invoke API methods without HTTP overhead.
     * This is useful for internal service calls, background jobs, and testing.
     *
     * @param string $rsx_controller Controller name (e.g., 'User_Controller')
     * @param string $rsx_action Action/method name (e.g., 'get_profile')
     * @param array $params Parameters to pass to the method
     * @param array $auth Authentication context (not yet implemented)
     * @return mixed The filtered response from the API method
     * @throws AjaxAuthRequiredException
     * @throws AjaxUnauthorizedException
     * @throws AjaxFormErrorException
     * @throws AjaxNotFoundException
     * @throws AjaxFatalErrorException
     * @throws Exception
     */
    public static function internal($rsx_controller, $rsx_action, $params = [], $auth = [])
    {
        // Get manifest to find controller
        $manifest = \App\RSpade\Core\Manifest\Manifest::get_all();
        $controller_class = null;
        $file_info = null;

        // Search for controller class in manifest
        foreach ($manifest as $file_path => $info) {
            // Skip non-PHP files or files without classes
            if (!isset($info['class']) || !isset($info['fqcn'])) {
                continue;
            }

            // Check if class name matches exactly (without namespace)
            $class_basename = basename(str_replace('\\', '/', $info['fqcn']));

            if ($class_basename === $rsx_controller) {
                $controller_class = $info['fqcn'];
                $file_info = $info;
                break;
            }
        }

        if (!$controller_class) {
            throw new Exception("Controller class not found: {$rsx_controller}");
        }

        // Check if class exists
        if (!class_exists($controller_class)) {
            throw new Exception("Controller class does not exist: {$controller_class}");
        }

        // Check if it's a subclass of Rsx_Controller_Abstract
        if (!Manifest::php_is_subclass_of($controller_class, \App\RSpade\Core\Controller\Rsx_Controller_Abstract::class)) {
            throw new Exception("Controller {$controller_class} must extend Rsx_Controller_Abstract");
        }

        // Check if method exists and has Ajax_Endpoint attribute
        if (!isset($file_info['public_static_methods'][$rsx_action])) {
            throw new Exception("Method {$rsx_action} not found in controller {$controller_class}");
        }

        $method_info = $file_info['public_static_methods'][$rsx_action];
        $has_ajax_endpoint = false;

        // Check for Ajax_Endpoint attribute in method metadata
        if (isset($method_info['attributes'])) {
            foreach ($method_info['attributes'] as $attr_name => $attr_data) {
                // Check for Ajax_Endpoint with or without namespace
                if ($attr_name === 'Ajax_Endpoint' ||
                    basename(str_replace('\\', '/', $attr_name)) === 'Ajax_Endpoint') {
                    $has_ajax_endpoint = true;
                    break;
                }
            }
        }

        if (!$has_ajax_endpoint) {
            throw new Exception("Method {$rsx_action} in {$controller_class} must have Ajax_Endpoint annotation");
        }

        // --- Declarative #[Auth] gates ---
        // Same seam as the browser path, on the internal caller. Denial raises the
        // coded unauthorized exception this entry point already contracts for, and
        // the endpoint body never runs.
        if (!static::_endpoint_gates_pass($file_info, $rsx_action)) {
            return static::_handle_special_response(response_unauthorized());
        }

        // Create a request object with the params
        $request = Request::create('/_ajax/' . $rsx_controller . '/' . $rsx_action, 'POST', $params);
        $request->setMethod('POST');

        // Mark the internal-call context (nesting-safe) and give this sub-call its OWN
        // Turnstile latch, so a batch cannot launder one call's verification across its
        // siblings. The calling scope's latch is restored afterwards: a plain POST handler
        // that validated and then made an internal call has not lost its own validation.
        $previous_internal_call = static::$_in_internal_call;
        $previous_turnstile_checked = \App\RSpade\Core\Turnstile\Rsx_Turnstile::_was_checked();
        static::$_in_internal_call = true;
        \App\RSpade\Core\Turnstile\Rsx_Turnstile::_reset_request_state();

        try {
            // Call pre_dispatch if it exists
            $response = null;
            if (method_exists($controller_class, 'pre_dispatch')) {
                $response = $controller_class::pre_dispatch($request, $params);
            }

            // If pre_dispatch returned something, use that as response
            if ($response === null) {
                // Call the actual method
                $response = $controller_class::$rsx_action($request, $params);
            }

            // rsx.post_dispatch - fired immediately after the handler returns and before
            // any response shaping, on the SUCCESS path only (never after an exception).
            // Handlers run INLINE and may throw; that is the point (the Turnstile
            // completeness guard is one). Handlers must not mutate 'result'.
            // See: php artisan rsx:man event_hooks
            \App\RSpade\Core\Rsx::trigger_action('rsx.post_dispatch', [
                'request' => $request,
                'params' => $params,
                'result' => $response,
            ]);
        } finally {
            static::$_in_internal_call = $previous_internal_call;
            \App\RSpade\Core\Turnstile\Rsx_Turnstile::_set_request_checked($previous_turnstile_checked);
        }

        // Handle special response types
        if ($response instanceof Rsx_Response_Abstract) {
            return static::_handle_special_response($response);
        }

        // For normal responses, filter through JSON encode/decode to remove PHP objects
        // This ensures we only return plain data structures
        $filtered_response = json_decode(json_encode($response), true);

        return $filtered_response;
    }

    /**
     * Evaluate the REALM and the declarative #[Auth] gates an Ajax endpoint declares.
     *
     * Each realm has its own internal-endpoint channel (/_ajax/... for staff,
     * <portal-prefix>/_ajax/... served by Portal_Dispatcher for the portal), so the
     * realm of the request is known and meaningful here. The surface's own realm is
     * checked FIRST - a staff request must not reach a portal endpoint, nor a portal
     * request a staff one, whatever the gate names say (see
     * Auth_Gates::surface_realm_permits). Only then do the gates run, resolved in the
     * REQUEST's realm. The indexed gate list already merges the controller's
     * class-level #[Auth] with the method's own, so that part is a straight lookup.
     *
     * @param array $file_info Manifest metadata for the controller's file (both entry
     *                         points resolve it before reaching here, and the manifest
     *                         search only matches entries carrying 'class')
     * @param string $action_name The endpoint method
     * @return bool True when the realm permits and every gate passes
     */
    protected static function _endpoint_gates_pass(array $file_info, string $action_name): bool
    {
        $target = $file_info['class'] . '::' . $action_name;
        $realm = Auth_Gates::active_realm();

        if (!Auth_Gates::surface_realm_permits($target, $realm)) {
            return false;
        }

        $gates = Auth_Gates::surface_gates($target);

        if (empty($gates)) {
            return true;
        }

        return Auth_Gates::gates_pass_at_seam($gates, $realm, $target);
    }

    /**
     * Handle special response types by throwing appropriate exceptions
     *
     * @param Rsx_Response_Abstract $response
     * @throws AjaxAuthRequiredException
     * @throws AjaxUnauthorizedException
     * @throws AjaxFormErrorException
     * @throws AjaxNotFoundException
     * @throws AjaxFatalErrorException
     */
    protected static function _handle_special_response(Rsx_Response_Abstract $response)
    {
        $type = $response->get_type();
        $reason = $response->get_reason();
        $details = $response->get_details();

        switch ($type) {
            case self::ERROR_AUTH_REQUIRED:
                throw new AjaxAuthRequiredException($reason);

            case self::ERROR_UNAUTHORIZED:
                throw new AjaxUnauthorizedException($reason);

            case self::ERROR_VALIDATION:
                throw new AjaxFormErrorException($reason, $details);

            // NOT_FOUND raises the narrower subclass so a caller that re-encodes the
            // exception into a client error code (Ajax_Batch_Controller) can keep the
            // code it came in with. Every existing `catch (AjaxFormErrorException)`
            // still catches it - the subclass IS one.
            case self::ERROR_NOT_FOUND:
                throw new AjaxNotFoundException($reason, $details);

            case self::ERROR_FATAL:
                $message = $reason;
                if (!empty($details)) {
                    $message .= ' - ' . json_encode($details);
                }
                throw new AjaxFatalErrorException($message);

            case self::ERROR_GENERIC:
                throw new Exception($reason);

            default:
                throw new Exception("Unknown RSX response type: {$type}");
        }
    }

    /**
     * Handle incoming AJAX requests from the browser
     *
     * This method processes /_ajax/:controller/:action requests and returns JSON responses.
     * It validates Ajax_Endpoint annotations and handles special response types.
     *
     * @param Request $request The incoming HTTP request
     * @param array $params Route parameters including controller and action
     * @return \Illuminate\Http\JsonResponse
     * @throws Exception
     */
    public static function handle_browser_request(Request $request, array $params = [])
    {
        // Enable AJAX response mode for error handlers
        static::set_ajax_response_mode(true);

        // Disable console debug HTML output for AJAX requests
        \App\RSpade\Core\Debug\Debugger::disable_console_html_output();

        // Get controller and action from params
        $controller_name = $params['controller'] ?? null;
        $action_name = $params['action'] ?? null;

        if (!$controller_name || !$action_name) {
            throw new Exception('Missing controller or action parameter');
        }

        // Use manifest to find the controller class
        $manifest = \App\RSpade\Core\Manifest\Manifest::get_all();
        $controller_class = null;
        $file_info = null;

        // Search for controller class in manifest
        foreach ($manifest as $file_path => $info) {
            // Skip non-PHP files or files without classes
            if (!isset($info['class']) || !isset($info['fqcn'])) {
                continue;
            }

            // Check if class name matches exactly (without namespace)
            $class_basename = basename(str_replace('\\', '/', $info['fqcn']));

            if ($class_basename === $controller_name) {
                $controller_class = $info['fqcn'];
                $file_info = $info;
                break;
            }
        }

        if (!$controller_class) {
            throw new Exception("Controller class not found: {$controller_name}");
        }

        // Check if class exists
        if (!class_exists($controller_class)) {
            throw new Exception("Controller class does not exist: {$controller_class}");
        }

        // Check if it's a subclass of Rsx_Controller_Abstract
        if (!Manifest::php_is_subclass_of($controller_class, \App\RSpade\Core\Controller\Rsx_Controller_Abstract::class)) {
            throw new Exception("Controller {$controller_class} must extend Rsx_Controller_Abstract");
        }

        // Check if method exists and has Ajax_Endpoint attribute
        if (!isset($file_info['public_static_methods'][$action_name])) {
            throw new Exception("Method {$action_name} not found in controller {$controller_class} public static methods");
        }

        $method_info = $file_info['public_static_methods'][$action_name];
        $has_ajax_endpoint = false;

        // Check for Ajax_Endpoint attribute in method metadata
        if (isset($method_info['attributes'])) {
            foreach ($method_info['attributes'] as $attr_name => $attr_data) {
                // Check for Ajax_Endpoint with or without namespace
                if ($attr_name === 'Ajax_Endpoint' ||
                    basename(str_replace('\\', '/', $attr_name)) === 'Ajax_Endpoint') {
                    $has_ajax_endpoint = true;
                    break;
                }
            }
        }

        if (!$has_ajax_endpoint) {
            throw new Exception("Method {$action_name} in {$controller_class} must have Ajax_Endpoint annotation");
        }

        // --- Declarative #[Auth] gates ---
        // The framework authorization seam for #[Ajax_Endpoint]: after CSRF (enforced
        // by the dispatcher for POST) and before the controller's pre_dispatch and the
        // endpoint body. Denial returns the standard coded unauthorized error, which
        // the client's generic handlers already render.
        if (!static::_endpoint_gates_pass($file_info, $action_name)) {
            return static::_handle_browser_special_response(response_unauthorized());
        }

        // Extract params from request body
        $ajax_params = [];

        // First check for JSON-encoded params field (old format)
        $params_json = $request->input('params');
        if ($params_json) {
            $ajax_params = json_decode($params_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON in params: ' . json_last_error_msg());
            }
        } else {
            // Get all request input (form-urlencoded or JSON body)
            $all_input = $request->all();

            // Remove route parameters (controller, action, etc)
            $ajax_params = array_diff_key($all_input, array_flip(['controller', 'action', '_method', '_route', '_handler']));
        }

        // Call pre_dispatch if it exists
        $response = null;
        if (method_exists($controller_class, 'pre_dispatch')) {
            $response = $controller_class::pre_dispatch($request, $ajax_params);
        }

        // If pre_dispatch returned something, use that as response
        if ($response === null) {
            // Call the actual method
            $response = $controller_class::$action_name($request, $ajax_params);
        }

        // rsx.post_dispatch - fired immediately after the handler returns and before any
        // response shaping, on the SUCCESS path only (never after an exception). Handlers
        // run INLINE and may throw; that is the point (the Turnstile completeness guard is
        // one). Handlers must not mutate 'result'.
        // See: php artisan rsx:man event_hooks
        \App\RSpade\Core\Rsx::trigger_action('rsx.post_dispatch', [
            'request' => $request,
            'params' => $ajax_params,
            'result' => $response,
        ]);

        // Handle special response types
        if ($response instanceof \App\RSpade\Core\Response\Rsx_Response_Abstract) {
            return static::_handle_browser_special_response($response);
        }

        // Wrap response using shared formatting method
        $json_response = static::format_ajax_response($response);

        return response()->json($json_response);
    }

    /**
     * Handle special response types for browser AJAX requests
     *
     * @param \App\RSpade\Core\Response\Rsx_Response_Abstract $response
     * @return \Illuminate\Http\JsonResponse
     */
    protected static function _handle_browser_special_response(\App\RSpade\Core\Response\Rsx_Response_Abstract $response)
    {
        $type = $response->get_type();

        // Handle fatal error - always throw exception
        if ($type === 'fatal') {
            $details = $response->get_details();
            $message = $response->get_reason();
            if (!empty($details)) {
                $message .= ' - ' . json_encode($details);
            }

            throw new Exception($message);
        }

        // Build error response
        $json_response = [
            '_success' => false,
            'error_code' => $response->get_error_code(),
            'reason' => $response->get_reason(),
            'metadata' => $response->get_metadata(),
            '_server_time' => \App\RSpade\Core\Time\Rsx_Time::now_iso(),
            '_user_timezone' => \App\RSpade\Core\Time\Rsx_Time::get_user_timezone(),
        ];

        // Add console debug messages if any
        $console_messages = \App\RSpade\Core\Debug\Debugger::_get_console_messages();
        if (!empty($console_messages)) {
            // Messages are now structured as [channel, [arguments]]
            $json_response['console_debug'] = $console_messages;
        }

        // Add flash_alerts if any pending for current session
        $flash_messages = \App\RSpade\Lib\Flash\Flash_Alert::get_pending_messages();
        if (!empty($flash_messages)) {
            $json_response['flash_alerts'] = $flash_messages;
        }

        return response()->json($json_response);
    }

    /**
     * Set AJAX response mode for error handlers
     *
     * When enabled, error handlers will return JSON instead of HTML
     *
     * @param bool $enabled
     * @return void
     */
    public static function set_ajax_response_mode(bool $enabled): void
    {
        static::$ajax_response_mode = $enabled;
    }

    /**
     * Check if AJAX response mode is enabled
     *
     * @return bool
     */
    public static function is_ajax_response_mode(): bool
    {
        return static::$ajax_response_mode;
    }

    /**
     * Validate Ajax endpoint return value
     *
     * Ensures developers don't manually add 'success' field to their responses.
     * The framework automatically wraps responses with success/failure metadata.
     *
     * @param mixed $response The Ajax method return value
     * @throws Exception If response contains manual success field
     */
    protected static function _validate_ajax_response($response): void
    {
        // Only validate if response is an array
        if (!is_array($response)) {
            return;
        }

        // Check if response contains '_success' or 'success' key with boolean value
        if ((array_key_exists('_success', $response) && is_bool($response['_success'])) ||
            (array_key_exists('success', $response) && is_bool($response['success']))) {
            $wrong_way = "return ['_success' => false, 'message' => 'Error'];";
            $right_way_validation = "return response_error(Ajax::ERROR_VALIDATION, ['email' => 'Invalid email']);";
            $right_way_success = "return ['user_id' => 123, 'data' => [...]];\n// Framework wraps: {_success: true, _ajax_return_value: {...}}";
            $right_way_exception = "// Let exceptions bubble - framework handles them\n\$user->save(); // Don't wrap in try/catch";

            throw new Exception(
                "YOU'RE DOING IT WRONG: Ajax endpoints must not return arrays with '_success' or 'success' boolean key.\n\n" .
                "The '_success' field is reserved for framework-level Ajax response wrapping.\n\n" .
                "WRONG WAY:\n" .
                "  {$wrong_way}\n\n" .
                "RIGHT WAY (validation errors):\n" .
                "  {$right_way_validation}\n\n" .
                "RIGHT WAY (success):\n" .
                "  {$right_way_success}\n\n" .
                "RIGHT WAY (exceptions):\n" .
                "  {$right_way_exception}\n\n" .
                "See: php artisan rsx:man ajax_error_handling"
            );
        }
    }

    /**
     * Format Ajax response with standard wrapper structure
     *
     * This method creates the standardized response format used by both
     * HTTP Ajax endpoints and CLI Ajax commands. Ensures consistency across
     * all Ajax response types.
     *
     * @param mixed $response The Ajax method return value
     * @return array Formatted response array with success, _ajax_return_value, and optional console_debug
     */
    public static function format_ajax_response($response): array
    {
        // Validate response before formatting
        static::_validate_ajax_response($response);

        $json_response = [
            '_success' => true,
            '_ajax_return_value' => $response,
            '_server_time' => \App\RSpade\Core\Time\Rsx_Time::now_iso(),
            '_user_timezone' => \App\RSpade\Core\Time\Rsx_Time::get_user_timezone(),
        ];

        // Add console debug messages if any
        $console_messages = \App\RSpade\Core\Debug\Debugger::_get_console_messages();
        if (!empty($console_messages)) {
            // Messages are now structured as [channel, [arguments]]
            $json_response['console_debug'] = $console_messages;
        }

        // Add flash_alerts if any pending for current session
        $flash_messages = \App\RSpade\Lib\Flash\Flash_Alert::get_pending_messages();
        if (!empty($flash_messages)) {
            $json_response['flash_alerts'] = $flash_messages;
        }

        return $json_response;
    }

    /**
     * Backward compatibility alias for internal()
     * @deprecated Use internal() instead
     */
    public static function call($rsx_controller, $rsx_action, $params = [], $auth = [])
    {
        return static::internal($rsx_controller, $rsx_action, $params, $auth);
    }
}
