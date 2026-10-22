<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Login;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Password_Reset_Required_Exception;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Login\Invite_Helper;
use Rsx\Emails\Signup_Verification_Email;

/**
 * Login controller for RSX authentication
 *
 * Handles user authentication, login/logout, the second-factor challenge, and the
 * destination every one of them leads to.
 *
 * Public by design: these are the routes an anonymous visitor arrives on.
 *
 * THE TWO-STAGE LOGIN. A password alone is not a login here. index() verifies the
 * password with recording and the last_login stamp BOTH suppressed, so a recorded
 * SUCCESS always means full authentication; whether a second step follows is
 * Rsx_Two_Factor::is_enabled().
 *
 * ONE COMPLETION FUNCTION. Every staff entrance finishes in complete_sign_in(): the
 * password form, the challenge (verify_2fa - a second factor, or the emailed code or link
 * that verifies an address), a passkey, an accepted invitation
 * (Accept_Invite_Controller) and a federated sign-in (Rsx\Handlers\Sso_Handlers). It signs
 * in when the caller has not, parks what the person still owes (an outstanding
 * second-factor enrollment under rsx.auth.require_two_factor) and answers where they land.
 * A rule that belongs to "being signed in" is written there once, and no entrance can skip
 * it.
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
     * The step a signed-in user still owes: enrolling a second factor, under
     * rsx.auth.require_two_factor. Parked by complete_sign_in(), read by Rsx\Main::pre_dispatch(),
     * which holds every page request on the enrollment screen while it is set and forgets it
     * once the identity holds a factor. A staff session value, so signing out clears it.
     */
    const ENROLL_TWO_FACTOR_KEY = 'auth.enroll_two_factor';

    /**
     * Marks the pending challenge as an ADDRESS VERIFICATION rather than a sign-in's second
     * factor, so send_code() knows which email to send and the screen says what it is for.
     * Parked beside the challenge, with the challenge's own expiry, by Signup_Controller::submit()
     * (a new account) and by index() (a password sign-in of an identity whose address was never
     * verified).
     */
    const ADDRESS_VERIFICATION_KEY = 'login_pending_address_verification';

    /**
     * Codes one challenge may have emailed. An application rule, not a security one - the
     * framework's attempt caps bound guessing - it keeps "Resend code" from mailing an inbox
     * without limit. Reaching it means starting again.
     */
    const MAX_CODES_PER_CHALLENGE = 5;

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
            // THROTTLE: RsxAuth::verify_credentials() refuses a client IP that has spent its
            // failure budget by THROWING Auth_Throttled_Exception (rsx:man session,
            // LOGIN THROTTLE) - a refusal is not a wrong password, so it must not be
            // reported as one. Catch it around the check and show its message; it
            // is already the user-facing string and deliberately says nothing about
            // which accounts exist or when to try again. Auth_Password_Reset_Required_Exception
            // is the other thrown refusal - a correct password on an account that must reset it
            // first - and is shown the same way.
            $thrown_refusal = null;
            $login_user = null;

            try {
                if (empty($posted_email)) {
                    $error = 'Email address is required';
                } elseif (!filter_var($posted_email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid email address';
                } elseif (empty($request->input('password'))) {
                    $error = 'Password is required';
                } else {
                    // THE PASSWORD IS CHECKED AND NOBODY IS SIGNED IN. verify_credentials()
                    // answers the identity the pair proves and touches no session; only
                    // complete_sign_in() - or a passed challenge - signs anybody in. So
                    // everything between here and there runs for a visitor who is still
                    // signed OUT, and a request that dies part-way leaves them that way.
                    //
                    // record: false because this is not a login yet - a second factor may
                    // still be owed - and a recorded SUCCESS must mean the identity is all
                    // the way in.
                    $login_user = RsxAuth::verify_credentials($credentials, record: false);

                    if ($login_user === null) {
                        // With record: false NOTHING is written, so the outcome is recorded
                        // here as the generic password failure. record_failure() feeds the
                        // throttle - the counter is never touched directly.
                        Login_History::record_failure($posted_email, Login_History::STATUS_FAILED_PASSWORD);
                    }
                }
            } catch (Auth_Throttled_Exception $e) {
                $thrown_refusal = $e->getMessage();
            } catch (Auth_Password_Reset_Required_Exception $e) {
                // The password was right and the account must choose a new one before it may
                // sign in with one (login_users.password_reset_required; the framework has
                // already recorded the refusal). This application has no self-service reset,
                // so the way through is an administrator: rsx:users:password:set.
                $thrown_refusal = $e->getMessage() . ' Contact your administrator to have it reset.';
            }

            // THE APPLICATION'S OWN QUESTIONS ABOUT THE ACCOUNT GO HERE - a status your
            // application defines, an agreement not yet accepted. Nobody is signed in, so a
            // refusal is just an error on the form: there is no session to undo and no
            // logout() to remember.
            $refusal = $login_user !== null ? static::__account_refusal($login_user) : null;

            if ($thrown_refusal !== null) {
                $error = $thrown_refusal;
            } elseif ($refusal !== null) {
                $error = $refusal;
            } elseif ($login_user !== null) {
                $invite_code = $request->input('code');

                if (Rsx_Two_Factor::is_enabled($login_user)) {
                    // The challenge signs the browser's staff sign-in out and parks the
                    // pending identity; the invite code is parked beside it AFTER, because a
                    // sign-out clears the staff realm's session values. The session carries
                    // both and is signed in as nobody.
                    Rsx_Two_Factor::begin_challenge($login_user);

                    static::__park_invite_code($invite_code);

                    return redirect(Rsx::Route('Login_Controller::verify'));
                }

                // AN ADDRESS NEVER VERIFIED IS PROVED BEFORE THE SIGN-IN - a self-signup that
                // left before confirming, above all: the password alone must not be a way
                // around the confirmation signup asked for. Same challenge as signup's, an
                // emailed code and link; passing it marks the address verified. Asked only
                // when the email can actually reach the address (Rsx_Mail::deliverable_to()):
                // a code nobody receives would lock the account out, so where mail cannot
                // arrive the password signs in as it always has.
                if (!$login_user->is_verified && Rsx_Mail::deliverable_to((string) $login_user->email, Rsx_Mail::SECURITY)) {
                    Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

                    Session::put_value(self::ADDRESS_VERIFICATION_KEY, true, Rsx_Two_Factor::challenge_expires_at());
                    static::__park_invite_code($invite_code);

                    return redirect(Rsx::Route('Login_Controller::verify'));
                }

                // No second factor: this IS the login, and complete_sign_in() performs it.
                //
                // It refuses an identity with no enabled site membership (null), which is
                // unreachable here: verify_credentials() asked the same question a few
                // statements ago.
                $destination = static::complete_sign_in($login_user, sign_in: true, invite_code: $invite_code);

                if ($destination === null) {
                    shouldnt_happen('RsxAuth::login() refused an identity verify_credentials() had just admitted');
                }

                return redirect($destination);
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
     * May this identity sign in, by THIS APPLICATION's own rules? Null admits; a string is
     * the error shown on the login form.
     *
     * The framework has already answered its own three questions (a live identity, the right
     * password, an enabled membership on an enabled site - RsxAuth::verify_credentials()).
     * What is left is whatever the application itself means by an account that may not sign
     * in: login_users.status_id, is_activated and is_verified are application vocabulary, and
     * this is the login-time half of enforcing them (Main::pre_dispatch() is the other, for a
     * session that is already open).
     *
     * This application has no such rule, so everybody the framework admits is admitted.
     */
    private static function __account_refusal(Login_User_Model $login_user): ?string
    {
        return null;
    }

    /**
     * The challenge screen - a sign-in's second factor, or a self-signup's address
     * verification.
     *
     * Reached from index() or Signup_Controller::submit(), which parked the pending identity
     * with the session signed out - so this page is served to a session that is deliberately
     * NOT signed in, which is why the class gate is 'public'.
     *
     * A visitor who IS signed in is sent to the dashboard: the challenge was answered in
     * another tab (the email link, or a code typed there), and <Two_Factor_Challenge>'s
     * Continue reloads this page expecting exactly that. Nothing pending otherwise means the
     * window expired or somebody typed the address - back to the login page rather than to a
     * dead end.
     *
     * The send endpoint is named only when the challenge accepts an emailed code: the screen
     * then sends the first code itself and offers "Resend code".
     */
    #[Route('/login/verify', methods: ['GET'])]
    public static function verify(Request $request, array $params = [])
    {
        if (Session::is_logged_in()) {
            return redirect(Rsx::Route('Dashboard_Index_Action'));
        }

        $pending = Rsx_Two_Factor::challenge_pending();

        if ($pending === null) {
            return redirect(Rsx::Route('Login_Controller::index'));
        }

        return rsx_view('Login_Verify', [
            'is_address_verification' => static::__is_address_verification(),
            'has_issued_code' => $pending['has_issued_code'],
            'has_recovery_codes' => $pending['has_recovery_codes'],
        ]);
    }

    /**
     * Issue a code for the pending challenge and email it with its sign-in link - the send
     * endpoint <Two_Factor_Challenge> calls for the first code and for "Resend code".
     *
     * Public like the rest of the challenge: the session is deliberately signed out, and the
     * endpoint acts only on the challenge parked on the caller's own session. The address is
     * the pending identity's own, read server-side - nothing the browser sends chooses where
     * a code goes.
     *
     * The one staff challenge this application ever issues a code for is an ADDRESS
     * VERIFICATION (ADDRESS_VERIFICATION_KEY: a self-signup, or the sign-in of an identity
     * whose address was never verified). A sign-in challenge accepting an emailed code as a
     * second factor is a policy this application does not have; adding one means adding its
     * email here, so it fails loud until then.
     *
     * A resend while the code is live re-sends the SAME code and the same link (the framework
     * keeps one per identity), so the email says so and states the minutes that code has left.
     *
     * @return null
     */
    #[Ajax_Endpoint]
    public static function send_code(Request $request, array $params = [])
    {
        $pending = Rsx_Two_Factor::challenge_pending();
        $login_user = Rsx_Two_Factor::pending_identity();

        if ($pending === null || $login_user === null) {
            return response_error(Ajax::ERROR_VALIDATION, 'Your verification window has expired. Please start again.');
        }

        if (!$pending['has_issued_code']) {
            return response_error(Ajax::ERROR_VALIDATION, 'This sign-in does not use emailed codes.');
        }

        if ($pending['codes_issued'] >= static::MAX_CODES_PER_CHALLENGE) {
            return response_error(Ajax::ERROR_VALIDATION, 'Too many codes have been sent. Please start again.');
        }

        if (!static::__is_address_verification()) {
            throw new \RuntimeException(
                'Login_Controller::send_code() was asked to email a code for a staff challenge that is not an '
                . 'address verification. This application emails codes only to verify an address; a sign-in '
                . 'policy that accepts an emailed code as a second factor needs its own email here.'
            );
        }

        // The code first, then its link: issued_link() is the code's twin and throws without it.
        $code = Rsx_Two_Factor::issue_code();
        $link = Rsx_Two_Factor::issued_link(Rsx::Route('Login_Controller::verify'));

        (new Signup_Verification_Email($login_user, $code, $link))
            ->to($login_user->email)
            ->send();

        return null;
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
            // The message and nothing else - never how many attempts are left. A count tells
            // an attacker exactly how much budget remains and when to stop before the
            // account locks. ($e->reason() says which failure this was, for code that must
            // act on one; the account owner is told about an exhausted challenge by
            // Two_Factor_Notice_Handlers, off the framework's own event.)
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }

        // verify_challenge() has signed the identity in, stamped last_login and written the
        // success row - and, for an emailed code or link, marked the address verified. An
        // address verification is over with it.
        $welcome = 'Welcome back!';

        if (static::__is_address_verification()) {
            Session::forget_value(self::ADDRESS_VERIFICATION_KEY);
            $welcome = 'Your email address is verified. Welcome!';
        }

        return [
            'redirect' => static::complete_sign_in(
                $login_user,
                sign_in: false,
                invite_code: static::__consume_invite_code(),
                welcome: $welcome
            ),
        ];
    }

    /**
     * Sign in with a passkey ALONE - the endpoint <Passkey_Sign_In> on the login page posts to.
     *
     * Rsx_Two_Factor::verify_passkey_login() does the whole sign-in: the throttle first, the
     * assertion verified with user verification required, the identity taken from the
     * credential, the membership check, last_login and the success row. No second factor
     * follows - a user-verified passkey is a complete sign-in (rsx:man two_factor). What is
     * left is where the identity lands, which is the same one function every other sign-in
     * path uses.
     *
     * NO TURNSTILE, for the reason verify_2fa() carries none: the component posts exactly
     * {assertion} and renders no widget, and the endpoint spends the brute-force budget as its
     * first statement.
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
            $login_user = Rsx_Two_Factor::verify_passkey_login($assertion);
        } catch (Auth_Throttled_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        } catch (Two_Factor_Failed_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }

        return [
            'redirect' => static::complete_sign_in($login_user, sign_in: false),
        ];
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
    // Completing a sign-in
    // -------------------------------------------------------------------------

    /**
     * Finish a staff sign-in: sign the identity in when the caller has not, park what the
     * person still owes, and answer where they land, as a URL.
     *
     * ONE FUNCTION, EVERY ENTRANCE. The password form, the challenge (a second factor, or a
     * emailed code or link that verifies an address), a passkey, an accepted invitation
     * (Accept_Invite_Controller) and a federated sign-in (Rsx\Handlers\Sso_Handlers) all end
     * here. PUBLIC FOR THOSE LAST TWO, and for no other reason. A rule that belongs to "being
     * signed in" written on one door and not another is the door nobody remembers - so it is
     * written here, once.
     *
     * $sign_in says who signs in. TRUE: the caller proved the identity and nobody is signed
     * in yet (the password form, an accepted invitation) - RsxAuth::login() is called here and
     * the success recorded; it refuses an identity holding no active site membership, and then
     * nothing is signed in and the answer is null. FALSE: the framework has signed the
     * identity in already and recorded it (a passed challenge, a passkey, a federated
     * sign-in), and calling RsxAuth::login() again would sign it in twice.
     *
     * WHAT IS OWED. With rsx.auth.require_two_factor on, an identity holding no second factor
     * owes an enrollment: ENROLL_TWO_FACTOR_KEY is parked (after the sign-in, which clears the
     * staff realm's session values) and Rsx\Main::pre_dispatch() holds page requests on the
     * Password & Security screen until a factor exists.
     *
     * WHERE THEY LAND. An identity holding one usable site is bound to it first, so the session
     * has a site whatever comes next. Then an invite in flight wins; then the site picker when
     * the identity holds several; otherwise the dashboard. The flash alerts are raised here so
     * the message does not depend on the door.
     *
     * @param Login_User_Model $login_user The identity that proved itself.
     * @param bool $sign_in True when this function performs the sign-in (see above).
     * @param string|null $invite_code The invite code the login form carried, if any.
     * @param string $welcome The flash on the way into the one site (a new account says so).
     * @return string|null The destination; null only when $sign_in and the sign-in was refused.
     */
    public static function complete_sign_in(
        Login_User_Model $login_user,
        bool $sign_in,
        ?string $invite_code = null,
        string $welcome = 'Welcome back!'
    ): ?string
    {
        if ($sign_in) {
            // login() establishes the session and stamps last_login; the success row is
            // written here because nothing beneath it records one.
            if (!RsxAuth::login($login_user)) {
                return null;
            }

            Login_History::record_success((int) $login_user->id, (string) $login_user->email);
        } elseif ((int) Session::get_login_user_id() !== (int) $login_user->id) {
            shouldnt_happen('Login_Controller::complete_sign_in(sign_in: false) for an identity this session is not signed in as');
        }

        if (config('rsx.auth.require_two_factor') && !Rsx_Two_Factor::is_enabled($login_user)) {
            Session::put_value(self::ENROLL_TWO_FACTOR_KEY, true);
        }

        // Which sites can this identity use?
        //
        // The ->active() filter here is PRESENTATION, not authorization: it decides which
        // sites are worth offering, so an identity usable on one site and disabled (or whose
        // site is disabled) on another lands on the usable one instead of a picker naming
        // both. ->active() is the framework's one definition of a usable membership, so this
        // list and the framework's enforcement cannot drift. Authorization is the
        // framework's - every sign-in refuses an identity holding no active membership, and
        // every request re-checks it - so at least one active membership is guaranteed to
        // exist here; there is no zero-sites branch. The query is unscoped by site: the
        // session has no site until this function binds one.
        // See: php artisan rsx:man session
        $login_user_id = (int) $login_user->id;
        $user_sites = User_Model::without_site_scope(
            fn () => User_Model::where('login_user_id', $login_user_id)->active()->get()
        );

        // Exactly one: the session serves it from here on, whatever the destination.
        if ($user_sites->count() === 1) {
            Session::set_site_id($user_sites->first()->site_id);
        }

        // An invite in flight wins: the account setup is what the user came to finish.
        if ($invite_code) {
            Flash_Alert::success('Login successful! Please complete your account setup.');

            return Rsx::Route('Accept_Invite_Controller::index', ['code' => $invite_code]);
        }

        // More than one: no site yet - the site selector binds the chosen one.
        if ($user_sites->count() > 1) {
            Flash_Alert::success('Login successful! Please select your site.');

            return Rsx::Route('Site_Selection_Controller');
        }

        Flash_Alert::success($welcome);

        return Rsx::Route('Dashboard_Index_Action');
    }

    /**
     * Where a signed-in user who still owes a second-factor enrollment is held, or null when
     * this request goes through - Rsx\Main::pre_dispatch() asks it of every request.
     *
     * Only a PAGE request is held (an Ajax call carries on, so the enrollment screen itself
     * works); the enrollment screen and sign-out are always reachable; and the step is
     * forgotten the moment the identity holds a factor, however it was enrolled.
     *
     * @param Request $request
     * @return string|null The enrollment screen's URL to redirect to, or null.
     */
    public static function outstanding_enrollment_redirect(Request $request): ?string
    {
        if (Rsx_Request_Channel::current() !== Rsx_Request_Channel::PAGE || !Session::is_logged_in()) {
            return null;
        }

        if (!Session::get_value(self::ENROLL_TWO_FACTOR_KEY)) {
            return null;
        }

        if (Rsx_Two_Factor::is_enabled(Session::get_login_user())) {
            Session::forget_value(self::ENROLL_TWO_FACTOR_KEY);

            return null;
        }

        $enrollment_url = Rsx::Route('Settings_Password_Security_Action');
        $path = '/' . ltrim($request->path(), '/');

        if ($path === parse_url($enrollment_url, PHP_URL_PATH) || $path === parse_url(Rsx::Route('Login_Controller::logout'), PHP_URL_PATH)) {
            return null;
        }

        Flash_Alert::warning('This site requires two-factor authentication. Set up an authenticator app or a passkey to continue.');

        return $enrollment_url;
    }

    /**
     * Is the pending challenge an address verification (a self-signup, or an unverified
     * identity's sign-in) rather than a second factor?
     *
     * @return bool
     */
    private static function __is_address_verification(): bool
    {
        return (bool) Session::get_value(self::ADDRESS_VERIFICATION_KEY);
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
