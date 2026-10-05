<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use Rsx\App\Login\Signup\Signup_Controller;
use Rsx\Handlers\Sso_Handlers;

/**
 * Who may create an account, and how.
 *
 *   /signup POST honours rsx.auth.signup_mode exactly as the page does: 'disabled' refuses
 *   every submission and 'invite_only' refuses one without a valid invitation. An address
 *   that already holds an account gets the same answer as a new one and nothing is written,
 *   so the form is not a way to learn which addresses exist.
 *
 *   Federated sign-in signs in an EXISTING account only: an open invitation to the asserted
 *   address, an unverified address and an unknown address all decline (null), and the
 *   framework's fail-closed refusal follows.
 */
class Self_Registration_Policy_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __submit(string $email)
    {
        return Signup_Controller::submit(new Request(), [
            'email' => $email,
            'first_name' => 'Sign',
            'last_name' => 'Up',
            'password' => 'a-long-enough-password',
            'password_confirm' => 'a-long-enough-password',
            Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE,
        ]);
    }

    private static function __existing_identity(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'existing_' . uniqid() . '@example.com';
        $login_user->password = 'not-a-login-path';
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    // ---------------------------------------------------------------------
    // /signup
    // ---------------------------------------------------------------------

    public static function test_disabled_signup_refuses_the_post()
    {
        config(['rsx.auth.signup_mode' => 'disabled']);
        $email = 'disabled_' . uniqid() . '@example.com';

        $result = static::__submit($email);

        static::__assert_instance_of(Error_Response::class, $result, 'a disabled signup refuses the POST');
        static::__assert_false(Login_User_Model::where('email', $email)->exists(), 'no account was created');
    }

    public static function test_invite_only_signup_refuses_a_post_without_an_invitation()
    {
        config(['rsx.auth.signup_mode' => 'invite_only']);
        $email = 'uninvited_' . uniqid() . '@example.com';

        $result = static::__submit($email);

        static::__assert_instance_of(Error_Response::class, $result, 'no invitation, no account');
        static::__assert_false(Login_User_Model::where('email', $email)->exists(), 'no account was created');
    }

    public static function test_open_signup_answers_an_existing_address_like_a_new_one()
    {
        config(['rsx.auth.signup_mode' => 'open']);

        $new_email = 'fresh_' . uniqid() . '@example.com';
        $fresh = static::__submit($new_email);

        $existing = static::__existing_identity();
        $repeat = static::__submit($existing->email);

        static::__assert_false($fresh instanceof Error_Response, 'a new address is accepted');
        static::__assert_false($repeat instanceof Error_Response, 'an existing address is not refused');
        static::__assert_equals($fresh, $repeat, 'both answers are identical');
        static::__assert_true(Login_User_Model::where('email', $new_email)->exists(), 'the new account exists');
        static::__assert_equals(
            'not-a-login-path',
            Login_User_Model::find($existing->id)->password,
            'the existing account was not touched'
        );
    }

    // ---------------------------------------------------------------------
    // federated sign-in
    // ---------------------------------------------------------------------

    public static function test_sso_declines_an_open_invitation()
    {
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
        $invite->save();

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
