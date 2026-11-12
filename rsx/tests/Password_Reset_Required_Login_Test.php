<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Portal_Auth;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Portal_Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Portal\Portal_User_Model;
use App\RSpade\Lib\Portal\Rsx_Portal;
use App\RSpade\Lib\Portal\Rsx_Portal_Url;
use App\RSpade\Lib\SignIn\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\SignIn\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Lib\SignIn\TwoFactor\Totp;
use Rsx\App\Login\Login_Controller;
use Rsx\Portal\Auth\Portal_Login_Controller;

/**
 * This application's two password forms - Login_Controller::index() (staff) and
 * Portal_Login_Controller::index() (client portal) - against an account flagged
 * password_reset_required.
 *
 * The framework pins the refusal itself (tests/session Password_Reset_Required_Test,
 * tests/portal Portal_Auth_Test); what is pinned HERE is what this application does with it:
 * both forms send the visitor to "Choose a new password" with the identity parked and nobody
 * signed in (Staff_Password_Reset_Test follows the staff step through; the portal step is
 * followed through here: the current password refused, the new one saved with the flag
 * cleared, then the sign-in completed - or, for a portal user holding a second factor, the
 * challenge next with nobody signed in). And the portal form, on the framework verifier,
 * records an ordinary password sign-in into the portal history.
 *
 * The controllers are called as static methods, as the dispatcher calls them; the view they
 * answer is inspected through its data.
 */
