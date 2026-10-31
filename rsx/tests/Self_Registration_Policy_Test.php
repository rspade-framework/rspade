<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use Rsx\App\Login\AcceptInvite\Accept_Invite_Controller;
use Rsx\App\Login\Login_Controller;
use Rsx\App\Login\Signup\Signup_Controller;
use Rsx\Handlers\Sso_Handlers;

/**
 * Who may create an account, and how.
 *
 *   SELF-SIGNUP (rsx.auth.self_signup, off as shipped). Off, /signup and its endpoint answer
 *   404. On while mail is not delivered (Rsx_Mail::delivers()), both THROW naming the mode. On with delivery,
 *   one submission creates the identity (unverified), a NEW site and the signer's owner
 *   membership, signs nobody in, and parks an address-verification challenge; the verify
 *   screen's send endpoint emails a code and a link; either one signs the new owner in on the
 *   new site with the address verified. An address that already holds an account gets the
 *   same answer and nothing is written.
 *
 *   AN UNVERIFIED ADDRESS SIGNING IN with a password is sent through the same verification
 *   when mail can reach it, so the password is no way around signup's confirmation.
 *
 *   INVITATIONS VERIFY. Creating an account from an invitation stores the invited address as
 *   verified and signs the account in; a different address is refused.
 *
 *   Federated sign-in signs in an EXISTING account only: an open invitation to the asserted
 *   address, an unverified address and an unknown address all decline (null), and the
 *   framework's fail-closed refusal follows.
 *
 * The cases that need mail to arrive declare it deliverable: delivery live, and the fixture
 * domain on the dev-site whitelist (the test host is a `.dev.` host). Nothing is drained -
 * the suite spawns no workers - so the rows stay queued and their template data is read back.
 */
