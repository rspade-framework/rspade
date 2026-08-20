<?php


namespace Rsx\App\Frontend\Settings\ProfileDisplay;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Profile_Display_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Get current user's profile data
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_profile(Request $request, array $params = [])
    {
        $user = Session::get_user();

        // Get profile photo if exists
        $profile_photo = $user->get_attachment('profile_photo');
        $profile_photo_url = $profile_photo
            ? $profile_photo->get_thumbnail_url('cover', 128, 128)
            : null;

        // Get user profile relation data
        $user_profile = null;
        if ($user->user_profile) {
            $user_profile = [
                'title' => $user->user_profile->title,
                'department' => $user->user_profile->department,
                'bio' => $user->user_profile->bio,
            ];
        }

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role_id__label' => $user->role_id__label ?? 'Member',
            'created_at' => $user->created_at,
            'last_login_at' => $user->last_login_at,
            'profile_photo_url' => $profile_photo_url,
            'user_profile' => $user_profile,
        ];
    }
}