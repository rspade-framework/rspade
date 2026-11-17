<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Billing\Frontend_Billing_Controller;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Billable_Entry_Expense_Detail_Model;
use Rsx\Models\Billable_Entry_Model;
use Rsx\Models\Billable_Entry_Time_Detail_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * Billable entries - the template's worked example of detail tables (class-table
 * inheritance), driven through the billing endpoints:
 *
 *   - each kind writes and reads its own fields: a TIME entry's hours and rate on
 *     billable_entry_time_details, an EXPENSE's vendor and cost on
 *     billable_entry_expense_details, a FIXED_FEE on the base row alone; the wrong kind's
 *     accessor throws; the detail rides the entry's toArray() under __details
 *   - the amount is computed: hours x rate rounded half-up, the expense cost, the fixed fee
 *   - a new time entry copies its project's rate; an entry keeps its own copy afterwards
 *   - a task must belong to the entry's project (the task's DERIVED project_id)
 *   - the lifecycle: DRAFT -> submit -> approve, or reject (a reason required) -> edit, which
 *     returns it to DRAFT; an approved entry is locked
 *   - an author changes only their own entries, a User never approves, and only an
 *     approver records work for somebody else
 */
class Billable_Entry_Test extends Rsx_Test_Abstract
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

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    private static function __project(?string $hourly_rate = '100.00'): Project_Model
    {
        $client = new Client_Model();
        $client->name = 'Billing Client ' . uniqid();
        $client->save();

        $project = new Project_Model();
        $project->name = 'Billing Project ' . uniqid();
        $project->client_id = $client->id;
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;
        $project->hourly_rate = $hourly_rate;
        $project->save();

        return $project;
    }

    private static function __task(Project_Model $project): Task_Model
    {
        $task = new Task_Model();
        $task->title = 'Billing Task ' . uniqid();
        $task->taskable_type = 'Project_Model';
        $task->taskable_id = $project->id;
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->save();

        return $task;
    }

    private static function __member(int $role_id): User_Model
    {
        $email = 'billing_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = 'not-a-login-path';
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Billing';
        $user->last_name = 'Member';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->invite_accepted_at = now();
        $user->save();

        return $user;
    }

    /**
     * Run $fn acting as $user_id, then act as user 1 again. The acting identity is a CLASS
     * declaration that survives between test methods, so it is always restored.
     */
    private static function __as(int $user_id, callable $fn): void
    {
        static::__reset_session();
        static::__acting_as_site(self::SITE_ID);
        static::__acting_as_user($user_id);
        try {
            $fn();
        } finally {
            static::__reset_session();
            static::__acting_as_site(self::SITE_ID);
            static::__acting_as_user(1);
        }
    }

    /** Create an entry through the endpoint; answers the endpoint's result. */
    private static function __save(array $values)
    {
        return Frontend_Billing_Controller::save(new Request(), $values);
    }

    /** Create an entry that must succeed; answers it, read fresh. */
    private static function __create(array $values): Billable_Entry_Model
    {
        $result = static::__save($values);
        static::__assert_array_has_key('billable_entry_id', $result, 'the entry saves: ' . json_encode($result instanceof Error_Response ? $result->get_metadata() : $result));

        return Billable_Entry_Model::find($result['billable_entry_id']);
    }

    private static function __time(Project_Model $project, array $values = []): Billable_Entry_Model
    {
        return static::__create(array_merge([
            'type_id' => Billable_Entry_Model::TYPE_TIME,
            'project_id' => $project->id,
            'entry_date' => '2026-11-02',
            'description' => 'Design review',
            'hours' => '1.5',
        ], $values));
    }

    /** An edit submission of $entry that changed only $changed. */
    private static function __edit(Billable_Entry_Model $entry, array $values, array $changed): array
    {
        return array_merge($values, [
            'id' => $entry->id,
            '_changed' => $changed,
            '_loaded_updated_at' => $entry->updated_at,
        ]);
    }

    private static function __assert_field_error($result, string $field, string $message): void
    {
        static::__assert_instance_of(Error_Response::class, $result, $message);
        static::__assert_array_has_key($field, $result->get_metadata(), "{$message} (on {$field})");
    }

    // ---------------------------------------------------------------------
    // the three kinds: detail write and read
    // ---------------------------------------------------------------------

    public static function test_a_time_entry_stores_hours_and_rate_on_its_detail()
    {
        $project = static::__project('85.00');
        $entry = static::__time($project, ['hours' => '2.25', 'hourly_rate' => '90.00']);

        static::__assert_equals(Billable_Entry_Model::STATUS_DRAFT, (int) $entry->status, 'a new entry is a draft');
        static::__assert_equals(1, (int) $entry->user_id, 'the signed-in user did the work');

        $detail = $entry->billable_entry_time;
        static::__assert_instance_of(Billable_Entry_Time_Detail_Model::class, $detail, 'the time detail');
        static::__assert_true($detail->exists, 'the detail row was written');
        static::__assert_equals('2.25', (string) $detail->hours, 'hours stored');
        static::__assert_equals('90.00', $detail->hourly_rate->to_decimal(), 'the submitted rate, not the project rate');
        static::__assert_equals('202.50', $entry->amount->to_decimal(), 'amount = 2.25 x 90.00');

        $array = $entry->toArray();
        static::__assert_array_has_key('billable_entry_time', $array['__details'] ?? [], 'the detail rides toArray()');
    }

    public static function test_an_expense_stores_vendor_and_cost_and_its_amount_is_the_cost()
    {
        $entry = static::__create([
            'type_id' => Billable_Entry_Model::TYPE_EXPENSE,
            'project_id' => static::__project()->id,
            'entry_date' => '2026-11-03',
            'description' => 'Train fare',
            'vendor' => 'Rail Co',
            'cost' => '$1,234.56',
        ]);

        $detail = $entry->billable_entry_expense;
        static::__assert_instance_of(Billable_Entry_Expense_Detail_Model::class, $detail, 'the expense detail');
        static::__assert_equals('Rail Co', $detail->vendor, 'vendor stored');
        static::__assert_equals('1234.56', $detail->cost->to_decimal(), 'typed cost read through the detail model');
        static::__assert_equals('1234.56', $entry->amount->to_decimal(), 'amount = cost');
    }

    public static function test_a_fixed_fee_has_no_detail_and_stores_the_amount_entered()
    {
        $entry = static::__create([
            'type_id' => Billable_Entry_Model::TYPE_FIXED_FEE,
            'project_id' => static::__project()->id,
            'entry_date' => '2026-11-04',
            'description' => 'Setup fee',
            'amount' => '500',
        ]);

        static::__assert_equals('500.00', $entry->amount->to_decimal(), 'the entered amount');
        static::__assert_null($entry->active_detail(), 'a fixed fee has no detail row');
        static::__assert_false(isset($entry->toArray()['__details']['billable_entry_time']), 'no detail is embedded');
    }

    public static function test_the_wrong_kinds_accessor_throws()
    {
        $entry = static::__time(static::__project());

        static::__assert_throws(\RuntimeException::class, fn () => $entry->billable_entry_expense);

        $fee = static::__create([
            'type_id' => Billable_Entry_Model::TYPE_FIXED_FEE,
            'project_id' => static::__project()->id,
            'entry_date' => '2026-11-04',
            'description' => 'Fee',
            'amount' => '10',
        ]);
        static::__assert_throws(\RuntimeException::class, fn () => $fee->billable_entry_time);
    }

    public static function test_each_kind_requires_its_own_figure()
    {
        $project = static::__project();
        $base = ['project_id' => $project->id, 'entry_date' => '2026-11-04', 'description' => 'x'];

        static::__assert_field_error(static::__save($base + ['type_id' => Billable_Entry_Model::TYPE_TIME]), 'hours', 'time needs hours');
        static::__assert_field_error(static::__save($base + ['type_id' => Billable_Entry_Model::TYPE_EXPENSE]), 'cost', 'an expense needs a cost');
        static::__assert_field_error(static::__save($base + ['type_id' => Billable_Entry_Model::TYPE_FIXED_FEE]), 'amount', 'a fixed fee needs an amount');
        static::__assert_field_error(static::__save($base + ['type_id' => 99]), 'type_id', 'an unknown kind is refused');
        static::__assert_field_error(
            static::__save($base + ['type_id' => Billable_Entry_Model::TYPE_TIME, 'hours' => '1.255']),
            'hours',
            'hours have at most two decimal places'
        );
        static::__assert_field_error(
            static::__save($base + ['type_id' => Billable_Entry_Model::TYPE_EXPENSE, 'cost' => '(5.00)']),
            'cost',
            'a negative cost is refused'
        );
    }

    // ---------------------------------------------------------------------
    // amounts and rates
    // ---------------------------------------------------------------------

    public static function test_time_is_rounded_half_up()
    {
        $project = static::__project();

        // 1.33 x 42.50 = 56.525 -> 56.53 (half up); 0.33 x 10.01 = 3.3033 -> 3.30
        static::__assert_equals('56.53', static::__time($project, ['hours' => '1.33', 'hourly_rate' => '42.50'])->amount->to_decimal(), 'a half cent rounds up');
        static::__assert_equals('3.30', static::__time($project, ['hours' => '0.33', 'hourly_rate' => '10.01'])->amount->to_decimal(), 'below a half cent rounds down');
    }

    public static function test_a_new_time_entry_copies_the_project_rate_and_keeps_its_copy()
    {
        $project = static::__project('120.00');
        $entry = static::__time($project, ['hours' => '2']);

        static::__assert_equals('120.00', $entry->billable_entry_time->hourly_rate->to_decimal(), 'the project rate is copied');
        static::__assert_equals('240.00', $entry->amount->to_decimal(), 'and priced');

        $project->hourly_rate = '200.00';
        $project->save();

        $entry = Billable_Entry_Model::find($entry->id);
        static::__assert_equals('120.00', $entry->billable_entry_time->hourly_rate->to_decimal(), 'a later project rate never reaches the entry');
        static::__assert_equals('240.00', $entry->amount->to_decimal(), 'nor its amount');
    }

    public static function test_a_project_without_a_rate_needs_one_entered()
    {
        $project = static::__project(null);

        static::__assert_field_error(
            static::__save([
                'type_id' => Billable_Entry_Model::TYPE_TIME,
                'project_id' => $project->id,
                'entry_date' => '2026-11-02',
                'description' => 'x',
                'hours' => '1',
            ]),
            'hourly_rate',
            'no rate to copy'
        );
    }

    public static function test_editing_hours_reprices_with_the_stored_rate()
    {
        $entry = static::__time(static::__project('100.00'), ['hours' => '1']);

        $result = static::__save(static::__edit($entry, ['hours' => '3', 'hourly_rate' => '999.00'], ['hours']));
        static::__assert_array_has_key('billable_entry_id', $result, 'the edit saves');

        $entry = Billable_Entry_Model::find($entry->id);
        static::__assert_equals('3.00', (string) $entry->billable_entry_time->hours, 'hours changed');
        static::__assert_equals('100.00', $entry->billable_entry_time->hourly_rate->to_decimal(), 'an unchanged rate is not written');
        static::__assert_equals('300.00', $entry->amount->to_decimal(), 'priced from what is stored');
    }

    // ---------------------------------------------------------------------
    // task and project
    // ---------------------------------------------------------------------

    public static function test_the_task_must_belong_to_the_project()
    {
        $project = static::__project();
        $other = static::__project();

        static::__assert_field_error(
            static::__save([
                'type_id' => Billable_Entry_Model::TYPE_TIME,
                'project_id' => $project->id,
                'task_id' => static::__task($other)->id,
                'entry_date' => '2026-11-02',
                'description' => 'x',
                'hours' => '1',
            ]),
            'task_id',
            'a task of another project is refused'
        );

        $task = static::__task($project);
        $entry = static::__time($project, ['task_id' => $task->id]);
        static::__assert_equals($task->id, (int) $entry->task_id, 'a task of the project is stored');
    }

    // ---------------------------------------------------------------------
    // lifecycle
    // ---------------------------------------------------------------------

    public static function test_submit_approve_locks_the_entry()
    {
        $entry = static::__time(static::__project());

        $result = Frontend_Billing_Controller::submit(new Request(), ['id' => $entry->id]);
        static::__assert_equals(Billable_Entry_Model::STATUS_SUBMITTED, (int) $result['status'], 'submitted');

        $entry = Billable_Entry_Model::find($entry->id);
        static::__assert_instance_of(Error_Response::class, static::__save(static::__edit($entry, ['description' => 'Changed'], ['description'])), 'a submitted entry is not edited');

        $result = Frontend_Billing_Controller::approve(new Request(), ['id' => $entry->id]);
        static::__assert_equals(Billable_Entry_Model::STATUS_APPROVED, (int) $result['status'], 'approved (self-approval allowed)');

        $entry = Billable_Entry_Model::find($entry->id);
        static::__assert_equals(1, (int) $entry->reviewed_by_user_id, 'the approver is recorded');
        static::__assert_not_null($entry->reviewed_at, 'and when');

        static::__assert_instance_of(Error_Response::class, static::__save(static::__edit($entry, ['description' => 'Changed'], ['description'])), 'an approved entry is locked');
        static::__assert_instance_of(Error_Response::class, Frontend_Billing_Controller::delete(new Request(), ['id' => $entry->id]), 'and cannot be deleted');
        static::__assert_instance_of(Error_Response::class, Frontend_Billing_Controller::submit(new Request(), ['id' => $entry->id]), 'or resubmitted');
        static::__assert_equals('Design review', Billable_Entry_Model::find($entry->id)->description, 'nothing changed');

        $log = Action_Log_Model::where('subject_type', 'Billable_Entry_Model')->where('subject_id', $entry->id)
            ->where('type_id', Action_Log_Model::TYPE_BILLABLE_ENTRY_APPROVED)->first();
        static::__assert_not_null($log, 'the approval is in the action log');
        static::__assert_contains('approved time entry "<a href="' . Rsx::Route('Billing_View_Action', $entry->id) . '">Design review</a>" on project <a href=', $log->render(), 'and renders linked to the entry and its project');
    }

    public static function test_reject_needs_a_reason_and_an_edit_returns_it_to_draft()
    {
        $entry = static::__time(static::__project());
        Frontend_Billing_Controller::submit(new Request(), ['id' => $entry->id]);

        static::__assert_field_error(
            Frontend_Billing_Controller::reject(new Request(), ['id' => $entry->id, 'rejection_reason' => '  ']),
            'rejection_reason',
            'a blank reason is refused'
        );

        $result = Frontend_Billing_Controller::reject(new Request(), ['id' => $entry->id, 'rejection_reason' => 'Wrong project']);
        static::__assert_equals(Billable_Entry_Model::STATUS_REJECTED, (int) $result['status'], 'rejected');
        $entry = Billable_Entry_Model::find($entry->id);
        static::__assert_equals('Wrong project', $entry->rejection_reason, 'the reason is kept');

        $result = static::__save(static::__edit($entry, ['description' => 'Design review (fixed)'], ['description']));
        static::__assert_array_has_key('billable_entry_id', $result, 'a rejected entry can be edited');

        $entry = Billable_Entry_Model::find($entry->id);
        static::__assert_equals(Billable_Entry_Model::STATUS_DRAFT, (int) $entry->status, 'saving a rejected entry returns it to draft');

        Frontend_Billing_Controller::submit(new Request(), ['id' => $entry->id]);
        $entry = Billable_Entry_Model::find($entry->id);
        static::__assert_equals(Billable_Entry_Model::STATUS_SUBMITTED, (int) $entry->status, 'and it can be resubmitted');
        static::__assert_null($entry->rejection_reason, 'resubmitting clears the earlier review');
    }

    public static function test_a_draft_is_deleted_softly()
    {
        $entry = static::__time(static::__project());

        $result = Frontend_Billing_Controller::delete(new Request(), ['id' => $entry->id]);
        static::__assert_true($result['deleted'] ?? false, 'deleted');
        static::__assert_null(Billable_Entry_Model::find($entry->id), 'gone from reads');
        static::__assert_not_null(Billable_Entry_Model::withTrashed()->find($entry->id), 'but soft-deleted');
    }

    // ---------------------------------------------------------------------
    // who may do what
    // ---------------------------------------------------------------------

    public static function test_a_user_is_refused_the_approval_gates()
    {
        static::__as((int) static::__member(User_Model::ROLE_USER)->id, function () {
            foreach (['approve', 'reject'] as $method) {
                $gates = Auth_Gates::surface_gates("Frontend_Billing_Controller::{$method}");
                static::__assert_true(in_array('can_approve_billing', $gates, true), "{$method} names can_approve_billing");
                static::__assert_false(Auth_Gates::gates_pass($gates, 'staff'), "a User may not {$method}");
            }
            static::__assert_true(Auth_Gates::gates_pass(Auth_Gates::surface_gates('Frontend_Billing_Controller::submit'), 'staff'), 'a User may submit');
        });

        static::__as((int) static::__member(User_Model::ROLE_MANAGER)->id, function () {
            static::__assert_true(Auth_Gates::gates_pass(Auth_Gates::surface_gates('Frontend_Billing_Controller::approve'), 'staff'), 'a Manager may approve');
        });
    }

    public static function test_an_author_changes_only_their_own_entries()
    {
        $project = static::__project();
        $author = static::__member(User_Model::ROLE_USER);
        $other = static::__member(User_Model::ROLE_USER);
        $entry = null;

        static::__as((int) $author->id, function () use ($project, $author, $other, &$entry) {
            $entry = static::__time($project);
            static::__assert_equals((int) $author->id, (int) $entry->user_id, 'the author did the work');

            static::__assert_field_error(
                static::__save([
                    'type_id' => Billable_Entry_Model::TYPE_TIME,
                    'project_id' => $project->id,
                    'user_id' => $other->id,
                    'entry_date' => '2026-11-02',
                    'description' => 'x',
                    'hours' => '1',
                ]),
                'user_id',
                'a User cannot record work for someone else'
            );
        });

        static::__as((int) $other->id, function () use ($entry) {
            $refused = Frontend_Billing_Controller::submit(new Request(), ['id' => $entry->id]);
            static::__assert_instance_of(Error_Response::class, $refused, 'another User cannot submit it');
            static::__assert_equals('unauthorized', $refused->get_error_code(), 'as unauthorized');
            static::__assert_instance_of(Error_Response::class, Frontend_Billing_Controller::delete(new Request(), ['id' => $entry->id]), 'or delete it');
        });

        // An approver (user 1) may act on anybody's entry, and log work for them.
        static::__assert_equals(Billable_Entry_Model::STATUS_SUBMITTED, (int) Frontend_Billing_Controller::submit(new Request(), ['id' => $entry->id])['status'], 'an approver may submit it');
        $logged = static::__time($project, ['user_id' => $other->id]);
        static::__assert_equals((int) $other->id, (int) $logged->user_id, 'an approver logs work for another person');
    }

    public static function test_form_options_offer_the_projects_tasks_and_rate()
    {
        $project = static::__project('75.00');
        $task = static::__task($project);

        $options = Frontend_Billing_Controller::form_options(new Request(), ['project_id' => $project->id]);

        static::__assert_true(in_array($project->id, array_column($options['projects'], 'id'), true), 'the project is offered');
        static::__assert_equals([$task->id], array_column($options['tasks'], 'id'), 'the project\'s tasks');
        static::__assert_instance_of(Rsx_Money::class, $options['default_hourly_rate'], 'the rate a new time entry copies');
        static::__assert_equals('75.00', $options['default_hourly_rate']->to_decimal(), 'is the project rate');
        static::__assert_true($options['can_choose_user'], 'an approver chooses who did the work');
    }
}
