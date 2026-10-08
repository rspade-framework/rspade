<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Login;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Login\Login_Requirement_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;

/**
 * Two_Factor_Enrollment_Requirement - an administrator requires a second factor on this
 * account, and it has none yet.
 *
 * users.is_2fa_required is this application's own policy column (an administrator sets it
 * in Settings > User Management); the framework decides only whether an identity HAS a
 * factor. While the flag is set and no factor is enrolled, the user is signed in but reads
 * as signed out everywhere except the setup screen and the enrollment endpoints below - so
 * the requirement cannot be stepped around by typing another URL or calling an endpoint.
 * The moment a factor is enrolled, the next request lets them in.
 *
 * Not while impersonating (the framework default): the impersonator signed in as
 * themselves, and the framework refuses every enrollment path while impersonating anyway.
 *
 * See: php artisan rsx:man login_requirements
 */
class Two_Factor_Enrollment_Requirement extends Login_Requirement_Abstract
{
    const REALM = 'staff';

    /**
     * @param \User_Model $user
     */
    public static function is_satisfied(Rsx_Model_Abstract $user): bool
    {
        return !$user->is_2fa_required || Rsx_Two_Factor::is_enabled((int) $user->login_user_id);
    }

    public static function screen(): string
    {
        return 'Login_Controller::two_factor_setup';
    }

    /**
     * The two enrollment ceremonies the setup screen offers - an authenticator app or a
     * passkey - and nothing else.
     */
    public static function surfaces(): array
    {
        return [
            'Rsx_Two_Factor_Controller::totp_begin',
            'Rsx_Two_Factor_Controller::totp_confirm',
            'Rsx_Two_Factor_Controller::passkey_register_begin',
            'Rsx_Two_Factor_Controller::passkey_register_confirm',
        ];
    }
}
