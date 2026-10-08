<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use Rsx\Portal\Auth\Portal_Login_Controller;

/**
 * This application's emailed portal sign-in codes (rsx.portal.emailed_sign_in_codes):
 * Portal_Login_Controller::challenge_accepts() is the policy, send_code() issues and emails
 * each code.
 *
 * PINNED: switched off, a portal user without a factor signs straight in (the shipped
 * default); switched on, the same password sign-in is parked behind a challenge accepting an
 * emailed code; send_code() queues a SECURITY email to the pending user's own address and
 * stops at MAX_CODES_PER_SIGN_IN; the emailed code completes the sign-in.
 */
class Portal_Emailed_Sign_In_Codes_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const PASSWORD = 'correct-horse-battery-staple';

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        config(['rsx.portal.emailed_sign_in_codes' => false]);
        Rsx_Turnstile::$force_verify_result_for_tests = null;
        Login_Throttle::reset('CLI');
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Session::logout();
        static::__reset_session();
    }

    /**
     * A portal user with no second factor, on a portal request, anonymous.
     */
    private static function __portal_user(): Portal_User_Model
    {
        Login_Throttle::reset('CLI');
        Portal_Session::set_site_id(self::SITE_ID);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_portal_user_id(0);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'portal_emailed_' . uniqid() . '@example.com';
        $portal_user->set_password(self::PASSWORD);
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        Rsx_Portal_Two_Factor::clear_failures($portal_user);

        return $portal_user;
    }

    private static function __password_login(Portal_User_Model $portal_user)
    {
        if (Rsx_Turnstile::is_enabled()) {
            Rsx_Turnstile::$force_verify_result_for_tests = true;
        }

        $request = Request::create(Rsx_Portal_Url::prefix() . '/login', 'POST', [
            'email' => $portal_user->email,
            'password' => self::PASSWORD,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::is_enabled() ? 'test-token' : Rsx_Turnstile::INACTIVE,
        ]);

        return Portal_Login_Controller::index($request, []);
    }

    private static function __send_code()
    {
        return Portal_Login_Controller::send_code(Request::create(Rsx_Portal_Url::prefix() . '/_ajax/Portal_Login_Controller/send_code', 'POST'), []);
    }

    /**
     * Off (the shipped default): no factor, no challenge - the password signs in.
     */
    public static function test_switched_off_a_password_signs_straight_in()
    {
        $portal_user = static::__portal_user();

        static::__assert_false(Portal_Login_Controller::challenge_accepts($portal_user), 'no challenge is owed');

        static::__password_login($portal_user);

        static::__assert_true(Portal_Session::is_logged_in(), 'signed in');
    }

    /**
     * On: the password parks the user behind a challenge that accepts an emailed code; the
     * code send_code() emails signs them in.
     */
    public static function test_switched_on_the_emailed_code_completes_the_sign_in()
    {
        config(['rsx.portal.emailed_sign_in_codes' => true]);
        $portal_user = static::__portal_user();

        static::__password_login($portal_user);

        static::__assert_false(Portal_Session::is_logged_in(), 'parked, not signed in');
        static::__assert_true(Rsx_Portal_Two_Factor::challenge_pending()['has_issued_code'], 'an emailed code is accepted');

        static::__assert_null(static::__send_code(), 'sent');

        $row = Email_Queue_Model::where('to_address', $portal_user->email)->orderBy('id', 'desc')->first();

        static::__assert_not_null($row, 'the code is queued to the pending user');
        static::__assert_equals(Email_Queue_Model::CATEGORY_SECURITY, (int) $row->category_id);

        $code = $row->template_data['code'];

        $result = Portal_Login_Controller::verify_2fa(Request::create(Rsx_Portal_Url::prefix() . '/_ajax/Portal_Login_Controller/verify_2fa', 'POST'), [
            'code' => $code,
        ]);

        static::__assert_array_has_key('redirect', $result);
        static::__assert_equals((int) $portal_user->id, (int) Portal_Session::get_portal_user_id(), 'signed in by the emailed code');
    }

    /**
     * send_code() stops at MAX_CODES_PER_SIGN_IN with a message the screen renders.
     */
    public static function test_send_code_stops_at_the_cap()
    {
        config(['rsx.portal.emailed_sign_in_codes' => true]);
        $portal_user = static::__portal_user();

        static::__password_login($portal_user);

        for ($i = 0; $i < Portal_Login_Controller::MAX_CODES_PER_SIGN_IN; $i++) {
            static::__assert_null(static::__send_code());
        }

        static::__assert_instance_of(Error_Response::class, static::__send_code(), 'refused past the cap');
    }
}
