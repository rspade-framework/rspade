<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Http\Request;
use Throwable;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;
use App\RSpade\Core\Rsx;

/**
 * Api_Exception_Handler - render uncaught API-dispatch errors as JSON 500.
 *
 * PRIORITY: 25 (between Ajax at 20 and Playwright at 30)
 *
 * Gates strictly on Api_Dispatcher::is_api_dispatch(), so it only handles exceptions that
 * escaped an in-flight external API request - never a normal web request. An API endpoint
 * error must never fall through to Laravel's HTML error page: it produces the same bare
 * {"error":{"code","message"}} shape as every other API failure.
 *
 * In development the message carries the exception text and class for debugging; in a
 * production-like build (Rsx::is_production()) it is the generic "Internal server error".
 */
class Api_Exception_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - runs after Ajax (20), before Playwright (30).
     */
    public static function get_priority(): int
    {
        return 25;
    }

    /**
     * Handle the exception only when an API dispatch is in flight.
     */
    public function handle(Throwable $e, Request $request)
    {
        if (!Api_Dispatcher::is_api_dispatch()) {
            return null;
        }

        $message = Rsx::is_production()
            ? 'Internal server error'
            : $e->getMessage() . ' (' . get_class($e) . ')';

        return response()->json([
            'error' => [
                'code' => 'internal_error',
                'message' => $message,
            ],
        ], 500);
    }
}
