<?php

namespace App\RSpade\Core\SPA;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;

/**
 * Spa_Session_Controller - Session validation endpoint for SPA navigation
 *
 * Called after SPA navigation to detect:
 * 1. Codebase updates (build_key changed)
 * 2. User account changes (user data changed)
 * 3. Session invalidation (logged out, ACLs changed)
 *
 * If any values differ from what the client has in window.rsxapp,
 * the client will trigger a page refresh to get fresh state.
 *
 * AUTHORIZATION: the class gate is 'public' - the endpoint reports the CALLER'S OWN
 * session state (or the absence of one) and discloses nothing else, so it answers
 * regardless of auth status. That is the point: a logged-out client must still be
 * able to learn that it is logged out.
 */
#[Auth('public')]
#[Auth_Realm('any')]
class Spa_Session_Controller extends Rsx_Controller_Abstract
{
    /**
     * Return current session state for validation
     *
     * Returns the same values that would be set in window.rsxapp
     * during a full page load, allowing client to detect staleness.
     */
    #[Ajax_Endpoint]
    public static function get_state(Request $request, array $params = [])
    {
        // Build key - detects codebase updates
        $build_key = Manifest::get_build_key();

        // ONE cookie, one session hash. The identity to report still depends on the
        // EXPERIENCE the page belongs to, and the caller passes is_portal (from
        // Rsx_Portal.is_portal()) because an Ajax request cannot reliably self-detect
        // the portal context server-side. This only drives client-side
        // staleness/refresh, never authorization, so trusting the flag is safe.
        $session_token = $_COOKIE['rsx'] ?? null;

        if (!empty($params['is_portal'])) {
            $user = Portal_Session::get_portal_user();
            $site = Portal_Session::get_site();
        } else {
            $user = Session::get_user();
            $site = Session::get_site();
        }

        // Session hash - detects session changes (logout, new session)
        $session_hash = $session_token
            ? hash_hmac('sha256', $session_token, config('app.key'))
            : null;

        return [
            'build_key' => $build_key,
            'session_hash' => $session_hash,
            'user' => $user,
            'site' => $site,
        ];
    }
}
