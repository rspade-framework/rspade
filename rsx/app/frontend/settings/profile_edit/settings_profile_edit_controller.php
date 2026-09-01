<?php


namespace Rsx\App\Frontend\Settings\ProfileEdit;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\User_Profile_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Profile_Edit_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Get profile data for edit form
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_profile_for_edit(Request $request, array $params = [])
    {
        $user = Session::get_user();

        // Get profile photo key if exists
        $profile_photo = $user->get_attachment('profile_photo');

        return [
            'profile_photo' => $profile_photo ? $profile_photo->key : '',
            'first_name' => $user->first_name ?? '',
            'last_name' => $user->last_name ?? '',
            'email' => $user->email ?? '',
            'phone' => $user->phone ?? '',
            'title' => $user->user_profile->title ?? '',
            'department' => $user->user_profile->department ?? '',
            'bio' => $user->user_profile->bio ?? '',
        ];
    }

    /**
     * Save profile changes
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function save_profile(Request $request, array $params = [])
    {
        $user = Session::get_user();
        if (!$user) {
            shouldnt_happen("User not authenticated in save_profile");
        }

        // Validate required fields
        $errors = [];

        if (empty($params['first_name'])) {
            $errors['first_name'] = 'First name is required';
        }

        if (empty($params['last_name'])) {
            $errors['last_name'] = 'Last name is required';
        }

        // Return validation errors if any
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        // Update user fields (no mass assignment - explicit field setting)
        $user->first_name = $params['first_name'];
        $user->last_name = $params['last_name'];
        $user->phone = $params['phone'] ?? null;
        // Email is read-only, don't save it
        $user->save();

        // Get or create user profile
        $profile = $user->user_profile;
        if (!$profile) {
            $profile = new User_Profile_Model();
            $profile->user_id = $user->id;
        }

        // Update profile fields (no mass assignment)
        $profile->title = $params['title'] ?? null;
        $profile->department = $params['department'] ?? null;
        $profile->bio = $params['bio'] ?? null;
        $profile->save();

        // Handle profile photo attachment
        if (isset($params['profile_photo'])) {
            if ($params['profile_photo'] === '' || $params['profile_photo'] === null) {
                // Empty string means remove the attachment
                $existing_attachment = $user->get_attachment('profile_photo');
                if ($existing_attachment) {
                    $existing_attachment->detach();
                }
            } else {
                // Non-empty string means attach new photo
                $attachment = \App\RSpade\Core\Files\File_Attachment_Model::find_by_key($params['profile_photo']);
                if ($attachment && $attachment->can_user_assign_this_file()) {
                    $attachment->attach_to($user, 'profile_photo');
                }
            }
        }

        return [
            'message' => 'Profile updated successfully',
            'redirect' => Rsx::Route('Settings_Profile_Display_Action')
        ];
    }
}