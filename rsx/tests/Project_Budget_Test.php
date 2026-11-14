<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use League\Csv\Reader;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Projects\Frontend_Projects_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;

/**
 * A project's budget is money, end to end - the template's worked example of a money column:
 *
 *   - the save endpoint reads what a person typed ("$1,234.50", "1,234.50") through
 *     Project_Model::money_from_submitted('budget', ...) and stores the exact two-place
 *     decimal; a blank is no budget (NULL), never zero
 *   - an entry that is not an amount, has a third decimal place, is negative or is larger
 *     than the DECIMAL(15,2) column holds is a FIELD error on `budget` with its own message,
 *     and nothing is written; the column's own maximum saves
 *   - re-saving the amount it already holds, typed differently, changes nothing: no write,
 *     no revision, `updated_at` unmoved
 *   - the subprojects endpoint carries each subproject's budget and their total, summed by
 *     the database (`sum_money()`), $0.00 when there are none; the view's "Unallocated" is
 *     the project's budget minus that total, negative when over-allocated
 *   - the CSV export writes the machine-readable decimal, and nothing for no budget
 *   - the column refuses a float
 */
class Project_Budget_Test extends Rsx_Test_Abstract
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

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    private static function __client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Project Budget ' . uniqid();
        $client->save();

        return $client;
    }

    /** A new project saved through the endpoint, as the add form submits it. */
    private static function __create(array $values)
    {
        return Frontend_Projects_Controller::save(new Request(), array_merge([
            'name' => 'Project Budget ' . uniqid(),
            'client_id' => static::__client()->id,
        ], $values));
    }

    /** A project written directly, with a budget assigned the way an import or seed assigns one. */
    private static function __project(?string $budget, ?Project_Model $parent = null): Project_Model
    {
        $project = new Project_Model();
        $project->name = 'Project Budget ' . uniqid();
        $project->client_id = $parent ? $parent->client_id : static::__client()->id;
        $project->parent_project_id = $parent?->id;
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;
        $project->budget = $budget;
        $project->save();

        return Project_Model::find($project->id);
    }

    private static function __assert_budget_error($result, string $expected, string $message): void
    {
        static::__assert_instance_of(Error_Response::class, $result, $message);
        static::__assert_array_has_key('budget', $result->get_metadata(), "{$message} (on budget)");
        static::__assert_equals($expected, $result->get_metadata()['budget'], "{$message} (the message)");
    }

    // ---------------------------------------------------------------------
    // the save endpoint
    // ---------------------------------------------------------------------

    public static function test_a_typed_amount_is_stored_exactly()
    {
        foreach (['$1,234.50', '1,234.50', '1234.5'] as $typed) {
            $result = static::__create(['budget' => $typed]);

            static::__assert_array_has_key('project_id', $result, "'{$typed}' saves");

            $budget = Project_Model::find($result['project_id'])->budget;
            static::__assert_instance_of(Rsx_Money::class, $budget, "'{$typed}' reads back as an Rsx_Money");
            static::__assert_equals('1234.50', $budget->to_decimal(), "'{$typed}' is stored as 1234.50");
        }
    }

    public static function test_a_blank_budget_is_no_budget()
    {
        foreach (['', '   '] as $typed) {
            $result = static::__create(['budget' => $typed]);

            static::__assert_array_has_key('project_id', $result, 'a blank budget saves');
            static::__assert_null(Project_Model::find($result['project_id'])->budget, 'blank is NULL, never zero');
        }
    }

    public static function test_a_refused_amount_is_a_field_error_and_writes_nothing()
    {
        $name = 'Project Budget Refused ' . uniqid();

        static::__assert_budget_error(
            static::__create(['name' => $name, 'budget' => '12.345']),
            'An amount of money has at most two decimal places.',
            'a third decimal place is refused, never rounded'
        );
        static::__assert_budget_error(
            static::__create(['name' => $name, 'budget' => '(5.00)']),
            'Budget must be zero or more.',
            'a negative amount in parentheses is refused'
        );
        static::__assert_budget_error(
            static::__create(['name' => $name, 'budget' => '12,34']),
            'This is not an amount of money.',
            'a misplaced group separator is not an amount'
        );

        static::__assert_equals(0, Project_Model::where('name', $name)->count(), 'no refused save wrote a project');
    }

    public static function test_an_amount_larger_than_the_column_is_a_field_error_and_writes_nothing()
    {
        $name = 'Project Budget Too Large ' . uniqid();

        static::__assert_equals(
            '9999999999999.99',
            Project_Model::money_limit('budget')->to_decimal(),
            'the budget column is DECIMAL(15,2)'
        );

        foreach (['99999999999999', '10000000000000.00', '$10,000,000,000,000'] as $typed) {
            static::__assert_budget_error(
                static::__create(['name' => $name, 'budget' => $typed]),
                Rsx_Money_Exception::MESSAGE_COLUMN_RANGE,
                "'{$typed}' is larger than the column holds"
            );
        }

        static::__assert_equals(0, Project_Model::where('name', $name)->count(), 'no over-large save wrote a project');

        $result = static::__create(['budget' => '9999999999999.99']);
        static::__assert_array_has_key('project_id', $result, "the column's maximum saves");
        static::__assert_equals(
            '9999999999999.99',
            Project_Model::find($result['project_id'])->budget->to_decimal(),
            "the column's maximum is stored exactly"
        );
    }

    public static function test_assigning_an_amount_larger_than_the_column_throws_before_the_database()
    {
        $project = new Project_Model();

        try {
            $project->budget = '10000000000000.00';
        } catch (Rsx_Money_Exception $e) {
            static::__assert_equals(Rsx_Money_Exception::REASON_RANGE, $e->reason, 'the refusal is range');
            static::__assert_equals(Rsx_Money_Exception::MESSAGE_COLUMN_RANGE, $e->getMessage());

            return;
        }

        static::__fail('the budget column accepted an amount larger than it holds');
    }

    public static function test_resaving_the_same_amount_changes_nothing()
    {
        $project = static::__project('1234.50');
        $updated_at = $project->updated_at;
        $revisions = count($project->revisions());

        $result = Frontend_Projects_Controller::save(new Request(), [
            'id' => $project->id,
            'name' => $project->name,
            'client_id' => $project->client_id,
            'status' => $project->status,
            'priority' => $project->priority,
            // The same amount, typed as a person would: it compares equal to the stored value.
            'budget' => '$1,234.50',
            '_changed' => ['budget'],
            '_loaded_updated_at' => $updated_at,
        ]);

        static::__assert_array_has_key('project_id', $result, 'the save succeeds');

        $reloaded = Project_Model::find($project->id);
        static::__assert_equals('1234.50', $reloaded->budget->to_decimal(), 'the stored column is unchanged');
        static::__assert_equals($updated_at, $reloaded->updated_at, 'updated_at did not move');
        static::__assert_equals($revisions, count($reloaded->revisions()), 'no revision was recorded');
    }

    public static function test_a_changed_amount_is_written()
    {
        $project = static::__project('1234.50');

        $result = Frontend_Projects_Controller::save(new Request(), [
            'id' => $project->id,
            'name' => $project->name,
            'client_id' => $project->client_id,
            'budget' => '$12,345.60',
            '_changed' => ['budget'],
            '_loaded_updated_at' => $project->updated_at,
        ]);

        static::__assert_array_has_key('project_id', $result, 'the save succeeds');
        static::__assert_equals('12345.60', Project_Model::find($project->id)->budget->to_decimal());
    }

    // ---------------------------------------------------------------------
    // the subprojects total and the Unallocated figure
    // ---------------------------------------------------------------------

    public static function test_subprojects_carry_their_budgets_and_the_total()
    {
        $parent = static::__project('1000.00');
        static::__project('300.00', $parent);
        static::__project('250.50', $parent);
        static::__project(null, $parent);

        $result = Frontend_Projects_Controller::project_subprojects(new Request(), ['id' => $parent->id]);

        static::__assert_count(3, $result['subprojects'], 'every subproject is listed');

        $budgets = [];
        foreach ($result['subprojects'] as $row) {
            $budgets[] = $row['budget'] === null ? null : $row['budget']->to_decimal();
        }
        sort($budgets);
        static::__assert_equals([null, '250.50', '300.00'], $budgets, 'each row carries its budget, null for none');

        $total = $result['subprojects_budget'];
        static::__assert_instance_of(Rsx_Money::class, $total, 'the total is an Rsx_Money');
        static::__assert_equals('550.50', $total->to_decimal(), 'the total skips a subproject with no budget');

        // The view's Unallocated figure: the project's budget minus its subprojects' total.
        static::__assert_equals('449.50', $parent->budget->subtract($total)->to_decimal(), 'Unallocated');
    }

    public static function test_an_over_allocated_project_is_unallocated_below_zero()
    {
        $parent = static::__project('100.00');
        static::__project('150.25', $parent);

        $total = Frontend_Projects_Controller::project_subprojects(new Request(), ['id' => $parent->id])['subprojects_budget'];
        $unallocated = $parent->budget->subtract($total);

        static::__assert_true($unallocated->is_negative(), 'over-allocation is negative');
        static::__assert_equals('-50.25', $unallocated->to_decimal());
    }

    public static function test_no_subprojects_total_zero()
    {
        $parent = static::__project('100.00');

        $result = Frontend_Projects_Controller::project_subprojects(new Request(), ['id' => $parent->id]);

        static::__assert_count(0, $result['subprojects']);
        static::__assert_equals('0.00', $result['subprojects_budget']->to_decimal(), 'an empty set totals $0.00');
    }

    // ---------------------------------------------------------------------
    // CSV export and assignment
    // ---------------------------------------------------------------------

    public static function test_csv_export_writes_the_decimal()
    {
        $marker = 'Project Budget CSV ' . uniqid();

        $with = static::__project('98765.40');
        $with->name = $marker . ' with';
        $with->save();

        $without = static::__project(null);
        $without->name = $marker . ' without';
        $without->save();

        $result = Frontend_Projects_Controller::export_csv(new Request(), [
            'mode' => 'all',
            'ids' => [],
            'filter_params' => ['filter' => $marker, 'sort' => 'id', 'order' => 'asc'],
        ]);

        static::__assert_equals(2, $result['count'], 'both projects are exported');

        $reader = Reader::fromString($result['csv']);
        $reader->setHeaderOffset(0);
        $cells = [];
        foreach ($reader->getRecords() as $row) {
            $cells[(int) $row['ID']] = $row['Budget'];
        }

        static::__assert_equals('98765.40', $cells[$with->id], 'the budget is the exact decimal, unformatted');
        static::__assert_equals('', $cells[$without->id], 'no budget is an empty cell');
    }

    public static function test_the_budget_column_refuses_a_float()
    {
        $project = new Project_Model();

        static::__assert_throws(Rsx_Money_Exception::class, function () use ($project) {
            $project->budget = 12.5;
        }, 'a float is never assigned to it');
    }
}
