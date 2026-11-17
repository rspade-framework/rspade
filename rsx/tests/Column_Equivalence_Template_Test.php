<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Revisions\Revision_Model;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Task_Model;

/**
 * "Did it change" is the column's own rule (rsx:man model, COLUMN EQUIVALENCE), shown on this
 * application's records:
 *
 *   - Task_Model.hour_estimate is a DECIMAL(8,2) that is not money and Task_Model.notes a
 *     nullable text column, on a revisioned model. Assigning the stored values in other shapes
 *     ("1.5", 1.5 over 1.50; '' over NULL) - directly, or through the task save endpoint, which
 *     casts hours to a float - is no change: nothing is dirty, no revision is recorded and
 *     updated_at / updated_by stay where they were.
 *   - A real change is recorded, its "after" in the column's storage form ("2.00" for 2).
 *   - Client_Model.portal_last_activity_at is bookkeeping, declared revision_exclude: a portal
 *     visit stamps it with no revision and leaves updated_at, the edit form's version, alone.
 */
class Column_Equivalence_Template_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** A recognisable past stamp, so "unmoved" cannot pass by landing in the same millisecond. */
    private const PAST_STAMP = '2020-01-02 03:04:05.678';

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        static::__acting_as_user(1);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    /** A task holding 1.50 hours and no notes, its audit stamp moved into the past. */
    private static function __task(): Task_Model
    {
        $task = new Task_Model();
        $task->site_id = self::SITE_ID;
        $task->title = 'Column Equivalence ' . uniqid();
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->hour_estimate = '1.50';
        $task->notes = null;
        $task->save();

        DB::table('tasks')->where('id', $task->id)->update([
            'updated_at' => self::PAST_STAMP,
            'updated_by_id' => 987654,
        ]);

        return Task_Model::find($task->id);
    }

    private static function __revision_count(string $model, int $id): int
    {
        return Revision_Model::where('record_type', $model)->where('record_id', $id)->count();
    }

    /** The stored row, read beside the model. */
    private static function __row(string $table, int $id): object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    // ---------------------------------------------------------------------
    // equal values in other shapes
    // ---------------------------------------------------------------------

    public static function test_equal_values_assigned_in_other_shapes_are_no_change()
    {
        foreach (['1.5', 1.5, '1.50'] as $hours) {
            $task = static::__task();
            $revisions = static::__revision_count('Task_Model', $task->id);

            $task->hour_estimate = $hours;
            $task->notes = '';

            $shown = var_export($hours, true);
            static::__assert_false($task->isDirty(), "{$shown} over 1.50 and '' over NULL are not dirty");

            $task->save();

            $row = static::__row('tasks', $task->id);
            static::__assert_equals('1.50', (string) $row->hour_estimate, "{$shown}: the stored value is unchanged");
            static::__assert_null($row->notes, "{$shown}: notes stays NULL");
            static::__assert_equals(self::PAST_STAMP, $row->updated_at, "{$shown}: updated_at did not move");
            static::__assert_equals(987654, (int) $row->updated_by_id, "{$shown}: updated_by did not move");
            static::__assert_equals($revisions, static::__revision_count('Task_Model', $task->id), "{$shown}: no revision");
        }
    }

    public static function test_the_save_endpoint_resubmitting_the_stored_values_changes_nothing()
    {
        $task = static::__task();
        $revisions = static::__revision_count('Task_Model', $task->id);

        $result = Frontend_Tasks_Controller::save(new Request(), [
            'id' => $task->id,
            'title' => $task->title,
            'status' => (string) $task->status,
            'priority' => (string) $task->priority,
            // As a form submits them: hours typed without the trailing zero, an empty notes box.
            'hour_estimate' => '1.5',
            'notes' => '',
            '_changed' => ['hour_estimate', 'notes'],
            '_loaded_updated_at' => $task->updated_at,
        ]);

        static::__assert_array_has_key('id', $result, 'the save succeeds');

        $row = static::__row('tasks', $task->id);
        static::__assert_equals('1.50', (string) $row->hour_estimate, 'the stored hours are unchanged');
        static::__assert_null($row->notes, 'notes stays NULL');
        static::__assert_equals(self::PAST_STAMP, $row->updated_at, 'updated_at did not move');
        static::__assert_equals($revisions, static::__revision_count('Task_Model', $task->id), 'no revision was recorded');
    }

    public static function test_a_real_change_is_recorded_in_its_storage_form()
    {
        $task = static::__task();

        $task->hour_estimate = 2;
        $task->save();

        $row = static::__row('tasks', $task->id);
        static::__assert_equals('2.00', (string) $row->hour_estimate, 'the new hours are stored');
        static::__assert_not_equals(self::PAST_STAMP, $row->updated_at, 'updated_at moved');

        $revision = Revision_Model::where('record_type', 'Task_Model')
            ->where('record_id', $task->id)
            ->where('operation_id', Revision_Model::OPERATION_UPDATE)
            ->orderBy('id', 'desc')
            ->first();

        static::__assert_not_null($revision, 'the change was recorded');
        static::__assert_equals(['hour_estimate' => ['1.50', '2.00']], $revision->diff(), 'both sides in the column\'s storage form');
    }

    // ---------------------------------------------------------------------
    // a bookkeeping column
    // ---------------------------------------------------------------------

    public static function test_portal_activity_is_bookkeeping()
    {
        static::__assert_true(
            in_array('portal_last_activity_at', Client_Model::revision_excluded_columns(), true),
            'portal_last_activity_at is declared revision_exclude'
        );

        $client = new Client_Model();
        $client->name = 'Column Equivalence ' . uniqid();
        $client->save();

        DB::table('clients')->where('id', $client->id)->update(['updated_at' => self::PAST_STAMP]);
        $client = Client_Model::find($client->id);
        $revisions = static::__revision_count('Client_Model', $client->id);

        $client->touch_portal_activity();

        $row = static::__row('clients', $client->id);
        static::__assert_not_null($row->portal_last_activity_at, 'the activity stamp was written');
        static::__assert_equals(self::PAST_STAMP, $row->updated_at, 'updated_at did not move');
        static::__assert_equals($revisions, static::__revision_count('Client_Model', $client->id), 'no revision was recorded');
    }
}
