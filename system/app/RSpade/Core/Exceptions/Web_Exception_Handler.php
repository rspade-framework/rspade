<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;
use App\RSpade\Core\Rsx;

/**
 * Web_Exception_Handler - the full-page terminal outcome of an uncaught exception
 *
 * PRIORITY: 1100 (runs LAST, after Rsx_Dispatch_Bootstrapper_Handler at 1000)
 *
 * Everything a request can end as is now an Error_Screens page: the dispatchers
 * render denials and unmatched routes themselves, and this handler covers what is
 * left - an exception that escaped all the way to the top of a full-page request.
 *
 * IT MUST RUN AFTER THE BOOTSTRAPPER. RSX routing is driven by Laravel throwing
 * NotFoundHttpException and the bootstrapper (priority 1000) dispatching it into
 * the RSX Dispatcher. A handler that claimed 404s ahead of it would take every RSX
 * route offline. By the time this one runs, RSX has already declined the URL.
 *
 * DEVELOPMENT KEEPS ITS DEBUG PAGE. In development mode with APP_DEBUG on, this
 * handler declines and Laravel renders the interactive debug error page (Ignition)
 * - by far the most useful thing to see while writing code. Every other
 * combination gets the themed screen: debug/production mode always (redacted, per
 * Error_Screens), and development with APP_DEBUG off (detail included - the
 * environment is not production).
 *
 * IT SPEAKS FOR THE FULL-PAGE CHANNEL ONLY, and does not re-test for that: the
 * Cli/Ajax/Api/Playwright handlers own their channels at priorities 10-30, so a
 * console command, an ajax call, an API request and a Playwright probe have all
 * been answered before this handler is reached. One place decides per channel.
 */
class Web_Exception_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - after the dispatch bootstrapper, last in the chain
     *
     * @return int
     */
    public static function get_priority(): int
    {
        return 1100;
    }

    /**
     * Render the themed terminal page for a full-page request
     *
     * @param Throwable $e
     * @param Request $request
     * @return mixed Response, or null to leave the exception to Laravel
     */
    public function handle(Throwable $e, Request $request)
    {
        // Coded HTTP outcomes raised by application code (abort(404), abort(403))
        // land on the same screens the dispatchers use, so a page never depends on
        // WHICH layer decided it was missing or denied.
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            if ($status === 404) {
                return Error_Screens::not_found($request);
            }

            if ($status === 403) {
                return Error_Screens::unauthorized($request);
            }

            // Every other HTTP status (405, 419, 429, 503 ...) keeps its own
            // meaning and its own Laravel view - this class speaks for the three
            // outcomes Error_Screens defines, not for the whole status space.
            return null;
        }

        if (Rsx::is_development() && config('app.debug')) {
            return null;
        }

        return Error_Screens::fatal($request, $e);
    }
}
