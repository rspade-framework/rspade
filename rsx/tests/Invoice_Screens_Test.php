<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\App\Frontend\Invoices\Frontend_Invoices_Controller;
use Rsx\Models\Billable_Entry_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Invoice_Model;
use Rsx\Models\Project_Model;

/**
 * The server half of the invoice screens (rsx/app/frontend/invoices/):
 *
 *   - the invoices grid filters by client, by status and by "overdue" (issued and past due),
 *     finds an invoice by its printed number, and carries each row's total, overdue flag and
 *     client name
 *   - is_overdue: an ISSUED invoice from the day after its due date, never a draft or a paid one
 *   - the client tab's figures: outstanding (issued), overdue, paid, invoices, entries ready
 *   - the client picker counts each client's entries ready to invoice
 *   - the lines endpoint names each line's project; create and save answer the view page
 *   - every invoice read is an approver's surface, and the activity feed is the action log's
 */
class Invoice_Screens_Test extends Rsx_Test_Abstract
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

    private static function __client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Invoice Screens Client ' . uniqid();
        $client->save();

        return $client;
    }

    private static function __project(Client_Model $client): Project_Model
    {
        $project = new Project_Model();
        $project->name = 'Invoice Screens Project ' . uniqid();
        $project->client_id = $client->id;
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;
        $project->save();

        return $project;
    }

    /** An APPROVED fixed-fee entry, written directly. */
    private static function __entry(Project_Model $project, string $amount): Billable_Entry_Model
    {
        $entry = new Billable_Entry_Model();
        $entry->type_id = Billable_Entry_Model::TYPE_FIXED_FEE;
        $entry->project_id = $project->id;
        $entry->user_id = 1;
        $entry->entry_date = '2026-11-02';
        $entry->description = 'Screens line ' . uniqid();
        $entry->amount = $amount;
        $entry->status = Billable_Entry_Model::STATUS_APPROVED;
        $entry->save();

        return $entry;
    }

    /** A DRAFT invoice for $client over $entries, created through the endpoint. */
    private static function __invoice(Client_Model $client, array $entries): Invoice_Model
    {
        $result = Frontend_Invoices_Controller::create(new Request(), [
            'client_id' => $client->id,
            'entry_ids' => array_map(fn ($e) => $e->id, $entries),
        ]);
        static::__assert_array_has_key('invoice_id', $result, 'the invoice is created: ' . json_encode($result instanceof Error_Response ? $result->get_metadata() : $result));

        return Invoice_Model::find($result['invoice_id']);
    }

    /** Issue $invoice through the endpoint with these dates. */
    private static function __issue(Invoice_Model $invoice, string $issue_date, string $due_date): Invoice_Model
    {
        $result = Frontend_Invoices_Controller::issue(new Request(), [
            'id' => $invoice->id,
            'issue_date' => $issue_date,
            'due_date' => $due_date,
        ]);
        static::__assert_equals(Invoice_Model::STATUS_ISSUED, (int) $result['status'], 'the invoice is issued');

        return Invoice_Model::find($invoice->id);
    }

    private static function __grid(array $params): array
    {
        return Frontend_Invoices_Controller::datagrid_fetch(new Request(), $params + ['per_page' => 100]);
    }

    private static function __ids(array $result): array
    {
        return array_map(fn ($row) => (int) $row['id'], $result['records']);
    }

    private static function __member(int $role_id): User_Model
    {
        $email = 'invoice_screens_' . uniqid() . '@example.com';

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
        $user->first_name = 'Invoice';
        $user->last_name = 'Screens';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->invite_accepted_at = now();
        $user->save();

        return $user;
    }

    // ---------------------------------------------------------------------
    // the invoices grid
    // ---------------------------------------------------------------------

    public static function test_the_grid_filters_by_client_status_overdue_and_number()
    {
        $client = static::__client();
        $project = static::__project($client);

        $draft = static::__invoice($client, [static::__entry($project, '100.00')]);
        $overdue = static::__issue(
            static::__invoice($client, [static::__entry($project, '200.00'), static::__entry($project, '50.25')]),
            Rsx_Date::add_days(Rsx_Date::today(), -40),
            Rsx_Date::add_days(Rsx_Date::today(), -10)
        );
        $current = static::__issue(
            static::__invoice($client, [static::__entry($project, '300.00')]),
            Rsx_Date::today(),
            Rsx_Date::add_days(Rsx_Date::today(), 30)
        );
        $other_client = static::__client();
        static::__invoice($other_client, [static::__entry(static::__project($other_client), '75.00')]);

        $all = static::__grid(['client_id' => $client->id]);
        static::__assert_count(3, $all['records'], "the client's invoices, and no other client's");

        $rows = [];
        foreach ($all['records'] as $row) {
            $rows[(int) $row['id']] = $row;
        }
        static::__assert_equals('250.25', $rows[$overdue->id]['total']->to_decimal(), "a row's total is the sum of its lines");
        static::__assert_true($rows[$overdue->id]['is_overdue'], 'an issued invoice past its due date is overdue');
        static::__assert_false($rows[$current->id]['is_overdue'], 'an issued invoice not yet due is not');
        static::__assert_false($rows[$draft->id]['is_overdue'], 'a draft is never overdue');
        static::__assert_equals($client->name, $rows[$draft->id]['client_name'], "the client's name is joined");

        static::__assert_equals([$overdue->id], static::__ids(static::__grid(['client_id' => $client->id, 'status' => 'overdue'])), "'overdue' is issued and past due");
        static::__assert_equals([$draft->id], static::__ids(static::__grid(['client_id' => $client->id, 'status' => Invoice_Model::STATUS_DRAFT])), 'a status id filters by status');

        $by_number = static::__ids(static::__grid(['filter' => $current->invoice_number_formatted()]));
        static::__assert_equals([$current->id], $by_number, 'the printed number finds its invoice');
        $by_bare_number = static::__ids(static::__grid(['client_id' => $client->id, 'filter' => (string) $current->invoice_number]));
        static::__assert_equals([$current->id], $by_bare_number, 'so does the bare number, which never matches inside a client name');
    }

    public static function test_a_paid_invoice_is_not_overdue()
    {
        $client = static::__client();
        $invoice = static::__issue(
            static::__invoice($client, [static::__entry(static::__project($client), '10.00')]),
            Rsx_Date::add_days(Rsx_Date::today(), -40),
            Rsx_Date::add_days(Rsx_Date::today(), -10)
        );
        static::__assert_true($invoice->is_overdue(), 'past due while issued');

        Frontend_Invoices_Controller::mark_paid(new Request(), ['id' => $invoice->id]);
        static::__assert_false(Invoice_Model::find($invoice->id)->is_overdue(), 'paid is never overdue');

        $due_today = static::__issue(
            static::__invoice($client, [static::__entry(static::__project($client), '10.00')]),
            Rsx_Date::add_days(Rsx_Date::today(), -30),
            Rsx_Date::today()
        );
        static::__assert_false($due_today->is_overdue(), 'due today is not yet overdue');
    }

    // ---------------------------------------------------------------------
    // the client tab and the client picker
    // ---------------------------------------------------------------------

    public static function test_the_client_figures_and_the_ready_count()
    {
        $client = static::__client();
        $project = static::__project($client);

        static::__invoice($client, [static::__entry($project, '999.00')]); // a draft is not outstanding
        static::__issue(
            static::__invoice($client, [static::__entry($project, '120.00')]),
            Rsx_Date::add_days(Rsx_Date::today(), -40),
            Rsx_Date::add_days(Rsx_Date::today(), -10)
        );
        static::__issue(
            static::__invoice($client, [static::__entry($project, '80.00')]),
            Rsx_Date::today(),
            Rsx_Date::add_days(Rsx_Date::today(), 30)
        );
        $paid = static::__issue(
            static::__invoice($client, [static::__entry($project, '45.50')]),
            Rsx_Date::today(),
            Rsx_Date::add_days(Rsx_Date::today(), 30)
        );
        Frontend_Invoices_Controller::mark_paid(new Request(), ['id' => $paid->id]);
        static::__entry($project, '15.00');
        static::__entry($project, '16.00');

        $summary = Frontend_Invoices_Controller::client_invoice_summary(new Request(), ['id' => $client->id]);
        static::__assert_equals(4, (int) $summary['invoice_count'], 'every invoice of the client');
        static::__assert_equals('200.00', $summary['outstanding']->to_decimal(), 'the issued invoices, overdue or not');
        static::__assert_equals('120.00', $summary['overdue']->to_decimal(), 'the issued invoices past due');
        static::__assert_equals('45.50', $summary['paid']->to_decimal(), 'the paid invoices');
        static::__assert_equals(2, (int) $summary['ready_count'], 'approved entries not yet invoiced');

        $options = Frontend_Invoices_Controller::client_options(new Request(), []);
        $mine = array_values(array_filter($options['clients'], fn ($c) => (int) $c['id'] === (int) $client->id));
        static::__assert_count(1, $mine, 'the client is offered');
        static::__assert_equals(2, $mine[0]['ready_count'], 'with its entries ready to invoice');
        static::__assert_contains($client->client_id_formatted(), $mine[0]['label'], 'labelled with its number, since names repeat');
    }

    // ---------------------------------------------------------------------
    // the view page's data, the answers, the gates
    // ---------------------------------------------------------------------

    public static function test_lines_name_their_project_and_create_and_save_answer_the_view_page()
    {
        $client = static::__client();
        $project = static::__project($client);
        $first = static::__entry($project, '10.00');
        $second = static::__entry($project, '20.00');

        $created = Frontend_Invoices_Controller::create(new Request(), [
            'client_id' => $client->id,
            'entry_ids' => [$first->id, $second->id],
        ]);
        static::__assert_equals(Rsx::Route('Invoices_View_Action', $created['invoice_id']), $created['redirect'], 'a create answers its view page');

        $lines = Frontend_Invoices_Controller::invoice_lines(new Request(), ['id' => $created['invoice_id']])['lines'];
        static::__assert_count(2, $lines, 'both lines');
        static::__assert_equals($project->name, $lines[0]['project_name'], "each line names its project");
        static::__assert_equals('10.00', $lines[0]['amount']->to_decimal(), 'and carries its amount');

        $invoice = Invoice_Model::find($created['invoice_id']);
        $saved = Frontend_Invoices_Controller::save(new Request(), [
            'id' => $invoice->id,
            'notes' => 'Net 30',
            'issue_date' => '',
            'due_date' => '',
            '_changed' => ['notes'],
            '_loaded_updated_at' => $invoice->updated_at,
        ]);
        static::__assert_equals(Rsx::Route('Invoices_View_Action', $invoice->id), $saved['redirect'], 'a save answers the view page');
    }

    public static function test_invoice_reads_are_an_approvers_surface()
    {
        foreach (['datagrid_fetch', 'client_options', 'invoice_lines', 'invoice_activity', 'client_invoice_summary', 'uninvoiced_entries'] as $method) {
            $gates = Auth_Gates::surface_gates("Frontend_Invoices_Controller::{$method}");
            static::__assert_true(in_array('can_approve_billing', $gates, true), "{$method} is gated to approvers");
        }

        $activity_gates = Auth_Gates::surface_gates('Frontend_Invoices_Controller::invoice_activity');
        static::__assert_true(in_array('can_view_user_activity', $activity_gates, true), "the activity feed is the action log's");

        $grid_gates = Auth_Gates::surface_gates('Frontend_Invoices_Controller::datagrid_fetch');
        $user = static::__member(User_Model::ROLE_USER);
        $manager = static::__member(User_Model::ROLE_MANAGER);
        try {
            static::__reset_session();
            static::__acting_as_site(self::SITE_ID);
            static::__acting_as_user((int) $user->id);
            static::__assert_false(Auth_Gates::gates_pass($grid_gates, 'staff'), 'a User may not list invoices');

            static::__reset_session();
            static::__acting_as_site(self::SITE_ID);
            static::__acting_as_user((int) $manager->id);
            static::__assert_true(Auth_Gates::gates_pass($grid_gates, 'staff'), 'a Manager may');
        } finally {
            static::__reset_session();
            static::__acting_as_site(self::SITE_ID);
            static::__acting_as_user(1);
        }
    }
}
