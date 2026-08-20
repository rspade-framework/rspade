<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Providers;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;

/**
 * Rsx_Dispatch_Bootstrapper_ExceptionHandler - Bootstrap RSX routing from Laravel 404s
 *
 * PRIORITY: 1000 (low priority - runs last as catch-all)
 *
 * THIS IS THE CRITICAL ENTRY POINT FOR RSX ROUTING.
 *
 * This handler provides the mechanism for RSX routes to work alongside Laravel routes.
 * When Laravel doesn't find a matching route and throws NotFoundHttpException (404),
 * this handler catches it and attempts to dispatch through the RSX Dispatcher.
 *
 * ROUTING PRIORITY:
 * 1. Laravel routes (handled by Laravel's router)
 * 2. Laravel throws 404 if no match
 * 3. This handler catches 404 and tries RSX Dispatcher
 * 4. If RSX finds a route, return the response
 * 5. If no RSX route, return null (Laravel shows 404 page)
 *
 * This gives Laravel routes priority over RSX routes, which is the desired behavior
 * since users can override framework routes with standard Laravel routing if needed.
 *
 * LOCATION:
 * This handler is in the Providers directory because it's a core bootstrapping mechanism
 * for the RSX framework, not just error handling.
 */
class Rsx_Dispatch_Bootstrapper_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - This runs last as a catch-all
     *
     * @return int
     */
    public static function get_priority(): int
    {
        return 1000;
    }

    /**
     * Handle 404 exceptions by attempting RSX dispatch
     *
     * @param Throwable $e
     * @param Request $request
     * @return mixed RSX response if route found, null to show 404
     */
    public function handle(Throwable $e, Request $request)
    {
        // Only handle 404 errors
        if (!($e instanceof NotFoundHttpException)) {
            return null;
        }

        // Get the requested path
        $path = '/' . ltrim($request->path(), '/');

        // Try RSX dispatch
        $response = Dispatcher::dispatch($path, $request->method(), [], $request);

        // Handle trace mode if enabled
        if (Debugger::is_trace_output_request()) {
            die();
        }

        // If RSX found a route, return the response
        if ($response !== null) {
            return $response;
        }

        // No RSX route found - let Laravel handle the 404 normally
        return null;
    }
}
