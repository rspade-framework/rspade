<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Portal_Login_History;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Portal\Settings\Portal_Settings_Controller;

/**
 * The portal account screen's Change Password (Portal_Settings_Controller::change_password()).
 *
 * What is pinned: every field rule is answered together; a wrong current password is counted
 * through the portal login history's failure counters (which feed the throttle) and changes
 * nothing; a correct change saves the new password.
 *
 * THE THROTTLE REFUSAL ITSELF IS NOT DRIVEN HERE: Session::get_client_ip() is null in CLI by
 * design, so the throttle inside the endpoint cannot trip from a PHP test (the framework's
 * Two_Factor_Challenge_Test records the same limit). It is verified over HTTP, in a real
 * portal browser session; the mechanism is pinned by the framework's Login_Throttle_Test.
 */
class Portal_Change_Password_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const PASSWORD = 'current-password-1';

    private static ?string $email = null;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        if (static::$email !== null) {
            Login_Throttle::reset(static::$email);
            static::$email = null;
        }
        Portal_Session::cli_set_portal_user_id(0);
        static::__reset_session();
    }

    public static function test_every_field_rule_is_answered_together()
    {
        static::__signed_in_portal_user();

        $response = static::__change('', 'short', 'different');

        static::__assert_validation($response, 'the empty form');
        $fields = $response->get_metadata();
        static::__assert_true(isset($fields['current_password'], $fields['new_password'], $fields['confirm_password']), 'all three fields answered at once');
    }

    public static function test_a_wrong_current_password_is_refused_and_recorded()
    {
        $user = static::__signed_in_portal_user();

        $response = static::__change('not-the-password', 'new-password-2', 'new-password-2');

        static::__assert_validation($response, 'a wrong current password');
        static::__assert_equals('Current password is incorrect', $response->get_metadata()['current_password'] ?? null);

        static::__assert_equals(1, Portal_Login_History::get_failed_attempts_count($user->email), 'counted as a failed attempt for the account');
        static::__assert_true($user->fresh()->check_password(self::PASSWORD), 'the password is unchanged');
    }

    public static function test_a_correct_change_saves_the_new_password()
    {
        $user = static::__signed_in_portal_user();

        $response = static::__change(self::PASSWORD, 'new-password-2', 'new-password-2');

        static::__assert_true(is_array($response), 'accepted');
        static::__assert_true($user->fresh()->check_password('new-password-2'), 'the new password signs in');
    }

    private static function __signed_in_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'portal_change_password_' . uniqid() . '@example.com';
        $user->set_password(self::PASSWORD);
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        static::$email = $user->email;
        Portal_Session::cli_set_portal_user_id((int) $user->id);

        return $user;
    }

    private static function __change(string $current, string $new_password, string $confirm)
    {
        return Portal_Settings_Controller::change_password(new Request(), [
            'current_password' => $current,
            'new_password' => $new_password,
            'confirm_password' => $confirm,
        ]);
    }

    private static function __assert_validation($response, string $message): void
    {
        static::__assert_true($response instanceof Error_Response, "{$message}: expected an error response");
        static::__assert_equals(Ajax::ERROR_VALIDATION, $response->get_error_code(), $message);
    }
}
