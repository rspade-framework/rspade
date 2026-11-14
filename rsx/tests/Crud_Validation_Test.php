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
use Rsx\App\Frontend\Party\Frontend_Party_Controller;
use Rsx\App\Frontend\Projects\Frontend_Projects_Controller;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Lib\FieldChecks\Field_Checks;
use Rsx\Models\Client_Model;
use Rsx\Models\Party_Model;
use Rsx\Models\Party_Person_Detail_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * Project, task and party saves refuse what the record cannot hold as FIELD errors - never
 * a database exception:
 *
 *   - an over-long name or title (the model's field_length())
 *   - a date that is not a real "YYYY-MM-DD"
 *   - a status or priority that is not declared
 *   - a budget that is not an amount of money, has more than two decimal places or is
 *     negative, each with its own message; a budget of 0 is stored as 0.00
 *   - a pivot list entry that is not a record id ("abc", 0)
 *   - a party email that is not an address, an employee count that is not a whole number
 *
 * Plus the Field_Checks rule those saves share: http(s)-only web addresses.
 */
class Crud_Validation_Test extends Rsx_Test_Abstract
{
    public static function setup(): void
    {
        static::__acting_as_site(1);
        static::__acting_as_user(1);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __assert_field_error($result, string $field, string $message): void
    {
        static::__assert_instance_of(Error_Response::class, $result, $message);
        static::__assert_array_has_key($field, $result->get_metadata(), "{$message} (on {$field})");
    }

    private static function __assert_field_message($result, string $field, string $expected, string $message): void
    {
        static::__assert_field_error($result, $field, $message);
        static::__assert_equals($expected, $result->get_metadata()[$field], "{$message} (the message)");
    }

    // ---------------------------------------------------------------------
    // projects
    // ---------------------------------------------------------------------

    private static function __save_project(array $values)
    {
        $client = new Client_Model();
        $client->name = 'Crud Validation ' . uniqid();
        $client->save();

        return Frontend_Projects_Controller::save(new Request(), array_merge([
            'name' => 'Crud Validation Project ' . uniqid(),
            'client_id' => $client->id,
        ], $values));
    }

    public static function test_project_refuses_bad_values()
    {
        static::__assert_field_error(static::__save_project(['name' => str_repeat('n', Project_Model::field_length('name') + 1)]), 'name', 'an over-long name is refused');
        static::__assert_field_error(static::__save_project(['start_date' => '2026-02-30']), 'start_date', 'an impossible date is refused');
        static::__assert_field_error(static::__save_project(['due_date' => 'tomorrow']), 'due_date', 'a non-date is refused');
        static::__assert_field_error(static::__save_project(['status' => '99']), 'status', 'an undeclared status is refused');
        static::__assert_field_error(static::__save_project(['priority' => '99']), 'priority', 'an undeclared priority is refused');
        static::__assert_field_message(static::__save_project(['budget' => 'lots']), 'budget', 'This is not an amount of money.', 'a non-amount budget is refused');
        static::__assert_field_message(static::__save_project(['budget' => '-5']), 'budget', 'Budget must be zero or more.', 'a negative budget is refused');
        static::__assert_field_message(static::__save_project(['budget' => '1.234']), 'budget', 'An amount of money has at most two decimal places.', 'a third decimal place is refused');
        static::__assert_field_error(static::__save_project(['contacts' => ['abc']]), 'contacts', 'a non-id contact is a field error');
        static::__assert_field_error(static::__save_project(['assigned_users' => ['0']]), 'assigned_users', 'a zero user id is a field error');
    }

    public static function test_project_budget_of_zero_is_stored_as_zero()
    {
        $result = static::__save_project(['budget' => '0', 'start_date' => '2026-03-01']);

        static::__assert_array_has_key('project_id', $result, 'the save succeeds');

        $project = Project_Model::find($result['project_id']);
        static::__assert_equals('0.00', $project->budget->to_decimal(), 'zero is a budget, not an empty one');
        static::__assert_equals('2026-03-01', $project->start_date);
    }

    public static function test_project_budget_is_stored_exactly()
    {
        $result = static::__save_project(['budget' => '1234567890123.45']);

        static::__assert_array_has_key('project_id', $result, 'the save succeeds');
        static::__assert_equals('1234567890123.45', Project_Model::find($result['project_id'])->budget->to_decimal());
    }

    // ---------------------------------------------------------------------
    // tasks
    // ---------------------------------------------------------------------

    private static function __save_task(array $values)
    {
        return Frontend_Tasks_Controller::save(new Request(), array_merge([
            'title' => 'Crud Validation Task ' . uniqid(),
        ], $values));
    }

    public static function test_task_refuses_bad_values()
    {
        static::__assert_field_error(static::__save_task(['title' => str_repeat('t', Task_Model::field_length('title') + 1)]), 'title', 'an over-long title is refused');
        static::__assert_field_error(static::__save_task(['due_date' => '13/45/2026']), 'due_date', 'a non-ISO date is refused');
        static::__assert_field_error(static::__save_task(['completed_date' => '2026-13-01']), 'completed_date', 'an impossible date is refused');
        static::__assert_field_error(static::__save_task(['status' => '99']), 'status', 'an undeclared status is refused');
        static::__assert_field_error(static::__save_task(['priority' => 'high']), 'priority', 'a label is not a priority id');
    }

    public static function test_task_with_valid_values_saves()
    {
        $result = static::__save_task([
            'status' => (string) Task_Model::STATUS_IN_PROGRESS,
            'priority' => (string) Task_Model::PRIORITY_HIGH,
            'due_date' => '2026-12-31',
        ]);

        static::__assert_array_has_key('id', $result, 'the save succeeds');
    }

    // ---------------------------------------------------------------------
    // party
    // ---------------------------------------------------------------------

    private static function __save_party(array $values)
    {
        return Frontend_Party_Controller::save(new Request(), $values);
    }

    public static function test_party_refuses_bad_values()
    {
        static::__assert_field_error(static::__save_party([
            'type_id' => Party_Model::TYPE_PERSON,
            'first_name' => str_repeat('f', Party_Person_Detail_Model::field_length('first_name') + 1),
            'last_name' => 'Doe',
        ]), 'first_name', 'an over-long first name is refused');

        static::__assert_field_error(static::__save_party([
            'type_id' => Party_Model::TYPE_PERSON,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'date_of_birth' => 'last spring',
        ]), 'date_of_birth', 'a non-date birth date is refused');

        static::__assert_field_error(static::__save_party([
            'type_id' => Party_Model::TYPE_GROUP,
            'name' => 'Group',
            'email' => 'not an address',
        ]), 'email', 'a malformed email is refused');

        static::__assert_field_error(static::__save_party([
            'type_id' => Party_Model::TYPE_COMPANY,
            'legal_name' => 'Acme',
            'employee_count' => '-3',
        ]), 'employee_count', 'a negative employee count is refused');
    }

    // ---------------------------------------------------------------------
    // Field_Checks
    // ---------------------------------------------------------------------

    public static function test_web_urls_are_http_or_https_only()
    {
        static::__assert_true(Field_Checks::is_web_url('https://example.com/path?x=1'), 'https accepted');
        static::__assert_true(Field_Checks::is_web_url('HTTP://example.com'), 'scheme is case-insensitive');
        static::__assert_false(Field_Checks::is_web_url('javascript:alert(1)'), 'javascript: refused');
        static::__assert_false(Field_Checks::is_web_url('data:text/html,x'), 'data: refused');
        static::__assert_false(Field_Checks::is_web_url('https://localhost'), 'a dotless host refused');
        static::__assert_false(Field_Checks::is_web_url("https://example.com/\n"), 'a control character refused');

        static::__assert_equals('example.com', Field_Checks::website_short_url('https://example.com/'));
        static::__assert_null(Field_Checks::website_short_url('ftp://example.com'), 'another scheme is not a short URL');
    }
}
