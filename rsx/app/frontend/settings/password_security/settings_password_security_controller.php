<?php

namespace Rsx\App\Frontend\Settings\PasswordSecurity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use Rsx\Lib\Sessions\Session_Listing;

/**
 * The Password & Security screen's own endpoints: the password change, and the signed-in
 * account's sessions and signing them out. Two-factor and connected accounts are served by
 * the framework's Rsx_Two_Factor_Controller and Rsx_Sso_Controller. Every endpoint acts on
 * the caller's own account and takes no user id.
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Password_Security_Controller extends Rsx_Controller_Abstract
{
    /** The shortest password this application accepts - the same rule signup applies. */
    const PASSWORD_MIN_LENGTH = 8;

    /**
     * Change the signed-in identity's password. The Change Password <Rsx_Form> posts here.
     *
     * Order: the new password's rules, then the CURRENT password - throttle first
     * (Login_Throttle::require_not_throttled()), and a wrong one is recorded through
     * Login_History::record_failure(), which feeds the throttle, so this form is no oracle
     * for guessing the password of a browser left signed in. A refusal changes nothing.
     *
     * Saving the new hash through Login_User_Model is the whole sign-out: the model ends
     * every OTHER browser's sign-in of this identity itself (rsx:man session, PASSWORD
     * CHANGES), keeping this one; API keys are untouched.
     *
     * Refused while impersonating: the account belongs to somebody else.
     *
     * @param array $params {current_password, new_password, confirm_password}
     * @return array {changed: true}
     */
    #[Ajax_Endpoint]
    public static function change_password(Request $request, array $params = [])
    {
        if (Session::is_impersonating()) {
            return response_error(Ajax::ERROR_UNAUTHORIZED, 'You cannot change the password of an account you are impersonating.');
        }

        $current = (string) ($params['current_password'] ?? '');
        $new_password = (string) ($params['new_password'] ?? '');
        $confirm = (string) ($params['confirm_password'] ?? '');

        $errors = [];

        if ($current === '') {
            $errors['current_password'] = 'Current password is required';
        }

        if ($new_password === '') {
            $errors['new_password'] = 'New password is required';
        } elseif (strlen($new_password) < self::PASSWORD_MIN_LENGTH) {
            $errors['new_password'] = 'Password must be at least ' . self::PASSWORD_MIN_LENGTH . ' characters';
        }

        if ($new_password !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match';
        }

        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        try {
            Login_Throttle::require_not_throttled();
        } catch (Auth_Throttled_Exception $e) {
            return response_form_error($e->getMessage());
        }

        $login_user = Session::get_login_user();

        if (!Hash::check($current, $login_user->password)) {
            Login_History::record_failure(
                $login_user->email,
                Login_History::STATUS_FAILED_PASSWORD,
                'Wrong current password on Change Password',
                (int) $login_user->id
            );

            return response_form_error('Please correct the errors below.', [
                'current_password' => 'Current password is incorrect',
            ]);
        }

        $login_user->password = Login_User_Model::hash_password($new_password);
        $login_user->save();

        return ['changed' => true];
    }

    /**
     * Where the signed-in account is signed in: its own sessions (Session_Listing - active
     * within the last few months, signed-out ones too), across every site it belongs to,
     * this device marked. Impersonations of the account are somebody else acting as it and
     * are never listed here.
     *
     * @return array {sessions: [Session_Listing::row()]}
     */
    #[Ajax_Endpoint]
    public static function get_sessions(Request $request, array $params = [])
    {
        return ['sessions' => Session_Listing::rows(Session::get_sessions_for_user())];
    }

    /**
     * Sign one of the account's OTHER sessions out (Session::terminate_session()). The id
     * must be one get_sessions() lists with Sign out offered - signed in, not this device,
     * not an API key's use - else not found.
     */
    #[Ajax_Endpoint]
    public static function sign_out_session(Request $request, array $params = [])
    {
        $session = Session_Listing::find(Session_Listing::recent(Session::get_sessions_for_user()), $params['user_session_id'] ?? null);

        if ($session === null || !Session_Listing::row($session)['can_sign_out'] || !Session::terminate_session($session['id'])) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'No signed-in session with that id');
        }

        return ['signed_out' => 1];
    }

    /**
     * Sign the account out everywhere but this device (Session::terminate_all_other_sessions()):
     * every other browser on every site, listed or not, and every other browser's two-factor
     * trust. API keys are untouched - revoking a key is what ends its use.
     *
     * @return array {signed_out} - how many sessions were ended
     */
    #[Ajax_Endpoint]
    public static function sign_out_other_sessions(Request $request, array $params = [])
    {
        return ['signed_out' => Session::terminate_all_other_sessions()];
    }
}
