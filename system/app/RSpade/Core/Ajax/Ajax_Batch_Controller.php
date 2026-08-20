<?php

namespace App\RSpade\Core\Ajax;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Ajax_Batch_Controller - Handles batched Ajax requests
 *
 * This controller receives multiple Ajax calls in a single HTTP request
 * and executes them individually, returning all results in one response.
 *
 * Request format:
 * POST /_ajax/_batch
 * {
 *   "batch_calls": "[{call_id: 0, controller: 'User_Controller', action: 'get_profile', params: {...}}, ...]"
 * }
 *
 * Response format:
 * {
 *   "C_0": {_success: true, _ajax_return_value: {...}},
 *   "C_1": {_success: false, error_type: "...", reason: "..."},
 *   ...
 * }
 */
class Ajax_Batch_Controller extends Rsx_Controller_Abstract
{
    /**
     * Handle a batch of Ajax calls
     *
     * ONE HANDLER, TWO CHANNELS: the #[Portal_Route] serves the same batch transport
     * under the portal's own base, so a batched portal call is dispatched as a portal
     * request (portal CSRF, portal realm, portal_fetch) exactly like a direct one.
     * See Ajax_Endpoint_Controller::dispatch().
     *
     * Gate is 'public': authorization is deferred to each batched endpoint's own realm
     * and #[Auth] gates, which Ajax::internal() evaluates per call before running any
     * endpoint body. Gating the transport itself would only duplicate that.
     *
     * @param Request $request
     * @param array $params
     * @return \Illuminate\Http\JsonResponse
     */
    #[Route('/_ajax/_batch', methods: ['POST'])]
    #[Portal_Route('/_ajax/_batch', methods: ['POST'])]
    #[Auth('public')]
    public static function batch(Request $request, array $params = [])
    {
        // Enable AJAX response mode
        Ajax::set_ajax_response_mode(true);

        // Disable console debug HTML output
        \App\RSpade\Core\Debug\Debugger::disable_console_html_output();

        // Get batch calls from request
        // With JSON Content-Type, Laravel auto-decodes the body
        $batch_calls = $request->input('batch_calls');

        if (empty($batch_calls)) {
            return response()->json([
                'error' => 'Missing batch_calls parameter'
            ], 400);
        }

        if (!is_array($batch_calls)) {
            return response()->json([
                'error' => 'Invalid batch_calls format - must be array'
            ], 400);
        }

        // Process each call
        $responses = [];

        foreach ($batch_calls as $call) {
            $call_id = $call['call_id'] ?? null;
            $controller = $call['controller'] ?? null;
            $action = $call['action'] ?? null;
            $call_params = $call['params'] ?? [];

            if ($call_id === null || !$controller || !$action) {
                $responses["C_{$call_id}"] = [
                    '_success' => false,
                    'error_type' => 'invalid_call',
                    'reason' => 'Missing required fields: call_id, controller, or action',
                ];
                continue;
            }

            try {
                // Make the Ajax call using Ajax::internal()
                $result = Ajax::internal($controller, $action, $call_params);

                // Build success response (must use _success to match non-batched format)
                $response = [
                    '_success' => true,
                    '_ajax_return_value' => $result,
                ];

                // Add console debug messages if any
                $console_messages = \App\RSpade\Core\Debug\Debugger::_get_console_messages();
                if (!empty($console_messages)) {
                    $response['console_debug'] = $console_messages;
                }

                $responses["C_{$call_id}"] = $response;

            } catch (Exceptions\AjaxAuthRequiredException $e) {
                $responses["C_{$call_id}"] = [
                    '_success' => false,
                    'error_code' => Ajax::ERROR_AUTH_REQUIRED,
                    'reason' => $e->getMessage(),
                    'metadata' => [],
                ];

            } catch (Exceptions\AjaxUnauthorizedException $e) {
                $responses["C_{$call_id}"] = [
                    '_success' => false,
                    'error_code' => Ajax::ERROR_UNAUTHORIZED,
                    'reason' => $e->getMessage(),
                    'metadata' => [],
                ];

            // BEFORE the AjaxFormErrorException arm - AjaxNotFoundException is a subclass,
            // and without its own arm a not-found sub-call reached the client stamped
            // 'validation'. Client code that branches on Ajax.ERROR_NOT_FOUND (the ORM
            // fetch path does) would then behave differently in batched debug/production
            // mode than in development, where the direct transport keeps the real code.
            } catch (Exceptions\AjaxNotFoundException $e) {
                $responses["C_{$call_id}"] = [
                    '_success' => false,
                    'error_code' => Ajax::ERROR_NOT_FOUND,
                    'reason' => $e->getMessage(),
                    'metadata' => $e->get_details(),
                ];

            } catch (Exceptions\AjaxFormErrorException $e) {
                $responses["C_{$call_id}"] = [
                    '_success' => false,
                    'error_code' => Ajax::ERROR_VALIDATION,
                    'reason' => $e->getMessage(),
                    'metadata' => $e->get_details(),
                ];

            } catch (Exceptions\AjaxFatalErrorException $e) {
                $responses["C_{$call_id}"] = [
                    '_success' => false,
                    'error_code' => Ajax::ERROR_FATAL,
                    'reason' => $e->getMessage(),
                    'metadata' => [],
                ];

            } catch (\Throwable $e) {
                // A genuine server fault - not one of the typed Ajax exceptions above.
                //
                // In strict production the message, file, line and trace must NEVER leave
                // the server: this batch response is fully inspectable via devtools or curl,
                // so the client render is not the boundary - the wire is. An unredacted
                // exception message here is an anonymous class/FQCN/method oracle (a raw
                // "Controller App\...\X must extend Rsx_Controller_Abstract" names the class
                // and its namespace). Mirror the direct-path redaction in
                // Ajax_Exception_Handler: production emits one generic string; non-production
                // keeps the real message for troubleshooting.
                //
                // Caught as \Throwable, not \Exception, so a TypeError from a sub-call (e.g.
                // an array where a string param was typed) is contained to THAT call and
                // redacted too, instead of escaping the loop and aborting the whole batch.
                $responses["C_{$call_id}"] = [
                    '_success' => false,
                    'error_type' => 'exception',
                    'reason' => \App\RSpade\Core\Rsx::is_production()
                        ? 'A server error has occurred.'
                        : $e->getMessage(),
                ];
            }
        }

        return response()->json($responses);
    }
}
