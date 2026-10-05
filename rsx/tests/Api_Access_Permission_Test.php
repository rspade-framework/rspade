<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * API use requires the can_use_api permission (User_Model::PERM_API_ACCESS).
 *
 * Every /api/v1 endpoint and the API Keys settings screen carry can_use_api, and a user
 * passes them only once the permission is GRANTED (no role holds it by default) and not
 * DENIED. Main::pre_dispatch() asks the same check of every bearer-key request, the file
 * routes included; that hook runs on the bearer identity the framework sets, which a test
 * cannot fake, so this class pins the check itself and the gates that name it.
 */
class Api_Access_Permission_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __member(): User_Model
    {
        $email = 'api_perm_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = 'not-a-login-path';
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Api';
        $user->last_name = 'Caller';
        $user->role_id = User_Model::ROLE_USER;
        $user->is_enabled = true;
        $user->is_api_access_enabled = 1;
        $user->save();

        return $user;
    }

    /**
     * Do the gates of a surface pass for this user?
     */
    private static function __passes(User_Model $user, string $target): bool
    {
        static::__reset_session();
        static::__acting_as_user((int) $user->id);

        return Auth_Gates::gates_pass(Auth_Gates::surface_gates($target), 'staff');
    }

    public static function test_the_api_and_key_minting_name_can_use_api()
    {
        foreach (['Clients_Api_Controller::list', 'Contacts_Api_Controller::get', 'Tasks_Api_Controller::list',
                  'Frontend_Settings_Api_Keys_Controller::create_key'] as $target) {
            static::__assert_true(
                in_array('can_use_api', Auth_Gates::surface_gates($target), true),
                "{$target} is gated on can_use_api"
            );
        }
    }

    public static function test_without_the_permission_the_api_is_refused()
    {
        $user = static::__member();

        static::__assert_false(static::__passes($user, 'Clients_Api_Controller::list'), 'no PERM_API_ACCESS: refused');
        static::__assert_false(static::__passes($user, 'Frontend_Settings_Api_Keys_Controller::create_key'), 'no key minting either');
    }

    public static function test_granting_the_permission_admits_the_api()
    {
        $user = static::__member();
        User_Permission_Model::grant((int) $user->id, User_Model::PERM_API_ACCESS);

        static::__assert_true(static::__passes($user, 'Clients_Api_Controller::list'), 'PERM_API_ACCESS granted: admitted');
        static::__assert_true(static::__passes($user, 'Frontend_Settings_Api_Keys_Controller::create_key'), 'and may mint a key');
    }

    public static function test_a_deny_row_refuses_the_api()
    {
        $user = static::__member();
        User_Permission_Model::grant((int) $user->id, User_Model::PERM_API_ACCESS);
        User_Permission_Model::deny((int) $user->id, User_Model::PERM_API_ACCESS);

        static::__assert_false(static::__passes($user, 'Clients_Api_Controller::list'), 'a DENY row wins: refused');
    }
}
