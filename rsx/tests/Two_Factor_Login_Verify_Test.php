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
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Totp;
use Rsx\App\Login\Login_Controller;

/**
 * This application's half of the challenge: Login_Controller::verify_2fa.
 *
 * WHY THIS SITS IN THE APPLICATION SUITE. verify_challenge() is deliberately not a framework
 * endpoint - where a signed-in user lands is application logic, so the app owns the
 * verification endpoint and <Two_Factor_Challenge> is pointed at it. The endpoint, its
 * destinations and its screens are all declared here, so the tests are here too. What they
 * pin is the contract the framework component depends on: one argument shape in, {redirect}
 * out, and every failure a user-safe ERROR_VALIDATION rather than an exception the challenge
 * screen cannot render.
 *
 * ENDPOINTS ARE CALLED AS STATIC METHODS, which is what the dispatcher does once the gate has
 * passed - so the Ajax envelope is not applied here and the assertions are on the RAW return.
 *
 * THE DESTINATION IS THE OTHER HALF. post_login_destination() is reached through this
 * endpoint, which is the honest way to test it: what matters is not the
 * function but that a challenge completed with an invite in flight lands on the invite, a
 * single-site identity lands on the dashboard, and a membership-less one lands on the
 * unauthorized screen.
 */
class Two_Factor_Login_Verify_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    /**
     * Per-email failure counter keys created here, deleted in teardown - they are redis keys
     * outside the per-test transaction.
     */
    private static array $counter_keys_used = [];

    public static function teardown()
    {
        Login_Throttle::reset('CLI');

        Session::logout();
        static::__reset_session();

        if (!empty(static::$counter_keys_used)) {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
            $redis->select(0);

            foreach (static::$counter_keys_used as $key) {
                $redis->del('cache:' . sha1($key));
            }

            $redis->close();
            static::$counter_keys_used = [];
        }
    }

    private static function __ajax_request(): Request
    {
        return Request::create('/_ajax/Login_Controller/verify_2fa', 'POST');
    }

    private static function __start_anonymous(): void
    {
        Login_Throttle::reset('CLI');
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    /**
     * An identity with a live TOTP factor and a challenge already pending, exactly as
     * Login_Controller::index() leaves things after a correct password.
     *
     * @return array {login_user, secret, email}
     */
    private static function __pending_challenge(string $suffix): array
    {
        static::__start_anonymous();

        $email = 'verify_' . $suffix . '_' . uniqid() . '@example.com';
        static::$counter_keys_used[] = 'login_failures:email:' . sha1(strtolower(trim($email)));

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        Session::set_login_user_id((int) $login_user->id);

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));

        static::__start_anonymous();

        Rsx_Two_Factor::begin_challenge($login_user);

        return ['login_user' => $login_user, 'secret' => $started['secret'], 'email' => $email];
    }

    /**
     * A code live for this seed and not the timestep the enrollment consumed.
     */
    private static function __unspent_code(string $secret): string
    {
        return Totp::code_for($secret, intdiv(time(), Totp::PERIOD) + 1);
    }

    /**
     * One enabled site membership, so the destination resolves to the dashboard.
     */
    private static function __give_one_site(Login_User_Model $login_user): User_Model
    {
        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->site_id = 1;
        $user->email = $login_user->email;
        $user->first_name = 'Verify';
        $user->last_name = 'Fixture';
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    // -------------------------------------------------------------------------
    // The happy path
    // -------------------------------------------------------------------------

    /**
     * tfa-app-01: a live code signs the identity in and answers the ONE key the challenge
     * component reads. A response with no redirect makes the component throw, so the shape
     * is the contract.
     */
    public static function test_a_live_code_answers_with_a_redirect_and_signs_in()
    {
        $fixture = static::__pending_challenge('happy');
        static::__give_one_site($fixture['login_user']);

        $result = Login_Controller::verify_2fa(static::__ajax_request(), [
            'code' => static::__unspent_code($fixture['secret']),
        ]);

        static::__assert_array_has_key('redirect', $result, 'the component follows exactly this key');
        static::__assert_not_empty($result['redirect']);
        static::__assert_true(Session::is_logged_in(), 'the challenge signed the identity in');
        static::__assert_equals(
            (int) $fixture['login_user']->id,
            (int) Session::get_login_user_id(),
            'and signed in the identity that was pending, not another'
        );
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'the pending value is spent');
    }

    /**
     * tfa-app-02: one enabled membership sets the site and lands on the dashboard.
     */
    public static function test_a_single_site_identity_lands_on_the_dashboard()
    {
        $fixture = static::__pending_challenge('single_site');
        static::__give_one_site($fixture['login_user']);

        $result = Login_Controller::verify_2fa(static::__ajax_request(), [
            'code' => static::__unspent_code($fixture['secret']),
        ]);

        static::__assert_equals(Rsx::Route('Dashboard_Index_Action'), $result['redirect']);
    }

    /**
     * tfa-app-03: no membership anywhere lands on the site-unauthorized screen, which is
     * where the logout happens. Signing the identity in and then dropping them on a page
     * they cannot use would be the alternative, and it is not one.
     */
    public static function test_an_identity_with_no_site_lands_on_site_unauthorized()
    {
        $fixture = static::__pending_challenge('no_site');

        $result = Login_Controller::verify_2fa(static::__ajax_request(), [
            'code' => static::__unspent_code($fixture['secret']),
        ]);

        static::__assert_equals(Rsx::Route('Site_Unauthorized_Controller'), $result['redirect']);
    }

    /**
     * tfa-app-04: THE INVITE SURVIVES THE CHALLENGE. The component posts {code} and nothing
     * else, so the code the login form carried rides the session across the two steps; a
     * user who clicked an invitation link must still finish the invitation after the second
     * factor, not be dropped on a dashboard having lost it.
     */
    public static function test_a_parked_invite_code_is_consumed_and_wins_the_destination()
    {
        $fixture = static::__pending_challenge('invite');
        static::__give_one_site($fixture['login_user']);

        Session::put_value(Login_Controller::INVITE_CODE_KEY, 'INVITE-XYZ');

        $result = Login_Controller::verify_2fa(static::__ajax_request(), [
            'code' => static::__unspent_code($fixture['secret']),
        ]);

        static::__assert_equals(
            Rsx::Route('Accept_Invite_Controller::index', ['code' => 'INVITE-XYZ']),
            $result['redirect'],
            'the invite beats the dashboard'
        );
        static::__assert_null(
            Session::get_value(Login_Controller::INVITE_CODE_KEY),
            'and is spent - it is good for exactly one sign-in'
        );
    }

    // -------------------------------------------------------------------------
    // The refusals
    // -------------------------------------------------------------------------

    /**
     * tfa-app-05: a wrong code is a VALIDATION error carrying a user-safe message, and
     * NOBODY is signed in. The challenge survives for a retry - the facade owns that rule,
     * and the endpoint must not turn a retryable failure into a dead screen.
     */
    public static function test_a_wrong_code_is_a_validation_error_and_signs_nobody_in()
    {
        $fixture = static::__pending_challenge('wrong');

        $response = Login_Controller::verify_2fa(static::__ajax_request(), ['code' => '000000']);

        static::__assert_instance_of(Error_Response::class, $response);
        static::__assert_equals(Ajax::ERROR_VALIDATION, $response->get_error_code());
        static::__assert_not_empty($response->get_reason(), 'the screen renders this message inline');
        static::__assert_false(Session::is_logged_in(), 'a wrong answer is not a login');
        static::__assert_not_null(Rsx_Two_Factor::challenge_pending(), 'and the challenge is still retryable');
    }

    /**
     * tfa-app-06: an EMPTY answer is refused the same way. The component will not submit a
     * blank box, but the endpoint is reachable without it.
     */
    public static function test_an_empty_answer_is_refused()
    {
        static::__pending_challenge('empty');

        $response = Login_Controller::verify_2fa(static::__ajax_request(), []);

        static::__assert_instance_of(Error_Response::class, $response);
        static::__assert_equals(Ajax::ERROR_VALIDATION, $response->get_error_code());
        static::__assert_false(Session::is_logged_in());
    }

    /**
     * tfa-app-07: nothing pending is a user-safe refusal, not an exception. An expired
     * window is the state most likely to be hit by a real user who walked away, and it has
     * to render as a sentence rather than a 500.
     */
    public static function test_no_pending_challenge_is_a_user_safe_refusal()
    {
        static::__start_anonymous();

        $response = Login_Controller::verify_2fa(static::__ajax_request(), ['code' => '123456']);

        static::__assert_instance_of(Error_Response::class, $response);
        static::__assert_equals(Ajax::ERROR_VALIDATION, $response->get_error_code());
        static::__assert_not_empty($response->get_reason());
        static::__assert_false(Session::is_logged_in());
    }
}
