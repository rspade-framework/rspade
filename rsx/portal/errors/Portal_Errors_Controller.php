<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Errors;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Portal_Errors_Controller - the portal realm's full-page error screens
 *
 * The portal gets its own pages because it is its own experience: these render the
 * portal bundle and the portal's auth chrome, and their home link is the portal's
 * home, not the staff application's. The realm is chosen by the FAILING request, so a
 * portal URL that 404s lands here and a staff URL lands on Rsx\App\Errors.
 *
 * Declared with #[Portal_Route], which is what puts the pattern in the portal route
 * table; the staff #[Route] twins are separate declarations in rsx/app/errors/.
 *
 * TWO PAGES, NOT FOUR. A portal realm with no exact page for a status falls to
 * /error/generic here, and a portal realm with neither falls to the STAFF pair - a
 * page is better than no page. So the portal declares the status it has something
 * particular to say about (404) and lets the catch-all answer the rest.
 *
 * The framework calls the method directly with $params['error'], an
 * App\RSpade\Core\Errors\Error_Context, and forces the response's status. A page
 * shows what the context carries and nothing else - there is no portal session to
 * read here and no client record to load.
 *
 * Public by declaration: these pages are reached by whoever hit the error, including
 * a visitor with no portal session at all.
 *
 * Preview (development only): <portal prefix>/error/404 and <portal prefix>/error/generic.
 *
 * See: php artisan rsx:man error_pages
 */
#[Auth('public')]
class Portal_Errors_Controller extends Rsx_Controller_Abstract
{
    /**
     * 404 - no portal route matched, or a record the URL named does not exist.
     */
    #[Portal_Route('/error/404')]
    public static function not_found(Request $request, array $params = [])
    {
        return rsx_view('Portal_Errors_Not_Found', ['error' => $params['error']]);
    }

    /**
     * Every other status a portal request can end on - 403, 419, 500, anything
     * abort() raised.
     */
    #[Portal_Route('/error/generic')]
    public static function generic(Request $request, array $params = [])
    {
        return rsx_view('Portal_Errors_Generic', ['error' => $params['error']]);
    }
}
