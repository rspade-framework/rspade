<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Login\Signup;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use Rsx\App\Login\Login_Controller;

/**
 * Signup_Controller - staff self-signup: a new site, owned by whoever signs up.
 *
 * OFF AS SHIPPED. config('rsx.auth.self_signup') is a hardcoded boolean in
 * rsx/resource/config/rsx.php, false: accounts come from an administrator's invitation,
 * accepted on /accept-invite. Off, the page and its endpoint answer 404 - a POST never
 * succeeds where the page does not exist - and the login page offers no Sign up link.
 *
 * ON, IT CREATES A WORKSPACE. One submission creates, in one transaction, the login identity
 * (is_verified = 0), a NEW SITE named by the signer, and the signer's membership of it at
 * ROLE_ROOT_ADMIN - the role the initial user gets (Rsx\Handlers\Initial_User_Handlers),
 * because the person who creates a workspace runs it.
 *
 * NOBODY IS SIGNED IN BY SIGNING UP. The address is proved first, by the framework's emailed
 * challenge: begin_challenge($identity, [ANSWER_ISSUED_CODE]) parks the new identity and the
 * browser goes to /login/verify, whose screen asks Login_Controller::send_code() to email a
 * six-digit code and a sign-in link (Signup_Verification_Email). Typing the code or opening
 * the link in this browser answers the challenge; the framework then marks the address
 * verified and signs the identity in, and Login_Controller::complete_sign_in() lands it on
 * its new site. An account whose owner never verifies is never signed in.
 *
 * SO DELIVERY MUST BE LIVE. With rsx.mail.delivery anything but 'live' the verification
 * email is recorded and never sent, and every account created would be one nobody can use.
 * The page and the endpoint therefore THROW, naming the delivery mode - an operator who
 * switched signup on finds out at once, not from a user.
 *
 * THE SUBMIT ANSWER DOES NOT ENUMERATE. An address that already has an account gets the same
 * answer as a new one ({redirect} to the verify screen) and nothing is created or sent. No
 * challenge is begun for it - begun against the existing account, a challenge would let the
 * mailbox alone sign that account in, or let a stranger spend its failure budget - so the
 * verify screen finds nothing pending and returns the visitor to the sign-in page, where the
 * owner of the address signs in.
 *
 * Public by design: registration is reachable by anonymous visitors.
 */
