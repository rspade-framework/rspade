<?php

namespace Rsx\App\Dev\Acl;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Session\Session;

/**
 * ACL tester - gated to debug sites by the 'dev_tools' check. (This replaces the
 * TODO that used to sit in pre_dispatch: the surface is now declared, and a
 * tighter policy is one more name in the #[Auth] list.)
 */
#[Auth('dev_tools')]
class Dev_Acl_Controller extends Rsx_Controller_Abstract
{
    /**
     * ACL Tester page - shows all users and their permissions
     */
    #[Route('/dev/acl')]
    public static function index(Request $request, array $params = [])
    {
        // Get all users (no eager loading - explicit relationship access)
        $users = User_Model::orderBy('role_id', 'asc')->get();

        // Build user data with permissions
        $user_data = [];
        foreach ($users as $user) {
            $login_user = $user->login_user;
            $email = $login_user->email ?? 'N/A';
            $user_data[] = [
                'id' => $user->id,
                'email' => $email,
                'name' => $user->get_full_name() ?: 'No Name',
                'role_id' => $user->role_id,
                'role_label' => $user->role_id__label ?? 'Unknown',
                'is_test_user' => str_ends_with($email, '@rspade.test'),
                'permissions' => [
                    'MANAGE_SITES_ROOT' => $user->has_permission(User_Model::PERM_MANAGE_SITES_ROOT),
                    'MANAGE_SITE_BILLING' => $user->has_permission(User_Model::PERM_MANAGE_SITE_BILLING),
                    'MANAGE_SITE_SETTINGS' => $user->has_permission(User_Model::PERM_MANAGE_SITE_SETTINGS),
                    'MANAGE_SITE_USERS' => $user->has_permission(User_Model::PERM_MANAGE_SITE_USERS),
                    'VIEW_USER_ACTIVITY' => $user->has_permission(User_Model::PERM_VIEW_USER_ACTIVITY),
                    'EDIT_DATA' => $user->has_permission(User_Model::PERM_EDIT_DATA),
                    'VIEW_DATA' => $user->has_permission(User_Model::PERM_VIEW_DATA),
                    'API_ACCESS' => $user->has_permission(User_Model::PERM_API_ACCESS),
                    'DATA_EXPORT' => $user->has_permission(User_Model::PERM_DATA_EXPORT),
                ],
                'supplementary' => User_Permission_Model::for_user($user->id),
            ];
        }

        // Get role definitions for display
        $roles = [];
        foreach (User_Model::$enums['role_id'] as $role_id => $role_def) {
            $roles[$role_id] = [
                'id' => $role_id,
                'constant' => $role_def['constant'],
                'label' => $role_def['label'],
                'permissions' => $role_def['permissions'],
                'can_admin_roles' => $role_def['can_admin_roles'],
                'selectable' => $role_def['selectable'] ?? true,
            ];
        }

        // Permission definitions
        $permissions = [
            User_Model::PERM_MANAGE_SITES_ROOT => 'MANAGE_SITES_ROOT',
            User_Model::PERM_MANAGE_SITE_BILLING => 'MANAGE_SITE_BILLING',
            User_Model::PERM_MANAGE_SITE_SETTINGS => 'MANAGE_SITE_SETTINGS',
            User_Model::PERM_MANAGE_SITE_USERS => 'MANAGE_SITE_USERS',
            User_Model::PERM_VIEW_USER_ACTIVITY => 'VIEW_USER_ACTIVITY',
            User_Model::PERM_EDIT_DATA => 'EDIT_DATA',
            User_Model::PERM_VIEW_DATA => 'VIEW_DATA',
            User_Model::PERM_API_ACCESS => 'API_ACCESS',
            User_Model::PERM_DATA_EXPORT => 'DATA_EXPORT',
        ];

        return rsx_view('Dev_Acl', [
            'users' => $user_data,
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Populate test users with @rspade.test emails
     * Creates one user for each role (except Developer)
     */
    #[Ajax_Endpoint]
    public static function populate_test_users(Request $request, array $params = [])
    {
        // TODO: Implement developer role check
        // $user = Session::get_user();
        // if (!$user || $user->role_id !== User_Model::ROLE_DEVELOPER) {
        //     return response_error(Ajax::ERROR_UNAUTHORIZED, 'Developer access required');
        // }

        $site_id = Session::get_site_id();
        $created = [];
        $skipped = [];

        // Define test users for each role (except Developer - that's user 1 only)
        $test_users = [
            ['email' => 'root.admin@rspade.test', 'first_name' => 'Root', 'last_name' => 'Admin', 'role_id' => User_Model::ROLE_ROOT_ADMIN],
            ['email' => 'site.owner@rspade.test', 'first_name' => 'Site', 'last_name' => 'Owner', 'role_id' => User_Model::ROLE_SITE_OWNER],
            ['email' => 'site.admin@rspade.test', 'first_name' => 'Site', 'last_name' => 'Admin', 'role_id' => User_Model::ROLE_SITE_ADMIN],
            ['email' => 'manager@rspade.test', 'first_name' => 'Test', 'last_name' => 'Manager', 'role_id' => User_Model::ROLE_MANAGER],
            ['email' => 'user@rspade.test', 'first_name' => 'Test', 'last_name' => 'User', 'role_id' => User_Model::ROLE_USER],
            ['email' => 'viewer@rspade.test', 'first_name' => 'Test', 'last_name' => 'Viewer', 'role_id' => User_Model::ROLE_VIEWER],
            ['email' => 'disabled@rspade.test', 'first_name' => 'Test', 'last_name' => 'Disabled', 'role_id' => User_Model::ROLE_DISABLED],
        ];

        foreach ($test_users as $test_user) {
            // Check if login_user already exists
            $login_user = Login_User_Model::where('email', $test_user['email'])->first();

            if ($login_user) {
                // Check if user record exists for this site
                $existing = User_Model::where('login_user_id', $login_user->id)
                    ->where('site_id', $site_id)
                    ->first();

                if ($existing) {
                    $skipped[] = $test_user['email'];
                    continue;
                }
            } else {
                // Create login user with random password
                $login_user = new Login_User_Model();
                $login_user->email = $test_user['email'];
                $login_user->password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $login_user->save();
            }

            // Create user record for this site
            $user = new User_Model();
            $user->login_user_id = $login_user->id;
            $user->site_id = $site_id;
            $user->first_name = $test_user['first_name'];
            $user->last_name = $test_user['last_name'];
            $user->email = $test_user['email'];
            $user->role_id = $test_user['role_id'];
            $user->is_enabled = true;
            $user->save();

            $created[] = $test_user['email'];
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * Delete all test users (@rspade.test domain)
     */
    #[Ajax_Endpoint]
    public static function delete_test_users(Request $request, array $params = [])
    {
        // TODO: Implement developer role check

        $site_id = Session::get_site_id();
        $deleted = [];

        // Find test login_users first, then find their user records for this site
        $test_login_users = Login_User_Model::where('email', 'LIKE', '%@rspade.test')->get();

        foreach ($test_login_users as $login_user) {
            $user = User_Model::where('login_user_id', $login_user->id)
                ->where('site_id', $site_id)
                ->first();

            if ($user) {
                $user->forceDelete();
                $deleted[] = $login_user->email;
            }
        }

        return [
            'deleted' => $deleted,
        ];
    }
}
