<?php

namespace Rsx\Portal\Settings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use Rsx\Portal_Permission;

/**
 * Portal_Settings_Controller - the portal account screen's endpoints: the caller's own
 * profile, password change, and device-session list/termination.
 *
 * Authorization: the class-level #[Auth('is_logged_in')] gate (portal realm) admits
 * only a logged-in portal user, at the framework seam, before any code here runs.
 * Every method then reads the SESSION's portal user - never a client-supplied id - so
 * a caller can only ever see or change their own account; the mutating methods
 * additionally refuse a read-only (staff impersonation) session.
 */
#[Auth('is_logged_in')]
class Portal_Settings_Controller extends Rsx_Controller_Abstract
{
    /**
     * Get current portal user's profile data
     */
    #[Ajax_Endpoint]
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
        if (Portal_Permission::is_read_only()) {
            return response_unauthorized('This is a read-only session; changes are disabled.');
        }

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
     * Get active sessions for current user
     */
    #[Ajax_Endpoint]
    public static function get_sessions(Request $request, array $params = [])
    {
        // The facade owns the query, the ordering and the is_current stamp - the
        // caller never touches the session table.
        $sessions = Portal_Session::get_sessions_for_user();

        $result = [];
        foreach ($sessions as $session) {
            $result[] = [
                'id' => $session['id'],
                'ip_address' => $session['ip_address'],
                'user_agent' => $session['user_agent'],
                'last_active' => $session['last_active'],
                'is_current' => $session['is_current'],
            ];
        }

        return ['sessions' => $result];
    }

    /**
     * Terminate a session
     */
    #[Ajax_Endpoint]
    public static function terminate_session(Request $request, array $params = [])
    {
        if (Portal_Permission::is_read_only()) {
            return response_unauthorized('This is a read-only session; changes are disabled.');
        }

        $session_id = $params['session_id'] ?? null;
        if (!$session_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Session ID is required');
        }

        // Fail-closed in the facade: the owning-user predicate is part of the
        // statement, so another user's session id matches nothing and returns false.
        if (!Portal_Session::terminate_session((int) $session_id)) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Session not found');
        }

        return ['message' => 'Session terminated'];
    }
}
