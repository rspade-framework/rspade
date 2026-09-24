<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\Models\Portal_Membership_Model;

/**
 * Portal Login Controller
 *
 * Handles portal user authentication: the password form, the second-factor challenge, and
 * passwordless passkey sign-in. Public by design - these are the routes an anonymous visitor
 * arrives on.
 *
 * THE TWO-STAGE LOGIN, portal edition. index() verifies the password and, when the portal
 * user holds a second factor (Rsx_Portal_Two_Factor::is_enabled()), parks them with
 * begin_challenge() and sends the browser to /login/verify instead of signing them in. The
 * challenge is answered over Ajax by <Two_Factor_Challenge>, posting to verify_2fa(). A
 * passkey can also be the ONLY credential: <Passkey_Sign_In> posts to passkey_login(). All
 * three land on the ONE destination function, post_auth_destination(), which the federated
 * sign-in handlers (Rsx\Handlers\Portal_Sso_Handlers) call too.
 *
 * See: php artisan rsx:man two_factor, rsx:man sso, rsx:man portal
 */
#[Auth('public')]
class Portal_Login_Controller extends Rsx_Controller_Abstract
{
    /**
     * The invited-client id the login form carried, parked across the second-factor step.
     *
     * The challenge is answered over Ajax by <Two_Factor_Challenge>, whose contract sends
     * exactly {code} or {assertion} - there is no place to thread the ?client= destination
     * through it, so it rides the SESSION with the challenge's own expiry and is consumed once.
     */
    const CLIENT_ID_KEY = 'portal_login_pending_client_id';

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
            return redirect(static::post_auth_destination(Portal_Session::get_portal_user(), $client_id));
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
                // BRUTE-FORCE THROTTLE. The portal verifies the password itself rather
                // than going through RsxAuth::attempt(), so it does the two things
                // attempt() would have done: refuse a client IP that has spent its
                // failure budget BEFORE the lookup (no enumeration from a locked-out
                // address), and count the miss afterwards. Nothing here records to
                // Login_History - that store is the staff identity's - so the throttle
                // is fed directly and nothing is double counted.
                // See rsx:man session, LOGIN THROTTLE.
                try {
                    Login_Throttle::require_not_throttled();
                } catch (Auth_Throttled_Exception $e) {
                    // The refusal message IS the user-facing string.
                    return rsx_view('Portal_Login_Index', [
                        'error' => $e->getMessage(),
                        'posted_email' => $posted_email,
                        'message' => $params['message'] ?? null,
                        'client_id' => $client_id,
                    ]);
                }

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
                    } elseif (Rsx_Portal_Two_Factor::is_enabled($portal_user)) {
                        // A SECOND FACTOR IS OWED. Nothing is signed in: the portal user is
                        // parked half-authenticated and the challenge page answers for them.
                        // The invited-client destination is parked FIRST, for the reason the
                        // facade writes its own pending value before signing out.
                        Portal_Session::put_value(static::CLIENT_ID_KEY, $client_id, Rsx_Portal_Two_Factor::challenge_expires_at());

                        Rsx_Portal_Two_Factor::begin_challenge($portal_user);

                        return redirect(Rsx_Portal::Route('Portal_Login_Controller::verify'));
                    } else {
                        // Login successful. No site argument: the session is created
                        // for the site already declared for this request, and
                        // set_portal_user_id() stamps last_login.
                        Portal_Session::set_portal_user_id($portal_user->id);

                        // Land on the invited client's workspace when one was
                        // carried in and the user has access, else the dashboard.
                        return redirect(static::post_auth_destination($portal_user, $client_id));
                    }
                } else {
                    Login_Throttle::record_failure();
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
     * The second-factor challenge screen.
     *
     * Reached only from index(), which parked the pending portal user without signing them
     * in - so it is served to a session that is deliberately NOT signed in to the portal.
     * Nothing pending (an expired window, a direct visit) goes back to the login page.
     */
    #[Portal_Route('/login/verify', methods: ['GET'])]
    public static function verify(Request $request, array $params = [])
    {
        if (Rsx_Portal_Two_Factor::challenge_pending() === null) {
            return redirect(Rsx_Portal::Route('Portal_Login_Controller::index'));
        }

        return rsx_view('Portal_Login_Verify');
    }

    /**
     * Answer the challenge - the endpoint <Two_Factor_Challenge> posts to.
     *
     * Rsx_Portal_Two_Factor::verify_challenge() spends the brute-force budget first, signs
     * the portal user in (refusing one can_login() rejects) and answers with the identity.
     * NO TURNSTILE: the component posts exactly {code} or {assertion}, and the endpoint only
     * ever answers from the challenge parked on the caller's own session.
     *
     * @return array {redirect}
     */
    #[Ajax_Endpoint]
    public static function verify_2fa(Request $request, array $params = [])
    {
        try {
            $portal_user = Rsx_Portal_Two_Factor::verify_challenge($params);
        } catch (Auth_Throttled_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        } catch (Two_Factor_Failed_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }

        $client_id = (int) (Portal_Session::get_value(static::CLIENT_ID_KEY) ?? 0);
        Portal_Session::forget_value(static::CLIENT_ID_KEY);

        return ['redirect' => static::post_auth_destination($portal_user, $client_id)];
    }

    /**
     * Sign in with a passkey ALONE - the endpoint <Passkey_Sign_In> posts to.
     *
     * Rsx_Portal_Two_Factor::verify_passkey_login() is the whole sign-in: throttle first,
     * user verification required, the portal user taken from the credential and admitted only
     * when can_login() accepts them on this portal's site. No second factor follows.
     *
     * @return array {redirect}
     */
    #[Ajax_Endpoint]
    public static function passkey_login(Request $request, array $params = [])
    {
        $assertion = $params['assertion'] ?? null;

        if (!is_array($assertion)) {
            return response_error(Ajax::ERROR_VALIDATION, 'That passkey could not sign you in.');
        }

        try {
            $portal_user = Rsx_Portal_Two_Factor::verify_passkey_login($assertion);
        } catch (Auth_Throttled_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        } catch (Two_Factor_Failed_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }

        return ['redirect' => static::post_auth_destination($portal_user, 0)];
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
     *
     * ONE function for every way in - the password form, the challenge, a passkey and a
     * federated sign-in (Rsx\Handlers\Portal_Sso_Handlers) - which is why it is public. The
     * welcome flash is raised here so it does not depend on which door was used.
     *
     * @param Portal_User_Model $portal_user The portal user who just signed in.
     * @param int $client_id The invited client carried in, or 0.
     * @return string
     */
    public static function post_auth_destination($portal_user, int $client_id): string
    {
        Flash_Alert::success('Welcome to the Client Portal!');

        if ($client_id && $portal_user
            && Portal_Membership_Model::has_membership($portal_user->id, $client_id)) {
            return Rsx_Portal::Route('Portal_Workspace_Overview_Action', $client_id);
        }

        return Login_Redirect::consume(Rsx_Portal::Route('Portal_Dashboard_Action'));
    }
}
