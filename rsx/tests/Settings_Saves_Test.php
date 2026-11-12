<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Profile_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Messaging\Mail\Email_Queue_Model;
use Rsx\App\Frontend\Settings\ProfileEdit\Frontend_Settings_Profile_Edit_Controller;
use Rsx\App\Frontend\Settings\UserManagement\Frontend_Settings_User_Management_Controller;

/**
 * Two settings saves that write exactly what they were sent.
 *
 *   - adding a user sends ONE invitation email, and the link add_user returns is the live
 *     one (the stored code)
 *   - save_profile edits through Form_Edit: a field the submission did not change is left as
 *     stored, a changed one is written, a blank required name is refused, and a photo key
 *     that cannot be claimed is a field error rather than a silent success
 *   - an over-long value is a field error, never a database exception: the profile's names,
 *     phone, title and department against their columns, the bio against
 *     BIO_MAX_LENGTH, and an invited or edited user's names
 */
class Settings_Saves_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;
    private const USER_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        static::__acting_as_user(self::USER_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    // ---------------------------------------------------------------------
    // add user
    // ---------------------------------------------------------------------

    public static function test_adding_a_user_sends_one_invitation_with_a_live_link()
    {
        $email = 'settings_saves_' . uniqid() . '@example.com';

        $result = Frontend_Settings_User_Management_Controller::add_user(new Request(), [
            'email' => $email,
            'first_name' => 'Invited',
            'last_name' => 'Once',
            'role_id' => User_Model::ROLE_MANAGER,
        ]);

        static::__assert_array_has_key('invite_url', $result, 'add_user returns the link it sent');

        $user = User_Model::find($result['id']);
        static::__assert_contains((string) $user->invite_code, (string) $result['invite_url'], 'the returned link carries the stored code');
        static::__assert_equals(
            1,
            Email_Queue_Model::where('to_address', $email)->where('email_class', 'User_Invitation_Email')->count(),
            'exactly one invitation email'
        );
    }

    // ---------------------------------------------------------------------
    // profile
    // ---------------------------------------------------------------------

    private static function __profile_edit(array $values, array $changed): array
    {
        $user = User_Model::find(self::USER_ID);

        return array_merge($values, [
            '_changed' => $changed,
            '_loaded_updated_at' => $user->updated_at,
        ]);
    }

    public static function test_a_profile_save_writes_only_what_changed()
    {
        $user = User_Model::find(self::USER_ID);
        $user->phone = '+15555550100';
        $user->save();
        $first_name = $user->first_name;

        $result = Frontend_Settings_Profile_Edit_Controller::save_profile(new Request(), static::__profile_edit([
            'title' => 'Chief Tester',
        ], ['title']));

        static::__assert_array_has_key('redirect', $result, 'the save succeeds');

        $saved = User_Model::find(self::USER_ID);
        static::__assert_equals('+15555550100', $saved->phone, 'an absent phone is left as stored');
        static::__assert_equals($first_name, $saved->first_name, 'an absent name is left as stored');
        static::__assert_equals('Chief Tester', $saved->user_profile->title, 'the changed field is written');
    }

    public static function test_a_blank_required_name_is_refused()
    {
        $result = Frontend_Settings_Profile_Edit_Controller::save_profile(new Request(), static::__profile_edit([
            'first_name' => '',
            'last_name' => 'Kept',
        ], ['first_name']));

        static::__assert_instance_of(Error_Response::class, $result);
        static::__assert_array_has_key('first_name', $result->get_metadata());
    }

    public static function test_a_photo_that_cannot_be_attached_is_a_field_error()
    {
        $result = Frontend_Settings_Profile_Edit_Controller::save_profile(new Request(), static::__profile_edit([
            'profile_photo' => 'no_such_key_' . bin2hex(random_bytes(8)),
        ], ['profile_photo']));

        static::__assert_instance_of(Error_Response::class, $result, 'the save is refused, not reported as a success');
        static::__assert_array_has_key('profile_photo', $result->get_metadata(), 'on the photo field');
    }

    // ---------------------------------------------------------------------
    // field lengths
    // ---------------------------------------------------------------------

    public static function test_an_over_long_profile_value_is_a_field_error()
    {
        $result = Frontend_Settings_Profile_Edit_Controller::save_profile(new Request(), static::__profile_edit([
            'first_name' => str_repeat('f', User_Model::field_length('first_name') + 1),
            'department' => str_repeat('d', User_Profile_Model::field_length('department') + 1),
            'phone' => str_repeat('5', User_Model::field_length('phone') + 1),
        ], ['first_name', 'department', 'phone']));

        static::__assert_instance_of(Error_Response::class, $result, 'the save is refused');
        $fields = $result->get_metadata();
        static::__assert_array_has_key('first_name', $fields);
        static::__assert_array_has_key('department', $fields);
        static::__assert_array_has_key('phone', $fields);
    }

    public static function test_the_bio_limit_is_enforced_on_the_server()
    {
        $limit = Frontend_Settings_Profile_Edit_Controller::BIO_MAX_LENGTH;

        $refused = Frontend_Settings_Profile_Edit_Controller::save_profile(new Request(), static::__profile_edit([
            'bio' => str_repeat('b', $limit + 1),
        ], ['bio']));

        static::__assert_instance_of(Error_Response::class, $refused, 'one character over is refused');
        static::__assert_array_has_key('bio', $refused->get_metadata());

        $accepted = Frontend_Settings_Profile_Edit_Controller::save_profile(new Request(), static::__profile_edit([
            'bio' => str_repeat('b', $limit),
        ], ['bio']));

        static::__assert_array_has_key('redirect', $accepted, 'exactly the limit saves');
    }

    public static function test_an_over_long_invited_name_is_a_field_error()
    {
        $result = Frontend_Settings_User_Management_Controller::add_user(new Request(), [
            'email' => 'settings_length_' . uniqid() . '@example.com',
            'first_name' => str_repeat('f', User_Model::field_length('first_name') + 1),
            'last_name' => 'Long',
            'role_id' => User_Model::ROLE_MANAGER,
        ]);

        static::__assert_instance_of(Error_Response::class, $result, 'the invitation is refused');
        static::__assert_array_has_key('first_name', $result->get_metadata());
    }

    public static function test_an_over_long_edited_user_value_is_a_field_error()
    {
        $user = User_Model::find(self::USER_ID);

        $result = Frontend_Settings_User_Management_Controller::save_user(new Request(), [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name ?: 'First',
            'last_name' => str_repeat('l', User_Model::field_length('last_name') + 1),
            'role_id' => $user->role_id,
            '_changed' => ['last_name'],
            '_loaded_updated_at' => $user->updated_at,
        ]);

        static::__assert_instance_of(Error_Response::class, $result, 'the save is refused');
        static::__assert_array_has_key('last_name', $result->get_metadata());
    }
}
