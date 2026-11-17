<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Billing\Frontend_Billing_Controller;
use Rsx\Models\Billable_Entry_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * The server half of the billing screens (rsx/app/frontend/billing/):
 *
 *   - the entries grid preloads every row's detail - one query per detail table, never one per
 *     row (DataGrid_Abstract's detail-tables hook) - and filters by project, status and "mine"
 *   - the approvals grid holds only SUBMITTED entries, whatever status is asked for, and is an
 *     approver's surface
 *   - the project and task figures: hours from the time details, approved and billed amounts,
 *     budget remaining; a deleted entry and another project's entries are not counted
 *   - a save answers the entry's view page; the entry's activity feed is the action log's
 */
class Billing_Screens_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** Queries against the detail tables, counted while $counting is set. */
    private static int $detail_queries = 0;

    private static bool $counting = false;

    private static bool $listening = false;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        static::__acting_as_user(1);

        if (!static::$listening) {
            static::$listening = true;
            DB::listen(function ($query) {
                if (static::$counting && preg_match('/from `billable_entry_(time|expense)_details`/', $query->sql)) {
                    static::$detail_queries++;
                }
            });
        }
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    private static function __project(?string $budget = null): Project_Model
    {
        $client = new Client_Model();
        $client->name = 'Screens Client ' . uniqid();
        $client->save();

        $project = new Project_Model();
        $project->name = 'Screens Project ' . uniqid();
        $project->client_id = $client->id;
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;
        $project->hourly_rate = '100.00';
        $project->budget = $budget;
        $project->save();

        return $project;
    }

    private static function __task(Project_Model $project): Task_Model
    {
        $task = new Task_Model();
        $task->title = 'Screens Task ' . uniqid();
        $task->taskable_type = 'Project_Model';
        $task->taskable_id = $project->id;
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->save();

        return $task;
    }

    private static function __member(int $role_id): User_Model
    {
        $email = 'screens_' . uniqid() . '@example.com';

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
        $user->first_name = 'Screens';
        $user->last_name = 'Member';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->invite_accepted_at = now();
        $user->save();

        return $user;
    }

    /** Create an entry through the endpoint (as user 1, an approver); answers it. */
    private static function __entry(Project_Model $project, int $type_id, array $values = []): Billable_Entry_Model
    {
        $figures = match ($type_id) {
            Billable_Entry_Model::TYPE_TIME => ['hours' => '2'],
            Billable_Entry_Model::TYPE_EXPENSE => ['cost' => '40.00', 'vendor' => 'Vendor'],
            default => ['amount' => '500.00'],
        };

        $result = Frontend_Billing_Controller::save(new Request(), array_merge([
            'type_id' => $type_id,
            'project_id' => $project->id,
            'entry_date' => '2026-11-02',
            'description' => 'Screens entry',
        ], $figures, $values));
        static::__assert_array_has_key('billable_entry_id', $result, 'the entry saves');

        return Billable_Entry_Model::find($result['billable_entry_id']);
    }

    private static function __grid(array $params): array
    {
        return Frontend_Billing_Controller::datagrid_fetch(new Request(), $params + ['per_page' => 100]);
    }

    // ---------------------------------------------------------------------
    // the entries grid
    // ---------------------------------------------------------------------

    public static function test_the_grid_preloads_details_one_query_per_detail_table()
    {
        $project = static::__project();
        for ($i = 0; $i < 3; $i++) {
            static::__entry($project, Billable_Entry_Model::TYPE_TIME);
            static::__entry($project, Billable_Entry_Model::TYPE_EXPENSE);
            static::__entry($project, Billable_Entry_Model::TYPE_FIXED_FEE);
        }

        static::$detail_queries = 0;
        static::$counting = true;
        try {
            $result = static::__grid(['project_id' => $project->id]);
        } finally {
            static::$counting = false;
        }

        static::__assert_count(9, $result['records'], 'every entry of the project');
        static::__assert_equals(2, static::$detail_queries, 'one query per detail table, not one per row');

        foreach ($result['records'] as $row) {
            $details = $row['__details'] ?? [];
            if ((int) $row['type_id'] === Billable_Entry_Model::TYPE_TIME) {
                static::__assert_array_has_key('billable_entry_time', $details, 'a time row carries its detail');
            } elseif ((int) $row['type_id'] === Billable_Entry_Model::TYPE_EXPENSE) {
                static::__assert_array_has_key('billable_entry_expense', $details, 'an expense row carries its detail');
            } else {
                static::__assert_empty($details, 'a fixed fee has no detail');
            }
            static::__assert_equals($project->name, $row['project_name'], 'the project name is joined');
            static::__assert_not_empty($row['user_name'], 'the person is named');
        }
    }

    public static function test_the_grid_filters_by_status_and_by_whose_entries()
    {
        $project = static::__project();
        $member = static::__member(User_Model::ROLE_USER);

        $mine = static::__entry($project, Billable_Entry_Model::TYPE_TIME);
        static::__entry($project, Billable_Entry_Model::TYPE_TIME, ['user_id' => $member->id]);
        Frontend_Billing_Controller::submit(new Request(), ['id' => $mine->id]);

        $submitted = static::__grid(['project_id' => $project->id, 'status' => Billable_Entry_Model::STATUS_SUBMITTED]);
        static::__assert_count(1, $submitted['records'], 'the status filter');
        static::__assert_equals($mine->id, (int) $submitted['records'][0]['id'], 'the submitted entry');

        $only_mine = static::__grid(['project_id' => $project->id, 'mine' => 1]);
        static::__assert_count(1, $only_mine['records'], "'mine' leaves out another person's entry");
        static::__assert_equals(1, (int) $only_mine['records'][0]['user_id'], "the signed-in user's own");

        static::__assert_count(2, static::__grid(['project_id' => $project->id])['records'], 'everyone');
    }

    // ---------------------------------------------------------------------
    // the approvals grid
    // ---------------------------------------------------------------------

    public static function test_the_approvals_grid_holds_only_submitted_entries_for_approvers()
    {
        $project = static::__project();
        $draft = static::__entry($project, Billable_Entry_Model::TYPE_FIXED_FEE);
        $waiting = static::__entry($project, Billable_Entry_Model::TYPE_FIXED_FEE);
        Frontend_Billing_Controller::submit(new Request(), ['id' => $waiting->id]);

        $result = Frontend_Billing_Controller::approvals_datagrid_fetch(new Request(), [
            'status' => Billable_Entry_Model::STATUS_DRAFT,
            'filter' => $project->name,
        ]);
        $ids = array_map(fn ($row) => (int) $row['id'], $result['records']);
        static::__assert_true(in_array($waiting->id, $ids, true), 'the submitted entry is in the queue');
        static::__assert_false(in_array($draft->id, $ids, true), 'a draft is not, even when the request asks for drafts');

        $gates = Auth_Gates::surface_gates('Frontend_Billing_Controller::approvals_datagrid_fetch');
        static::__assert_true(in_array('can_approve_billing', $gates, true), 'the queue is an approver surface');

        $user = static::__member(User_Model::ROLE_USER);
        static::__reset_session();
        static::__acting_as_site(self::SITE_ID);
        static::__acting_as_user((int) $user->id);
        try {
            static::__assert_false(Auth_Gates::gates_pass($gates, 'staff'), 'a User may not read the queue');
        } finally {
            static::__reset_session();
            static::__acting_as_site(self::SITE_ID);
            static::__acting_as_user(1);
        }
    }

    // ---------------------------------------------------------------------
    // the project and task figures
    // ---------------------------------------------------------------------

    public static function test_the_project_and_task_figures()
    {
        $project = static::__project('1000.00');
        $task = static::__task($project);

        $approved = static::__entry($project, Billable_Entry_Model::TYPE_TIME, ['hours' => '2.5', 'task_id' => $task->id]);
        static::__entry($project, Billable_Entry_Model::TYPE_TIME, ['hours' => '1.25', 'task_id' => $task->id]);
        $fee = static::__entry($project, Billable_Entry_Model::TYPE_FIXED_FEE, ['amount' => '300.00']);
        $deleted = static::__entry($project, Billable_Entry_Model::TYPE_TIME, ['hours' => '9', 'task_id' => $task->id]);
        Frontend_Billing_Controller::delete(new Request(), ['id' => $deleted->id]);
        static::__entry(static::__project(), Billable_Entry_Model::TYPE_TIME, ['hours' => '7']);

        foreach ([$approved, $fee] as $entry) {
            Frontend_Billing_Controller::submit(new Request(), ['id' => $entry->id]);
            Frontend_Billing_Controller::approve(new Request(), ['id' => $entry->id]);
        }

        $summary = Frontend_Billing_Controller::project_billing_summary(new Request(), ['id' => $project->id]);
        static::__assert_equals('3.75', $summary['hours_logged'], 'the live time entries of this project, whatever their status');
        static::__assert_equals('550.00', $summary['approved_amount']->to_decimal(), '250.00 + 300.00 approved');
        static::__assert_equals('0.00', $summary['billed_amount']->to_decimal(), 'nothing invoiced');
        static::__assert_equals('450.00', $summary['budget_remaining']->to_decimal(), 'the budget less the approved amount');
        static::__assert_equals(3, (int) $summary['entry_count'], 'the deleted entry is not counted');

        $task_summary = Frontend_Billing_Controller::task_billing_summary(new Request(), ['id' => $task->id]);
        static::__assert_equals('3.75', $task_summary['hours_logged'], "the task's live time entries");

        $none = Frontend_Billing_Controller::project_billing_summary(new Request(), ['id' => static::__project()->id]);
        static::__assert_equals('0.00', $none['hours_logged'], 'no entries: zero hours');
        static::__assert_null($none['budget_remaining'], 'no budget: nothing remaining to report');
    }

    // ---------------------------------------------------------------------
    // the save answer and the activity feed
    // ---------------------------------------------------------------------

    public static function test_a_save_answers_the_view_page_and_the_activity_feed_names_it()
    {
        $project = static::__project();
        $result = Frontend_Billing_Controller::save(new Request(), [
            'type_id' => Billable_Entry_Model::TYPE_FIXED_FEE,
            'project_id' => $project->id,
            'entry_date' => '2026-11-02',
            'description' => 'Workshop fee',
            'amount' => '250.00',
        ]);
        static::__assert_equals(Rsx::Route('Billing_View_Action', $result['billable_entry_id']), $result['redirect'], 'a create answers its view page');

        $activity = Frontend_Billing_Controller::billing_activity(new Request(), ['id' => $result['billable_entry_id']]);
        static::__assert_count(1, $activity['activity'], 'the creation is logged');
        static::__assert_contains('Workshop fee</a>', $activity['activity'][0]['html'], 'the line links the entry');

        $gates = Auth_Gates::surface_gates('Frontend_Billing_Controller::billing_activity');
        static::__assert_true(in_array('can_view_user_activity', $gates, true), "the feed is the action log's");
    }
}
