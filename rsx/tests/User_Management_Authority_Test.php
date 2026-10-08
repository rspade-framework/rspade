<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Settings\UserManagement\Frontend_Settings_User_Management_Controller;

/**
 * Equal-or-lower administration in the user-management screens.
 *
 * A caller creates, edits, re-roles, disables and reveals invitation links only for users
 * whose role is EQUAL to or LOWER than their own - asked of the target's CURRENT role and of
 * any NEW role. Each refusal is an ERROR_UNAUTHORIZED response and leaves the target
 * untouched; the cases below are the escalations that refusal exists to stop (a Site Admin
 * demoting the Root Admin, clearing a superior's second-factor rule, promoting a Manager to
 * Site Owner, reading a superior's invitation link).
 */
class User_Management_Authority_Test extends Rsx_Test_Abstract
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

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    /**
     * A signed-up member of the site at a role (login identity + membership).
     */
    private static function __member(int $role_id, string $label): User_Model
    {
        $email = 'authority_' . $label . '_' . uniqid() . '@example.com';

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
        $user->first_name = ucfirst($label);
        $user->last_name = 'Member';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->invite_accepted_at = now();
        $user->save();

        return $user;
    }

    /**
     * A pending invitation at a role (no login identity yet).
     */
    private static function __pending_invite(int $role_id): User_Model
    {
        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = null;
        $user->email = 'authority_invite_' . uniqid() . '@example.com';
        $user->first_name = 'Pending';
        $user->last_name = 'Invitee';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->invite_code = 'code_' . bin2hex(random_bytes(8));
        $user->invite_expires_at = now()->addDays(7);
        $user->save();

        return $user;
    }

    private static function __act_as(User_Model $user): void
    {
        static::__reset_session();
        static::__acting_as_user((int) $user->id);
    }

    private static function __save_params(User_Model $target, int $role_id): array
    {
        return [
            'id' => $target->id,
            'email' => 'changed_' . uniqid() . '@example.com',
            'first_name' => 'Changed',
            'last_name' => 'Name',
            'role_id' => $role_id,
        ];
    }

    private static function __assert_unauthorized($result, string $message): void
    {
        static::__assert_instance_of(Error_Response::class, $result, $message);
        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $result->get_error_code(), $message);
    }

    private static function __reload(User_Model $user): User_Model
    {
        return User_Model::find($user->id);
    }

    // ---------------------------------------------------------------------
    // the matrix
    // ---------------------------------------------------------------------

    public static function test_every_administering_role_administers_its_own_role_and_none_above()
    {
        foreach (User_Model::role_id__enum() as $role_id => $definition) {
            $can_admin = array_map('intval', $definition['can_admin_roles'] ?? []);

            if (empty($can_admin)) {
                continue;
            }

            static::__assert_true(in_array((int) $role_id, $can_admin, true), "role {$role_id} administers its own role");

            foreach ($can_admin as $administered) {
                static::__assert_true($administered >= (int) $role_id, "role {$role_id} administers nothing above it ({$administered})");
            }
        }
    }

    // ---------------------------------------------------------------------
    // the target's CURRENT role
    // ---------------------------------------------------------------------

    public static function test_a_site_admin_cannot_change_a_superior()
    {
        $owner = static::__member(User_Model::ROLE_SITE_OWNER, 'owner');
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');
        static::__act_as($admin);

        $result = Frontend_Settings_User_Management_Controller::save_user(new Request(), static::__save_params($owner, User_Model::ROLE_MANAGER));
        static::__assert_unauthorized($result, 'demoting a Site Owner is refused');

        $reloaded = static::__reload($owner);
        static::__assert_equals(User_Model::ROLE_SITE_OWNER, (int) $reloaded->role_id, 'the role is untouched');
        static::__assert_equals($owner->email, $reloaded->email, 'the email is untouched');

        static::__assert_unauthorized(
            Frontend_Settings_User_Management_Controller::get_user_for_edit(new Request(), ['user_id' => $owner->id]),
            'the edit form of a superior is refused'
        );
        static::__assert_unauthorized(
            Frontend_Settings_User_Management_Controller::set_user_enabled(new Request(), ['id' => $owner->id, 'is_enabled' => 0]),
            'disabling a superior is refused'
        );
        static::__assert_unauthorized(
            Frontend_Settings_User_Management_Controller::revoke_user_api_key(new Request(), ['id' => $owner->id, 'key_id' => 1]),
            "revoking a superior's key is refused"
        );
        static::__assert_equals(1, (int) static::__reload($owner)->is_enabled, 'the superior stays enabled');
    }

    public static function test_a_site_admin_manages_a_peer()
    {
        $peer = static::__member(User_Model::ROLE_SITE_ADMIN, 'peer');
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');
        static::__act_as($admin);

        $params = static::__save_params($peer, User_Model::ROLE_MANAGER);
        $result = Frontend_Settings_User_Management_Controller::save_user(new Request(), $params);

        static::__assert_false($result instanceof Error_Response, 'editing a peer is permitted');
        static::__assert_equals(User_Model::ROLE_MANAGER, (int) static::__reload($peer)->role_id, 'the peer is re-roled');
    }

    // ---------------------------------------------------------------------
    // the NEW role
    // ---------------------------------------------------------------------

    public static function test_a_site_admin_cannot_promote_above_their_own_role()
    {
        $manager = static::__member(User_Model::ROLE_MANAGER, 'manager');
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');
        static::__act_as($admin);

        $result = Frontend_Settings_User_Management_Controller::save_user(new Request(), static::__save_params($manager, User_Model::ROLE_SITE_OWNER));

        static::__assert_instance_of(Error_Response::class, $result, 'promoting to Site Owner is refused');
        static::__assert_equals(User_Model::ROLE_MANAGER, (int) static::__reload($manager)->role_id, 'the role is untouched');

        $created = Frontend_Settings_User_Management_Controller::add_user(new Request(), [
            'email' => 'authority_new_' . uniqid() . '@example.com',
            'first_name' => 'New',
            'last_name' => 'Owner',
            'role_id' => User_Model::ROLE_SITE_OWNER,
        ]);
        static::__assert_instance_of(Error_Response::class, $created, 'inviting a Site Owner is refused');
    }

    public static function test_a_system_assigned_role_is_kept_but_never_handed_out()
    {
        $root = static::__member(User_Model::ROLE_ROOT_ADMIN, 'root');
        $other_root = static::__member(User_Model::ROLE_ROOT_ADMIN, 'otherroot');
        $manager = static::__member(User_Model::ROLE_MANAGER, 'manager');
        static::__act_as($root);

        $kept = Frontend_Settings_User_Management_Controller::save_user(new Request(), static::__save_params($other_root, User_Model::ROLE_ROOT_ADMIN));
        static::__assert_false($kept instanceof Error_Response, 'a Root Admin edits another Root Admin who keeps the role');

        $handed = Frontend_Settings_User_Management_Controller::save_user(new Request(), static::__save_params($manager, User_Model::ROLE_ROOT_ADMIN));
        static::__assert_instance_of(Error_Response::class, $handed, 'Root Admin is never assigned through the form');
        static::__assert_equals(User_Model::ROLE_MANAGER, (int) static::__reload($manager)->role_id, 'the manager keeps their role');
    }

    // ---------------------------------------------------------------------
    // invitation links
    // ---------------------------------------------------------------------

    public static function test_an_invitation_link_above_the_callers_role_is_hidden()
    {
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');
        $owner_invite = static::__pending_invite(User_Model::ROLE_SITE_OWNER);
        $manager_invite = static::__pending_invite(User_Model::ROLE_MANAGER);
        static::__act_as($admin);

        $hidden = Frontend_Settings_User_Management_Controller::send_invite(new Request(), ['user_id' => $owner_invite->id]);
        static::__assert_true($hidden['invite_url_hidden'], 'the link of a superior role is withheld');
        static::__assert_null($hidden['invite_url'], 'no link is returned');

        $shown = Frontend_Settings_User_Management_Controller::send_invite(new Request(), ['user_id' => $manager_invite->id]);
        static::__assert_false($shown['invite_url_hidden'], 'the link of a lower role is shown');
        static::__assert_contains(
            (string) static::__reload($manager_invite)->invite_code,
            (string) $shown['invite_url'],
            'the shown link carries the fresh code'
        );
    }

    // ---------------------------------------------------------------------
    // disabling
    // ---------------------------------------------------------------------

    public static function test_disabling_is_the_enabled_switch_and_never_yourself()
    {
        $manager = static::__member(User_Model::ROLE_MANAGER, 'manager');
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');
        static::__act_as($admin);

        $result = Frontend_Settings_User_Management_Controller::set_user_enabled(new Request(), ['id' => $manager->id, 'is_enabled' => 0]);
        static::__assert_false($result['is_enabled'], 'the manager is disabled');

        $reloaded = static::__reload($manager);
        static::__assert_equals(0, (int) $reloaded->is_enabled, 'users.is_enabled is off');
        static::__assert_equals(User_Model::ROLE_MANAGER, (int) $reloaded->role_id, 'the role is kept for re-enabling');

        static::__assert_unauthorized(
            Frontend_Settings_User_Management_Controller::set_user_enabled(new Request(), ['id' => $admin->id, 'is_enabled' => 0]),
            'disabling yourself is refused'
        );
    }

    // ---------------------------------------------------------------------
    // the view page
    // ---------------------------------------------------------------------

    public static function test_the_view_shows_only_this_sites_sessions_and_the_callers_authority()
    {
        $owner = static::__member(User_Model::ROLE_SITE_OWNER, 'owner');
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');

        foreach ([self::SITE_ID, self::SITE_ID + 4242] as $site_id) {
            DB::table('_sessions')->insert([
                'session_token' => bin2hex(random_bytes(16)),
                'csrf_token' => bin2hex(random_bytes(16)),
                'active' => 1,
                'site_id' => $site_id,
                'version' => 1,
                'ip_address' => '10.9.' . ($site_id % 250) . '.1',
                'user_agent' => 'authority-test',
                'login_user_id' => $owner->login_user_id,
                'last_active' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        static::__act_as($admin);
        $viewed = Frontend_Settings_User_Management_Controller::get_user(new Request(), ['id' => $owner->id]);

        static::__assert_count(1, $viewed['recent_sessions'], "another site's session is not listed");
        static::__assert_false($viewed['can_administer'], 'a superior is shown as not administrable');
        static::__assert_false($viewed['is_self'], 'and is not the caller');
    }
}
