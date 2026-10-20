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
use App\RSpade\Core\Auth\Auth_Password_Reset_Required_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Session\Portal_Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use Rsx\App\Login\Login_Controller;
use Rsx\Portal\Auth\Portal_Login_Controller;

/**
 * This application's two password forms - Login_Controller::index() (staff) and
 * Portal_Login_Controller::index() (client portal) - against an account flagged
 * password_reset_required.
 *
 * The framework pins the refusal itself (tests/session Password_Reset_Required_Test,
 * tests/portal Portal_Auth_Test); what is pinned HERE is what this application does with it:
 * the form shows the refusal as its error, naming the way through (an administrator for
 * staff, "Forgot your password?" on the portal), and nobody is signed in or parked behind a
 * challenge. And the portal form, now on the framework verifier, records an ordinary
 * password sign-in into the portal history.
 *
 * The controllers are called as static methods, as the dispatcher calls them; the view they
 * answer is inspected through its data.
 */
class Password_Reset_Required_Login_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const PASSWORD = 'correct-horse-battery-staple';

    public static function teardown(): void
    {
        Rsx_Turnstile::$force_verify_result_for_tests = null;
        Login_Throttle::reset('CLI');
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
        Login_Throttle::reset('CLI');
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

    /**
     * The staff form shows the refusal and the administrator route, and signs nobody in.
     */
    public static function test_the_staff_form_shows_the_refusal()
    {
        Login_Throttle::reset('CLI');
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

        $view = Login_Controller::index($request, []);
        $error = (string) $view->getData()['error'];

        static::__assert_contains(Auth_Password_Reset_Required_Exception::MESSAGE, $error);
        static::__assert_contains('administrator', $error);
        static::__assert_null(Session::get_login_user_id(), 'nobody is signed in');
    }

    /**
     * The portal form shows the refusal and the forgot-password route, signs nobody in and
     * parks nobody behind a challenge.
     */
    public static function test_the_portal_form_shows_the_refusal()
    {
        $portal_user = static::__portal_user(true);

        $view = static::__portal_post($portal_user);
        $error = (string) $view->getData()['error'];

        static::__assert_contains(Auth_Password_Reset_Required_Exception::MESSAGE, $error);
        static::__assert_contains('Forgot your password?', $error);
        static::__assert_false(Portal_Session::is_logged_in(), 'nobody is signed in');
        static::__assert_equals(
            Portal_Login_History::STATUS_FAILED_PASSWORD_RESET_REQUIRED,
            Portal_Login_History::get_history_for_user((int) $portal_user->id, 1)[0]['status'],
            'the framework recorded the refusal'
        );
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
