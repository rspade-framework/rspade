<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Revisions\Revision_Model;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * Task_Derived_Project_Test - tasks.project_id, a derived column.
 *
 * project_id is the project the task's taskable parent chain reaches, otherwise the
 * user-chosen direct_project_id. Covers the chain resolver (direct project /
 * task->task->project / client parent / cycle guard / deep chain), derivation on save,
 * the descendant rederive of Task_Model::save() on a re-parent (every descendant, also below
 * a child whose own value stays), a broken chain restoring each descendant's direct value,
 * the refusals of an assigned project_id, a cascade that leaves no trace in revisions,
 * updated_at or updated_by, and the staff save writing direct_project_id. DB-backed; each
 * test runs in a rolled-back transaction (framework default).
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

    private static function __task(?string $type, ?int $id, ?int $direct_project_id = null): Task_Model
    {
        $t = new Task_Model();
        $t->site_id = self::SITE_ID;
        $t->title = 'T';
        $t->taskable_type = $type;
        $t->taskable_id = $id;
        $t->status = Task_Model::STATUS_PENDING;
        $t->priority = Task_Model::PRIORITY_MEDIUM;
        $t->direct_project_id = $direct_project_id;
        $t->save();
        return $t;
    }

    /** The stored project_id, read fresh. */
    private static function __project_of(Task_Model $task): ?int
    {
        $project_id = Task_Model::find($task->id)->project_id;
        return $project_id === null ? null : (int) $project_id;
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
        static::__assert_equals((int) $p->id, static::__project_of($t), 'project_id is derived on insert, never assigned');
    }

    public static function test_task_to_task_to_project_derives_project()
    {
        $p = static::__project();
        $a = static::__task('Project_Model', $p->id);
        $b = static::__task('Task_Model', $a->id);

        static::__assert_equals($p->id, $b->resolve_chain_project_id(), 'task->task->project resolves through the chain');
        static::__assert_equals((int) $p->id, static::__project_of($b), 'the subtask derives the chain project');
    }

    public static function test_client_parent_is_not_derived()
    {
        $client = static::__client();
        $t = static::__task('Client_Model', $client->id);

        static::__assert_null($t->resolve_chain_project_id(), 'a client parent yields no chain project');
        static::__assert_null(static::__project_of($t), 'no chain project and no direct project: no project');
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
        static::__assert_equals((int) $p->id, static::__project_of($last), 'and the deepest task derives it');
    }

    public static function test_cycle_guard_returns_null()
    {
        // Build A -> B, then point A's parent at B (A<->B). Neither should loop.
        $a = static::__task(null, null);
        $b = static::__task('Task_Model', $a->id);
        $a->taskable_type = 'Task_Model';
        $a->taskable_id = $b->id;
        $a->save();

        static::__assert_null($a->resolve_chain_project_id(), 'cyclic chain resolves to null (A)');
        static::__assert_null($b->resolve_chain_project_id(), 'cyclic chain resolves to null (B)');
    }

    // ---- direct_project_id ------------------------------------------------

    public static function test_direct_project_is_the_project_of_an_unparented_task()
    {
        $p = static::__project();
        $t = static::__task(null, null, $p->id);

        static::__assert_equals((int) $p->id, static::__project_of($t), 'direct_project_id flows to project_id when unparented');

        $other = static::__project();
        $t->direct_project_id = $other->id;
        $t->save();

        static::__assert_equals((int) $other->id, static::__project_of($t), 'a changed direct_project_id is re-derived on save');
    }

    public static function test_direct_project_is_ignored_but_kept_under_a_project_chain()
    {
        $chain = static::__project();
        $mine = static::__project();
        $t = static::__task('Project_Model', $chain->id, $mine->id);

        $fresh = Task_Model::find($t->id);
        static::__assert_equals((int) $chain->id, (int) $fresh->project_id, 'the chain project wins');
        static::__assert_equals((int) $mine->id, (int) $fresh->direct_project_id, 'the direct choice is stored untouched');
    }

    public static function test_rederive_of_a_consistent_record_saves_nothing()
    {
        $p = static::__project();
        $t = static::__task('Project_Model', $p->id);

        static::__assert_false(Task_Model::find($t->id)->rederive(), 'rederive() answers false when the value is already right');
    }

    // ---- re-parent cascade ------------------------------------------------

    public static function test_reparent_rederives_every_descendant()
    {
        $p1 = static::__project();
        $p2 = static::__project();
        $a = static::__task('Project_Model', $p1->id);
        $b = static::__task('Task_Model', $a->id);
        $c = static::__task('Task_Model', $b->id);

        $a = Task_Model::find($a->id);
        $a->taskable_id = $p2->id;
        $a->save();

        static::__assert_equals((int) $p2->id, static::__project_of($a), 'the re-parented task derives its new project');
        static::__assert_equals((int) $p2->id, static::__project_of($b), 'its child follows');
        static::__assert_equals((int) $p2->id, static::__project_of($c), 'its grandchild follows');
    }

    public static function test_reparent_reaches_below_a_child_whose_value_does_not_change()
    {
        // A sits under a client; B under A chose P2 itself; C under B chose nothing.
        $p2 = static::__project();
        $a = static::__task('Client_Model', static::__client()->id);
        $b = static::__task('Task_Model', $a->id, $p2->id);
        $c = static::__task('Task_Model', $b->id);

        static::__assert_equals((int) $p2->id, static::__project_of($b), 'B starts on its direct project');
        static::__assert_null(static::__project_of($c), 'C starts with no project');

        // Moving A under P2: B's value stays P2 (now from the chain), C's changes.
        $a = Task_Model::find($a->id);
        $a->taskable_type = 'Project_Model';
        $a->taskable_id = $p2->id;
        $a->save();

        static::__assert_equals((int) $p2->id, static::__project_of($b), 'B is unchanged');
        static::__assert_equals((int) $p2->id, static::__project_of($c), 'C, below the unchanged B, still follows the chain');
    }

    public static function test_breaking_the_chain_restores_each_descendants_direct_project()
    {
        $p1 = static::__project();
        $p2 = static::__project();
        $p3 = static::__project();
        $a = static::__task('Project_Model', $p1->id);
        $b = static::__task('Task_Model', $a->id, $p2->id);
        $c = static::__task('Task_Model', $b->id, $p3->id);

        static::__assert_equals((int) $p1->id, static::__project_of($c), 'under the chain every task is on P1');

        $a = Task_Model::find($a->id);
        $a->taskable_type = 'Client_Model';
        $a->taskable_id = static::__client()->id;
        $a->save();

        static::__assert_null(static::__project_of($a), 'A had no direct project');
        static::__assert_equals((int) $p2->id, static::__project_of($b), 'B gets its own direct project back');
        static::__assert_equals((int) $p3->id, static::__project_of($c), 'C gets its own direct project back');
    }

    public static function test_the_cascade_leaves_no_revision_and_no_authorship_on_descendants()
    {
        $p1 = static::__project();
        $p2 = static::__project();
        $a = static::__task('Project_Model', $p1->id);
        $b = static::__task('Task_Model', $a->id);

        // A recognisable past stamp on the child, written beside the model.
        DB::table('tasks')->where('id', $b->id)->update([
            'updated_at' => '2020-01-02 03:04:05.678',
            'updated_by_id' => 987654,
        ]);
        $before_revisions = Revision_Model::where('record_type', 'Task_Model')->where('record_id', $b->id)->count();

        $a = Task_Model::find($a->id);
        $a->taskable_id = $p2->id;
        $a->save();

        $row = DB::table('tasks')->where('id', $b->id)->first();
        static::__assert_equals((int) $p2->id, (int) $row->project_id, 'the child was rederived');
        static::__assert_equals('2020-01-02 03:04:05.678', $row->updated_at, 'the child\'s updated_at is untouched');
        static::__assert_equals(987654, (int) $row->updated_by_id, 'the child\'s updated_by is untouched');
        static::__assert_equals(
            $before_revisions,
            Revision_Model::where('record_type', 'Task_Model')->where('record_id', $b->id)->count(),
            'the cascade records no revision for the child'
        );
    }

    // ---- refusals -----------------------------------------------------------

    public static function test_assigning_project_id_is_refused()
    {
        $p = static::__project();

        static::__assert_throws(LogicException::class, function () use ($p) {
            $t = new Task_Model();
            $t->title = 'Assigned';
            $t->project_id = $p->id;
            $t->save();
        }, 'derived column');

        $existing = static::__task(null, null);
        static::__assert_throws(LogicException::class, function () use ($existing, $p) {
            $existing->project_id = $p->id;
            $existing->save();
        }, 'derived column');
    }

    public static function test_form_edit_set_of_project_id_is_refused()
    {
        $p = static::__project();

        static::__assert_throws(LogicException::class, function () use ($p) {
            $task = new Task_Model();
            Form_Edit::run($task, [], function (Form_Edit $edit) use ($p) {
                $edit->set('project_id', $p->id);
            });
        }, 'derived column');
    }

    public static function test_bulk_update_of_project_id_is_refused()
    {
        $t = static::__task(null, null);

        static::__assert_throws(LogicException::class, function () use ($t) {
            Task_Model::where('id', $t->id)->update(['project_id' => 1]);
        }, 'derived column');
    }

    // ---- staff save -----------------------------------------------------------

    public static function test_save_writes_direct_project_and_the_chain_still_wins()
    {
        $p = static::__project();
        $other = static::__project();

        $result = static::__save([
            'title' => 'Chain wins',
            'taskable' => ['type' => 'Project_Model', 'id' => $p->id],
            'direct_project_id' => $other->id,
        ]);

        static::__assert_array_has_key('id', $result, 'save succeeds');
        $task = Task_Model::find($result['id']);
        static::__assert_equals((int) $p->id, (int) $task->project_id, 'the chain project is the project');
        static::__assert_equals((int) $other->id, (int) $task->direct_project_id, 'the submitted direct project is stored');
    }

    public static function test_save_honors_direct_project_when_not_derived()
    {
        $client = static::__client();
        $p = static::__project();

        $result = static::__save([
            'title' => 'User picks',
            'taskable' => ['type' => 'Client_Model', 'id' => $client->id],
            'direct_project_id' => $p->id,
        ]);

        $task = Task_Model::find($result['id']);
        static::__assert_equals((int) $p->id, (int) $task->project_id, 'the direct project is the project when the chain reaches none');
    }

    public static function test_an_edit_without_direct_project_id_keeps_the_stored_one()
    {
        $chain = static::__project();
        $mine = static::__project();
        $t = Task_Model::find(static::__task(null, null, $mine->id)->id);

        // The edit form under a project chain: the picker shows the derived project and the
        // submission carries no direct_project_id, while _changed may still name it.
        $result = static::__save([
            'id' => $t->id,
            'title' => 'Moved under a project',
            'taskable' => ['type' => 'Project_Model', 'id' => $chain->id],
            '_changed' => ['title', 'taskable', 'direct_project_id'],
            '_loaded_updated_at' => $t->updated_at,
        ]);

        static::__assert_array_has_key('id', $result, 'save succeeds');
        $fresh = Task_Model::find($t->id);
        static::__assert_equals((int) $chain->id, (int) $fresh->project_id, 'the chain project is the project');
        static::__assert_equals((int) $mine->id, (int) $fresh->direct_project_id, 'the stored direct project survives');

        // Taking the parent away again brings the direct project back.
        static::__save([
            'id' => $t->id,
            'title' => 'Moved under a project',
            'taskable' => ['type' => null, 'id' => null],
            '_changed' => ['taskable'],
            '_loaded_updated_at' => $fresh->updated_at,
        ]);
        static::__assert_equals((int) $mine->id, static::__project_of($t), 'the direct project is the project again');
    }

    public static function test_save_refuses_a_direct_project_that_does_not_exist()
    {
        $result = static::__save([
            'title' => 'Bad project',
            'direct_project_id' => 999999999,
        ]);

        static::__assert_false(is_array($result) && isset($result['id']), 'an unknown direct project is refused');
    }

    public static function test_save_rejects_cycle()
    {
        $p = static::__project();
        $a = static::__save([
            'title' => 'A',
            'taskable' => ['type' => 'Project_Model', 'id' => $p->id],
        ]);
        $b = static::__save([
            'title' => 'B',
            'taskable' => ['type' => 'Task_Model', 'id' => $a['id']],
        ]);

        // Try to re-parent A under B (A is B's ancestor) -> cycle rejected.
        $result = static::__save([
            'id' => $a['id'],
            'title' => 'A',
            'taskable' => ['type' => 'Task_Model', 'id' => $b['id']],
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
