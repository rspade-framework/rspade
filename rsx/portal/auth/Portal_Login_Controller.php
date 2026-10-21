<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Password_Reset_Required_Exception;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\Portal_Auth;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Portal_Login_History;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\Emails\Portal_Sign_In_Code_Email;
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
 * passkey can also be the ONLY credential: <Passkey_Sign_In> posts to passkey_login().
 *
 * ONE COMPLETION FUNCTION. Every portal entrance finishes in complete_sign_in(): the password
 * form, the challenge, a passkey, a registration from an invitation
 * (Portal_Register_Controller) and a federated sign-in (Rsx\Handlers\Portal_Sso_Handlers). It
 * signs in when the caller has not and answers where the portal user lands, so a rule that
 * belongs to "being signed in" is written once and no entrance can skip it.
 *
 * EMAILED SIGN-IN CODES (config rsx.portal.emailed_sign_in_codes, off by default). When on,
 * EVERY password sign-in owes a second step - not only an identity holding a factor - and the
 * challenge accepts a six-digit code emailed to the account (Rsx_Portal_Two_Factor::
 * ANSWER_ISSUED_CODE) as well as any factor the portal user holds. challenge_accepts() is that
 * policy, in one place; send_code() issues each code and emails it with its sign-in link. The
 * framework supplies the code, the link, their storage, the attempt caps and the verification;
 * everything else here is this application's choice. A passkey sign-in owes nothing, so a client with a passkey never
 * waits for an email.
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
     * Codes one sign-in may have emailed. An application rule, not a security one - the
     * framework's attempt caps bound guessing - it keeps "Resend code" from mailing an
     * inbox without limit. Reaching it means signing in again.
     */
    const MAX_CODES_PER_SIGN_IN = 5;

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
            return redirect(static::destination(Portal_Session::get_portal_user(), $client_id));
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
                // THE PASSWORD IS CHECKED AND NOBODY IS SIGNED IN. Portal_Auth::verify_credentials()
                // answers the portal user the pair proves on THIS portal's site (declared by
                // Portal_Main::init()) and touches no session. It refuses a client IP that has
                // spent its failure budget by THROWING Auth_Throttled_Exception - before the
                // lookup - and a correct password on an account flagged
                // password_reset_required by THROWING Auth_Password_Reset_Required_Exception.
                //
                // record: false because this is not a sign-in yet - the account questions below
                // and a second factor may still be owed - so a recorded SUCCESS means the portal
                // user is all the way in. See rsx:man portal, SIGNING IN.
                $portal_user = null;

                try {
                    $portal_user = Portal_Auth::verify_credentials(
                        Portal_Session::get_site_id(),
                        ['email' => $posted_email, 'password' => $password],
                        record: false
                    );

                    if ($portal_user === null) {
                        // With record: false nothing is written, so the outcome is recorded here
                        // as the generic password failure. record_failure() feeds the throttle.
                        Portal_Login_History::record_failure($posted_email, Portal_Login_History::STATUS_FAILED_PASSWORD);
                        $error = 'Invalid email or password. Please try again.';
                    }
                } catch (Auth_Throttled_Exception $e) {
                    // The refusal message IS the user-facing string.
                    $error = $e->getMessage();
                } catch (Auth_Password_Reset_Required_Exception $e) {
                    // The password was right; the account must choose a new one first. Already
                    // recorded. The form's "Forgot your password?" link is the way through.
                    $error = $e->getMessage() . ' Use "Forgot your password?" below to choose a new one.';
                }

                if ($portal_user !== null) {
                    // What this sign-in's second step accepts, or false for none -
                    // this application's policy, from the one function that holds it.
                    $accepts = static::challenge_accepts($portal_user);

                    // Check if user can log in (active and verified) - the application's
                    // account rules, asked between the password and the sign-in.
                    if (!$portal_user->can_login()) {
                        if (!$portal_user->is_verified) {
                            $error = 'Your account has not been verified. Please check your email.';
                        } else {
                            $error = 'Your account is not active. Please contact support.';
                        }
                    } elseif ($accepts !== false) {
                        // A SECOND STEP IS OWED. Nothing is signed in: the portal user is
                        // parked half-authenticated and the challenge page answers for them.
                        // The invited-client destination is parked AFTER the challenge, which
                        // signs the portal out first - and a sign-out clears the portal
                        // realm's session values.
                        Rsx_Portal_Two_Factor::begin_challenge($portal_user, $accepts);

                        Portal_Session::put_value(static::CLIENT_ID_KEY, $client_id, Rsx_Portal_Two_Factor::challenge_expires_at());

                        return redirect(Rsx_Portal::Route('Portal_Login_Controller::verify'));
                    } else {
                        // No second step: this IS the sign-in, and complete_sign_in()
                        // performs it - landing on the invited client's workspace when one
                        // was carried in and the user has access, else the dashboard.
                        return redirect(static::complete_sign_in($portal_user, sign_in: true, client_id: $client_id));
                    }
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
     *
     * A visitor who IS signed in goes to their destination: the challenge was answered in
     * another tab (the email link, or a code typed there), and <Two_Factor_Challenge>'s
     * Continue reloads this page expecting exactly that. Nothing pending otherwise (an expired
     * window, a direct visit) goes back to the login page.
     */
    #[Portal_Route('/login/verify', methods: ['GET'])]
    public static function verify(Request $request, array $params = [])
    {
        if (Portal_Session::is_logged_in()) {
            return redirect(static::destination(Portal_Session::get_portal_user(), 0));
        }

        $pending = Rsx_Portal_Two_Factor::challenge_pending();

        if ($pending === null) {
            return redirect(Rsx_Portal::Route('Portal_Login_Controller::index'));
        }

        return rsx_view('Portal_Login_Verify', [
            'has_issued_code' => $pending['has_issued_code'],
            'has_recovery_codes' => $pending['has_recovery_codes'],
        ]);
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

        return ['redirect' => static::complete_sign_in($portal_user, sign_in: false, client_id: $client_id)];
    }

    /**
     * Issue a sign-in code for the pending challenge and email it with its sign-in link - the
     * send endpoint <Two_Factor_Challenge> calls for the first code and for "Resend code".
     *
     * Public like the rest of the challenge: the session is deliberately signed out, and the
     * endpoint acts only on the challenge parked on the caller's own session. The address is
     * the pending portal user's own, read server-side - nothing the browser sends chooses
     * where a code goes.
     *
     * A resend while the portal user's code is still live re-sends the SAME code and the same
     * link (the framework keeps one per identity), so the email says so, and states the time
     * that code has left rather than a fresh window. The link works only in this browser: it
     * brings it back to the verify page, whose screen submits it.
     *
     * @return null
     */
    #[Ajax_Endpoint]
    public static function send_code(Request $request, array $params = [])
    {
        $pending = Rsx_Portal_Two_Factor::challenge_pending();
        $portal_user = Rsx_Portal_Two_Factor::pending_identity();

        if ($pending === null || $portal_user === null) {
            return response_error(Ajax::ERROR_VALIDATION, 'Your verification window has expired. Please sign in again.');
        }

        if (!$pending['has_issued_code']) {
            return response_error(Ajax::ERROR_VALIDATION, 'This sign-in does not use emailed codes.');
        }

        if ($pending['codes_issued'] >= static::MAX_CODES_PER_SIGN_IN) {
            return response_error(Ajax::ERROR_VALIDATION, 'Too many codes have been sent. Please sign in again.');
        }

        // Asked BEFORE issuing: a live code means this send repeats the digits already sent.
        $again = Rsx_Portal_Two_Factor::issued_code_expires_at() !== null;

        // The code first, then its link: issued_link() is the code's twin and throws without it.
        $code = Rsx_Portal_Two_Factor::issue_code();
        $link = Rsx_Portal_Two_Factor::issued_link(Rsx_Portal::Route('Portal_Login_Controller::verify'));

        (new Portal_Sign_In_Code_Email($portal_user, $code, $link, Rsx_Portal_Two_Factor::issued_code_expires_at(), $again))
            ->to($portal_user->email)
            ->send();

        return null;
    }

    /**
     * What a portal sign-in's challenge accepts, or false when it owes none.
     *
     * THE ONE PLACE this application's second-step policy lives - the password form above
     * and the federated sign-in (Rsx\Handlers\Portal_Sso_Handlers) both ask it:
     *   - emailed sign-in codes on: always a challenge, accepting an emailed code or any
     *     factor the portal user holds - unless the code could not reach them
     *     (Rsx_Mail::deliverable_to(): mail suppressed or frozen, a dev host, a blocked
     *     address), when a code nobody receives would lock the account: the challenge then
     *     accepts only the factors they hold, and a portal user holding none signs in on the
     *     password alone;
     *   - off: a challenge only for a portal user holding a factor, accepting what they hold
     *     (null - the framework's default list).
     *
     * @param Portal_User_Model $portal_user
     * @return array|null|false The $accepts for begin_challenge(), or false for no challenge.
     */
    public static function challenge_accepts(Portal_User_Model $portal_user): array|null|false
    {
        if (config('rsx.portal.emailed_sign_in_codes')
            && Rsx_Mail::deliverable_to($portal_user->email, Rsx_Mail::SECURITY)) {
            return [
                Rsx_Portal_Two_Factor::ANSWER_ISSUED_CODE,
                Rsx_Portal_Two_Factor::ANSWER_TOTP,
                Rsx_Portal_Two_Factor::ANSWER_PASSKEY,
                Rsx_Portal_Two_Factor::ANSWER_RECOVERY_CODE,
            ];
        }

        return Rsx_Portal_Two_Factor::is_enabled($portal_user) ? null : false;
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

        return ['redirect' => static::complete_sign_in($portal_user, sign_in: false)];
    }

    /**
     * Finish a portal sign-in: sign the portal user in when the caller has not, and answer
     * where they land, as a URL.
     *
     * ONE FUNCTION, EVERY ENTRANCE - the password form, the challenge, a passkey, a
     * registration from an invitation (Portal_Register_Controller) and a federated sign-in
     * (Rsx\Handlers\Portal_Sso_Handlers), which is why it is public. The welcome flash is raised
     * here so it does not depend on which door was used.
     *
     * $sign_in says who signs in. TRUE: the caller proved the portal user and nobody is
     * signed in yet - Portal_Session::set_portal_user_id() is called here (for the site this
     * request declared, stamping last_login) and the success recorded. FALSE: the framework
     * signed them in already and recorded it (a passed challenge, a passkey, a federated
     * sign-in).
     *
     * @param Portal_User_Model $portal_user The portal user who proved themselves.
     * @param bool $sign_in True when this function performs the sign-in (see above).
     * @param int $client_id The invited client carried in, or 0.
     * @return string
     */
    public static function complete_sign_in(Portal_User_Model $portal_user, bool $sign_in, int $client_id = 0): string
    {
        if ($sign_in) {
            Portal_Session::set_portal_user_id($portal_user->id);
            Portal_Login_History::record_success((int) $portal_user->id, (string) $portal_user->email);
        } elseif ((int) Portal_Session::get_portal_user_id() !== (int) $portal_user->id) {
            shouldnt_happen('Portal_Login_Controller::complete_sign_in(sign_in: false) for a portal user this session is not signed in as');
        }

        Flash_Alert::success('Welcome to the Client Portal!');

        return static::destination($portal_user, $client_id);
    }

    /**
     * Where a signed-in portal user lands: the invited client's workspace when the user has
     * access to it, otherwise the intended-URL the user was bounced from (a deep portal link
     * followed while logged out), else the dashboard. (An existing account following an invite
     * for a new client has a pending Accept on the dashboard, so dashboard is correct there.)
     *
     * The flow-owned invited-client destination WINS over the threaded redirect; consume()
     * supplies the default. See: php artisan rsx:man login_redirect.
     *
     * @param Portal_User_Model $portal_user
     * @param int $client_id The invited client carried in, or 0.
     * @return string
     */
    public static function destination(Portal_User_Model $portal_user, int $client_id): string
    {
        if ($client_id && Portal_Membership_Model::has_membership($portal_user->id, $client_id)) {
            return Rsx_Portal::Route('Portal_Workspace_Overview_Action', $client_id);
        }

        return Login_Redirect::consume(Rsx_Portal::Route('Portal_Dashboard_Action'));
    }
}
