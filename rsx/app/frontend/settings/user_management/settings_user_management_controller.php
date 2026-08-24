<?php

namespace Rsx\App\Frontend\Settings\UserManagement;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Frontend\Settings\UserManagement\List\Users_DataGrid;

/**
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

        // Return validation errors if any
        if (!empty($errors)) {
            return response_error(Ajax::ERROR_VALIDATION, $errors);
        }

        $site_id = Session::get_site_id();

        // Check if already a member or invited
        $existing = User_Model::where('site_id', $site_id)
            ->where('email', $email)
            ->first();

        if ($existing) {
            return response_error(Ajax::ERROR_VALIDATION, [
                'email' => 'User already invited to this site'
            ]);
        }

        // Create invitation (pending users record)
        $user = new User_Model();
        $user->site_id = $site_id;
        $user->email = $email;
        $user->first_name = $first_name;
        $user->last_name = $last_name;
        $user->phone = $params['phone'] ?? null;
        $user->role_id = !empty($params['role_id']) ? (int)$params['role_id'] : User_Model::ROLE_USER;
        $user->login_user_id = null; // Not linked yet
        $user->invite_code = \Illuminate\Support\Str::random(32); // Unique code
        $user->invite_accepted_at = null; // Pending
        $user->invite_expires_at = now()->addDays(
            config('rsx.auth.invite_expiration_days', 7)
        );
        $user->save();

        // Send invitation email
        $invite_url = url(Rsx::Route('Accept_Invite_Controller::index', ['code' => $user->invite_code]));

        \App\RSpade\Core\Mail\Rsx_Mail::send(
            $user->email,
            'You\'re Invited to ' . config('rsx.name', 'RSpade'),
            'User_Invitation_Email',
            [
                'app_name' => config('rsx.name', 'RSpade'),
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'invite_url' => $invite_url,
                'expiry_days' => config('rsx.auth.invite_expiration_days', 7),
            ],
            \App\RSpade\Core\Mail\Rsx_Mail::TRANSACTIONAL
        );

        Flash_Alert::success('User created and invitation sent');

        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ];
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
        $user_id = $params['user_id'] ?? null;

        if (!$user_id) {
            return response_error(Ajax::ERROR_VALIDATION, [
                'user_id' => 'User ID is required'
            ]);
        }

        $site_id = Session::get_site_id();

        // Load user
        $user = User_Model::where('site_id', $site_id)
            ->where('id', $user_id)
            ->first();

        if (!$user) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'User not found in this site');
        }

        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'role_id' => $user->role_id,
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
            return response_error(Ajax::ERROR_VALIDATION, [
                'id' => 'User ID is required'
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

        // Return validation errors if any
        if (!empty($errors)) {
            return response_error(Ajax::ERROR_VALIDATION, $errors);
        }

        $site_id = Session::get_site_id();

        // Load user
        $user = User_Model::where('site_id', $site_id)
            ->where('id', $user_id)
            ->first();

        if (!$user) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'User not found in this site');
        }

        // Check if email is being changed to one that already exists
        if ($user->email !== $email) {
            $existing = User_Model::where('site_id', $site_id)
                ->where('email', $email)
                ->where('id', '!=', $user_id)
                ->first();

            if ($existing) {
                return response_error(Ajax::ERROR_VALIDATION, [
                    'email' => 'Email address already in use by another user'
                ]);
            }
        }

        // Update user
        $user->email = $email;
        $user->first_name = $first_name;
        $user->last_name = $last_name;
        $user->phone = $params['phone'] ?? null;
        $user->role_id = !empty($params['role_id']) ? (int)$params['role_id'] : User_Model::ROLE_USER;
        $user->save();

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
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function send_invite(Request $request, array $params = [])
    {
        $user_id = $params['user_id'] ?? null;

        if (!$user_id) {
            return response_error(Ajax::ERROR_VALIDATION, [
                'user_id' => 'User ID is required'
            ]);
        }

        $site_id = Session::get_site_id();

        // Load user
        $user = User_Model::where('site_id', $site_id)
            ->where('id', $user_id)
            ->first();

        if (!$user) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'User not found in this site');
        }

        // Check if user account is enabled
        if (!$user->is_enabled) {
            return response_error(Ajax::ERROR_VALIDATION, [
                'user_id' => 'Cannot send invitation to disabled account'
            ]);
        }

        // Check if invitation already accepted
        if ($user->invite_accepted_at !== null) {
            return response_error(Ajax::ERROR_VALIDATION, [
                'user_id' => 'This user has already accepted their invitation'
            ]);
        }

        // Generate new invite code and expiration
        $user->invite_code = \Illuminate\Support\Str::random(32);
        $user->invite_expires_at = now()->addDays(
            config('rsx.auth.invite_expiration_days', 7)
        );
        $user->save();

        $invite_url = url(Rsx::Route('Accept_Invite_Controller::index', ['code' => $user->invite_code]));

        \App\RSpade\Core\Mail\Rsx_Mail::send(
            $user->email,
            'You\'re Invited to ' . config('rsx.name', 'RSpade'),
            'User_Invitation_Email',
            [
                'app_name' => config('rsx.name', 'RSpade'),
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'invite_url' => $invite_url,
                'expiry_days' => config('rsx.auth.invite_expiration_days', 7),
            ],
            \App\RSpade\Core\Mail\Rsx_Mail::TRANSACTIONAL
        );

        return [
            'message' => 'Invitation sent successfully',
            'invite_url' => $invite_url,
            'user_id' => $user->id,
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
        $user_id = $params['id'] ?? null;

        if (!$user_id) {
            return response_error(Ajax::ERROR_VALIDATION, [
                'id' => 'User ID is required'
            ]);
        }

        $site_id = Session::get_site_id();

        // Load user
        $user = User_Model::where('site_id', $site_id)
            ->where('id', $user_id)
            ->first();

        if (!$user) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'User not found');
        }

        // Get recent sessions for this login user
        $recent_sessions = [];
        if ($user->login_user_id) {
            $sessions = \App\RSpade\Core\Session\Session::where('login_user_id', $user->login_user_id)
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

        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'is_enabled' => $user->is_enabled,
            'role_id' => $user->role_id,
            'role_id__label' => $user->role_id__label ?? 'Member',
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
}
