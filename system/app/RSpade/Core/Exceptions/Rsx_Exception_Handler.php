<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 *
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;

/**
 * Rsx_ExceptionHandler - Main exception handler for RSX framework
 *
 * FILE-SUBCLASS-01*: Extends Laravel's Handler but uses compound suffix ExceptionHandler
 * This is intentional for consistency with other *_ExceptionHandler classes
 *
 * HOW THIS IS CALLED:
 * File: bootstrap/app.php
 * Mechanism: Laravel's exception handler registration
 * Code: $app->singleton(ExceptionHandler::class, Rsx_ExceptionHandler::class)
 *
 * WHAT IT DOES:
 * Implements a chain-of-responsibility pattern for exception handling:
 * 1. Loads exception handler classes from config/rsx.php
 * 2. Sorts handlers by priority (lower number = higher priority)
 * 3. Calls each handler's handle() method in order
 * 4. Returns first non-null response
 * 5. Falls back to Laravel's default handling if no handler responds
 *
 * HANDLER EXECUTION ORDER:
 * - Priority 10: CLI exceptions (formatted output for terminal)
 * - Priority 20: AJAX exceptions (JSON error responses)
 * - Priority 30: Playwright test exceptions (plain text output)
 * - Priority 1000: RSX dispatch bootstrapper (404 → RSX routing)
 *
 * EXTENSIBILITY:
 * Users can add/remove/reorder handlers by modifying config/rsx.php:
 * 'exception_handlers' => [
 *     \App\MyApp\My_Custom_ExceptionHandler::class,
 *     \App\RSpade\Core\Exceptions\Cli_ExceptionHandler::class,
 *     // ... etc
 * ]
 */
#[Instantiatable]
class Rsx_Exception_Handler extends Handler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            // Default reportable - can be extended
        });

        // Main renderable - executes the handler chain
        $this->renderable(function (Throwable $e, Request $request) {
            // An HttpResponseException is NOT an error - it CARRIES an already-rendered
            // response, deliberately built by the thrower (Rsx_Csrf's rejection, an
            // abort-with-response, response()->throwResponse()). It must reach the client
            // verbatim, so it is honored BEFORE the chain runs: every handler downstream
            // of here renders SOME error surface for an unrecognized Throwable
            // (Web_Exception_Handler's fatal screen outside dev+debug,
            // Playwright_Exception_Handler's plain-text dump, an app-registered handler),
            // and any of them would replace the intended response with a 500. This is the
            // same rule Laravel applies in Handler::render(), moved ahead of our chain so
            // the chain can never outrank it.
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            // Get handler classes from config
            $handler_classes = config('rsx.exception_handlers', []);

            if (empty($handler_classes)) {
                // No handlers configured, fall back to default Laravel handling
                return null;
            }

            // Validate and collect handlers with their priorities
            $handlers_with_priority = [];
            foreach ($handler_classes as $handler_class) {
                // Validate handler class exists
                if (!class_exists($handler_class)) {
                    throw new RuntimeException(
                        "Exception handler class not found: {$handler_class}. " .
                        "Check your config/rsx.php 'exception_handlers' array."
                    );
                }

                // Validate handler extends abstract base
                if (!is_subclass_of($handler_class, Rsx_Exception_Handler_Abstract::class)) {
                    throw new RuntimeException(
                        "Exception handler {$handler_class} must extend Rsx_Exception_Handler_Abstract. " .
                        "Check your config/rsx.php 'exception_handlers' array."
                    );
                }

                $handlers_with_priority[] = [
                    'class' => $handler_class,
                    'priority' => $handler_class::get_priority(),
                ];
            }

            // Sort by priority (lower number = higher priority)
            usort($handlers_with_priority, function ($a, $b) {
                return $a['priority'] <=> $b['priority'];
            });

            // Execute handlers in priority order
            foreach ($handlers_with_priority as $handler_info) {
                $handler_class = $handler_info['class'];
                $handler = new $handler_class();

                $response = $handler->handle($e, $request);

                // If handler returned a response, use it
                if ($response !== null) {
                    return $response;
                }
            }

            // No handler handled the exception, continue to Laravel's default handling
            return null;
        });
    }
}
