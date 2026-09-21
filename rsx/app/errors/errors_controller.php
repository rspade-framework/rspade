<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Errors;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Errors_Controller - the staff realm's full-page error screens
 *
 * These routes are declarations, not destinations. The framework calls the method
 * directly when a request ends on that status - no controller pre_dispatch runs, no
 * link ever points here - and hands it the failure as $params['error'], an
 * App\RSpade\Core\Errors\Error_Context. The response's status is then forced to the
 * context's status, so a page that renders a view cannot answer 200.
 *
 * WHAT A PAGE MAY SHOW. The context is all there is: status, title, message, path,
 * method, realm, home_url, preview and (for a 500, outside production) detail. The
 * failing request is over and its controller never ran, so there is no record to load
 * and no session chrome to draw - and these pages are anonymous and inspectable with
 * curl, so nothing that is not already in the context belongs on one.
 *
 * NO /error/500. The generic page answers every status without an exact page of its
 * own, 500 included - that is what makes the generic page's role visible in the template rather
 * than theoretical. An application that wants a bespoke crash page adds
 * #[Route('/error/500')] here and a blade beside it; nothing else changes.
 *
 * Public by declaration: an error page is reached by whoever hit the error, including
 * an anonymous visitor and a caller whose session has just been denied. It renders
 * only what the context carries, so there is nothing behind the gate to protect.
 *
 * PREVIEW, DEVELOPMENT ONLY. Browsing /error/404, /error/403, /error/419 or
 * /error/generic renders the page with a fabricated context (the generic preview is a
 * 500 carrying a fabricated detail block). The framework intercepts the path in every
 * mode, so these URLs are never served as ordinary 200 pages.
 *
 * See: php artisan rsx:man error_pages
 */
#[Auth('public')]
class Errors_Controller extends Rsx_Controller_Abstract
{
    /**
     * 404 - no route matched, or a record the URL named does not exist.
     *
     * Preview: /error/404
     */
    #[Route('/error/404')]
    public static function not_found(Request $request, array $params = [])
    {
        return rsx_view('Errors_Not_Found', ['error' => $params['error']]);
    }

    /**
     * 403 - the caller is identified and the gate said no.
     *
     * An anonymous caller never reaches this page: the framework sends an
     * unidentified visitor to the login route instead, with the URL they wanted
     * threaded through Login_Redirect.
     *
     * Preview: /error/403
     */
    #[Route('/error/403')]
    public static function forbidden(Request $request, array $params = [])
    {
        return rsx_view('Errors_Forbidden', ['error' => $params['error']]);
    }

    /**
     * 419 - a CSRF failure on a native form POST.
     *
     * Preview: /error/419
     */
    #[Route('/error/419')]
    public static function expired(Request $request, array $params = [])
    {
        return rsx_view('Errors_Expired', ['error' => $params['error']]);
    }

    /**
     * Every status with no exact page of its own - 400, 500, 429, anything abort()
     * raised on a browsed request.
     *
     * Preview: /error/generic (rendered as a 500, with a detail block)
     */
    #[Route('/error/generic')]
    public static function generic(Request $request, array $params = [])
    {
        return rsx_view('Errors_Generic', ['error' => $params['error']]);
    }
}
