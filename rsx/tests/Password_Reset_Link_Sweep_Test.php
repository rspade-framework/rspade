<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Portal\Portal_User_Model;
use Rsx\Models\Login_User_Password_Reset_Model;
use Rsx\Models\Portal_Password_Reset_Model;

/**
 * Password_Reset_Link_Service::sweep, the nightly maintenance pass over password reset links:
 * it deletes every USED link (completed or superseded) and every EXPIRED one, staff and portal,
 * keeps every live link, and reports what it removed in its state and summary.
 *
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Password_Reset_Link_Sweep_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'sweep_staff_' . uniqid() . '@example.com';
        $login_user->set_password('sweep-fixture-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    private static function __portal_user(): Portal_User_Model
    {
        Portal_Session::set_site_id(self::SITE_ID);
        static::__acting_as_site(self::SITE_ID);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'sweep_portal_' . uniqid() . '@example.com';
        $portal_user->set_password('sweep-fixture-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        return $portal_user;
    }

    private static function __staff_exists(int $id): bool
    {
        return Login_User_Password_Reset_Model::where('id', $id)->exists();
    }

    private static function __portal_exists(int $id): bool
    {
        return Portal_Password_Reset_Model::without_site_scope(fn () => Portal_Password_Reset_Model::where('id', $id)->exists());
    }

    public static function test_the_sweep_deletes_used_and_expired_links_and_keeps_live_ones()
    {
        static::__acting_as_site(self::SITE_ID);

        // Staff: a superseded link (minting a second marks the first used), a claimed link, an
        // expired one, and the live one each identity still holds.
        $staff_a = static::__login_user();
        $staff_superseded = Login_User_Password_Reset_Model::create_for($staff_a);
        $staff_live = Login_User_Password_Reset_Model::create_for($staff_a);

        $staff_b = static::__login_user();
        $staff_claimed = Login_User_Password_Reset_Model::create_for($staff_b);
        static::__assert_true($staff_claimed->claim(), 'the fixture link is claimed');

        $staff_c = static::__login_user();
        $staff_expired = Login_User_Password_Reset_Model::create_for($staff_c);
        $staff_expired->expires_at = now()->subMinute()->toDateTimeString();
        $staff_expired->save();

        // Portal: the same three shapes on site 1.
        $portal_a = static::__portal_user();
        $portal_superseded = Portal_Password_Reset_Model::create_for_user($portal_a);
        $portal_live = Portal_Password_Reset_Model::create_for_user($portal_a);

        $portal_claimed = Portal_Password_Reset_Model::create_for_user(static::__portal_user());
        static::__assert_true($portal_claimed->claim(), 'the fixture portal link is claimed');

        $portal_expired = Portal_Password_Reset_Model::create_for_user(static::__portal_user());
        $portal_expired->expires_at = now()->subMinute()->toDateTimeString();
        $portal_expired->save();

        // A scheduled run serves no tenant.
        static::__reset_session();

        $run = Task::internal('Password_Reset_Link_Service', 'sweep');
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the sweep completed');

        static::__assert_false(static::__staff_exists((int) $staff_superseded->id), 'a superseded staff link is deleted');
        static::__assert_false(static::__staff_exists((int) $staff_claimed->id), 'a claimed staff link is deleted');
        static::__assert_false(static::__staff_exists((int) $staff_expired->id), 'an expired staff link is deleted');
        static::__assert_true(static::__staff_exists((int) $staff_live->id), 'a live staff link is kept');

        static::__assert_false(static::__portal_exists((int) $portal_superseded->id), 'a superseded portal link is deleted');
        static::__assert_false(static::__portal_exists((int) $portal_claimed->id), 'a claimed portal link is deleted');
        static::__assert_false(static::__portal_exists((int) $portal_expired->id), 'an expired portal link is deleted');
        static::__assert_true(static::__portal_exists((int) $portal_live->id), 'a live portal link is kept');

        // The test database may hold spent links of other runs' making, so the counts are floors.
        $state = $run->state();
        static::__assert_true(($state['deleted_staff'] ?? 0) >= 3, 'the state reports the staff rows: ' . json_encode($state));
        static::__assert_true(($state['deleted_portal'] ?? 0) >= 3, 'and the portal rows: ' . json_encode($state));
        static::__assert_equals(
            "Deleted {$state['deleted_staff']} staff and {$state['deleted_portal']} portal used or expired password reset link(s).",
            (string) $run->summary(),
            'the summary reports the counts'
        );
    }

    public static function test_a_sweep_with_nothing_spent_deletes_nothing()
    {
        static::__acting_as_site(self::SITE_ID);

        // Clear whatever spent links the test database already holds, then leave one live link.
        Task::internal('Password_Reset_Link_Service', 'sweep');
        $live = Login_User_Password_Reset_Model::create_for(static::__login_user());

        static::__reset_session();

        $run = Task::internal('Password_Reset_Link_Service', 'sweep');

        static::__assert_equals(['deleted_staff' => 0, 'deleted_portal' => 0], $run->state(), 'nothing was spent');
        static::__assert_true(static::__staff_exists((int) $live->id), 'the live link is kept');
    }
}
