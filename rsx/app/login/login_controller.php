<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Login;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Login\Invite_Helper;

/**
 * Login controller for RSX authentication
 *
 * Handles user authentication, login/logout, the second-factor challenge, the
 * forced-enrollment interstitial, and the destination every one of them leads to.
 *
 * Public by design: these are the routes an anonymous visitor arrives on.
 *
 * THE TWO-STAGE LOGIN. A password alone is not a login here. index() verifies the
 * password with recording and the last_login stamp BOTH suppressed, so a recorded
 * SUCCESS always means full authentication; whether a second step follows is
 * Rsx_Two_Factor::is_enabled(). Where the user lands afterwards is one function -
 * post_login_destination() - reached from both the password-only path and the
 * challenge, because a destination that is computed twice drifts.
 *
 * See: php artisan rsx:man session (APP RECIPE - TWO-FACTOR), rsx:man two_factor
 */
#[Auth('public')]
class Login_Controller extends Rsx_Controller_Abstract
{
    /**
     * The invite code the login form carried, parked for the second-factor step.
     *
     * The challenge is answered over Ajax by <Two_Factor_Challenge>, whose contract sends
     * exactly {code} or {assertion} - there is no query string on that call and no place to
     * thread a form field through it. So the code rides the SESSION across the challenge,
     * the same way the pending identity does, and is consumed once on the way out.
     */
    const INVITE_CODE_KEY = 'login_pending_invite_code';

