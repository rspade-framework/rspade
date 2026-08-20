<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * Task_Derived_Project_Test - the DERIVED tasks.project_id (see docs.dev/BACKLOG.md B-1).
 *
 * Covers the chain resolver (direct project / task->task->project / task->client editable /
 * cycle guard / deep chain), the post-save descendant cascade, and the save path honoring a
 * user project value only when the chain does NOT resolve. DB-backed; each test runs in a
 * rolled-back transaction (framework default).
 */
class Task_Derived_Project_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __client(): Client_Model
    {
        $c = new Client_Model();
        $c->site_id = self::SITE_ID;
        $c->name = 'Derived Test Client';
        $c->save();
        return $c;
    }

    private static function __project(): Project_Model
    {
        $p = new Project_Model();
        $p->site_id = self::SITE_ID;
        $p->name = 'Derived Test Project';
        $p->client_id = static::__client()->id;
        $p->status = Project_Model::STATUS_ACTIVE;
        $p->priority = Project_Model::PRIORITY_MEDIUM;
        $p->save();
        return $p;
    }

    private static function __task(?string $type, ?int $id, ?int $project_id = null): Task_Model
    {
        $t = new Task_Model();
        $t->site_id = self::SITE_ID;
        $t->title = 'T';
        $t->taskable_type = $type;
        $t->taskable_id = $id;
        $t->status = Task_Model::STATUS_PENDING;
        $t->priority = Task_Model::PRIORITY_MEDIUM;
        $t->project_id = $project_id;
        $t->save();
        return $t;
    }

    private static function __save(array $params)
    {
        return Frontend_Tasks_Controller::save(new Request(), $params);
    }

    // ---- chain resolution -------------------------------------------------

    public static function test_direct_project_parent_derives_project()
    {
        $p = static::__project();
        $t = static::__task('Project_Model', $p->id);

        static::__assert_equals($p->id, $t->resolve_chain_project_id(), 'direct project parent resolves');
        static::__assert_true($t->is_project_id_derived(), 'is derived when parented to a project');
    }

    public static function test_task_to_task_to_project_derives_project()
    {
        $p = static::__project();
        $a = static::__task('Project_Model', $p->id);
        $b = static::__task('Task_Model', $a->id);

        static::__assert_equals($p->id, $b->resolve_chain_project_id(), 'task->task->project resolves through the chain');
    }

    public static function test_client_parent_is_not_derived()
    {
        $client = static::__client();
        $t = static::__task('Client_Model', $client->id);

        static::__assert_null($t->resolve_chain_project_id(), 'a client parent yields no chain project');
        static::__assert_false($t->is_project_id_derived(), 'client-parented task is user-editable');
    }

    public static function test_no_parent_is_not_derived()
    {
        $t = static::__task(null, null);
        static::__assert_null($t->resolve_chain_project_id(), 'no parent -> no chain project');
    }

    public static function test_deep_chain_resolves()
    {
        $p = static::__project();
        $prev_type = 'Project_Model';
        $prev_id = $p->id;
        $last = null;
        for ($i = 0; $i < 6; $i++) {
            $last = static::__task($prev_type, $prev_id);
            $prev_type = 'Task_Model';
            $prev_id = $last->id;
        }
        static::__assert_equals($p->id, $last->resolve_chain_project_id(), 'a 6-deep chain still resolves to the root project');
    }

    public static function test_cycle_guard_returns_null()
    {
        // Build A -> B, then point B's parent at A (A<->B). Neither should loop.
        $a = static::__task('Task_Model', 0); // temporary
        $b = static::__task('Task_Model', $a->id);
        $a->taskable_id = $b->id;
        $a->save();

        static::__assert_null($a->resolve_chain_project_id(), 'cyclic chain resolves to null (A)');
        static::__assert_null($b->resolve_chain_project_id(), 'cyclic chain resolves to null (B)');
    }

    // ---- cascade ----------------------------------------------------------

    public static function test_cascade_recomputes_descendants_on_reparent()
    {
        $p = static::__project();
        $a = static::__task('Project_Model', $p->id, $p->id);
        $b = static::__task('Task_Model', $a->id, $p->id);

        // Re-parent A to a client -> A no longer derives -> cascade should null B.
        $client = static::__client();
        $a->taskable_type = 'Client_Model';
        $a->taskable_id = $client->id;
        $a->project_id = $a->resolve_chain_project_id(); // null
        $a->save();
        $a->recompute_descendant_project_ids();

        $b_fresh = Task_Model::find($b->id);
        static::__assert_null($b_fresh->project_id, 'descendant project_id recomputed to null after ancestor left the project chain');
    }

    // ---- save path (derived vs editable) ----------------------------------

    public static function test_save_ignores_user_project_when_chain_derives()
    {
        $p = static::__project();
        $other = static::__project();

        // Parented to $p, but the user tries to force $other->id: chain must win.
        $result = static::__save([
            'title' => 'Chain wins',
            'taskable_type' => 'Project_Model',
            'taskable_id' => $p->id,
            'project_id' => $other->id,
        ]);

        static::__assert_array_has_key('id', $result, 'save succeeds');
        $task = Task_Model::find($result['id']);
        static::__assert_equals($p->id, (int) $task->project_id, 'derived chain project overrides the user-supplied project');
    }

    public static function test_save_honors_user_project_when_not_derived()
    {
        $client = static::__client();
        $p = static::__project();

        // Parented to a client (no chain project): the user value is honored.
        $result = static::__save([
            'title' => 'User picks',
            'taskable_type' => 'Client_Model',
            'taskable_id' => $client->id,
            'project_id' => $p->id,
        ]);

        $task = Task_Model::find($result['id']);
        static::__assert_equals($p->id, (int) $task->project_id, 'user-set project honored when the chain does not resolve');
    }

    public static function test_save_no_parent_honors_user_project()
    {
        $p = static::__project();
        $result = static::__save([
            'title' => 'Orphan with project',
            'project_id' => $p->id,
        ]);

        $task = Task_Model::find($result['id']);
        static::__assert_null($task->taskable_type, 'no parent stored');
        static::__assert_equals($p->id, (int) $task->project_id, 'user project honored for a parentless task');
    }

    public static function test_save_rejects_cycle()
    {
        $p = static::__project();
        $a = static::__save([
            'title' => 'A',
            'taskable_type' => 'Project_Model',
            'taskable_id' => $p->id,
        ]);
        $b = static::__save([
            'title' => 'B',
            'taskable_type' => 'Task_Model',
            'taskable_id' => $a['id'],
        ]);

        // Try to re-parent A under B (A is B's ancestor) -> cycle rejected.
        $result = static::__save([
            'id' => $a['id'],
            'title' => 'A',
            'taskable_type' => 'Task_Model',
            'taskable_id' => $b['id'],
        ]);

        static::__assert_false(is_array($result) && isset($result['id']), 'a cycle-creating parent is rejected at the controller');
    }

    public static function test_save_validates_hour_estimate()
    {
        $result = static::__save([
            'title' => 'Bad hours',
            'hour_estimate' => -5,
        ]);
        static::__assert_false(is_array($result) && isset($result['id']), 'negative hour_estimate is rejected');
    }
}
