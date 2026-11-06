<?php


namespace Rsx\App\Frontend\Settings\ProfileEdit;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Profile_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use Rsx\Lib\FieldChecks\Field_Checks;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Profile_Edit_Controller extends Rsx_Controller_Abstract
{
    /**
     * The longest a profile bio may be. user_profiles.bio is LONGTEXT, so the column sets
     * no limit; this is the application's. save_profile() enforces it and the form's
     * $max_length and help text read it from get_profile_for_edit().
     */
    const BIO_MAX_LENGTH = 500;

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
            'bio_max_length' => self::BIO_MAX_LENGTH,
            // The form's values ($data on the Rsx_Form).
            'form' => [
                // The version the form edits from: save_profile() goes through Form_Edit.
                'updated_at' => $user->updated_at,
                'profile_photo' => $profile_photo ? $profile_photo->key : '',
                'first_name' => $user->first_name ?? '',
                'last_name' => $user->last_name ?? '',
                'email' => $user->email ?? '',
                'phone' => $user->phone ?? '',
                'title' => $user->user_profile->title ?? '',
                'department' => $user->user_profile->department ?? '',
                'bio' => $user->user_profile->bio ?? '',
            ],
        ];
    }

    /**
     * Save profile changes
     *
     * An edit of the signed-in user's own record, through Form_Edit: only the fields the
     * submission changed are written, and an absent key leaves its field as stored. A
     * blank required field is refused. A new profile_photo key that cannot be claimed (not
     * an unclaimed upload of this site) is refused as a field error, never dropped; an
     * empty one removes the photo.
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Writer]
    #[Ajax_Endpoint]
    public static function save_profile(Request $request, array $params = [])
    {
        $user = Session::get_user();
        if (!$user) {
            shouldnt_happen("User not authenticated in save_profile");
        }

        // Validate required fields: blank is a value and is refused; absent is untouched.
        $errors = [];

        if (array_key_exists('first_name', $params) && empty($params['first_name'])) {
            $errors['first_name'] = 'First name is required';
        }

        if (array_key_exists('last_name', $params) && empty($params['last_name'])) {
            $errors['last_name'] = 'Last name is required';
        }

        // Each value must fit its column; the bio, a LONGTEXT, has the application's limit.
        $errors += Field_Checks::length_errors(User_Model::class, $params, [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'phone' => 'Phone',
        ]);
        $errors += Field_Checks::length_errors(User_Profile_Model::class, $params, [
            'title' => 'Title',
            'department' => 'Department',
        ]);
        $bio = $params['bio'] ?? null;
        if ($bio !== null && (!is_string($bio) || mb_strlen($bio) > self::BIO_MAX_LENGTH)) {
            $errors['bio'] = 'Bio must be ' . self::BIO_MAX_LENGTH . ' characters or fewer';
        }

        // A photo key other than the one already attached must be claimable now.
        $current_photo = $user->get_attachment('profile_photo');
        $new_photo = null;
        $photo_key = $params['profile_photo'] ?? null;
        if ($photo_key !== null && $photo_key !== '' && (!$current_photo || $current_photo->key !== $photo_key)) {
            $new_photo = File_Attachment_Model::find_by_key((string) $photo_key);
            if (!$new_photo || !$new_photo->can_user_assign_this_file()) {
                $errors['profile_photo'] = 'The photo could not be attached. Upload it again.';
            }
        }

        // Return validation errors if any
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        return Form_Edit::run($user, $params, function (Form_Edit $edit) use ($user, $params, $current_photo, $new_photo) {
            // Explicit field setting (no mass assignment). Email is read-only, never saved.
            $edit->set('first_name', $params['first_name'] ?? null);
            $edit->set('last_name', $params['last_name'] ?? null);
            $edit->set('phone', $params['phone'] ?? null);
            $user->save();

            // The profile row is not the record Form_Edit guards, so changed() decides
            // each field.
            $profile_fields = array_values(array_filter(['title', 'department', 'bio'], fn ($field) => $edit->changed($field)));
            if ($profile_fields) {
                $profile = $user->user_profile;
                if (!$profile) {
                    $profile = new User_Profile_Model();
                    $profile->user_id = $user->id;
                }
                foreach ($profile_fields as $field) {
                    $profile->{$field} = $params[$field] ?? null;
                }
                $profile->save();
            }

            if ($edit->changed('profile_photo')) {
                if ($new_photo) {
                    $new_photo->attach_to($user, 'profile_photo');
                } elseif (($params['profile_photo'] ?? '') === '' && $current_photo) {
                    // An empty value removes the photo.
                    $current_photo->detach();
                }
            }

            return [
                'message' => 'Profile updated successfully',
                'redirect' => Rsx::Route('Settings_Profile_Display_Action')
            ];
        }, Rsx::Route('Settings_Profile_Display_Action'));
    }
}
