<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Param_Validator;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Api\V1\Tasks_Api_Controller;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * Creating and updating a task over the external API (POST /api/v1/tasks/create,
 * POST /api/v1/tasks/:id/update) runs the staff save's rules and write: Task_Model::value_errors()
 * refuses the same values with the same messages on both paths, a parent of another site or
 * a deleted one is refused, the derived project follows the parent chain, and an update
 * writes only the fields it sent. The project a write sends is direct_project_id; the
 * derived project_id is not a write parameter, so the dispatcher's parameter check refuses
 * it as unknown. Over the real dispatch the API carries plain values: `description` (a Rich_Text
 * column) reads as its HTML string, and the string read, sent back, stores the same value.
 */
class Task_Api_Write_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        static::__acting_as_user(1);
    }

    public static function teardown(): void
    {
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        static::__reset_session();
    }

    private static function __project(): Project_Model
    {
        $client = new Client_Model();
        $client->name = 'Task API client ' . uniqid();
        $client->save();

        $project = new Project_Model();
        $project->name = 'Task API project ' . uniqid();
        $project->client_id = $client->id;
        $project->save();

        return $project;
    }

    /** A project on a second tenant, created there and then left behind. */
    private static function __foreign_project(): Project_Model
    {
        $site = new Site_Model();
        $site->slug = 'task-api-' . uniqid();
        $site->name = 'Task API probe tenant';
        $site->save();

        static::__acting_as_site((int) $site->id);
        $project = static::__project();
        static::__acting_as_site(self::SITE_ID);

        return $project;
    }

    private static function __api_create(array $params)
    {
        return Tasks_Api_Controller::create(new Request(), array_merge(['title' => 'Api task ' . uniqid()], $params));
    }

    private static function __json($response): array
    {
        return json_decode($response->getContent(), true);
    }

    private static function __assert_api_field_error($response, string $field, string $message): array
    {
        static::__assert_equals(422, $response->getStatusCode(), $message);
        $fields = static::__json($response)['error']['fields'] ?? [];
        static::__assert_array_has_key($field, $fields, "{$message} (names {$field})");

        return $fields;
    }

    private static function __assert_staff_field_error($result, string $field, string $message): array
    {
        static::__assert_instance_of(Error_Response::class, $result, $message);
        $fields = $result->get_metadata();
        static::__assert_array_has_key($field, $fields, "{$message} (on {$field})");

        return $fields;
    }

    public static function test_create_writes_the_task_and_derives_its_project()
    {
        $project = static::__project();

        $response = static::__api_create([
            'title' => 'Draft the statement of work',
            'description' => '<p>Include the <strong>payment</strong> schedule.</p><script>x()</script>',
            'priority' => Task_Model::PRIORITY_HIGH,
            'due_date' => '2026-09-04',
            'hour_estimate' => '2.5',
            'taskable_type' => 'Project_Model',
            'taskable_id' => (int) $project->id,
        ]);

        static::__assert_equals(201, $response->getStatusCode(), 'create answers 201');
        $body = static::__json($response);

        $task = Task_Model::find($body['id']);
        static::__assert_not_null($task, 'the task exists on the caller\'s site');
        static::__assert_equals('Draft the statement of work', $task->title, 'the title is stored');
        static::__assert_equals(Task_Model::STATUS_PENDING, (int) $task->status, 'a blank status is Pending');
        static::__assert_equals(Task_Model::PRIORITY_HIGH, (int) $task->priority, 'the priority is stored');
        static::__assert_equals('Project_Model', $task->taskable_type, 'the parent type is stored');
        static::__assert_equals((int) $project->id, (int) $task->project_id, 'project_id is derived from the parent chain');
        static::__assert_equals(2.5, (float) $task->hour_estimate, 'the hour estimate is stored');
        static::__assert_equals(
            '<p>Include the <strong>payment</strong> schedule.</p>',
            $task->description->to_storage(),
            'description is HTML: sanitized through the type\'s filter and stored as markup'
        );
    }

    public static function test_create_refuses_bad_values_like_the_staff_save()
    {
        $cases = [
            'title' => ['title' => '   '],
            'status' => ['status' => 999],
            'due_date' => ['due_date' => '04/09/2026'],
            'hour_estimate' => ['hour_estimate' => '-1'],
        ];

        foreach ($cases as $field => $values) {
            $api = static::__assert_api_field_error(static::__api_create($values), $field, "the API refuses a bad {$field}");

            $staff_values = array_merge(['title' => 'Staff task'], $values);
            $staff = static::__assert_staff_field_error(Frontend_Tasks_Controller::save(new Request(), $staff_values), $field, "the staff save refuses a bad {$field}");

            static::__assert_equals($staff[$field], $api[$field], "{$field}: one rule, one message on both paths");
        }

        $long = str_repeat('t', Task_Model::field_length('title') + 1);
        static::__assert_api_field_error(static::__api_create(['title' => $long]), 'title', 'an over-long title is refused');
        static::__assert_staff_field_error(Frontend_Tasks_Controller::save(new Request(), ['title' => $long]), 'title', 'the staff save refuses an over-long title');
    }

    public static function test_a_parent_of_another_site_or_a_deleted_one_is_refused()
    {
        $foreign = static::__foreign_project();

        static::__assert_api_field_error(
            static::__api_create(['taskable_type' => 'Project_Model', 'taskable_id' => (int) $foreign->id]),
            'taskable',
            'another site\'s project is not a parent'
        );
        static::__assert_staff_field_error(
            Frontend_Tasks_Controller::save(new Request(), ['title' => 'Staff task', 'taskable' => ['type' => 'Project_Model', 'id' => $foreign->id]]),
            'taskable',
            'the staff save refuses another site\'s project as parent'
        );
        static::__assert_api_field_error(
            static::__api_create(['direct_project_id' => (int) $foreign->id]),
            'direct_project_id',
            'another site\'s project is not a direct_project_id'
        );

        $deleted = static::__json(static::__api_create([]))['id'];
        Task_Model::find($deleted)->delete();

        static::__assert_api_field_error(
            static::__api_create(['taskable_type' => 'Task_Model', 'taskable_id' => (int) $deleted]),
            'taskable',
            'a deleted task is not a parent'
        );
        static::__assert_api_field_error(
            static::__api_create(['taskable_type' => 'Not_A_Model', 'taskable_id' => 1]),
            'taskable',
            'an unknown parent type is refused'
        );
        static::__assert_api_field_error(
            static::__api_create(['taskable_id' => 1]),
            'taskable_type',
            'a parent id without its type is refused'
        );
    }

    public static function test_project_id_is_not_a_write_parameter()
    {
        foreach (['/api/v1/tasks/create', '/api/v1/tasks/:id/update'] as $pattern) {
            $specs = Api_Catalog::params_for_pattern($pattern);
            static::__assert_not_empty($specs, "{$pattern} declares its parameters");

            $raw = ['id' => 1, 'title' => 'Api task', 'project_id' => 4];
            if ($pattern === '/api/v1/tasks/create') {
                unset($raw['id']);
            }

            $result = Api_Param_Validator::validate($specs, $raw);
            static::__assert_false($result['valid'], "{$pattern}: a write sending project_id is refused");
            static::__assert_equals('Unknown parameter', $result['fields']['project_id'] ?? null, "{$pattern}: project_id is named");

            unset($raw['project_id']);
            $raw['direct_project_id'] = 4;
            static::__assert_true(Api_Param_Validator::validate($specs, $raw)['valid'], "{$pattern}: direct_project_id is accepted");
        }

        $list = Api_Param_Validator::validate(Api_Catalog::params_for_pattern('/api/v1/tasks'), ['project_id' => 4]);
        static::__assert_true($list['valid'], 'the list still filters on project_id');
    }

    public static function test_direct_project_id_is_the_project_until_a_chain_reaches_one()
    {
        $mine = static::__project();
        $response = static::__api_create(['direct_project_id' => (int) $mine->id]);
        $body = static::__json($response);

        static::__assert_equals((int) $mine->id, (int) $body['project_id'], 'an unparented task\'s project is its direct project');
        static::__assert_equals((int) $mine->id, (int) $body['direct_project_id'], 'the response carries direct_project_id');

        $chain = static::__project();
        $updated = Tasks_Api_Controller::update(new Request(), [
            'id' => $body['id'],
            'taskable_type' => 'Project_Model',
            'taskable_id' => (int) $chain->id,
        ]);
        static::__assert_equals((int) $chain->id, (int) $updated->project_id, 'under a project the chain wins');
        static::__assert_equals((int) $mine->id, (int) $updated->direct_project_id, 'the direct project is kept');

        $cleared = Tasks_Api_Controller::update(new Request(), ['id' => $body['id'], 'taskable_type' => '']);
        static::__assert_equals((int) $mine->id, (int) $cleared->project_id, 'removing the parent brings the direct project back');

        $none = Tasks_Api_Controller::update(new Request(), ['id' => $body['id'], 'direct_project_id' => 0]);
        static::__assert_null($none->project_id, '0 clears the direct project');
    }

    public static function test_update_writes_only_the_fields_sent()
    {
        $id = static::__json(static::__api_create([
            'title' => 'Original title',
            'priority' => Task_Model::PRIORITY_LOW,
            'notes' => 'Keep these notes',
        ]))['id'];

        $response = Tasks_Api_Controller::update(new Request(), [
            'id' => $id,
            'status' => Task_Model::STATUS_IN_PROGRESS,
            'due_date' => '2026-09-11',
        ]);

        static::__assert_equals(200, $response instanceof Task_Model ? 200 : $response->getStatusCode(), 'update succeeds');

        $task = Task_Model::find($id);
        static::__assert_equals(Task_Model::STATUS_IN_PROGRESS, (int) $task->status, 'the status sent is written');
        static::__assert_equals('2026-09-11', $task->due_date, 'the due date sent is written');
        static::__assert_equals('Original title', $task->title, 'the title not sent is untouched');
        static::__assert_equals(Task_Model::PRIORITY_LOW, (int) $task->priority, 'the priority not sent is untouched');
        static::__assert_equals('Keep these notes', $task->notes, 'the notes not sent are untouched');
    }

    public static function test_update_reparents_checks_cycles_and_refuses_bad_values()
    {
        $parent_id = static::__json(static::__api_create([]))['id'];
        $child_id = static::__json(static::__api_create(['taskable_type' => 'Task_Model', 'taskable_id' => (int) $parent_id]))['id'];

        static::__assert_api_field_error(
            Tasks_Api_Controller::update(new Request(), ['id' => $parent_id, 'taskable_type' => 'Task_Model', 'taskable_id' => (int) $child_id]),
            'taskable',
            'a task cannot be parented to its own descendant'
        );
        static::__assert_api_field_error(
            Tasks_Api_Controller::update(new Request(), ['id' => $child_id, 'title' => '']),
            'title',
            'a blank title sent on update is refused'
        );

        $project = static::__project();
        Tasks_Api_Controller::update(new Request(), ['id' => $parent_id, 'taskable_type' => 'Project_Model', 'taskable_id' => (int) $project->id]);

        static::__assert_equals((int) $project->id, (int) Task_Model::find($child_id)->project_id, 'the derived project cascades to the descendant');

        Tasks_Api_Controller::update(new Request(), ['id' => $child_id, 'taskable_type' => '']);
        static::__assert_null(Task_Model::find($child_id)->taskable_type, 'a blank taskable_type removes the parent');
    }

    public static function test_update_of_another_sites_task_is_not_found()
    {
        $foreign = static::__foreign_project();

        static::__acting_as_site((int) $foreign->site_id);
        $task = new Task_Model();
        $task->title = 'Foreign task';
        $task->save();
        static::__acting_as_site(self::SITE_ID);

        $response = Tasks_Api_Controller::update(new Request(), ['id' => (int) $task->id, 'title' => 'Hijacked']);

        static::__assert_equals(404, $response->getStatusCode(), 'another site\'s task is not found');
    }

    /**
     * The round trip over real HTTP dispatch: GET answers `description` as the HTML string -
     * no {__TEXT} envelope anywhere in the body - and an update sending that string back
     * leaves the stored value byte for byte as it was.
     */
    public static function test_description_reads_as_a_string_and_round_trips_unchanged()
    {
        $key = static::__api_key();
        $html = '<p>Include the <strong>payment</strong> schedule.</p><ul><li>Deposit</li></ul>';

        $created = static::__api($key, 'POST', '/api/v1/tasks/create', ['title' => 'Round trip ' . uniqid(), 'description' => $html]);
        static::__assert_equals(201, $created['status'], $created['raw']);
        $id = (int) $created['body']['id'];
        $stored = Task_Model::find($id)->description->to_storage();

        $read = static::__api($key, 'GET', "/api/v1/tasks/{$id}");
        static::__assert_equals(200, $read['status'], $read['raw']);
        static::__assert_false(str_contains($read['raw'], '__TEXT'), 'no text envelope in the body: ' . $read['raw']);
        static::__assert_equals($stored, $read['body']['description'], 'description is the stored HTML string');

        $updated = static::__api($key, 'POST', "/api/v1/tasks/{$id}/update", ['description' => $read['body']['description']]);
        static::__assert_equals(200, $updated['status'], $updated['raw']);
        static::__assert_equals($stored, $updated['body']['description'], 'the answer carries the same string');
        static::__assert_equals($stored, Task_Model::find($id)->description->to_storage(), 'the stored value is unchanged');
    }

    private static function __api_key(): string
    {
        $user = User_Model::find(1);
        $user->is_api_access_enabled = 1;
        $user->save();
        static::grant_every_permission(1);

        return Api_Key_Model::generate(1, 'Task API round trip (test)')['key'];
    }

    /** One request through the front controller: [status, body (decoded), raw]. */
    private static function __api(string $key, string $method, string $path, array $body = []): array
    {
        $request = Request::create($path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
        ], $method === 'POST' ? json_encode($body) : null);
        app()->instance('request', $request);

        $response = Rsx_Front_Controller::handle($request);

        // The front controller ended the API identity: act as the baseline user again.
        static::__acting_as_site(self::SITE_ID);
        static::__acting_as_user(1);

        $raw = (string) $response->getContent();

        return ['status' => $response->getStatusCode(), 'body' => json_decode($raw, true) ?? [], 'raw' => $raw];
    }
}
