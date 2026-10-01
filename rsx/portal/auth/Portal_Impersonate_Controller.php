<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Portal\Portal_Session;

/**
 * Portal Impersonate Controller
 *
 * The portal end of the staff "View as Client" feature. Staff begin an impersonation from
 * the main app (Frontend_Contacts_Controller::begin_portal_impersonation), which calls
 * Portal_Session::begin_impersonation_from_staff() and opens the URL it returns: the
 * portal itself on the same host, or the framework's linked-session handshake
 * (/_session_link/*) when the portal is on its own host. Neither passes through here.
 *
 *   - stop: end the impersonation (clears the portal properties, leaving the staff
 *     login on the same browser session alone) and show a "you may close this tab" page.
 *
 * Read-only enforcement is NOT here - the framework refuses every portal Ajax endpoint
 * not marked #[Portal_Impersonation_Readable] while the session is impersonating
 * (Ajax::execute). See: php artisan rsx:man portal.
 *
 * Public gate: stop must work even after the session is gone.
 */
#[Auth('public')]
class Portal_Impersonate_Controller extends Rsx_Controller_Abstract
{
    /**
     * End the current impersonation session.
     */
    #[Portal_Route('/impersonate/stop', methods: ['GET'])]
    public static function stop(Request $request, array $params = [])
    {
        Portal_Session::stop_impersonation();

        return rsx_view('Portal_Impersonate_Stopped');
    }
}
