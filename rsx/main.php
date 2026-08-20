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
     * Called before any route dispatch. If a non-null value is returned,
     * dispatch is halted and that value is returned as the response.
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

        // Check if user is authorized for frontend routes
        $handler = $params['_handler'] ?? '';
        if (str_starts_with($handler, 'Rsx\App\Frontend')) {
            // User must be logged in and have access to current site
            $login_user_id = Session::get_login_user_id();
            $site_id = Session::get_site_id();

            if ($login_user_id && $site_id) {
                // Check if user has access to this site
                $user = \User_Model::where('login_user_id', $login_user_id)
                    ->where('site_id', $site_id)
                    ->first();

                if (!$user) {
                    // User is not authorized for this site
                    return redirect(\Rsx::Route('Site_Unauthorized_Controller'));
                }
            }
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
        // Custom 404 handling logic here
        // Return null to use default 404 behavior
        return null;
    }
}
