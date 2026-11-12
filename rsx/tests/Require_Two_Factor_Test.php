<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\SignIn\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\SignIn\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Lib\SignIn\TwoFactor\Totp;
use Rsx\App\Frontend\Frontend_Bundle;
use Rsx\App\Login\Login_Controller;
use Rsx\Main;

/**
 * rsx.auth.require_two_factor - a staff member who signs in holding no second factor is held
 * on the Password & Security screen until they enroll one.
 *
 * PINNED: off (the shipped default), a password sign-in parks nothing and no page is held;
 * on, the same sign-in still signs in - the password is not refused - and parks the step;
 * Rsx\Main::pre_dispatch() then redirects a page request to the enrollment screen, lets the
 * enrollment screen itself and sign-out through, refuses every Ajax call outside the
 * enrollment allow-list with a coded unauthorized answer, publishes the hold to the browser
 * (page_data.two_factor_enrollment_owed), and forgets the step the moment the identity holds
 * a factor.
 */
class Require_Two_Factor_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one (Rsx_Test_Abstract::__as_browser()). */
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;

    private const PASSWORD = 'correct-horse-battery-staple';

    private static $__saved = null;

    public static function setup(): void
    {
        static::$__saved = config('rsx.auth.require_two_factor');
    }

    public static function teardown(): void
    {
        config(['rsx.auth.require_two_factor' => static::$__saved]);
        Session::logout();
        static::__reset_session();
    }

    /**
     * A verified identity with one enabled membership on the template's site and no second
     * factor, signed in through the password form.
     */
    private static function __sign_in_with_password(): Login_User_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $login_user = new Login_User_Model();
        $login_user->email = 'require_2fa_' . uniqid() . '@example.com';
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = 1;
        $login_user->is_verified = 1;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->site_id = self::SITE_ID;
        $user->email = $login_user->email;
        $user->first_name = 'Require';
        $user->last_name = 'Factor';
        $user->role_id = User_Model::ROLE_USER;
        $user->is_enabled = 1;
        User_Model::without_site_scope(fn () => $user->save());

        Login_Controller::index(Request::create('/login', 'POST', [
            'email' => $login_user->email,
            'password' => self::PASSWORD,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE,
        ]), []);

        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'the password signs in either way');

        return $login_user;
    }

    /** Main::pre_dispatch() for a page request to $path. */
    private static function __page(string $path)
    {
        $request = Request::create($path, 'GET');
        Rsx_Request_Channel::classify($request);

        return Main::pre_dispatch($request, []);
    }

    /** Main::pre_dispatch() for an Ajax call to $controller::$action. */
    private static function __ajax(string $controller, string $action)
    {
        $request = Request::create('/_ajax/_batch', 'POST');
        Rsx_Request_Channel::classify($request);

        return Main::pre_dispatch($request, ['controller' => $controller, 'action' => $action]);
    }

    public static function test_off_parks_nothing_and_holds_no_page()
    {
        config(['rsx.auth.require_two_factor' => false]);

        static::__sign_in_with_password();

        static::__assert_null(Session::get_value(Login_Controller::ENROLL_TWO_FACTOR_KEY), 'nothing is owed');
        static::__assert_null(static::__page('/dashboard'), 'the dashboard is served');
        static::__assert_null(static::__ajax('Frontend_Dashboard_Controller', 'dashboard_data'), 'and its data');
        static::__assert_false(isset(Frontend_Bundle::load_rsxapp_data()['two_factor_enrollment_owed']), 'and the browser is told nothing');
    }

    public static function test_on_a_page_request_is_held_on_the_enrollment_screen()
    {
        config(['rsx.auth.require_two_factor' => true]);

        static::__sign_in_with_password();

        static::__assert_true((bool) Session::get_value(Login_Controller::ENROLL_TWO_FACTOR_KEY), 'the enrollment is owed');

        $response = static::__page('/dashboard');

        static::__assert_not_null($response, 'the dashboard is not served');
        static::__assert_equals(
            Rsx::Route('Settings_Password_Security_Action'),
            parse_url($response->getTargetUrl(), PHP_URL_PATH),
            'held on Password & Security'
        );

        static::__assert_null(static::__page(Rsx::Route('Settings_Password_Security_Action')), 'the enrollment screen itself is served');
        static::__assert_null(static::__page(Rsx::Route('Login_Controller::logout')), 'sign-out is always reachable');

        static::__assert_null(static::__ajax('Rsx_Two_Factor_Controller', 'begin_totp'), 'the enrollment screen\'s Ajax carries on');
        static::__assert_null(static::__ajax('Frontend_Settings_Password_Security_Controller', 'get_sessions'), 'and the screen\'s own endpoints');
        static::__assert_null(static::__ajax('Spa_Session_Controller', 'get_state'), 'and the session machinery');

        static::__assert_true(Frontend_Bundle::load_rsxapp_data()['two_factor_enrollment_owed'] ?? false, 'the browser is told the step is owed');
    }

    public static function test_on_an_ordinary_ajax_call_is_refused()
    {
        config(['rsx.auth.require_two_factor' => true]);

        static::__sign_in_with_password();

        foreach ([['Frontend_Dashboard_Controller', 'dashboard_data'], ['Frontend_Clients_Controller', 'datagrid_fetch'],
                  ['Frontend_Notifications_Controller', 'get_dropdown']] as [$controller, $action]) {
            $refusal = static::__ajax($controller, $action);

            static::__assert_instance_of(Error_Response::class, $refusal, "{$controller}::{$action} is refused");
            static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $refusal->get_error_code(), 'as unauthorized');
            static::__assert_true($refusal->get_details()['two_factor_enrollment_required'] ?? false, 'coded as the enrollment hold');
            static::__assert_equals(Rsx::Route('Settings_Password_Security_Action'), $refusal->get_details()['redirect'] ?? null, 'naming the enrollment screen');
        }
    }

    public static function test_enrolling_a_factor_releases_the_hold()
    {
        config(['rsx.auth.require_two_factor' => true]);

        static::__sign_in_with_password();

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));

        static::__assert_null(static::__page('/dashboard'), 'the dashboard is served once a factor exists');
        static::__assert_null(static::__ajax('Frontend_Dashboard_Controller', 'dashboard_data'), 'and its data');
        static::__assert_null(Session::get_value(Login_Controller::ENROLL_TWO_FACTOR_KEY), 'and the step is forgotten');
    }
}
