<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Settings\ProfileDisplay\Frontend_Settings_Profile_Display_Controller;
use Rsx\App\Frontend\Settings\UserManagement\Frontend_Settings_User_Management_Controller;

/**
 * The Developer chip: what the three screens that show it are told.
 *
 * A developer is a per-identity flag on the LOGIN identity (login_users.is_developer),
 * never a role and never a hostname. The framework owns the column and Session::is_developer()
 * (framework-tested); what this application owns is which screens SAY so, and the properties
 * that make the chip trustworthy are:
 *
 *   - every one of the three payloads carries the key ALWAYS, as a real boolean - a chip
 *     driven by a key that is sometimes absent and sometimes the string "0" renders for the
 *     wrong people, and "0" is truthy in JavaScript;
 *   - the flag is read from the LOGIN identity, so a site membership whose login identity is
 *     not a developer is answered false even though the two rows share an email;
 *   - a membership with no login identity at all (an invitation never accepted) is false
 *     rather than an error;
 *   - the list answers for every row from ONE query - the join - so the grid can never grow
 *     a per-row lookup to say it.
 *
 * Endpoints are invoked directly at the controller layer; the returned value is the
 * endpoint's own contract.
 */
class Developer_Chip_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** The baseline account, which Rsx_Initial_User::create() makes a developer. */
    private const DEVELOPER_USER_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_user(self::DEVELOPER_USER_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    /**
     * A site membership behind a login identity that is not a developer.
     */
    private static function __ordinary_user(string $suffix): User_Model
    {
        $email = 'developer_chip_' . $suffix . '_' . uniqid() . '@example.com';

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
        $user->first_name = 'Ordinary';
        $user->last_name = 'Member';
        $user->role_id = User_Model::ROLE_VIEWER;
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    /**
     * A membership whose invitation was never accepted: no login identity to ask.
     */
    private static function __uninvited_user(): User_Model
    {
        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = null;
        $user->email = 'developer_chip_pending_' . uniqid() . '@example.com';
        $user->first_name = 'Never';
        $user->last_name = 'Accepted';
        $user->role_id = User_Model::ROLE_VIEWER;
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    private static function __grid_row(int $user_id): ?array
    {
        $grid = Frontend_Settings_User_Management_Controller::datagrid_fetch(
            new Request(),
            ['per_page' => 500]
        );

        foreach ($grid['records'] as $record) {
            if ((int) $record['id'] === $user_id) {
                return $record;
            }
        }

        return null;
    }

    // ============================================================================
    // THE OWN-PROFILE SCREEN
    // ============================================================================

    public static function test_the_profile_payload_says_a_developer_is_one()
    {
        $profile = Frontend_Settings_Profile_Display_Controller::get_profile(new Request(), []);

        static::__assert_array_has_key('is_developer', $profile);
        static::__assert_true(
            $profile['is_developer'] === true,
            'The baseline account is a developer, and the key is a real boolean'
        );
    }

    public static function test_the_profile_payload_says_an_ordinary_member_is_not()
    {
        $user = static::__ordinary_user('profile');

        static::__acting_as_user((int) $user->id);

        $profile = Frontend_Settings_Profile_Display_Controller::get_profile(new Request(), []);

        static::__assert_true(
            $profile['is_developer'] === false,
            'Present and false - never absent, so the template asks one question'
        );

        static::__acting_as_user(self::DEVELOPER_USER_ID);
    }

    // ============================================================================
    // THE ADMIN USER VIEW
    // ============================================================================

    public static function test_the_admin_view_reports_the_flag()
    {
        $viewed = Frontend_Settings_User_Management_Controller::get_user(
            new Request(),
            ['id' => self::DEVELOPER_USER_ID]
        );

        static::__assert_true($viewed['is_developer'] === true);
    }

    public static function test_the_admin_view_reports_an_ordinary_member()
    {
        $user = static::__ordinary_user('view');

        $viewed = Frontend_Settings_User_Management_Controller::get_user(
            new Request(),
            ['id' => $user->id]
        );

        static::__assert_true($viewed['is_developer'] === false);
    }

    public static function test_a_membership_with_no_login_identity_is_not_a_developer()
    {
        $user = static::__uninvited_user();

        $viewed = Frontend_Settings_User_Management_Controller::get_user(
            new Request(),
            ['id' => $user->id]
        );

        static::__assert_true(
            $viewed['is_developer'] === false,
            'An invitation that was never accepted has no identity to ask, and that is false'
        );
    }

    // ============================================================================
    // THE ADMIN LIST
    // ============================================================================

    public static function test_the_grid_row_carries_the_flag()
    {
        $row = static::__grid_row(self::DEVELOPER_USER_ID);

        static::__assert_not_null($row, 'The baseline account is in the grid');
        static::__assert_true(
            $row['is_developer'] === true,
            'The joined column reaches the row as a boolean'
        );
    }

    public static function test_the_grid_row_of_an_ordinary_member_is_false()
    {
        $user = static::__ordinary_user('grid');

        $row = static::__grid_row((int) $user->id);

        static::__assert_not_null($row);
        static::__assert_true(
            $row['is_developer'] === false,
            'False, not the string "0" - which JavaScript would read as a developer'
        );
    }

    public static function test_the_grid_row_of_an_uninvited_member_is_false()
    {
        $user = static::__uninvited_user();

        $row = static::__grid_row((int) $user->id);

        static::__assert_not_null($row);
        static::__assert_true(
            $row['is_developer'] === false,
            'The left join contributes NULL for a membership with no login identity'
        );
    }

    /**
     * The join must not change what the grid IS. Two properties one query can silently
     * break: the row count (a join that multiplies rows), and the search filter (an
     * unqualified column over a join is ambiguous SQL, which is an error, not a wrong
     * answer - so the filter returning the row proves the qualification).
     */
    public static function test_the_join_leaves_the_grid_intact()
    {
        $user = static::__ordinary_user('filter');

        $unfiltered = Frontend_Settings_User_Management_Controller::datagrid_fetch(
            new Request(),
            ['per_page' => 500]
        );

        $matching = 0;

        foreach ($unfiltered['records'] as $record) {
            if ((int) $record['id'] === (int) $user->id) {
                $matching++;
            }
        }

        static::__assert_equals(1, $matching, 'One row per user, not one per join match');
        static::__assert_equals(
            $unfiltered['total'],
            count($unfiltered['records']),
            'The count matches the rows it counted'
        );

        $filtered = Frontend_Settings_User_Management_Controller::datagrid_fetch(
            new Request(),
            ['per_page' => 500, 'filter' => $user->email, 'sort' => 'email', 'order' => 'asc']
        );

        static::__assert_equals(1, count($filtered['records']), 'The search finds it');
        static::__assert_equals(
            (int) $user->id,
            (int) $filtered['records'][0]['id'],
            'and sorting by a column both tables carry resolves to the users one'
        );
    }
}
