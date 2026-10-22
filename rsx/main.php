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
use Rsx\App\Login\Login_Controller;
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
        // A web request serves the site its SIGN-IN carries. Every sign-in finishes in
        // Login_Controller::complete_sign_in(), which binds the session to one of the
        // identity's sites (the site picker when there are several), and that binding is
        // what Session::get_site_id() answers on every request after it. So nothing is
        // declared here for a web request: a declaration would OUTRANK the sign-in's site
        // (a request override wins over the stored session) and pin every member of every
        // other site to site 1 - where they hold no membership, so the framework would end
        // their session. Self-signup (rsx.auth.self_signup) creates a new site per signer,
        // which is exactly that case. A visitor who has not signed in has no site, and
        // nothing on the sign-in pages asks for one.
        //
        // A COMMAND, a task and a test run have no sign-in to carry a site, and serve site 1
        // - the template's own. init() runs from the framework service provider in both
        // contexts, before any session is read.
        //
        // The PORTAL's site is the framework's: it is resolved from the portal URL and
        // declared per request before Portal_Main::init() (rsx:man portal); every framework
        // site seam asks the EXPERIENCE of the request which of the two to use
        // (Rsx_Site_Model_Abstract::get_current_site_id).
        if (app()->runningInConsole()) {
            Session::set_site_id(1);
        }
    }

    /**
     * Pre-dispatch hook
     *
     * Called before any route dispatch, before every external API call (after the bearer
     * identity and the gates), and before every Ajax call - once per call, with
     * $params['controller'] / $params['action'] naming the endpoint. If a non-null value is
     * returned, dispatch is halted: a page answers with that value, an API call with 403
     * account_refused, an Ajax call with a coded result's envelope (response_unauthorized()
     * keeps its reason) or otherwise unauthorized.
     *
     * Two interceptions live here:
     *   - API ACCESS. A bearer-key request (Session::is_api_request()) whose user lacks the
     *     can_use_api permission (User_Model::PERM_API_ACCESS) is refused - the framework
     *     answers it with 403 account_refused. users.is_api_access_enabled is the framework's
     *     own switch and is checked before this hook; the permission is this application's,
     *     and an API caller needs both.
     *   - AN OUTSTANDING SECOND-FACTOR ENROLLMENT (rsx.auth.require_two_factor, off as
     *     shipped). A signed-in user who owes one is redirected to the Password & Security
     *     screen on every page request until they hold a factor - the rule and its exemptions
     *     are Login_Controller::outstanding_enrollment_redirect().
     * Site membership is not checked here - users.is_enabled is the framework's switch and
     * the framework enforces it before dispatch.
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

        // A step the signed-in user still owes, parked at sign-in by
        // Login_Controller::complete_sign_in(): page requests are held on its screen.
        $enrollment_url = Login_Controller::outstanding_enrollment_redirect($request);

        if ($enrollment_url !== null) {
            return redirect($enrollment_url);
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
