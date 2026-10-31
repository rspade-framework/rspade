<?php

namespace Rsx\Portal\Settings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Portal_Login_History;
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
     * Change the signed-in portal user's password. The Change Password form posts here.
     *
     * Order: every field rule first (all errors together), then the CURRENT password -
     * throttled first (Login_Throttle::require_not_throttled() for this account: the budget
     * every sign-in of the address and the account shares), and a wrong one is recorded
     * through Portal_Login_History::record_failure(), which feeds the throttle, so this form
     * is no oracle for guessing the password of a browser left signed in. A refusal changes
     * nothing.
     */
    #[Ajax_Endpoint]
    public static function change_password(Request $request, array $params = [])
    {
        $user = Portal_Session::get_portal_user();

        $current = (string) ($params['current_password'] ?? '');
        $new_password = (string) ($params['new_password'] ?? '');
        $confirm = (string) ($params['confirm_password'] ?? '');

        $errors = [];
        if ($current === '') {
            $errors['current_password'] = 'Current password is required';
        }

        $min_length = config('rsx.portal.password_min_length', 8);
        if (strlen($new_password) < $min_length) {
            $errors['new_password'] = "Password must be at least {$min_length} characters";
        }

        if ($new_password !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match';
        }

        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        try {
            Login_Throttle::require_not_throttled($user->email);
        } catch (Auth_Throttled_Exception $e) {
            return response_form_error($e->getMessage());
        }

        if (!$user->check_password($current)) {
            Portal_Login_History::record_failure(
                $user->email,
                Portal_Login_History::STATUS_FAILED_PASSWORD,
                'Wrong current password on Change Password',
                (int) $user->id
            );

            return response_form_error('Please correct the errors below.', [
                'current_password' => 'Current password is incorrect',
            ]);
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
