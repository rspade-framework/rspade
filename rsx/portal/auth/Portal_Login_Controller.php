<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\Models\Portal_Membership_Model;

/**
 * Portal Login Controller
 *
 * Handles portal user authentication.
 * This is a public controller - no authentication required.
 */
#[Auth('public')]
class Portal_Login_Controller extends Rsx_Controller_Abstract
{
    /**
     * Show portal login form and handle login
     */
    #[Portal_Route('/login', methods: ['GET', 'POST'])]
    public static function index(Request $request, array $params = [])
    {
        // Optional invited-client destination (set when a returning contact follows
        // an invite link that resolved to an existing account).
        $client_id = (int) ($params['client'] ?? 0);

        // If already logged in, go straight to the invited client (or dashboard).
        if (Portal_Session::is_logged_in()) {
            return redirect(static::_post_auth_destination(Portal_Session::get_portal_user(), $client_id));
        }

        $error = null;
        $posted_email = null;

        if ($request->isMethod('POST')) {
            // Human verification first: it gates credential checking, and therefore
            // account enumeration, before any lookup happens.
            Rsx_Turnstile::validate($request);

            $posted_email = $request->input('email');
            $password = $request->input('password');

            // Validate input
            if (empty($posted_email)) {
                $error = 'Email address is required';
            } elseif (!filter_var($posted_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address';
            } elseif (empty($password)) {
                $error = 'Password is required';
            } else {
                // The site this portal serves, declared by Portal_Main::init().
                // Logins are scoped to it: a portal account is a (site, email) pair.
                $site_id = Portal_Session::get_site_id();

                // Find portal user by email within this site
                $portal_user = Portal_User_Model::find_by_email($site_id, $posted_email);

                if ($portal_user && $portal_user->check_password($password)) {
                    // Check if user can log in (active and verified)
                    if (!$portal_user->can_login()) {
                        if (!$portal_user->is_verified) {
                            $error = 'Your account has not been verified. Please check your email.';
                        } else {
                            $error = 'Your account is not active. Please contact support.';
                        }
                    } else {
                        // Login successful. No site argument: the session is created
                        // for the site already declared for this request.
                        Portal_Session::set_portal_user_id($portal_user->id);
                        $portal_user->touch_last_login();

                        Flash_Alert::success('Welcome to the Client Portal!');

                        // Land on the invited client's workspace when one was
                        // carried in and the user has access, else the dashboard.
                        return redirect(static::_post_auth_destination($portal_user, $client_id));
                    }
                } else {
                    $error = 'Invalid email or password. Please try again.';
                }
            }
        }

        // Check for message query parameter
        $message = $params['message'] ?? null;

        return rsx_view('Portal_Login_Index', [
            'error' => $error,
            'posted_email' => $posted_email,
            'message' => $message,
            'client_id' => $client_id,
        ]);
    }

    /**
     * Post-auth destination: the invited client's workspace when the user has
     * access to it, otherwise the intended-URL the user was bounced from (a deep
     * portal link followed while logged out), else the dashboard. (An existing
     * account following an invite for a new client has a pending Accept on the
     * dashboard, so dashboard is correct there.)
     *
     * The flow-owned invited-client destination WINS over the threaded redirect;
     * consume() supplies the default. See: php artisan rsx:man login_redirect.
     */
    private static function _post_auth_destination($portal_user, int $client_id): string
    {
        if ($client_id && $portal_user
            && Portal_Membership_Model::has_membership($portal_user->id, $client_id)) {
            return Rsx_Portal::Route('Portal_Workspace_Overview_Action', $client_id);
        }

        return Login_Redirect::consume(Rsx_Portal::Route('Portal_Dashboard_Action'));
    }
}
