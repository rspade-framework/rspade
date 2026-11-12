<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * A task's parent is ONE fact in two columns, and a stale save does not put an old parent back.
 *
 * The edit form sends the parent as the one field `taskable` = {type, id}; it is stored in
 * the taskable_type / taskable_id pair. Task_Model declares the pair its `parent` fact group
 * and the save passes Task_Model::EDIT_FIELDS (both columns -> taskable) to Form_Edit, so
 * another editor's re-parent - in either column - is a field_conflict naming the group for a
 * stale submission that also sends `taskable`, while a stale submission of another field
 * keeps their parent.
 */
class Task_Parent_Conflict_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        static::__acting_as_user(1);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Parent Conflict Client';
        $client->save();

        return $client;
    }

    private static function __project(Client_Model $client): Project_Model
    {
        $project = new Project_Model();
        $project->name = 'Parent Conflict Project';
        $project->client_id = $client->id;
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;
        $project->save();

        return $project;
    }

    private static function __task(Project_Model $project): Task_Model
    {
        $task = new Task_Model();
        $task->title = 'Parent Conflict Task';
        $task->taskable_type = 'Project_Model';
        $task->taskable_id = $project->id;
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->save();

        return Task_Model::find($task->id);
    }

    /** Another editor's save of the parent, a clear millisecond after the form loaded. */
    private static function __other_reparent(Task_Model $task, string $type, int $id): void
    {
        usleep(3000);
        $other = Task_Model::find($task->id);
        $other->taskable_type = $type;
        $other->taskable_id = $id;
        $other->save();
    }

    /** A staff edit submission made from the version $loaded, changing only $changed. */
    private static function __submit(Task_Model $task, string $loaded, array $values, array $changed)
    {
        usleep(3000);

        return Frontend_Tasks_Controller::save(new Request(), $values + [
            'id' => $task->id,
            'title' => $task->title,
            '_changed' => $changed,
            '_loaded_updated_at' => $loaded,
        ]);
    }

    public static function test_a_stale_parent_change_is_asked_about_naming_the_group()
    {
        $client = static::__client();
        $first = static::__project($client);
        $second = static::__project($client);
        $task = static::__task($first);
        $loaded = $task->updated_at;

        static::__other_reparent($task, 'Client_Model', $client->id);
        $result = static::__submit($task, $loaded, ['taskable' => ['type' => 'Project_Model', 'id' => $second->id]], ['taskable']);

        static::__assert_instance_of(Error_Response::class, $result, 'a question was asked');
        $question = $result->get_metadata()['question'];
        static::__assert_equals('field_conflict', $question['kind']);
        static::__assert_equals(['taskable'], array_column($question['fields'], 'field'));
        static::__assert_equals(['Parent'], $question['fields'][0]['groups'], 'the fact group names the change');

        $stored = Task_Model::find($task->id);
        static::__assert_equals('Client_Model', $stored->taskable_type, 'their parent stands until the user answers');
    }

    public static function test_a_change_to_one_column_of_the_pair_is_a_change_to_the_parent()
    {
        $client = static::__client();
        $first = static::__project($client);
        $second = static::__project($client);
        $task = static::__task($first);
        $loaded = $task->updated_at;

        // Same type, another id: only taskable_id moves.
        static::__other_reparent($task, 'Project_Model', $second->id);
        $result = static::__submit($task, $loaded, ['taskable' => ['type' => 'Project_Model', 'id' => $first->id]], ['taskable']);

        static::__assert_instance_of(Error_Response::class, $result, 'a question was asked');
        static::__assert_equals(['taskable_id'], array_column($result->get_metadata()['question']['fields'][0]['changes'], 'column'));
        static::__assert_equals($second->id, (int) Task_Model::find($task->id)->taskable_id, 'their parent stands');
    }

    public static function test_a_stale_save_of_another_field_keeps_their_parent()
    {
        $client = static::__client();
        $task = static::__task(static::__project($client));
        $loaded = $task->updated_at;

        static::__other_reparent($task, 'Client_Model', $client->id);
        $result = static::__submit($task, $loaded, ['title' => 'Renamed'], ['title']);

        static::__assert_array_has_key('id', $result, 'no question: the title was not changed by anyone else');
        $stored = Task_Model::find($task->id);
        static::__assert_equals('Renamed', $stored->title);
        static::__assert_equals('Client_Model', $stored->taskable_type, 'their parent stands');
    }
}
