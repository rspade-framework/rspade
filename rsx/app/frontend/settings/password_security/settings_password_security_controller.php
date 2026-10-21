<?php

namespace Rsx\App\Frontend\Settings\PasswordSecurity;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;
use Rsx\Lib\Sessions\Session_Listing;

/**
 * The Password & Security screen's own endpoints: the password change (a stub that changes
 * nothing yet) and the signed-in account's sessions and signing them out. Two-factor and connected accounts are served by the framework's
 * Rsx_Two_Factor_Controller and Rsx_Sso_Controller. Every endpoint acts on the caller's own
 * account and takes no user id.
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Password_Security_Controller extends Rsx_Controller_Abstract
{
    /**
     * Change password (static for now)
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function change_password(Request $request, array $params = [])
    {
        // TODO: Implement password change logic
        return [
            'message' => 'Password changed successfully',
        ];
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