class Password_Reset_Required_Login_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one (Rsx_Test_Abstract::__as_browser()). */
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;

    private const PASSWORD = 'correct-horse-battery-staple';

    private const NEW_PASSWORD = 'the-clients-own-password';

    public static function teardown(): void
    {
        Rsx_Turnstile::$force_verify_result_for_tests = null;
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Session::logout();
        static::__reset_session();
    }

    private static function __turnstile_token(): string
    {
        if (!Rsx_Turnstile::is_enabled()) {
            return Rsx_Turnstile::INACTIVE;
        }

        Rsx_Turnstile::$force_verify_result_for_tests = true;

        return 'test-token';
    }

    private static function __as_portal(): void
    {
        Portal_Session::set_site_id(self::SITE_ID);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_portal_user_id(0);
    }

    private static function __portal_user(bool $flagged): Portal_User_Model
    {
        static::__as_portal();

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'portal_reset_' . uniqid() . '@example.com';
        $portal_user->set_password(self::PASSWORD);
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;

        if ($flagged) {
            $portal_user->require_password_reset();
        }

        $portal_user->save();

        return $portal_user;
    }

    private static function __portal_post(Portal_User_Model $portal_user)
    {
        $request = Request::create(Rsx_Portal_Url::prefix() . '/login', 'POST', [
            'email' => $portal_user->email,
            'password' => self::PASSWORD,
            Rsx_Turnstile::FIELD => static::__turnstile_token(),
        ]);

        return Portal_Login_Controller::index($request, []);
    }

    private static function __portal_new_password_post(string $password, ?string $confirmation = null)
    {
        $request = Request::create(Rsx_Portal_Url::prefix() . '/login/new-password', 'POST', [
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);

        return Portal_Login_Controller::new_password($request, []);
    }

    private static function __path($response): string
    {
        return (string) parse_url($response->getTargetUrl(), PHP_URL_PATH);
    }

    /**
     * The staff form sends a flagged identity to "Choose a new password": nobody is signed in,
     * the identity is parked for the step.
     */
    public static function test_the_staff_form_sends_the_user_to_choose_a_new_password()
    {
        Session::logout();
        static::__reset_session();
        static::__acting_as_site(self::SITE_ID);

        $email = 'staff_reset_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->require_password_reset();
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->site_id = self::SITE_ID;
        $user->email = $email;
        $user->first_name = 'Reset';
        $user->last_name = 'Fixture';
        $user->is_enabled = true;
        $user->save();

        Session::logout();
        static::__reset_session();

        $request = Request::create('/login', 'POST', [
            'email' => $email,
            'password' => self::PASSWORD,
            Rsx_Turnstile::FIELD => static::__turnstile_token(),
        ]);

        $response = Login_Controller::index($request, []);

        static::__assert_equals(Rsx::Route('Login_Controller::new_password'), parse_url($response->getTargetUrl(), PHP_URL_PATH));
        static::__assert_null(Session::get_login_user_id(), 'nobody is signed in');
        static::__assert_equals(['email' => $email], RsxAuth::forced_password_change_pending(), 'the identity is parked');
    }

    /**
     * The portal form sends a flagged portal user to "Choose a new password": nobody is signed
     * in, the portal user is parked, and the framework recorded the refusal.
     */
    public static function test_the_portal_form_sends_the_user_to_choose_a_new_password()
    {
        $portal_user = static::__portal_user(true);

        $response = static::__portal_post($portal_user);

        static::__assert_equals(
            (string) parse_url(Rsx_Portal::Route('Portal_Login_Controller::new_password'), PHP_URL_PATH),
            static::__path($response)
        );
        static::__assert_false(Portal_Session::is_logged_in(), 'nobody is signed in');
        static::__assert_equals(['email' => $portal_user->email], Portal_Auth::forced_password_change_pending(), 'the portal user is parked');
        static::__assert_equals(
            Portal_Login_History::STATUS_FAILED_PASSWORD_RESET_REQUIRED,
            Portal_Login_History::get_history_for_user((int) $portal_user->id, 1)[0]['status'],
            'the framework recorded the refusal'
        );
    }

    /**
     * The portal step refuses the current password and a mismatched confirmation, then saves
     * the new password (flag cleared) and signs in through complete_sign_in().
     */
    public static function test_a_portal_forced_change_completes_the_sign_in()
    {
        $portal_user = static::__portal_user(true);

        static::__portal_post($portal_user);

        $refused = static::__portal_new_password_post(self::PASSWORD);
        static::__assert_contains('different from your current one', (string) $refused->getData()['error'], 'the current password is refused');

        $mismatch = static::__portal_new_password_post(self::NEW_PASSWORD, 'something-else-entirely');
        static::__assert_equals('Passwords do not match', $mismatch->getData()['error']);

        $done = static::__portal_new_password_post(self::NEW_PASSWORD);

        // The dashboard answers at the portal root, so the trailing slash is not compared.
        static::__assert_equals(
            rtrim((string) parse_url(Rsx_Portal::Route('Portal_Dashboard_Action'), PHP_URL_PATH), '/'),
            rtrim(static::__path($done), '/'),
            'landed as any sign-in lands'
        );
        static::__assert_equals((int) $portal_user->id, (int) Portal_Session::get_portal_user_id(), 'signed in');
        static::__assert_null(Portal_Auth::forced_password_change_pending(), 'nothing is parked any more');

        $row = DB::table('portal_users')->where('id', $portal_user->id)->first();
        static::__assert_equals(0, (int) $row->password_reset_required, 'the flag is cleared');
        static::__assert_true(Hash::check(self::NEW_PASSWORD, $row->password), 'the new password works');
        static::__assert_false(Hash::check(self::PASSWORD, $row->password), 'the old one no longer does');
    }

    /**
     * A flagged portal user holding a second factor chooses the password FIRST and then faces
     * the challenge - nobody is signed in until it is answered.
     */
    public static function test_a_portal_forced_change_is_followed_by_the_second_factor()
    {
        $portal_user = static::__portal_user(true);

        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);
        $started = Rsx_Portal_Two_Factor::begin_totp_enrollment();
        Rsx_Portal_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));
        Portal_Session::cli_set_portal_user_id(0);

        static::__portal_post($portal_user);
        $response = static::__portal_new_password_post(self::NEW_PASSWORD);

        static::__assert_equals(
            (string) parse_url(Rsx_Portal::Route('Portal_Login_Controller::verify'), PHP_URL_PATH),
            static::__path($response),
            'the challenge comes next'
        );
        static::__assert_false(Portal_Session::is_logged_in(), 'nobody is signed in before the second factor');
        static::__assert_not_null(Rsx_Portal_Two_Factor::challenge_pending(), 'the challenge is pending');
        static::__assert_equals(0, (int) DB::table('portal_users')->where('id', $portal_user->id)->value('password_reset_required'), 'the password was changed');
    }

    /**
     * An ordinary portal password sign-in signs in and writes one success row.
     */
    public static function test_a_portal_password_sign_in_is_recorded()
    {
        $portal_user = static::__portal_user(false);

        static::__portal_post($portal_user);

        static::__assert_equals((int) $portal_user->id, (int) Portal_Session::get_portal_user_id(), 'signed in');
        static::__assert_equals(
            [Portal_Login_History::STATUS_SUCCESS],
            array_column(Portal_Login_History::get_history_for_user((int) $portal_user->id), 'status'),
            'one success row'
        );
    }
}
