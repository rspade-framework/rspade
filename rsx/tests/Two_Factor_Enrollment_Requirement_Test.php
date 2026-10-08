<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Totp;
use Rsx\App\Login\Two_Factor_Enrollment_Requirement;

/**
 * Two_Factor_Enrollment_Requirement - an administrator-required second factor, as a login
 * requirement.
 *
 * PINNED: it is unmet only for a flagged identity with no confirmed factor, and enrolling a
 * factor meets it. How an unmet requirement keeps the identity out of the site is the
 * framework's, pinned in its own suite (tests/login_requirements).
 */
class Two_Factor_Enrollment_Requirement_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Session::logout();
        static::__reset_session();
    }

    private static function __make_user(bool $flagged): User_Model
    {
        $email = 'tfa_requirement_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make('password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Fixture';
        $user->last_name = 'Flagged';
        $user->is_enabled = 1;
        $user->is_2fa_required = $flagged ? 1 : 0;
        $user->save();

        return $user;
    }

    public static function test_only_a_flagged_identity_without_a_factor_is_unmet()
    {
        static::__assert_true(Two_Factor_Enrollment_Requirement::is_satisfied(static::__make_user(false)), 'not flagged');
        static::__assert_false(Two_Factor_Enrollment_Requirement::is_satisfied(static::__make_user(true)), 'flagged, no factor');
    }

    public static function test_enrolling_a_factor_meets_it()
    {
        $user = static::__make_user(true);

        Session::set_login_user_id((int) $user->login_user_id);
        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));

        static::__assert_true(Two_Factor_Enrollment_Requirement::is_satisfied($user), 'met once a factor is enrolled');
    }
}
