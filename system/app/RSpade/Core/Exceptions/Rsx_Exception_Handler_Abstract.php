<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Http\Request;
use Throwable;

/**
 * Rsx_Exception_Handler_Abstract - Base class for RSX exception handlers
 *
 * This abstract class defines the contract for exception handlers in the RSX framework.
 * Handlers are executed in priority order (lower number = higher priority) and can
 * choose to handle an exception by returning a response, or pass it to the next handler
 * by returning null.
 *
 * HANDLER CHAIN:
 * - Handlers are registered in config/rsx.php under 'exception_handlers'
 * - Main Rsx_ExceptionHandler loads and sorts handlers by priority
 * - Each handler's handle() method is called in order
 * - First non-null response is returned
 * - If all handlers return null, Laravel's default handling is used
 *
 * CREATING A HANDLER:
 * 1. Extend this abstract class
 * 2. Implement handle() method
 * 3. Optionally override get_priority() (default is 100)
 * 4. Add to config/rsx.php 'exception_handlers' array
 *
 * EXAMPLE:
 * class My_Custom_ExceptionHandler extends Rsx_Exception_Handler_Abstract
 * {
 *     public static function get_priority(): int { return 50; }
 *
 *     public function handle(Throwable $e, Request $request)
 *     {
 *         if (!($e instanceof MyException)) {
 *             return null; // Not my concern
 *         }
 *
 *         return response('Custom error', 500);
 *     }
 * }
 */
#[Instantiatable]
abstract class Rsx_Exception_Handler_Abstract
{
    /**
     * Try to handle the exception
     *
     * @param Throwable $e The exception to handle
     * @param Request $request The current request
     * @return mixed Response if this handler handles the exception, null to pass to next handler
     */
    abstract public function handle(Throwable $e, Request $request);

    /**
     * Get priority for this handler
     *
     * Lower numbers have higher priority and run first.
     * Default is 100 for normal handlers.
     *
     * Suggested priority ranges:
     * - 1-50: Critical/environment-specific handlers (CLI, AJAX)
     * - 51-100: Standard handlers
     * - 101-500: Low priority handlers
     * - 501+: Fallback* or catch-all handlers
     *
     * @return int Priority value (lower = higher priority)
     */
    #[Replaceable]
    public static function get_priority(): int
    {
        return 100;
    }
}
