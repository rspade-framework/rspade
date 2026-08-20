<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Http\Request;
use Throwable;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Dispatch\Ajax_Endpoint_Controller;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;
use App\RSpade\Core\Rsx;

/**
 * Ajax_ExceptionHandler - Handle exceptions during AJAX endpoint execution
 *
 * PRIORITY: 20
 *
 * This handler processes exceptions that occur during AJAX endpoint execution
 * (when using #[Ajax_Endpoint] attribute). It returns JSON-formatted errors with:
 * - Error message
 * - File and line number
 * - Stack trace (last 10 frames)
 * - Console debug messages (if any)
 *
 * This ensures AJAX errors are returned as JSON instead of HTML error pages.
 */
class Ajax_Exception_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - AJAX handlers run after CLI but before web
     *
     * @return int
     */
    public static function get_priority(): int
    {
        return 20;
    }

    /**
     * Handle exception if in AJAX response mode
     *
     * @param Throwable $e
     * @param Request $request
     * @return mixed JSON response if in AJAX mode, null otherwise
     */
    public function handle(Throwable $e, Request $request)
    {
        // Only handle if we're in AJAX response mode
        if (!Ajax_Endpoint_Controller::is_ajax_response_mode()) {
            return null;
        }

        // Strict production redacts ALL exception detail (file/line/message/backtrace)
        // to prevent information disclosure - a production Ajax fatal response is fully
        // inspectable via devtools or curl, so a generic-message client render is not
        // enough; the detail must never leave the server. Development and debug (both
        // non-production) keep full detail for troubleshooting.
        if (Rsx::is_production()) {
            return response()->json([
                '_success' => false,
                'error_type' => 'fatal',
                'error' => [
                    'error' => 'An unexpected error occurred. Please try again.',
                ],
            ], 200);
        }

        // Build error response (development + debug: full detail for troubleshooting)
        $error_data = [
            'file' => str_replace(base_path() . '/', '', $e->getFile()),
            'line' => $e->getLine(),
            'error' => $e->getMessage(),
            'backtrace' => [],
        ];

        // Get backtrace without args
        $trace = $e->getTrace();
        foreach ($trace as $index => $frame) {
            if ($index >= 10) {
                break;
            } // Limit to 10 frames

            $error_data['backtrace'][] = [
                'file' => isset($frame['file']) ? str_replace(base_path() . '/', '', $frame['file']) : 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
            ];
        }

        // Build response
        $response = [
            '_success' => false,
            'error_type' => 'fatal',
            'error' => $error_data
        ];

        // Include console debug messages if any
        $console_messages = Debugger::_get_console_messages();
        if (!empty($console_messages)) {
            $response['console_debug'] = $console_messages;
        }

        // Return JSON error response with 200 status
        return response()->json($response, 200);
    }
}
