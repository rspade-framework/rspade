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
use App\RSpade\Core\Portal\Rsx_Portal;

/**
 * Portal Impersonate Controller
 *
 * Server-rendered endpoints for the staff "View as Client" feature. Staff begin an
 * impersonation from the main app (Frontend_Contacts_Controller::begin_portal_impersonation),
 * which mints a single-use handoff token and opens this claim URL in a new tab.
 *
 *   - claim: consume the handoff token (stamps the impersonated portal identity onto
 *     this browser's own session, replacing any portal identity already on it) and
 *     land on the dashboard.
 *   - stop:  end the impersonation (clears the portal properties, leaving the staff
 *     login on the same browser session alone) and show a "you may close this tab" page.
 *
 * Read-only enforcement is NOT here - it lives at the portal's mutating endpoints
 * via Portal_Permission::is_read_only(). See: php artisan rsx:man portal.
 *
 * Public gate: both routes are reached WITHOUT a prior portal login (claim mints the
 * session; stop must work even after the session is gone).
 */
#[Auth('public')]
class Portal_Impersonate_Controller extends Rsx_Controller_Abstract
{
    /**
     * Claim a single-use impersonation handoff token and start the session.
     */
    #[Portal_Route('/impersonate/claim', methods: ['GET'])]
    public static function claim(Request $request, array $params = [])
    {
        $token = (string) ($params['t'] ?? '');

        if (!Portal_Session::claim_impersonation($token)) {
            return rsx_view('Portal_Impersonate_Invalid');
        }

        return redirect(Rsx_Portal::Route('Portal_Dashboard_Action'));
    }

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