    /**
     * Show login form
     */
    #[Route('/login', methods: ['GET', 'POST'])]
    public static function index(Request $request, array $params = [])
    {
        $error = null;
        $posted_email = null;

        if ($request->is_post()) {
            // Human verification first: it gates credential checking, and therefore
            // account enumeration, before any lookup happens.
            Rsx_Turnstile::validate($request);

            $posted_email = $request->input('email');
            $credentials = [
                'email' => $posted_email,
                'password' => $request->input('password'),
            ];

            // Validate email
            //
            // THROTTLE: RsxAuth::attempt() refuses a client IP that has spent its
            // failure budget by THROWING Auth_Throttled_Exception (rsx:man session,
            // LOGIN THROTTLE) - a refusal is not a wrong password, so it must not be
            // reported as one. Catch it around the attempt and show its message; it
            // is already the user-facing string and deliberately says nothing about
            // which accounts exist or when to try again.
            $throttled = null;

            try {
                $authenticated = false;

                if (empty($posted_email)) {
                    $error = 'Email address is required';
                } elseif (!filter_var($posted_email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid email address';
                } elseif (empty($request->input('password'))) {
                    $error = 'Password is required';
                } else {
                    // THE PASSWORD STAGE ONLY. record: false and touch_last_login: false
                    // suppress both halves of "this was a login", because it is not one yet
                    // - a second factor may still be owed. Nothing is recorded and
                    // last_login is not stamped until the identity is all the way in.
                    $authenticated = RsxAuth::attempt($credentials, record: false, touch_last_login: false);

                    if (!$authenticated) {
                        // attempt() distinguishes an unknown address from a wrong password
                        // only when it is recording for itself; with record: false NOTHING
                        // is written, so the outcome is recorded here as the generic
                        // password failure. record_failure() feeds the throttle - the
                        // counter is never touched directly.
                        Login_History::record_failure($posted_email, Login_History::STATUS_FAILED_PASSWORD);
                    }
                }
            } catch (Auth_Throttled_Exception $e) {
                $throttled = $e->getMessage();
            }

            if ($throttled !== null) {
                $error = $throttled;
            } elseif ($authenticated) {
                $login_user = Session::get_login_user();
                $invite_code = $request->input('code');

                if (Rsx_Two_Factor::is_enabled($login_user)) {
                    // The invite code is parked BEFORE begin_challenge() for the same reason
                    // the facade writes its own pending value before logging out: put_value()
                    // is a writer, so it establishes the session row the value hangs off, and
                    // the logout that follows clears the identity but not the row.
                    static::__park_invite_code($invite_code);

                    Rsx_Two_Factor::begin_challenge($login_user);

                    return redirect(Rsx::Route('Login_Controller::verify'));
                }

                // No second factor: this IS the login. login() stamps last_login (attempt()
                // was told not to), and the success row is written here because nothing
                // beneath this line records one.
                RsxAuth::login($login_user);
                Login_History::record_success((int) $login_user->id, $posted_email);

                return redirect(static::post_login_destination((int) $login_user->id, $invite_code));
            } elseif ($error === null) {
                $error = 'Invalid email or password. Please try again.';
            }
        }

        // Get invite code and fill preference from GET parameters
        $invite_code = $params['code'] ?? null;
        $fill = $params['fill'] ?? 'true';
        $prefill_email = null;

        // If invite code provided and fill is not explicitly false, validate and get email
        if ($invite_code && $fill !== 'false') {
            $validation = Invite_Helper::validate_invitation($invite_code);
            if ($validation['valid']) {
                $prefill_email = $validation['invitation']->email;
            }
        }

        // Use posted email if present (form was submitted), otherwise use prefill
        $email_value = $posted_email ?? $prefill_email;

        return rsx_view('Login_Index', [
            'invite_code' => $invite_code,
            'prefill_email' => $email_value,
            'error' => $error,
        ]);
    }

    /**
     * The second-factor challenge screen.
     *
     * Reached only from index(), which has already parked the pending identity and logged
     * the session back out - so this page is served to a session that is deliberately NOT
     * signed in, which is why the class gate is 'public'.
     *
     * Nothing pending means the window expired, the user already signed in, or somebody
     * typed the address. All three go back to the login page rather than to a dead end.
     */
    #[Route('/login/verify', methods: ['GET'])]
    public static function verify(Request $request, array $params = [])
    {
        if (Rsx_Two_Factor::challenge_pending() === null) {
            return redirect(Rsx::Route('Login_Controller::index'));
        }

        return rsx_view('Login_Verify');
    }

    /**
     * Answer the challenge - the endpoint <Two_Factor_Challenge> posts to.
     *
     * NO TURNSTILE HERE, deliberately. The component's contract sends exactly {code} or
     * {assertion} and renders no widget, so there is no __turnstile field to validate; the
     * completeness guard (Rsx_Turnstile::_guard_unvalidated_token) fires only when a token
     * WAS submitted, so it stays silent. The surface is not unguarded: it answers only from
     * the pending challenge parked on the caller's own session, and verify_challenge()
     * spends the brute-force budget as its first statement.
     *
     * Both catches carry a message that is user-safe by contract, and the throttle refusal
     * matters most of all: it must reach the screen as itself and never as a wrong code.
     *
     * @return array {redirect}
     */
    #[Ajax_Endpoint]
    public static function verify_2fa(Request $request, array $params = [])
    {
        try {
            $login_user = Rsx_Two_Factor::verify_challenge($params);
        } catch (Auth_Throttled_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        } catch (Two_Factor_Failed_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }

        // verify_challenge() has signed the identity in, stamped last_login and written the
        // success row. All that is left is where to send them.
        return [
            'redirect' => static::post_login_destination(
                (int) $login_user->id,
                static::__consume_invite_code()
            ),
        ];
    }

    /**
     * The forced-enrollment interstitial: an administrator requires a second factor on this
     * account and it does not have one yet.
     *
     * The class is public because most of this controller is; this ONE route needs a signed-in
     * identity, because the enrollment endpoints it drives are themselves gated that way and
     * enroll the SIGNED-IN identity and no other. Method gates are additive, so the two
     * declarations read as "logged in".
     *
     * Rsx\Main::pre_dispatch() is what sends people here. An identity that already has a
     * factor has nothing to do on this page.
     */
    #[Route('/login/two_factor_setup', methods: ['GET'])]
    #[Auth('is_logged_in')]
    public static function two_factor_setup(Request $request, array $params = [])
    {
        $login_user_id = Session::get_login_user_id();

        if ($login_user_id && Rsx_Two_Factor::is_enabled((int) $login_user_id)) {
            return redirect('/');
        }

        return rsx_view('Login_Two_Factor_Setup');
    }

    /**
     * Logout user
     *
     * Optional redirect parameter:
     * - If provided and it validates as a local, non-login-flow path (via the
     *   single Login_Redirect sanitizer), redirect there after logout.
     * - Otherwise (absent, hostile, or a login-flow route), redirect to the login
     *   page with the logged_out message.
     *
     * Optional reason parameter:
     * - If provided, passes through to login page (e.g., 'unauthorized')
     */
    #[Route('/logout', methods: ['GET'])]
    public static function logout(Request $request, array $params = [])
    {
        RsxAuth::logout();

        // Check for reason parameter to pass through
        $reason = $params['reason'] ?? null;

        // Build the default destination: the login page with a message/reason.
        $query_params = [];
        if ($reason) {
            $query_params['reason'] = $reason;
        } else {
            $query_params['message'] = 'logged_out';
        }
        $default = Rsx::Route('Login_Controller::index', $query_params);

        // Route the redirect param through the one redirect sanitizer. A valid
        // local target is honored; anything else (hostile, off-host, or a
        // login-flow route) degrades silently to the login default.
        return redirect(Login_Redirect::consume($default));
    }

    // -------------------------------------------------------------------------
    // Post-authentication destination
    // -------------------------------------------------------------------------

    /**
     * Where a fully authenticated identity goes, as a URL.
     *
     * ONE function, THREE callers: the password-only path returns redirect() of it, the
     * second-factor endpoint hands the same string to the challenge component, which follows
     * it with window.location, and the federated sign-in reaches it from outside this class
     * through Rsx\Handlers\Sso_Handlers. It is a URL rather than a RedirectResponse because
     * two of the three callers cannot return a response object.
     *
     * PUBLIC FOR THAT THIRD CALLER, and for no other reason. A destination computed twice
     * drifts, and "signing in with Google skips the site picker" is the shape that drift
     * takes - a bug nobody reports, because the user assumes it was meant.
     *
     * The flash alerts are raised here, so the message a user sees does not depend on which
     * of the two paths they took.
     *
     * @param int $login_user_id The identity that just signed in.
     * @param string|null $invite_code The invite code the login form carried, if any.
     * @return string
     */
    public static function post_login_destination(int $login_user_id, ?string $invite_code): string
    {
        // An invite in flight wins: the account setup is what the user came to finish.
        if ($invite_code) {
            Flash_Alert::success('Login successful! Please complete your account setup.');

            return Rsx::Route('Accept_Invite_Controller::index', ['code' => $invite_code]);
        }

        // Which sites does this identity have?
        $user_sites = User_Model::where('login_user_id', $login_user_id)
            ->where('is_enabled', true)
            ->get();

        // More than one: don't set site_id yet - show the site selector.
        if ($user_sites->count() > 1) {
            Flash_Alert::success('Login successful! Please select your site.');

            return Rsx::Route('Site_Selection_Controller');
        }

        // Exactly one - set it and go to the dashboard.
        if ($user_sites->count() === 1) {
            Session::set_site_id($user_sites->first()->site_id);
            Flash_Alert::success('Welcome back!');

            return Rsx::Route('Dashboard_Index_Action');
        }

        // None - the unauthorized screen handles the logout.
        return Rsx::Route('Site_Unauthorized_Controller');
    }

    /**
     * Park the login form's invite code for the duration of the challenge.
     *
     * Parked with the challenge's own expiry, so it cannot outlive the sign-in it belongs to.
     *
     * @param string|null $invite_code
     * @return void
     */
    private static function __park_invite_code(?string $invite_code): void
    {
        if (!$invite_code) {
            return;
        }

        Session::put_value(self::INVITE_CODE_KEY, (string) $invite_code, Rsx_Two_Factor::challenge_expires_at());
    }

    /**
     * Read the parked invite code and forget it - it is good for exactly one sign-in.
     *
     * @return string|null
     */
    private static function __consume_invite_code(): ?string
    {
        $invite_code = Session::get_value(self::INVITE_CODE_KEY);

        Session::forget_value(self::INVITE_CODE_KEY);

        return is_string($invite_code) && $invite_code !== '' ? $invite_code : null;
    }
}
