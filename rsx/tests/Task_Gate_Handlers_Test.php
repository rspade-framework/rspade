<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Handlers\Task_Gate_Handlers;

/**
 * The application's task gates grant least privilege: a staff user sees and acts on only the
 * runs THEY started of the tasks listed in Task_Gate_Handlers::USER_TASKS, and only with the
 * actions each entry lists.
 *
 * The handlers are called directly. Through Task_Gates the baseline user (a developer) would
 * pass every gate without the handlers being asked, which is the framework's rule and not
 * what this class tests.
 */
class Task_Gate_Handlers_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    private const OTHER_USER_ID = 999999;

    public static function setup(): void
    {
        static::__acting_as_user(self::USER_ID);
    }

    /** A run of $service::$method dispatched by staff user $user_id. */
    private static function __plant(string $service, string $method, int $user_id): Task_Run_Model
    {
        $id = Task_Runner::insert_row(Task::resolve_task_class($service, $method), $method, [], Task_Run_Model::ORIGIN_DISPATCHED, [
            'status_id' => Task_Run_Model::STATUS_PENDING,
            'dispatched_by_type' => Type_Ref_Registry::class_to_id('User_Model'),
            'dispatched_by_id' => $user_id,
        ]);

        return Task_Run_Model::find($id);
    }

    public static function test_a_user_sees_only_their_own_runs_of_listed_tasks()
    {
        $own_listed = static::__plant('Task_Showcase_Service', 'walk', self::USER_ID);
        $own_unlisted = static::__plant('Task_Retention_Service', 'sweep', self::USER_ID);
        $others_listed = static::__plant('Task_Showcase_Service', 'walk', self::OTHER_USER_ID);

        static::__assert_true(Task_Gate_Handlers::view_own_runs(['task' => $own_listed, 'user' => null]), 'their run of a listed task');
        static::__assert_false(Task_Gate_Handlers::view_own_runs(['task' => $own_unlisted, 'user' => null]), 'a task nobody granted stays invisible, even their own run');
        static::__assert_false(Task_Gate_Handlers::view_own_runs(['task' => $others_listed, 'user' => null]), 'another user\'s run');

        $visible = Task_Gate_Handlers::scope_own_runs(Task_Run_Model::query())
            ->whereIn('id', [$own_listed->id, $own_unlisted->id, $others_listed->id])
            ->pluck('id')
            ->all();
        static::__assert_equals([$own_listed->id], array_map('intval', $visible), 'the scope grants exactly what the gate grants');
    }

    public static function test_a_user_takes_only_the_actions_their_tasks_entry_lists()
    {
        $own_listed = static::__plant('Task_Showcase_Service', 'walk', self::USER_ID);
        $own_unlisted = static::__plant('Task_Retention_Service', 'sweep', self::USER_ID);

        foreach (Task_Gate_Handlers::USER_TASKS['Task_Showcase_Service::walk'] as $action) {
            static::__assert_true(Task_Gate_Handlers::control_own_runs(['task' => $own_listed, 'user' => null, 'action' => $action]), "{$action} is granted");
        }
        static::__assert_false(Task_Gate_Handlers::control_own_runs(['task' => $own_listed, 'user' => null, 'action' => 'force_kill']), 'force_kill is never granted');
        static::__assert_false(Task_Gate_Handlers::control_own_runs(['task' => $own_unlisted, 'user' => null, 'action' => 'stop']), 'no action on a task nobody granted');
    }
}
