<?php

namespace Rsx\Portal\Settings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use Rsx\Lib\Sessions\Session_Listing;

/**
 * Portal_Settings_Controller - the portal account screen's endpoints: the caller's own
 * profile, password change, and where the account is signed in (list, sign out a device).
 *
 * Authorization: the class-level #[Auth('is_logged_in')] gate (portal realm) admits
 * only a logged-in portal user, at the framework seam, before any code here runs.
 * Every method then reads the SESSION's portal user - never a client-supplied id - so
 * a caller can only ever see or change their own account. During a staff member's
 * read-only "View as Client" session the framework refuses every endpoint not marked
 * #[Portal_Impersonation_Readable] - here, the two writes.
 */
#[Auth('is_logged_in')]
class Portal_Settings_Controller extends Rsx_Controller_Abstract
{
    /**
     * Get current portal user's profile data
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function get_profile(Request $request, array $params = [])
    {
        $user = Portal_Session::get_portal_user();

        return [
            'email' => $user->email,
            'is_verified' => $user->is_verified,
            'status_id' => $user->status_id,
            'status_id__label' => $user->status_id__label,
            'last_login' => $user->last_login,
            'created_at' => $user->created_at,
        ];
    }

    /**
     * Change password
     */
    #[Ajax_Endpoint]
    public static function change_password(Request $request, array $params = [])
    {
        $user = Portal_Session::get_portal_user();

        $current = $params['current_password'] ?? '';
        $new_password = $params['new_password'] ?? '';
        $confirm = $params['confirm_password'] ?? '';

        if (empty($current)) {
            return response_form_error('Validation failed', ['current_password' => 'Current password is required']);
        }

        if (!$user->check_password($current)) {
            return response_form_error('Validation failed', ['current_password' => 'Current password is incorrect']);
        }

        $min_length = config('rsx.portal.password_min_length', 8);
        if (strlen($new_password) < $min_length) {
            return response_form_error('Validation failed', ['new_password' => "Password must be at least {$min_length} characters"]);
        }

        if ($new_password !== $confirm) {
            return response_form_error('Validation failed', ['confirm_password' => 'Passwords do not match']);
        }

        $user->set_password($new_password);
        $user->save();

        return ['message' => 'Password updated successfully'];
    }

    /**
     * Where the portal account is signed in: its own sessions (Session_Listing - active
     * within the last few months, signed-out ones too), this device marked. A staff member's
     * View as Client of the account is never listed here.
     *
     * @return array {sessions: [Session_Listing::row()]}
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function get_sessions(Request $request, array $params = [])
    {
        return ['sessions' => Session_Listing::rows(Portal_Session::get_sessions_for_user())];
    }

    /**
     * Sign one of the account's OTHER devices out of the portal
     * (Portal_Session::terminate_session()). The id must be one get_sessions() lists with
     * Sign out offered - signed in, not this device - else not found. That device's staff
     * sign-in, if it has one, is untouched.
     */
    #[Ajax_Endpoint]
    public static function terminate_session(Request $request, array $params = [])
    {
        $session = Session_Listing::find(Session_Listing::recent(Portal_Session::get_sessions_for_user()), $params['user_session_id'] ?? null);

        if ($session === null || !Session_Listing::row($session)['can_sign_out'] || !Portal_Session::terminate_session($session['id'])) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Session not found');
        }

        return ['message' => 'Session terminated'];
    }
}
