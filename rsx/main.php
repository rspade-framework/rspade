<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx;

use Illuminate\Http\Request;
use App\RSpade\Core\Base\Main_Abstract;
use App\RSpade\Core\Session\Session;
use Rsx\Permission;

/**
 * Main - Application-wide middleware hooks
 *
 * Provides application-wide initialization and request lifecycle hooks
 */
class Main extends Main_Abstract
{
    /**
     * Initialize the Main class
     *
     * Called once during application bootstrap
     *
     * @return void
     */
    public static function init()
    {
        // WHICH SITE DOES THE STAFF APP SERVE?
        //
        // This template is mono-site: every STAFF request, and every CLI process
        // (init() runs from the framework service provider in both), serves site 1.
        // A multi-tenant app resolves it from the request host or the signed-in user
        // instead - same call, different source.
        //
        // This is the staff app's own declaration and nothing else's. The PORTAL
        // declares its site separately, in Portal_Main::init() (see rsx/portal_main.php),
        // and since B-76 the framework's tenant boundary - and every other site seam -
        // asks the EXPERIENCE of the request which of the two to use
        // (Rsx_Site_Model_Abstract::get_current_site_id). Portal tenancy no longer rides
        // this line: it was proven live by neutralizing it and running a full portal
        // login + site-scoped portal Ajax, which returned the portal's own site-1 data.
        //
        // Historical note, because the previous comment here said the opposite: before
        // B-76 that ORM seam read the STAFF facade unconditionally, so this line was
        // accidentally load-bearing for the portal and guarding it with
        // is_portal_request() broke portal login outright. That is fixed; the line is
        // now exactly what it looks like.
        Session::set_site_id(1);
    }

    /**
     * Pre-dispatch hook
     *
     * Called before any route dispatch, and before every external API call (after the
     * bearer identity and the gates). If a non-null value is returned, dispatch is halted:
     * a page answers with that value, an API call with 403 account_refused.
     *
     * One interception lives here:
     *   - API ACCESS. A bearer-key request (Session::is_api_request()) whose user lacks the
     *     can_use_api permission (User_Model::PERM_API_ACCESS) is refused - the framework
     *     answers it with 403 account_refused. users.is_api_access_enabled is the framework's
     *     own switch and is checked before this hook; the permission is this application's,
     *     and an API caller needs both.
     * Site membership is not checked here - users.is_enabled is the framework's switch and
     * the framework enforces it before dispatch. An administrator-required second factor is
     * not here either: it is a login requirement (rsx/app/login/
     * two_factor_enrollment_requirement.php), which the framework enforces on every surface.
     *
     * @param Request $request The current request
     * @param array $params Combined GET values and URL parameters
     * @return mixed|null Return null to continue, or a response to halt dispatch
     */
    public static function pre_dispatch(Request $request, array $params)
    {
        // Site locks are now automatically acquired in RsxSession::get_site_id()
        // when any code accesses the site_id from the session

        // NOTE: the rsx:debug / Playwright dev-auth backdoor used to live here. It is
        // now Dispatcher::__handle_dev_auth() (framework-side, mirroring the portal's),
        // because the declarative #[Auth] gates run before this hook and must see the
        // identity the harness asserts. See: php artisan rsx:man auth_gates

        // API ACCESS: the permission, on every bearer-key request - the /api/vN surface and
        // the file routes alike. Any non-null answer is the API's 403 account_refused.
        if (Session::is_api_request() && !Permission::can_use_api()) {
            return 'api_access_not_granted';
        }

        // Return null to continue normal dispatch
        return null;
    }

    /**
     * Unhandled route hook
     *
     * Called when no route matches the request
     *
     * @param Request $request The current request
     * @param array $params Combined GET values and URL parameters
     * @return mixed|null Return null for default 404, or a response to handle
     */
    public static function unhandled_route(Request $request, array $params)
    {
        // THE 404 PAGE IS NOT HERE. An unmatched staff URL renders rsx/app/errors/
        // (Errors_Controller::not_found, declared as #[Route('/error/404')]), which the
        // framework invokes after this hook declines. Styling or rewording the 404 is a
        // change to that page.
        //
        // This hook is for answering an unmatched URL with something OTHER than an error
        // page - a redirect map for retired URLs, a vanity path, a slug table - by returning a
        // response. Returning null means "I have nothing for this URL", and the error
        // page follows.
        return null;
    }
}
