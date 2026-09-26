<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Models\Portal_Password_Reset_Model;

/**
 * Portal credentials never serialize: a portal user's password hash, an invitation's code
 * and a password reset's token are all absent from toArray() and its JSON - the one
 * serializer behind window.rsxapp.user, model fetch and every model-built payload.
 */
class Portal_Credential_Never_Export_Test extends Rsx_Test_Abstract
{
    public static function test_a_portal_user_payload_has_no_password()
    {
        $user = new Portal_User_Model();
        $user->email = 'never-export@example.com';
        $user->password = 'probe-password-hash';

        static::__assert_false(array_key_exists('password', $user->toArray()), 'no password key');
        static::__assert_false(str_contains(json_encode($user), 'probe-password-hash'), 'no hash in the JSON');
    }

    public static function test_an_invitation_payload_has_no_code()
    {
        $invitation = new Portal_Invitation_Model();
        $invitation->email = 'never-export@example.com';
        $invitation->invitation_code = 'probe-invitation-code';

        static::__assert_false(array_key_exists('invitation_code', $invitation->toArray()), 'no invitation_code key');
        static::__assert_false(str_contains(json_encode($invitation), 'probe-invitation-code'), 'no code in the JSON');
    }

    public static function test_a_password_reset_payload_has_no_token()
    {
        $reset = new Portal_Password_Reset_Model();
        $reset->token = 'probe-reset-token';

        static::__assert_false(array_key_exists('token', $reset->toArray()), 'no token key');
        static::__assert_false(str_contains(json_encode($reset), 'probe-reset-token'), 'no token in the JSON');
    }
}
