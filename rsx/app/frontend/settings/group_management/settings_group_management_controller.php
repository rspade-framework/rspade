<?php

namespace Rsx\App\Frontend\Settings\GroupManagement;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Date;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Frontend\Settings\GroupManagement\List\Groups_DataGrid;
use Rsx\Models\User_Group_Model;

/**
 * Frontend_Settings_Group_Management_Controller - Ajax endpoints for user groups.
 *
 * Gated 'can_manage_users' (PERM_MANAGE_SITE_USERS), which resolves to exactly the
 * role set the deleted pre_dispatch floor admitted (Site Admin and above): the four
 * roles that carry the permission are Developer, Root Admin, Site Owner and Site
 * Admin.
 */
#[Auth('is_logged_in', 'can_manage_users')]
class Frontend_Settings_Group_Management_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Groups_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: Add group
     */
    #[Ajax_Endpoint]
    public static function add_group(Request $request, array $params = [])
    {
        $errors = [];

        // Validate name
        $name = trim($params['name'] ?? '');
        if (empty($name)) {
            $errors['name'] = 'Group name is required';
        } elseif (strlen($name) > 100) {
            $errors['name'] = 'Group name cannot exceed 100 characters';
        }

        // Return validation errors if any
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        $site_id = Session::get_site_id();

        // Check if group with same name already exists
        $existing = User_Group_Model::where('site_id', $site_id)
            ->where('name', $name)
            ->first();

        if ($existing) {
            return response_form_error('A group with this name already exists', [
                'name' => 'A group with this name already exists',
            ]);
        }

        // Create group
        $group = new User_Group_Model();
        $group->site_id = $site_id;
        $group->name = $name;
        $group->description = $params['description'] ?? null;
        $group->save();

        // Sync members if provided
        $member_ids = $params['member_ids'] ?? [];
        if (!empty($member_ids)) {
            // Validate all member_ids belong to this site
            $valid_ids = User_Model::where('site_id', $site_id)
                ->whereIn('id', $member_ids)
                ->pluck('id')
                ->toArray();

            $group->members()->sync($valid_ids);
        }

        Flash_Alert::success('Group created successfully');

        return [
            'id' => $group->id,
            'name' => $group->name,
        ];
    }

    /**
     * Ajax endpoint: Get group data for editing
     */
    #[Ajax_Endpoint]
    public static function get_group_for_edit(Request $request, array $params = [])
    {
        $group_id = $params['group_id'] ?? null;

        if (!$group_id) {
            return response_form_error('Group ID is required', [
                'group_id' => 'Group ID is required',
            ]);
        }

        $site_id = Session::get_site_id();

        $group = User_Group_Model::where('site_id', $site_id)
            ->where('id', $group_id)
            ->first();

        if (!$group) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Group not found');
        }

        // Get current member IDs
        $member_ids = $group->members()->pluck('users.id')->toArray();

        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'member_ids' => $member_ids,
        ];
    }

    /**
     * Ajax endpoint: Save group changes
     */
    #[Ajax_Endpoint]
    public static function save_group(Request $request, array $params = [])
    {
        $errors = [];

        // Validate group ID
        $group_id = $params['id'] ?? null;
        if (!$group_id) {
            return response_form_error('Group ID is required', [
                'id' => 'Group ID is required',
            ]);
        }

        // Validate name
        $name = trim($params['name'] ?? '');
        if (empty($name)) {
            $errors['name'] = 'Group name is required';
        } elseif (strlen($name) > 100) {
            $errors['name'] = 'Group name cannot exceed 100 characters';
        }

        // Return validation errors if any
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        $site_id = Session::get_site_id();

        // Load group
        $group = User_Group_Model::where('site_id', $site_id)
            ->where('id', $group_id)
            ->first();

        if (!$group) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Group not found');
        }

        // Check if name is being changed to one that already exists
        if ($group->name !== $name) {
            $existing = User_Group_Model::where('site_id', $site_id)
                ->where('name', $name)
                ->where('id', '!=', $group_id)
                ->first();

            if ($existing) {
                return response_form_error('A group with this name already exists', [
                    'name' => 'A group with this name already exists',
                ]);
            }
        }

        // Update group
        $group->name = $name;
        $group->description = $params['description'] ?? null;
        $group->save();

        // Sync members
        $member_ids = $params['member_ids'] ?? [];
        // Validate all member_ids belong to this site
        $valid_ids = User_Model::where('site_id', $site_id)
            ->whereIn('id', $member_ids)
            ->pluck('id')
            ->toArray();

        $group->members()->sync($valid_ids);

        Flash_Alert::success('Group updated successfully');

        return [
            'id' => $group->id,
            'name' => $group->name,
        ];
    }

    /**
     * Ajax endpoint: Get group details for view page
     */
    #[Ajax_Endpoint]
    public static function get_group(Request $request, array $params = [])
    {
        $group_id = $params['id'] ?? null;

        if (!$group_id) {
            return response_form_error('Group ID is required', [
                'id' => 'Group ID is required',
            ]);
        }

        $site_id = Session::get_site_id();

        $group = User_Group_Model::where('site_id', $site_id)
            ->where('id', $group_id)
            ->first();

        if (!$group) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Group not found');
        }

        // Get members
        $members = $group->members()->get()->map(function ($user) {
            return [
                'id' => $user->id,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'full_name' => trim($user->first_name . ' ' . $user->last_name) ?: $user->email,
            ];
        })->toArray();

        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'deletion_protection' => (bool) $group->deletion_protection,
            'member_count' => count($members),
            'members' => $members,
            'created_at' => $group->created_at,
            'updated_at' => $group->updated_at,
        ];
    }

    /**
     * Ajax endpoint: Get selectable users for group membership forms
     *
     * Returns active users for add form, or active users + current members for edit form.
     */
    #[Ajax_Endpoint]
    public static function get_selectable_users(Request $request, array $params = [])
    {
        $site_id = Session::get_site_id();
        $group_id = $params['group_id'] ?? null;

        // Get active users
        $query = User_Model::where('site_id', $site_id)
            ->where('is_enabled', true)
            ->where('role_id', '!=', User_Model::ROLE_DISABLED)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('email');

        $active_users = $query->get();

        // If editing, also include current members even if inactive
        $current_member_ids = [];
        if ($group_id) {
            $group = User_Group_Model::where('site_id', $site_id)
                ->where('id', $group_id)
                ->first();

            if ($group) {
                $current_member_ids = $group->members()->pluck('users.id')->toArray();

                // Get inactive members that need to be included
                $inactive_member_ids = array_diff($current_member_ids, $active_users->pluck('id')->toArray());

                if (!empty($inactive_member_ids)) {
                    $inactive_members = User_Model::where('site_id', $site_id)
                        ->whereIn('id', $inactive_member_ids)
                        ->get();

                    $active_users = $active_users->concat($inactive_members);
                }
            }
        }

        // Format for display
        $users = $active_users->map(function ($user) use ($current_member_ids) {
            $display_name = trim($user->first_name . ' ' . $user->last_name);
            if (empty($display_name)) {
                $display_name = $user->email;
            }

            return [
                'id' => $user->id,
                'display_name' => $display_name,
                'email' => $user->email,
                'is_active' => $user->is_enabled && $user->role_id !== User_Model::ROLE_DISABLED,
            ];
        })->values()->toArray();

        return [
            'users' => $users,
            'current_member_ids' => $current_member_ids,
        ];
    }

    /**
     * Ajax endpoint: Delete group
     */
    #[Ajax_Endpoint]
    public static function delete_group(Request $request, array $params = [])
    {
        $group_id = $params['id'] ?? null;

        if (!$group_id) {
            return response_form_error('Group ID is required', [
                'id' => 'Group ID is required',
            ]);
        }

        $site_id = Session::get_site_id();

        $group = User_Group_Model::where('site_id', $site_id)
            ->where('id', $group_id)
            ->first();

        if (!$group) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Group not found');
        }

        // Check deletion protection
        if ($group->deletion_protection) {
            return response_error(Ajax::ERROR_FATAL, 'This group cannot be deleted');
        }

        // Soft delete the group
        $group->delete();

        Flash_Alert::success('Group deleted successfully');

        return ['deleted' => true];
    }

    /**
     * Ajax endpoint: export the selected groups as CSV.
     *
     * Export only - a group is deleted from its own screen, where the deletion_protection
     * rule is enforced, so the grid offers no mass delete.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Auth('can_export_data')]
    #[Ajax_Endpoint]
    public static function export_csv(Request $request, array $params = [])
    {
        $query = Groups_DataGrid::build_query_public($params['filter_params'] ?? []);

        $query = Groups_DataGrid::apply_selection($query, 'user_groups.id', $params);

        if ($query instanceof Error_Response) {
            return $query;
        }

        $rows = [];

        foreach (Groups_DataGrid::iterate_selection($query, 'user_groups.id') as $group) {
            $rows[] = [
                $group->id,
                $group->name,
                $group->description,
                $group->member_count(),
                $group->deletion_protection ? 'Yes' : 'No',
                $group->created_at,
            ];
        }

        $csv = Groups_DataGrid::build_csv(
            ['ID', 'Name', 'Description', 'Members', 'Protected', 'Created'],
            $rows
        );

        return [
            'csv' => $csv,
            'filename' => 'groups_export_' . Rsx_Date::today() . '.csv',
            'count' => count($rows),
        ];
    }
}
