<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Totp;
use Rsx\Portal\Auth\Portal_Login_Controller;

/**
 * This application's half of the PORTAL sign-in ladder's second factor and passkey doors:
 * Portal_Login_Controller::index() (the two-stage password login), verify_2fa and
 * passkey_login.
 *
 * The framework pins the realm itself (tests/two_factor, Portal_Two_Factor_Test); what is
 * pinned HERE is what this application wired around it - that a portal user with a second
 * factor is parked and sent to /login/verify instead of being signed in, that the challenge
 * endpoint answers the ONE key <Two_Factor_Challenge> follows, and that the passkey endpoint
 * turns every failure into a user-safe ERROR_VALIDATION rather than an exception the
 * component cannot render.
 *
 * ENDPOINTS ARE CALLED AS STATIC METHODS, as the dispatcher calls them once the gate passes,
 * inside a PORTAL request declaring this app's portal site.
 */
class Portal_Two_Factor_Login_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const PASSWORD = 'correct-horse-battery-staple';

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Rsx_Turnstile::$force_verify_result_for_tests = null;
        Login_Throttle::reset('CLI');
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Session::logout();
        static::__reset_session();
    }

    private static function __as_portal(): void
    {
        Login_Throttle::reset('CLI');
        Portal_Session::set_site_id(self::SITE_ID);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_portal_user_id(0);
    }

    /**
     * A portal user with a confirmed authenticator app, anonymous again afterwards.
     *
     * @return array {portal_user, secret}
     */
    private static function __portal_user_with_totp(): array
    {
        static::__as_portal();

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'portal_verify_' . uniqid() . '@example.com';
        $portal_user->set_password(self::PASSWORD);
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);

        $started = Rsx_Portal_Two_Factor::begin_totp_enrollment();
        Rsx_Portal_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));

        Portal_Session::cli_set_portal_user_id(0);

        return ['portal_user' => $portal_user, 'secret' => $started['secret']];
    }

    private static function __turnstile_token(): string
    {
        if (!Rsx_Turnstile::is_enabled()) {
            return Rsx_Turnstile::INACTIVE;
        }

        Rsx_Turnstile::$force_verify_result_for_tests = true;

        return 'test-token';
    }

    // -------------------------------------------------------------------------
    // The password stage
    // -------------------------------------------------------------------------

    /**
     * A correct password for a portal user holding a second factor signs NOBODY in: the user
     * is parked and sent to the challenge page.
     */
    public static function test_a_password_login_with_a_second_factor_is_parked_not_signed_in()
    {
        $fixture = static::__portal_user_with_totp();

        $request = Request::create('/_portal/login', 'POST', [
            'email' => $fixture['portal_user']->email,
            'password' => self::PASSWORD,
            Rsx_Turnstile::FIELD => static::__turnstile_token(),
        ]);

        $response = Portal_Login_Controller::index($request, []);

        static::__assert_equals(
            Rsx_Portal::Route('Portal_Login_Controller::verify'),
            parse_url($response->getTargetUrl(), PHP_URL_PATH),
            'sent to the challenge page'
        );
        static::__assert_false(Portal_Session::is_logged_in(), 'and not signed in');
        static::__assert_not_null(Rsx_Portal_Two_Factor::challenge_pending(), 'with the challenge pending');
    }

    // -------------------------------------------------------------------------
    // The challenge endpoint
    // -------------------------------------------------------------------------

    /**
     * A live code signs the portal user in and answers {redirect}.
     */
    public static function test_verify_2fa_signs_in_and_answers_a_redirect()
    {
        $fixture = static::__portal_user_with_totp();

        Rsx_Portal_Two_Factor::begin_challenge($fixture['portal_user']);

        $result = Portal_Login_Controller::verify_2fa(Request::create('/_portal/_ajax/Portal_Login_Controller/verify_2fa', 'POST'), [
            'code' => Totp::code_for($fixture['secret'], intdiv(time(), Totp::PERIOD) + 1),
        ]);

        static::__assert_array_has_key('redirect', $result, 'the component follows exactly this key');
        static::__assert_equals((int) $fixture['portal_user']->id, (int) Portal_Session::get_portal_user_id(), 'signed in');
    }

    /**
     * A wrong code is a user-safe ERROR_VALIDATION the challenge screen renders inline.
     */
    public static function test_verify_2fa_refuses_a_wrong_code_as_a_validation_error()
    {
        $fixture = static::__portal_user_with_totp();

        Rsx_Portal_Two_Factor::begin_challenge($fixture['portal_user']);

        $result = Portal_Login_Controller::verify_2fa(Request::create('/_portal/_ajax/Portal_Login_Controller/verify_2fa', 'POST'), [
            'code' => '000000',
        ]);

        static::__assert_instance_of(Error_Response::class, $result);
        static::__assert_false(Portal_Session::is_logged_in());
    }

    // -------------------------------------------------------------------------
    // The passkey endpoint
    // -------------------------------------------------------------------------

    /**
     * A missing or unverifiable assertion is a user-safe ERROR_VALIDATION, never an exception
     * <Passkey_Sign_In> could not render.
     */
    public static function test_passkey_login_refuses_a_bad_assertion_as_a_validation_error()
    {
        static::__as_portal();

        $request = Request::create('/_portal/_ajax/Portal_Login_Controller/passkey_login', 'POST');

        static::__assert_instance_of(Error_Response::class, Portal_Login_Controller::passkey_login($request, []));

        Rsx_Portal_Two_Factor::begin_passkey_login();

        $result = Portal_Login_Controller::passkey_login($request, [
            'assertion' => [
                'id' => 'bm90LWEta2V5',
                'clientDataJSON' => 'e30',
                'authenticatorData' => 'AA',
                'signature' => 'AA',
            ],
        ]);

        static::__assert_instance_of(Error_Response::class, $result);
        static::__assert_false(Portal_Session::is_logged_in(), 'nobody is signed in');
    }
}