class Self_Registration_Policy_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one (Rsx_Test_Abstract::__as_browser()). */
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;

    private const PASSWORD = 'a-long-enough-password';

    private static array $__saved_config = [];

    public static function setup(): void
    {
        static::$__saved_config = [
            'rsx.auth.self_signup' => config('rsx.auth.self_signup'),
            'rsx.mail.delivery' => config('rsx.mail.delivery'),
            'rsx.mail.dev_site.domain_whitelist' => config('rsx.mail.dev_site.domain_whitelist'),
        ];
    }

    public static function teardown(): void
    {
        config(static::$__saved_config);
        Session::logout();
        static::__reset_session();
    }

    /**
     * Every test starts as a visitor on the template's own site. The CLI site declaration
     * survives between test methods by design (it belongs to the class), and a test that
     * signs a new owner in moves it to a site the rollback then removes.
     */
    private static function __start(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /** Self-signup on, with mail that reaches the fixture's addresses. */
    private static function __signup_on_with_delivery(): void
    {
        config([
            'rsx.auth.self_signup' => true,
            'rsx.mail.delivery' => 'live',
            'rsx.mail.dev_site.domain_whitelist' => 'example.com',
        ]);
    }

    private static function __submit(string $email, string $site_name = 'Fixture Workspace')
    {
        return Signup_Controller::submit(new Request(), [
            'site_name' => $site_name,
            'email' => $email,
            'first_name' => 'Sign',
            'last_name' => 'Up',
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE,
        ]);
    }

    private static function __existing_identity(int $is_verified = 1): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'existing_' . uniqid() . '@example.com';
        $login_user->password = 'not-a-login-path';
        $login_user->is_activated = true;
        $login_user->is_verified = $is_verified;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * Ask the verify screen's send endpoint for the code, and read the queued email back.
     *
     * @return array {code, link, token}
     */
    private static function __send_and_read(string $email): array
    {
        static::__assert_null(
            Login_Controller::send_code(Request::create('/_ajax/Login_Controller/send_code', 'POST'), []),
            'the code is sent'
        );

        $row = Email_Queue_Model::without_site_scope(
            fn () => Email_Queue_Model::where('to_address', $email)->orderBy('id', 'desc')->first()
        );

        static::__assert_not_null($row, 'the verification email is queued to the new address');
        static::__assert_equals(Email_Queue_Model::CATEGORY_SECURITY, (int) $row->category_id, 'a SECURITY email');

        $data = $row->template_data;

        static::__assert_true(
            (bool) preg_match('#/_email_link/([0-9a-f]{64})\?s=[0-9a-f]{64}$#', $data['link'], $m),
            'the email carries an absolute signed sign-in link: ' . $data['link']
        );
        static::__assert_true(str_starts_with($data['link'], 'http'), 'the link is absolute');

        return ['code' => $data['code'], 'link' => $data['link'], 'token' => $m[1]];
    }

    private static function __verify(array $answer)
    {
        return Login_Controller::verify_2fa(Request::create('/_ajax/Login_Controller/verify_2fa', 'POST'), $answer);
    }

    // ---------------------------------------------------------------------
    // /signup - off
    // ---------------------------------------------------------------------

    public static function test_self_signup_off_answers_404_on_the_page_and_the_post()
    {
        static::__start();

        config(['rsx.auth.self_signup' => false]);
        $email = 'off_' . uniqid() . '@example.com';

        $page = Signup_Controller::index(new Request(), []);
        $post = static::__submit($email);

        static::__assert_instance_of(Error_Response::class, $page, 'the page is not found');
        static::__assert_instance_of(Error_Response::class, $post, 'the POST is not found');
        static::__assert_equals(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, $post->get_error_code());
        static::__assert_false(Login_User_Model::where('email', $email)->exists(), 'no account was created');
    }

    // ---------------------------------------------------------------------
    // /signup - on, mail not live
    // ---------------------------------------------------------------------

    public static function test_self_signup_on_without_live_delivery_throws_naming_the_mode()
    {
        static::__start();

        config(['rsx.auth.self_signup' => true, 'rsx.mail.delivery' => 'suppressed']);
        $email = 'nodelivery_' . uniqid() . '@example.com';

        static::__assert_throws(\RuntimeException::class, fn () => Signup_Controller::index(new Request(), []), "mail delivery is 'suppressed'");
        static::__assert_throws(\RuntimeException::class, fn () => static::__submit($email), 'set rsx.mail.delivery to live');
        static::__assert_false(Login_User_Model::where('email', $email)->exists(), 'no account was created');
    }

    // ---------------------------------------------------------------------
    // /signup - on
    // ---------------------------------------------------------------------

    public static function test_self_signup_creates_a_site_an_owner_and_a_pending_verification()
    {
        static::__start();

        static::__signup_on_with_delivery();
        $email = 'founder_' . uniqid() . '@example.com';

        $result = static::__submit($email, 'Founder Workspace');

        static::__assert_equals(['redirect' => Rsx::Route('Login_Controller::verify')], $result, 'on to the verify screen');

        $login_user = Login_User_Model::where('email', $email)->first();
        static::__assert_not_null($login_user, 'the identity exists');
        static::__assert_equals(0, (int) $login_user->is_verified, 'unverified until the address is proved');

        $membership = User_Model::without_site_scope(fn () => User_Model::where('login_user_id', $login_user->id)->first());
        static::__assert_not_null($membership, 'the owner membership exists');
        static::__assert_equals(User_Model::ROLE_ROOT_ADMIN, (int) $membership->role_id, 'the founder gets the initial user\'s role');
        static::__assert_true((bool) $membership->is_enabled);

        $site = Site_Model::find($membership->site_id);
        static::__assert_not_null($site, 'a site was created');
        static::__assert_true((int) $site->id !== self::SITE_ID, 'a NEW site, not the template\'s own');
        static::__assert_equals('Founder Workspace', $site->name);

        static::__assert_false(Session::is_logged_in(), 'nobody is signed in by signing up');

        $pending = Rsx_Two_Factor::challenge_pending();
        static::__assert_not_null($pending, 'an address-verification challenge is pending');
        static::__assert_equals([Rsx_Two_Factor::ANSWER_ISSUED_CODE], $pending['accepts'], 'it accepts the emailed code only');
        static::__assert_true((bool) Session::get_value(Login_Controller::ADDRESS_VERIFICATION_KEY), 'and is marked an address verification');
    }

    public static function test_the_emailed_code_signs_the_owner_in_verified_on_the_new_site()
    {
        static::__start();

        static::__signup_on_with_delivery();
        $email = 'by_code_' . uniqid() . '@example.com';

        static::__submit($email);
        $sent = static::__send_and_read($email);

        $result = static::__verify(['code' => $sent['code']]);

        $login_user = Login_User_Model::where('email', $email)->first();
        $membership = User_Model::without_site_scope(fn () => User_Model::where('login_user_id', $login_user->id)->first());

        static::__assert_equals(Rsx::Route('Dashboard_Index_Action'), $result['redirect'], 'lands on the dashboard');
        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'signed in as the new owner');
        static::__assert_equals((int) $membership->site_id, (int) Session::get_site_id(), 'on the new site');
        static::__assert_equals(1, (int) $login_user->is_verified, 'the address is verified');
        static::__assert_null(Session::get_value(Login_Controller::ADDRESS_VERIFICATION_KEY), 'the verification marker is spent');
    }

    public static function test_the_emailed_link_signs_the_owner_in_verified()
    {
        static::__start();

        static::__signup_on_with_delivery();
        $email = 'by_link_' . uniqid() . '@example.com';

        static::__submit($email);
        $sent = static::__send_and_read($email);

        // The landing route hands the token to the verify screen, which posts it as {email_link}.
        $result = static::__verify(['email_link' => $sent['token']]);

        $login_user = Login_User_Model::where('email', $email)->first();

        static::__assert_array_has_key('redirect', $result);
        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'signed in by the link');
        static::__assert_equals(1, (int) $login_user->is_verified, 'the address is verified');
    }

    public static function test_self_signup_answers_an_existing_address_like_a_new_one()
    {
        static::__start();

        static::__signup_on_with_delivery();

        $fresh = static::__submit('fresh_' . uniqid() . '@example.com');

        Session::logout();
        Rsx_Two_Factor::abandon_challenge();

        $existing = static::__existing_identity();
        $sites_before = Site_Model::count();
        $repeat = static::__submit($existing->email);

        static::__assert_equals($fresh, $repeat, 'both answers are identical');
        static::__assert_equals($sites_before, Site_Model::count(), 'no site was created for the existing address');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'no challenge is begun against the existing account');
        static::__assert_equals(
            'not-a-login-path',
            Login_User_Model::find($existing->id)->password,
            'the existing account was not touched'
        );
    }

    // ---------------------------------------------------------------------
    // An unverified address signing in
    // ---------------------------------------------------------------------

    public static function test_a_password_sign_in_of_an_unverified_address_owes_the_email_verification()
    {
        static::__start();

        static::__signup_on_with_delivery();

        $login_user = static::__existing_identity(0);
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->save();

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

        static::__assert_equals(
            Rsx::Route('Login_Controller::verify'),
            parse_url($response->getTargetUrl(), PHP_URL_PATH),
            'sent to verify the address'
        );
        static::__assert_false(Session::is_logged_in(), 'the password alone signs nobody in');
        static::__assert_true(Rsx_Two_Factor::challenge_pending()['has_issued_code'], 'the challenge takes the emailed code');

        $sent = static::__send_and_read($login_user->email);
        static::__verify(['code' => $sent['code']]);

        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'signed in once the address is proved');
        static::__assert_equals(1, (int) Login_User_Model::find($login_user->id)->is_verified);
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

        static::__assert_null(
            Sso_Handlers::match_verified_email_of_existing_account([
                'provider_key' => 'google',
                'provider_user_key' => 'g-' . uniqid(),
                'email' => 'nobody_' . uniqid() . '@example.com',
                'email_verified' => true,
            ]),
            'an address with no account is declined'
        );
    }
}
