<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Env\Rsx_Initial_User;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * This application's own user.initial.created handlers (/rsx/handlers/Initial_User_Handlers.php).
 *
 * The framework guarantees the event fires and that a caller-chosen role survives it
 * (framework suite, initial_user concern). What this application does with the founder -
 * the top role, and membership of the Administrators group - is its own contract, so it
 * is asserted here.
 *
 * Two states are covered: an account this test creates, and the COMMITTED baseline the
 * runner seeded through the same function, which proves the handlers ran during
 * provisioning and so may be assumed by every other test in the suite.
 */
class Initial_User_Handler_Test extends Rsx_Test_Abstract
{
    /**
     * Remove the initial account, so the calling test faces the state a fresh install
     * is in. Raw deletes: `users` soft-deletes and is site-scoped, and neither of those
     * frees up id 1.
     */
    private static function __clear_initial_user(): void
    {
        DB::statement('DELETE FROM users WHERE id = ?', [Rsx_Initial_User::INITIAL_USER_ID]);
        DB::statement('DELETE FROM login_users WHERE id = ?', [Rsx_Initial_User::INITIAL_USER_ID]);
    }

    /**
     * One raw row, or null. Raw rather than the ORM: the assertions are about what is ON
     * DISK at a given id, and `users` soft-deletes and is site-scoped - a model read
     * would hide exactly the rows these tests exist to see.
     */
    private static function __row(string $sql, array $bindings = []): ?object
    {
        $rows = DB::select($sql, $bindings);

        return $rows[0] ?? null;
    }

    public static function test_application_handlers_run_on_creation()
    {
        static::__clear_initial_user();

        $user = Rsx_Initial_User::create('handled@rspade.test', 'a-password-nobody-uses', [
            'site_id' => 1,
            'source' => Rsx_Initial_User::SOURCE_MANUAL,
        ]);

        // This application's handler (/rsx/handlers/Initial_User_Handlers.php)
        // makes the founder a root admin and puts them in the Administrators group. That it
        // ran here is the proof that a handler in /rsx/handlers/ is discovered and fired.
        $stored = static::__row('SELECT role_id FROM users WHERE id = ?', [$user->id]);
        static::__assert_equals(
            User_Model::ROLE_ROOT_ADMIN,
            (int) $stored->role_id,
            'the application handler assigned the founder its top role'
        );

        // Newest first: the committed baseline carries an Administrators group of its own
        // (the same handler ran when the runner seeded it), and the one this test is about
        // is the one just created.
        $group = static::__row(
            'SELECT id FROM user_groups WHERE site_id = 1 AND name = ? AND deleted_at IS NULL'
            . ' ORDER BY id DESC LIMIT 1',
            ['Administrators']
        );

        static::__assert_not_empty($group, 'the application handler created the Administrators group');
        static::__assert_not_empty(
            static::__row(
                'SELECT id FROM user_group_members WHERE user_group_id = ? AND user_id = ?',
                [$group->id, $user->id]
            ),
            'the founder is a member of that group'
        );
    }

    public static function test_baseline_carries_the_handler_rows()
    {
        // Committed state, not built by this test: the runner seeded the baseline account
        // through Rsx_Initial_User, so the event fired during provisioning and the
        // application's handler rows are part of every test's starting database.
        $group = static::__row(
            'SELECT id FROM user_groups WHERE name = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            ['Administrators']
        );

        static::__assert_not_empty($group, 'the test baseline carries the Administrators group');
        static::__assert_not_empty(
            static::__row(
                'SELECT id FROM user_group_members WHERE user_group_id = ? AND user_id = ?',
                [$group->id, Rsx_Initial_User::INITIAL_USER_ID]
            ),
            'the baseline user is a member of it'
        );
    }
}
