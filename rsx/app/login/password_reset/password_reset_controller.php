<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Login\PasswordReset;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Auth\Password_Change;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\SignIn\Redirect\Login_Notice;
use App\RSpade\Lib\SignIn\Turnstile\Rsx_Turnstile;
use Rsx\Emails\Password_Reset_Email;
use Rsx\Models\Login_User_Password_Reset_Model;

/**
 * The staff "Forgot your password?" flow: /password/reset asks for an address and emails a
 * link, /password/reset/:token sets the new password.
 *
 * Public by design: the visitor cannot sign in, which is why they are here.
 *
 * THE REQUEST NEVER SAYS WHETHER AN ADDRESS HAS AN ACCOUNT. Every well-formed submission that
 * passes Turnstile and the throttle answers the same "if an account exists for that address, a
 * link is on its way" - an unknown address, a deleted identity and one holding no enabled site
 * membership are mailed nothing and told exactly that sentence. A link is minted and emailed
 * only for a live login identity that may use the installation (RsxAuth::has_enabled_membership(),
 * the framework's own sign-in question).
 *
 * THE LINK (Login_User_Password_Reset_Model / Password_Reset_Token): the stored token is the
 * SHA-256 of the link's secret; one live link per identity (a new request ends the old one);
 * rsx.auth.password_reset_expiry_hours; single use, claimed atomically before the password is
 * written; any change of the identity's password - this link's included - ends every other open
 * link (the password-change event, Password_Event_Handlers).
 *
 * COMPLETING A RESET SIGNS NOBODY IN. The new password is saved through Login_User_Model (which
 * clears a forced-reset flag, forgets two-factor trusted browsers and ends every sign-in of the
 * identity), and the visitor is sent to /login to sign in with it - so a second factor, the
 * site picker and everything else a sign-in owes run through Login_Controller::complete_sign_in()
 * like any other sign-in. Holding the mailbox is not holding the second factor.
 *
 * Throttled: the request draws on the sign-in budget (Login_Throttle::require_not_throttled() on
 * the address) and refuses with its message when spent. Nothing here counts a failure: a request
 * proves nothing and guesses nothing, and a link's secret is 256 random bits.
 *
 * No lock is declared (page routes take none): the token and the identity are not site-scoped,
 * and the queued email - a site-scoped row, filed under the anonymous visitor's site 0 - is
 * written inside a Site_Lock::write() section on that site.
 */
#[Auth('public')]
class Password_Reset_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ask for a reset link.
     */
    #[Route('/password/reset', methods: ['GET', 'POST'])]
    public static function request(Request $request, array $params = [])
    {
        if (Session::is_logged_in()) {
            return redirect(Rsx::Route('Dashboard_Index_Action'));
        }

        $error = null;
        $sent = false;
        $posted_email = null;

        if ($request->is_post()) {
            // Human verification first: it gates the lookup and the email, and therefore both
            // enumeration and using this form as a mailer.
            Rsx_Turnstile::validate($request);

            $posted_email = trim((string) $request->input('email'));

            if ($posted_email === '') {
                $error = 'Email address is required';
            } elseif (!filter_var($posted_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address';
            } else {
                try {
                    Login_Throttle::require_not_throttled($posted_email);

                    static::__send_link_if_account($posted_email);

                    $sent = true;
                } catch (Auth_Throttled_Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return rsx_view('Password_Reset_Request', [
            'error' => $error,
            'sent' => $sent,
            'posted_email' => $posted_email,
        ]);
    }

    /**
     * Set a new password from an emailed link.
     */
    #[Route('/password/reset/:token', methods: ['GET', 'POST'])]
    public static function reset(Request $request, array $params = [])
    {
        if (Session::is_logged_in()) {
            return redirect(Rsx::Route('Dashboard_Index_Action'));
        }

        $token = (string) ($params['token'] ?? '');
        $reset = Login_User_Password_Reset_Model::find_by_secret($token);
        $login_user = $reset?->login_user();

        if ($reset === null || $login_user === null) {
            return static::__invalid('That password reset link is not valid. Request a new one.');
        }

        if ($reset->is_used()) {
            return static::__invalid('That password reset link has already been used. Sign in, or request a new one.');
        }

        if ($reset->is_expired()) {
            return static::__invalid('That password reset link has expired. Request a new one.');
        }

        $error = null;

        if ($request->is_post()) {
            $password = (string) $request->input('password');
            $confirmation = (string) $request->input('password_confirmation');
            $min_length = (int) config('rsx.auth.password_min_length');

            if ($password === '') {
                $error = 'Password is required';
            } elseif (strlen($password) < $min_length) {
                $error = "Password must be at least {$min_length} characters";
            } elseif ($password !== $confirmation) {
                $error = 'Passwords do not match';
            } else {
                // Claim FIRST: of two submissions racing on one link, only the one that uses it
                // up writes a password. One transaction, so a failed save leaves the link usable.
                $claimed = DB::transaction(function () use ($reset, $login_user, $password) {
                    if (!$reset->claim()) {
                        return false;
                    }

                    // The one place a reset link sets a password. Once this commits, the
                    // password-change event ends every other open link of the identity and
                    // records the change (Password_Event_Handlers).
                    $login_user->set_password($password, Password_Change::CAUSE_RESET_LINK);
                    $login_user->save();

                    return true;
                });

                if (!$claimed) {
                    return static::__invalid('That password reset link has already been used. Sign in, or request a new one.');
                }

                return redirect(Rsx::Route('Login_Controller::index', Login_Notice::params('password_reset')));
            }
        }

        return rsx_view('Password_Reset_Reset', [
            'token' => $token,
            'email' => (string) $login_user->email,
            'error' => $error,
            'min_length' => (int) config('rsx.auth.password_min_length'),
        ]);
    }

    /**
     * Mint and email a link when the address is a live identity that may sign in; otherwise
     * do nothing. The caller answers the same either way.
     */
    private static function __send_link_if_account(string $email): void
    {
        $login_user = Login_User_Model::find_by_email($email);

        if ($login_user === null || !RsxAuth::has_enabled_membership($login_user)) {
            return;
        }

        Site_Lock::write((int) Session::get_site_id(), function () use ($login_user) {
            $reset = Login_User_Password_Reset_Model::create_for($login_user);

            (new Password_Reset_Email($login_user, $reset->get_reset_url()))->to($login_user)->send();
        }, 'Staff password reset requested');

        // The one place a staff reset is requested. An APPLICATION event (the reset flow is this
        // application's), shaped like the framework's login_user.password.changed so the one
        // handler records both (Password_Event_Handlers). Only for a link actually sent: an
        // address with no account has nobody to record it against.
        Rsx::trigger_action('login_user.password.reset_requested', [
            'identity' => $login_user,
            'cause' => null,
            'actor' => null,
            'audit_session_id' => Session::get_audit_session_id(),
            'ip_address' => Session::get_client_ip(),
        ]);
    }

    private static function __invalid(string $error)
    {
        return rsx_view('Password_Reset_Invalid', ['error' => $error]);
    }
}
