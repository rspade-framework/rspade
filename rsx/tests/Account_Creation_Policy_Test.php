<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use Rsx\App\Login\AcceptInvite\Accept_Invite_Controller;
use Rsx\App\Login\Login_Controller;
use Rsx\Handlers\Sso_Handlers;

/**
 * Who may create an account, and how.
 *
 *   THERE IS NO SELF-SIGNUP, staff or portal. No route of this application lets a visitor
 *   create an account of their own: nothing answers /signup, and no surface is a signup.
 *
 *   INVITATIONS CREATE ACCOUNTS, AND VERIFY. Creating an account from an invitation stores the
 *   invited address as verified and signs the account in; a different address is refused.
 *
 *   A PASSWORD SIGN-IN OWES NO EMAILED STEP. The address verification existed for accounts a
 *   visitor created for themselves; an identity whose is_verified is 0 signs in on its
 *   password like any other.
 *
 *   Federated sign-in signs in an EXISTING account only: an open invitation to the asserted
 *   address, an unverified address and an unknown address all decline (null) - a provider
 *   never creates an account - and the framework's fail-closed refusal follows.
 */
class Account_Creation_Policy_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one (Rsx_Test_Abstract::__as_browser()). */
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;

    private const PASSWORD = 'a-long-enough-password';

    public static function teardown(): void
    {
        Session::logout();
        static::__reset_session();
    }

    private static function __start(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __existing_identity(int $is_verified = 1): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'existing_' . uniqid() . '@example.com';
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = $is_verified;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    // ---------------------------------------------------------------------
    // no self-signup
    // ---------------------------------------------------------------------

    public static function test_no_route_lets_a_visitor_sign_up()
    {
        foreach (Manifest::get_routes() as $pattern => $route) {
            static::__assert_false(
                (bool) preg_match('#/(sign-?up|register-account)(/|$)#i', (string) $pattern),
                "no signup route: {$pattern}"
            );
            static::__assert_false(
                str_contains(strtolower((string) ($route['class'] ?? '')), 'signup'),
                'no signup controller: ' . ($route['class'] ?? '')
            );
        }

        static::__assert_null(config('rsx.auth.self_signup'), 'no self-signup switch exists');
    }

    // ---------------------------------------------------------------------
    // a password sign-in
    // ---------------------------------------------------------------------

    public static function test_a_password_sign_in_of_an_unverified_address_signs_in()
    {
        static::__start();

        config([
            'rsx.mail.delivery' => 'live',
            'rsx.mail.dev_site.domain_whitelist' => 'example.com',
        ]);

        $login_user = static::__existing_identity(0);

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->site_id = self::SITE_ID;
        $user->email = $login_user->email;
        $user->first_name = 'Never';
        $user->last_name = 'Verified';
        $user->role_id = User_Model::ROLE_USER;
        $user->is_enabled = 1;
        User_Model::without_site_scope(fn () => $user->save());

        $response = Login_Controller::index(Request::create('/login', 'POST', [
            'email' => $login_user->email,
            'password' => self::PASSWORD,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE,
        ]), []);

        static::__assert_not_equals(
            Rsx::Route('Login_Controller::verify'),
            parse_url($response->getTargetUrl(), PHP_URL_PATH),
            'no verification step'
        );
        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'the password signs in');
    }

    // ---------------------------------------------------------------------
    // invitations
    // ---------------------------------------------------------------------

    public static function test_an_account_created_from_an_invitation_is_verified_and_signed_in()
    {
        static::__start();

        $email = 'invitee_' . uniqid() . '@example.com';

        $invite = new User_Model();
        $invite->site_id = self::SITE_ID;
        $invite->login_user_id = null;
        $invite->email = $email;
        $invite->first_name = 'Invited';
        $invite->last_name = 'Person';
        $invite->role_id = User_Model::ROLE_USER;
        $invite->is_enabled = true;
        $invite->invite_code = 'code_' . bin2hex(random_bytes(8));
        $invite->invite_expires_at = now()->addDays(7);
        User_Model::without_site_scope(fn () => $invite->save());

        $fields = [
            'email' => $email,
            'first_name' => 'Invited',
            'last_name' => 'Person',
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
            'invite_code' => $invite->invite_code,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE,
        ];

        // A different address is refused, and nothing is created.
        $other = array_merge($fields, ['email' => 'someone_else_' . uniqid() . '@example.com']);
        $refused = Accept_Invite_Controller::create_account_submit(Request::create('/_ajax', 'POST', $other), $other);

        static::__assert_instance_of(Error_Response::class, $refused, 'another address is refused');
        static::__assert_false(Login_User_Model::where('email', $other['email'])->exists());

        $result = Accept_Invite_Controller::create_account_submit(Request::create('/_ajax', 'POST', $fields), $fields);

        static::__assert_false($result instanceof Error_Response, 'the invited address is accepted');

        $login_user = Login_User_Model::where('email', $email)->first();

        static::__assert_equals(Rsx::Route('Accept_Invite_Controller::success'), $result['redirect']);
        static::__assert_not_null($login_user);
        static::__assert_equals(1, (int) $login_user->is_verified, 'the invitation verified the address');
        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'signed in through the completion function');
        static::__assert_equals(self::SITE_ID, (int) Session::get_site_id(), 'on the invited site');
    }

    // ---------------------------------------------------------------------
    // federated sign-in
    // ---------------------------------------------------------------------

    public static function test_sso_declines_an_open_invitation()
    {
        static::__start();

        $invite = new User_Model();
        $invite->site_id = self::SITE_ID;
        $invite->login_user_id = null;
        $invite->email = 'sso_invitee_' . uniqid() . '@example.com';
        $invite->first_name = 'Sso';
        $invite->last_name = 'Invitee';
        $invite->role_id = User_Model::ROLE_VIEWER;
        $invite->is_enabled = true;
        $invite->invite_code = 'code_' . bin2hex(random_bytes(8));
        $invite->invite_expires_at = now()->addDays(7);
        User_Model::without_site_scope(fn () => $invite->save());

        foreach ([true, false] as $verified) {
            static::__assert_null(
                Sso_Handlers::match_verified_email_of_existing_account([
                    'provider_key' => 'google',
                    'provider_user_key' => 'g-' . uniqid(),
                    'email' => $invite->email,
                    'email_verified' => $verified,
                ]),
                'an open invitation is never a way in (verified: ' . var_export($verified, true) . ')'
            );
        }

        static::__assert_false(Login_User_Model::where('email', $invite->email)->exists(), 'no account was created');
    }

    public static function test_sso_declines_an_unverified_or_unknown_address()
    {
        static::__start();

        $existing = static::__existing_identity();

        static::__assert_null(
            Sso_Handlers::match_verified_email_of_existing_account([
                'provider_key' => 'google',
                'provider_user_key' => 'g-' . uniqid(),
                'email' => $existing->email,
                'email_verified' => false,
            ]),
            'an unverified address never matches an account'
        );

        $unknown = 'nobody_' . uniqid() . '@example.com';

        static::__assert_null(
            Sso_Handlers::match_verified_email_of_existing_account([
                'provider_key' => 'google',
                'provider_user_key' => 'g-' . uniqid(),
                'email' => $unknown,
                'email_verified' => true,
            ]),
            'an address with no account is declined'
        );

        static::__assert_false(Login_User_Model::where('email', $unknown)->exists(), 'and no account is created for it');
    }
}
