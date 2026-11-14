<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Api\V1\Projects_Api_Controller;
use Rsx\App\Frontend\Projects\Frontend_Projects_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;

/**
 * Projects over the external API (GET /api/v1/projects, GET /api/v1/projects/:id,
 * POST /api/v1/projects/create, POST /api/v1/projects/:id/update) - the template's money
 * example. Over the real dispatch: a stored budget reads as "1234.50", the computed
 * subprojects total and unallocated remainder read as plain strings, and no body carries a
 * {__MONEY} envelope; a write of "1234.5" stores 1234.50, null and blank clear it, and
 * "12.345", text that is not an amount, an envelope object and an amount larger than the
 * column are refused on `budget` with nothing written. Create and update run the staff save's
 * rules (Project_Model::value_errors()), so the two refuse the same values with the same
 * messages.
 */
class Project_Api_Test extends Rsx_Test_Abstract
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

    private static function __client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Project API client ' . uniqid();
        $client->save();

        return $client;
    }

    private static function __project(?string $budget, ?Project_Model $parent = null): Project_Model
    {
        $project = new Project_Model();
        $project->name = 'Project API project ' . uniqid();
        $project->client_id = $parent ? $parent->client_id : static::__client()->id;
        $project->parent_project_id = $parent?->id;
        $project->budget = $budget === null ? null : Rsx_Money::from_decimal($budget);
        $project->save();

        return $project;
    }

    private static function __api_key(): string
    {
        $user = User_Model::find(1);
        $user->is_api_access_enabled = 1;
        $user->save();
        static::grant_every_permission(1);

        return Api_Key_Model::generate(1, 'Project API (test)')['key'];
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

    private static function __assert_budget_refused(array $answer, string $message, string $label): void
    {
        static::__assert_equals(422, $answer['status'], "{$label}: {$answer['raw']}");
        static::__assert_equals($message, $answer['body']['error']['fields']['budget'] ?? null, "{$label}: the field error on budget");
    }

    public static function test_a_read_carries_the_budget_and_the_computed_amounts_as_strings()
    {
        $key = static::__api_key();
        $parent = static::__project('1234.50');
        static::__project('1000.00', $parent);
        static::__project('500.25', $parent);
        static::__project(null, $parent);

        $read = static::__api($key, 'GET', "/api/v1/projects/{$parent->id}");
        static::__assert_equals(200, $read['status'], $read['raw']);
        static::__assert_equals('1234.50', $read['body']['budget'], 'the stored budget is its decimal string');
        static::__assert_equals('1500.25', $read['body']['subprojects_budget'], 'the subprojects total is a plain string');
        static::__assert_equals('-265.75', $read['body']['unallocated_budget'], 'over-allocated: the remainder is negative');
        static::__assert_false(str_contains($read['raw'], '__MONEY'), 'no money envelope in the body: ' . $read['raw']);

        $bare = static::__project(null);
        $read = static::__api($key, 'GET', "/api/v1/projects/{$bare->id}");
        static::__assert_null($read['body']['budget'], 'no budget is null');
        static::__assert_equals('0.00', $read['body']['subprojects_budget'], 'no subprojects total 0.00');
        static::__assert_null($read['body']['unallocated_budget'], 'no budget, no remainder');

        $list = static::__api($key, 'GET', '/api/v1/projects?per_page=100&client_id=' . $parent->client_id);
        static::__assert_equals(200, $list['status'], $list['raw']);
        static::__assert_false(str_contains($list['raw'], '__MONEY'), 'the list carries no money envelope');
        $budgets = array_column($list['body']['items'], 'budget', 'id');
        static::__assert_equals('1234.50', $budgets[$parent->id], 'the list carries the budget as a string');
    }

    public static function test_a_write_stores_the_amount_exactly_and_blank_clears_it()
    {
        $key = static::__api_key();
        $client = static::__client();

        $created = static::__api($key, 'POST', '/api/v1/projects/create', [
            'name' => 'Api project ' . uniqid(),
            'client_id' => (int) $client->id,
            'budget' => '1234.5',
        ]);
        static::__assert_equals(201, $created['status'], $created['raw']);
        static::__assert_equals('1234.50', $created['body']['budget'], 'the answer carries the stored amount');
        $id = (int) $created['body']['id'];
        static::__assert_equals('1234.50', Project_Model::find($id)->budget->to_decimal(), '"1234.5" stores 1234.50');

        $updated = static::__api($key, 'POST', "/api/v1/projects/{$id}/update", ['budget' => 27000]);
        static::__assert_equals(200, $updated['status'], $updated['raw']);
        static::__assert_equals('27000.00', Project_Model::find($id)->budget->to_decimal(), 'a JSON int is whole units');

        $max = Project_Model::money_limit('budget')->to_decimal();
        $updated = static::__api($key, 'POST', "/api/v1/projects/{$id}/update", ['budget' => $max]);
        static::__assert_equals(200, $updated['status'], $updated['raw']);
        static::__assert_equals($max, Project_Model::find($id)->budget->to_decimal(), 'the column\'s maximum saves');

        foreach ([null, ''] as $blank) {
            static::__api($key, 'POST', "/api/v1/projects/{$id}/update", ['budget' => '100.00']);
            $cleared = static::__api($key, 'POST', "/api/v1/projects/{$id}/update", ['budget' => $blank]);
            static::__assert_equals(200, $cleared['status'], $cleared['raw']);
            static::__assert_null($cleared['body']['budget'], json_encode($blank) . ' clears the budget');
            static::__assert_null(Project_Model::find($id)->budget, json_encode($blank) . ' stores no budget');
        }
    }

    public static function test_a_value_that_is_not_an_amount_the_column_holds_is_refused_and_nothing_written()
    {
        $key = static::__api_key();
        $project = static::__project('50.00');
        $path = "/api/v1/projects/{$project->id}/update";

        static::__assert_budget_refused(static::__api($key, 'POST', $path, ['budget' => '12.345']), Rsx_Money_Exception::MESSAGE_SCALE, 'a third decimal place');
        static::__assert_budget_refused(static::__api($key, 'POST', $path, ['budget' => 'lots']), Rsx_Money_Exception::MESSAGE_FORMAT, 'text that is not an amount');
        static::__assert_budget_refused(static::__api($key, 'POST', $path, ['budget' => ['__MONEY' => '10.00']]), Rsx_Money_Exception::MESSAGE_FORMAT, 'an envelope object');
        static::__assert_budget_refused(static::__api($key, 'POST', $path, ['budget' => '10000000000000.00']), Rsx_Money_Exception::MESSAGE_COLUMN_RANGE, 'larger than the column');
        static::__assert_budget_refused(static::__api($key, 'POST', $path, ['budget' => '-5']), 'Budget must be zero or more.', 'a negative amount, the model\'s rule');

        $create = static::__api($key, 'POST', '/api/v1/projects/create', [
            'name' => 'Refused project ' . uniqid(),
            'client_id' => (int) $project->client_id,
            'budget' => '10000000000000.00',
        ]);
        static::__assert_budget_refused($create, Rsx_Money_Exception::MESSAGE_COLUMN_RANGE, 'create, larger than the column');

        static::__assert_equals('50.00', Project_Model::find($project->id)->budget->to_decimal(), 'the stored budget is unchanged');
        static::__assert_equals(0, Project_Model::where('name', 'like', 'Refused project %')->count(), 'nothing was created');
    }

    public static function test_the_api_and_the_staff_save_refuse_the_same_values()
    {
        $project = static::__project(null);
        $cases = [
            'name' => ['name' => ''],
            'status' => ['status' => 99],
            'due_date' => ['due_date' => 'tomorrow'],
            'client_id' => ['client_id' => 0],
            'parent_project_id' => ['parent_project_id' => (int) $project->id],
        ];

        foreach ($cases as $field => $values) {
            $api = Projects_Api_Controller::update(new Request(), ['id' => (int) $project->id] + $values);
            static::__assert_equals(422, $api->getStatusCode(), "{$field}: the API refuses it");
            $api_message = json_decode($api->getContent(), true)['error']['fields'][$field] ?? null;

            $staff = Frontend_Projects_Controller::save(new Request(), array_merge([
                'id' => (int) $project->id,
                'name' => $project->name,
                'client_id' => (int) $project->client_id,
            ], $values));
            static::__assert_instance_of(Error_Response::class, $staff, "{$field}: the staff save refuses it");

            static::__assert_not_null($api_message, "{$field}: named by the API");
            static::__assert_equals($staff->get_metadata()[$field] ?? null, $api_message, "{$field}: the same message on both paths");
        }
    }

    public static function test_an_update_writes_only_the_fields_sent()
    {
        $project = static::__project('75.00');
        $project->notes = 'Keep these notes';
        $project->save();

        $result = Projects_Api_Controller::update(new Request(), ['id' => (int) $project->id, 'status' => Project_Model::STATUS_ON_HOLD]);
        static::__assert_instance_of(Project_Model::class, $result, 'update succeeds');

        $fresh = Project_Model::find($project->id);
        static::__assert_equals(Project_Model::STATUS_ON_HOLD, (int) $fresh->status, 'the status sent is written');
        static::__assert_equals('75.00', $fresh->budget->to_decimal(), 'the budget not sent is untouched');
        static::__assert_equals('Keep these notes', (string) $fresh->notes->to_storage(), 'the notes not sent are untouched');
    }
}