#[Auth('public')]
class Signup_Controller extends Rsx_Controller_Abstract
{
    /**
     * The signup form.
     */
    #[Route('/signup', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        if (!config('rsx.auth.self_signup')) {
            return response_not_found();
        }

        static::__require_delivery();

        if (Session::is_logged_in()) {
            return redirect(Rsx::Route('Dashboard_Index_Action'));
        }

        return rsx_view('Signup_Index', [
            'max_site_name' => Site_Model::field_length('name'),
            'max_email' => Login_User_Model::field_length('email'),
            'max_first_name' => User_Model::field_length('first_name'),
            'max_last_name' => User_Model::field_length('last_name'),
        ]);
    }

    /**
     * Create the identity, its site and its owner membership, then hand the browser to the
     * address-verification challenge.
     *
     * @return array {redirect}
     */
    #[Ajax_Endpoint]
    public static function submit(Request $request, array $params = [])
    {
        // Human verification first: it gates the email-exists lookup below, and therefore
        // account enumeration, before any record work happens. $params is passed because this
        // transport delivers the field there (a batch sub-call carries the envelope, not the
        // field, on the Request).
        Rsx_Turnstile::validate($request, $params);

        // The page's own rules, asked again here: a POST must never succeed where the page
        // would not have rendered.
        if (!config('rsx.auth.self_signup')) {
            return response_not_found();
        }

        static::__require_delivery();

        $site_name = trim((string) ($params['site_name'] ?? ''));
        $email = trim((string) ($params['email'] ?? ''));
        $first_name = trim((string) ($params['first_name'] ?? ''));
        $last_name = trim((string) ($params['last_name'] ?? ''));
        $password = (string) ($params['password'] ?? '');
        $password_confirm = (string) ($params['password_confirm'] ?? '');

        $errors = [];

        if ($site_name === '') {
            $errors['site_name'] = 'Organization name is required';
        } elseif (mb_strlen($site_name) > Site_Model::field_length('name')) {
            $errors['site_name'] = 'Organization name is too long';
        }

        if ($email === '') {
            $errors['email'] = 'Email address is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > Login_User_Model::field_length('email')) {
            $errors['email'] = 'Please enter a valid email address';
        }

        if ($first_name === '') {
            $errors['first_name'] = 'First name is required';
        } elseif (mb_strlen($first_name) > User_Model::field_length('first_name')) {
            $errors['first_name'] = 'First name is too long';
        }

        if ($last_name === '') {
            $errors['last_name'] = 'Last name is required';
        } elseif (mb_strlen($last_name) > User_Model::field_length('last_name')) {
            $errors['last_name'] = 'Last name is too long';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        if ($password !== $password_confirm) {
            $errors['password_confirm'] = 'Passwords do not match';
        }

        // response_form_error() carries a summary under _message alongside the field map, so
        // the form's top alert says something even when every message matched a field.
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        // Computed either way, so the existing-address answer takes the same time as a new one.
        $password_hash = Hash::make($password);

        $login_user = Login_User_Model::where('email', $email)->first();

        if ($login_user !== null) {
            // The same answer a new address gets, and nothing written or sent. No challenge is
            // begun (see the class docblock), so the verify screen returns to sign-in.
            return ['redirect' => Rsx::Route('Login_Controller::verify')];
        }

        $login_user = static::__create_workspace($site_name, $email, $password_hash, $first_name, $last_name);

        // THE ADDRESS IS PROVED BEFORE ANYBODY IS SIGNED IN. The challenge signs the browser's
        // staff sign-in out and parks the new identity; it accepts only an emailed code (and
        // its link). The marker is parked AFTER it, because the challenge's sign-out clears
        // the staff realm's session values.
        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

        Session::put_value(Login_Controller::ADDRESS_VERIFICATION_KEY, true, Rsx_Two_Factor::challenge_expires_at());

        // 'redirect' is the one result key the form itself acts on.
        return ['redirect' => Rsx::Route('Login_Controller::verify')];
    }

    /**
     * The identity, its site and its owner membership - created together or not at all: an
     * identity with no site could never sign in, and a site with no owner could never be run.
     *
     * Every field is assigned explicitly. The membership is written outside the site scope,
     * because the session has no site yet - it is about to be the new one.
     *
     * @return Login_User_Model
     */
    private static function __create_workspace(
        string $site_name,
        string $email,
        string $password_hash,
        string $first_name,
        string $last_name
    ): Login_User_Model {
        return DB::transaction(function () use ($site_name, $email, $password_hash, $first_name, $last_name) {
            $login_user = new Login_User_Model();
            $login_user->email = $email;
            $login_user->password = $password_hash;
            $login_user->is_activated = 1;
            // Unverified until the emailed code or link answers the challenge; the framework
            // sets it then (rsx:man two_factor_codes, A PASSED EMAILED ANSWER VERIFIES THE ADDRESS).
            $login_user->is_verified = 0;
            $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
            $login_user->save();

            $site = new Site_Model();
            $site->name = $site_name;
            // A slug nobody chose: unique, and the owner can rename the site without it moving.
            $site->slug = 'site-' . strtolower(random_hash(12));
            $site->is_enabled = 1;
            $site->save();

            User_Model::without_site_scope(function () use ($login_user, $site, $email, $first_name, $last_name) {
                $user = new User_Model();
                $user->login_user_id = $login_user->id;
                $user->site_id = $site->id;
                $user->email = $email;
                $user->first_name = $first_name;
                $user->last_name = $last_name;
                // The founder of a workspace runs it: the role the initial user gets.
                $user->role_id = User_Model::ROLE_ROOT_ADMIN;
                $user->is_enabled = 1;
                $user->save();
            });

            return $login_user;
        });
    }

    /**
     * Self-signup sends a verification email, so it refuses to run where mail does not leave.
     *
     * @return void
     * @throws \RuntimeException When rsx.mail.delivery is not 'live'.
     */
    private static function __require_delivery(): void
    {
        if (!Rsx_Mail::delivers()) {
            throw new \RuntimeException(
                "Self-signup (rsx.auth.self_signup) sends a verification email and mail delivery is '"
                . Rsx_Mail_Transport::delivery_mode() . "'; set rsx.mail.delivery to live."
            );
        }
    }
}
