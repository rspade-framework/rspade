<?php

namespace Rsx\App\Frontend\Settings\UserManagement;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Login\Login_Requirements;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Date;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Frontend\Settings\UserManagement\List\Users_DataGrid;
use Rsx\Emails\User_Invitation_Email;

/**
 * User administration for the signed-in user's site.
 *
 * EQUAL-OR-LOWER ADMINISTRATION. A caller creates, edits, re-roles, re-invites the link of
 * and disables only users whose role is EQUAL to or LOWER than their own - the role's
 * can_admin_roles list (User_Model::$enums), read through can_admin_role(). Every endpoint
 * that changes a user asks it of the target's CURRENT role (__administrable_user()) and of
 * any NEW role it assigns (_validate_role_id()); a refusal is an ERROR_UNAUTHORIZED
 * response, never a silent no-op. Reads (the list, the view page) stay open to every
 * holder of can_manage_users.
 */
#[Auth('is_logged_in', 'can_manage_users')]
class Frontend_Settings_User_Management_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        // Call static fetch method on DataGrid
        return Users_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: Add user (create invitation)
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function add_user(Request $request, array $params = [])
    {
        $errors = [];

        // Validate email
        $email = $params['email'] ?? '';
        if (empty($email)) {
            $errors['email'] = 'Email address is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address';
        }

        // Validate first name
        $first_name = $params['first_name'] ?? '';
        if (empty($first_name)) {
            $errors['first_name'] = 'First name is required';
        }

        // Validate last name
        $last_name = $params['last_name'] ?? '';
        if (empty($last_name)) {
            $errors['last_name'] = 'Last name is required';
        }

        // Validate role: a real, selectable role the signed-in user may administer.
        $role_id = static::_validate_role_id($params['role_id'] ?? null, null, $errors);

        // Return validation errors if any
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        $site_id = Session::get_site_id();

        // Check if already a member or invited
        $existing = User_Model::where('site_id', $site_id)
            ->where('email', $email)
            ->first();

        if ($existing) {
            return response_form_error('User already invited to this site', [
                'email' => 'User already invited to this site',
            ]);
        }

        // Create invitation (pending users record)
        $user = new User_Model();
        $user->site_id = $site_id;
        $user->email = $email;
        $user->first_name = $first_name;
        $user->last_name = $last_name;
        $user->phone = $params['phone'] ?? null;
        $user->role_id = $role_id;
        $user->login_user_id = null; // Not linked yet
        $user->invite_code = \Illuminate\Support\Str::random(32); // Unique code
        $user->invite_accepted_at = null; // Pending
        $user->invite_expires_at = now()->addDays(
            config('rsx.auth.invite_expiration_days', 7)
        );

        // A checkbox posts nothing when unchecked, so absence means OFF. The form ships it
        // checked, so a caller who leaves the field alone gets what the column defaults to.
        $user->is_api_access_enabled = !empty($params['is_api_access_enabled']) ? 1 : 0;
        $user->save();

        // Send invitation email
        $invite_url = rsx_absolute_url(Rsx::Route('Accept_Invite_Controller::index', ['code' => $user->invite_code]));

        (new User_Invitation_Email($user, $invite_url))->to($user)->send();

        Flash_Alert::success('User created and invitation sent');

        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ];
    }

    /**
     * Ajax endpoint: the ACTIVE API keys belonging to one user, for the admin key screen.
     *
     * Active only. The question this page answers is "what can still reach the API as this
     * user", and a revoked or expired key is not part of that answer.
     *
     * Site-scoped like every other endpoint here: an admin may only see keys belonging to a
     * user in their own site, and the lookup fails as not-found rather than empty when they
     * are not - the two are the same to a caller, which is what stops this being a way to
     * probe for user ids in other sites.
     */
    #[Ajax_Endpoint]
    public static function get_user_api_keys(Request $request, array $params = [])
    {
        $user = static::__site_user($params['id'] ?? null);

        if (!$user instanceof User_Model) {
            return $user;
        }

        $keys = [];

        foreach (Api_Key_Model::get_for_user((int) $user->id) as $key) {
            if (!$key->is_valid()) {
                continue;
            }

            $keys[] = [
                'id' => $key->id,
                'name' => $key->name,
                'key_prefix' => $key->key_prefix,
                'created_at' => $key->created_at,
                'expires_at' => $key->expires_at,
                'last_used_at' => $key->last_used_at,
            ];
        }

        return [
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
            ],
            'keys' => $keys,
        ];
    }

    /**
     * Ajax endpoint: revoke one of a user's API keys, as an admin.
     *
     * REVOKES, never deletes: _api_request_log rows reference api_key_id, and destroying the
     * key would leave every call it ever made pointing at nothing - exactly when somebody is
     * working out what a compromised key did.
     *
     * The key is re-checked against the user AND the site, so a key id from elsewhere cannot
     * be revoked by passing it here.
     */
    #[Ajax_Endpoint]
    public static function revoke_user_api_key(Request $request, array $params = [])
    {
        $user = static::__administrable_user($params['id'] ?? null);

        if (!$user instanceof User_Model) {
            return $user;
        }

        $key = Api_Key_Model::where('id', $params['key_id'] ?? null)
            ->where('user_id', $user->id)
            ->first();

        if (!$key) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'API key not found for this user');
        }

        $key->revoke();

        Flash_Alert::success('API key revoked');

        return ['revoked' => true];
    }

    /**
     * Resolve a user id within the caller's site, or the error response to return.
     *
     * The READ answer to "which users may I see": every user of this site.
     */
    private static function __site_user($user_id, string $field = 'id')
    {
        if (!$user_id) {
            return response_form_error('User ID is required', [$field => 'User ID is required']);
        }

        $user = User_Model::where('site_id', Session::get_site_id())
            ->where('id', $user_id)
            ->first();

        if (!$user) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'User not found');
        }

        return $user;
    }

    /**
     * Resolve a user id the caller may CHANGE, or the error response to return.
     *
     * The WRITE answer to "which users may I act on": a user of this site whose CURRENT role
     * the signed-in user may administer (equal-or-lower administration). Refused as
     * ERROR_UNAUTHORIZED, never silently.
     */
    private static function __administrable_user($user_id, string $field = 'id')
    {
        $user = static::__site_user($user_id, $field);

        if (!$user instanceof User_Model) {
            return $user;
        }

        if (!Session::get_user()->can_admin_role((int) $user->role_id)) {
            return response_unauthorized("You cannot manage a user whose access level is above your own");
        }

        return $user;
    }

    /**
     * Ajax endpoint: Get user data for editing
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_user_for_edit(Request $request, array $params = [])
    {
        $user = static::__administrable_user($params['user_id'] ?? null, 'user_id');

        if (!$user instanceof User_Model) {
            return $user;
        }

        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'role_id' => $user->role_id,
            'is_api_access_enabled' => (bool) $user->is_api_access_enabled,
            'is_2fa_required' => (bool) $user->is_2fa_required,
        ];
    }

    /**
     * Ajax endpoint: Save user changes
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function save_user(Request $request, array $params = [])
    {
        $errors = [];

        // Validate user ID
        $user_id = $params['id'] ?? null;
        if (!$user_id) {
            return response_form_error('User ID is required', [
                'id' => 'User ID is required',
            ]);
        }

        // Validate email
        $email = $params['email'] ?? '';
        if (empty($email)) {
            $errors['email'] = 'Email address is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address';
        }

        // Validate first name
        $first_name = $params['first_name'] ?? '';
        if (empty($first_name)) {
            $errors['first_name'] = 'First name is required';
        }

        // Validate last name
        $last_name = $params['last_name'] ?? '';
        if (empty($last_name)) {
            $errors['last_name'] = 'Last name is required';
        }

        $site_id = Session::get_site_id();

        // The target's CURRENT role first: a caller who may not administer it changes
        // nothing about this user - not the role, the email, the second-factor rule or
        // API access.
        $user = static::__administrable_user($user_id);

        if (!$user instanceof User_Model) {
            return $user;
        }

        // Then the NEW role: a real role the signed-in user may administer, selectable
        // unless it is the role the user already holds.
        $role_id = static::_validate_role_id($params['role_id'] ?? null, (int) $user->role_id, $errors);

        // Return validation errors if any
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        // Check if email is being changed to one that already exists
        if ($user->email !== $email) {
            $existing = User_Model::where('site_id', $site_id)
                ->where('email', $email)
                ->where('id', '!=', $user_id)
                ->first();

            if ($existing) {
                return response_form_error('Email address already in use by another user', [
                    'email' => 'Email address already in use by another user',
                ]);
            }
        }

        // Update user
        $user->email = $email;
        $user->first_name = $first_name;
        $user->last_name = $last_name;
        $user->phone = $params['phone'] ?? null;
        $user->role_id = $role_id;
        $user->is_api_access_enabled = !empty($params['is_api_access_enabled']) ? 1 : 0;

        // An absent checkbox means OFF: every input serializes on every submit, so a key that
        // did not arrive is an unticked box, not an untouched field.
        $was_2fa_required = (bool) $user->is_2fa_required;
        $user->is_2fa_required = !empty($params['is_2fa_required']) ? 1 : 0;
        $flag_changed = $was_2fa_required !== (bool) $user->is_2fa_required;

        $user->save();

        // Turning the requirement ON must reach the user's live sessions NOW, not at their next
        // sign-in: recheck_user() re-evaluates Two_Factor_Enrollment_Requirement on every
        // session they hold, and the push makes their open tabs ask again, which sends them
        // to the setup screen. Only on a CONFIRMED change - a save that left the flag alone is
        // not news.
        if ($flag_changed) {
            Login_Requirements::recheck_user($user);
            Realtime::push_user_refresh((int) $user->site_id, (int) $user->id);
        }

        // Flash success message for display after redirect
        Flash_Alert::success('User updated successfully');

        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ];
    }

    /**
     * Ajax endpoint: Send/resend user invitation
     *
     * Generates new invite code and expiration for pending/expired invitations.
     * Used for both initial invites (after user creation) and resending.
     *
     * Re-sending is open to every user manager - the email goes to the invitee, not to the
     * caller. The LINK is returned only when the caller may administer the invitee's role:
     * an invitation a superior created for a higher role is delivered, but its link never
     * reaches a caller below that role (accepting it would hand them that role). Instead the
     * response carries invite_url_hidden, and the modal says why.
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function send_invite(Request $request, array $params = [])
    {
        $user = static::__site_user($params['user_id'] ?? null, 'user_id');

        if (!$user instanceof User_Model) {
            return $user;
        }

        // Check if user account is enabled
        if (!$user->is_enabled) {
            return response_form_error('Cannot send invitation to disabled account', [
                'user_id' => 'Cannot send invitation to disabled account',
            ]);
        }

        // Check if invitation already accepted
        if ($user->invite_accepted_at !== null) {
            return response_form_error('This user has already accepted their invitation', [
                'user_id' => 'This user has already accepted their invitation',
            ]);
        }

        // Generate new invite code and expiration
        $user->invite_code = \Illuminate\Support\Str::random(32);
        $user->invite_expires_at = now()->addDays(
            config('rsx.auth.invite_expiration_days', 7)
        );
        $user->save();

        $invite_url = rsx_absolute_url(Rsx::Route('Accept_Invite_Controller::index', ['code' => $user->invite_code]));

        (new User_Invitation_Email($user, $invite_url))->to($user)->send();

        if (!Session::get_user()->can_admin_role((int) $user->role_id)) {
            return [
                'message' => 'Invitation sent successfully',
                'invite_url' => null,
                'invite_url_hidden' => true,
                'user_id' => $user->id,
            ];
        }

        return [
            'message' => 'Invitation sent successfully',
            'invite_url' => $invite_url,
            'invite_url_hidden' => false,
            'user_id' => $user->id,
        ];
    }

    /**
     * Ajax endpoint: switch a user's membership of this site on or off.
     *
     * users.is_enabled is the ONE disable lever: the framework refuses a disabled membership
     * at sign-in and ends a live session of it on that session's next request. The role and
     * ACL rows are left as they are, so re-enabling restores exactly what the user had.
     * The caller may not switch themselves off (that would lock them out of the screen that
     * switches them back on), and may only switch users whose role they administer.
     */
    #[Ajax_Endpoint]
    public static function set_user_enabled(Request $request, array $params = [])
    {
        $user = static::__administrable_user($params['id'] ?? null);

        if (!$user instanceof User_Model) {
            return $user;
        }

        $is_enabled = !empty($params['is_enabled']);

        if (!$is_enabled && (int) $user->id === (int) Session::get_user_id()) {
            return response_unauthorized('You cannot disable your own account');
        }

        // User_Model::save() pushes a realtime refresh to the user's open tabs on a confirmed
        // change of is_enabled, so a disabled member is signed out at once.
        $user->is_enabled = $is_enabled ? 1 : 0;
        $user->save();

        Flash_Alert::success($is_enabled ? 'User enabled' : 'User disabled');

        return [
            'id' => $user->id,
            'is_enabled' => (bool) $user->is_enabled,
        ];
    }

    /**
     * Ajax endpoint: Get user details for view page
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_user(Request $request, array $params = [])
    {
        $user = static::__site_user($params['id'] ?? null);

        if (!$user instanceof User_Model) {
            return $user;
        }

        $site_id = Session::get_site_id();

        // Recent sessions of this login identity ON THIS SITE. The identity may belong to
        // other sites too; their sessions (address, device) are none of this site's business.
        $recent_sessions = [];
        if ($user->login_user_id) {
            $sessions = \App\RSpade\Core\Session\Session::where('login_user_id', $user->login_user_id)
                ->where('site_id', $site_id)
                ->orderBy('last_active', 'desc')
                ->limit(10)
                ->get();

            foreach ($sessions as $session) {
                $recent_sessions[] = [
                    'last_active' => $session->last_active->format('M j, Y g:i A'),
                    'ip_address' => $session->ip_address,
                    'user_agent' => \Illuminate\Support\Str::limit($session->user_agent, 50),
                    'active' => $session->active,
                ];
            }
        }

        // The profile photo travels as an ATTACHMENT ID, never a URL: <Attachment_Thumbnail>
        // is what renders it, and it builds its own URL from the record it fetches.
        $profile_photo = $user->get_attachment('profile_photo');
        $profile_photo_attachment_id = $profile_photo ? (int) $profile_photo->id : null;

        // API access summary. Counted here rather than shipped as a key list: the view page
        // shows a count and a last-used moment, and a page that only needs "how many" should
        // not carry every row to say so - the admin key screen is where the rows belong.
        $api_keys = Api_Key_Model::where('user_id', $user->id)
            ->where('is_revoked', false)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        $api_active_key_count = (clone $api_keys)->count();
        $api_last_used_at = (clone $api_keys)->max('last_used_at');

        // Whether the identity actually HAS a second factor, which is a different question
        // from whether an administrator requires one. A site user with no login identity
        // (an invitation that was never accepted) cannot have enrolled anything.
        $is_2fa_enrolled = $user->login_user_id
            ? Rsx_Two_Factor::is_enabled((int) $user->login_user_id)
            : false;

        // Whether the LOGIN identity behind this membership is a developer. Read as one
        // scalar rather than through the relation: the screen needs the flag, not the row,
        // and an administrator must be able to recognise an account that can reach a
        // developer-only surface. Displayed here, never edited from here.
        $is_developer = $user->login_user_id
            ? (bool) Login_User_Model::where('id', $user->login_user_id)->value('is_developer')
            : false;

        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'is_enabled' => $user->is_enabled,
            'is_api_access_enabled' => (bool) $user->is_api_access_enabled,
            'is_2fa_required' => (bool) $user->is_2fa_required,
            'is_2fa_enrolled' => $is_2fa_enrolled,
            'is_developer' => $is_developer,
            'api_active_key_count' => $api_active_key_count,
            'api_last_used_at' => $api_last_used_at,
            'role_id' => $user->role_id,
            'role_id__label' => $user->role_id__label ?? 'Member',
            'can_administer' => Session::get_user()->can_admin_role((int) $user->role_id),
            'is_self' => (int) $user->id === (int) Session::get_user_id(),
            'invitation_status' => $user->get_invitation_status(),
            'created_at' => $user->created_at,
            'profile_photo_attachment_id' => $profile_photo_attachment_id,
            'user_profile' => $user->user_profile ? [
                'title' => $user->user_profile->title,
                'department' => $user->user_profile->department,
                'bio' => $user->user_profile->bio,
            ] : null,
            'recent_sessions' => $recent_sessions,
        ];
    }

    /**
     * Ajax endpoint: export the selected users as CSV.
     *
     * Export only - user records are removed one at a time through the user-management screens,
     * where the role and self-deletion rules live, so the grid offers no mass delete.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Auth('can_export_data')]
    #[Ajax_Endpoint]
    public static function export_csv(Request $request, array $params = [])
    {
        $query = Users_DataGrid::build_query_public($params['filter_params'] ?? []);

        $query = Users_DataGrid::apply_selection($query, 'users.id', $params);

        if ($query instanceof Error_Response) {
            return $query;
        }

        $rows = [];

        foreach (Users_DataGrid::iterate_selection($query, 'users.id') as $user) {
            $rows[] = [
                $user->id,
                $user->first_name,
                $user->last_name,
                $user->email,
                $user->phone,
                $user->role_id__label,
                $user->get_invitation_status(),
                $user->is_enabled ? 'Yes' : 'No',
                $user->created_at,
            ];
        }

        $csv = Users_DataGrid::build_csv(
            ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Role', 'Invitation Status', 'Enabled', 'Created'],
            $rows
        );

        return [
            'csv' => $csv,
            'filename' => 'users_export_' . Rsx_Date::today() . '.csv',
            'count' => count($rows),
        ];
    }

    /**
     * The role a user-management form may assign: present, a member of the role_id enum,
     * selectable (Root Admin is assigned by the system only) unless it is the role the
     * target already holds ($current_role_id), and one the signed-in user may administer -
     * the same set the form offered, enforced here because the form is presentation. Fills
     * $errors['role_id'] and returns null when the value fails.
     */
    private static function _validate_role_id($value, ?int $current_role_id, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            $errors['role_id'] = 'Role is required';
            return null;
        }

        $role_id = (int) $value;
        $roles = User_Model::role_id__enum();

        if (!isset($roles[$role_id])
            || (($roles[$role_id]['selectable'] ?? true) === false && $role_id !== $current_role_id)) {
            $errors['role_id'] = 'Invalid role';
            return null;
        }

        if (!Session::get_user()->can_admin_role($role_id)) {
            $errors['role_id'] = 'You cannot assign this role';
            return null;
        }

        return $role_id;
    }
}
