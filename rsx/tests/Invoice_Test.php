<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\App\Frontend\Invoices\Frontend_Invoices_Controller;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Billable_Entry_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Invoice_Model;
use Rsx\Models\Project_Model;

/**
 * Invoices, driven through the invoice endpoints:
 *
 *   - numbers are sequential per site (MAX + 1 under the site write lock), printed INV-0001
 *   - creating an invoice bills the chosen APPROVED, uninvoiced entries of that client's
 *     projects (BILLED, invoice_id set); a draft, submitted or already-billed entry, or one on
 *     another client's project, is refused with nothing written
 *   - the total is the exact sum of the entries' amounts, never stored
 *   - removing a line from a draft and voiding an invoice release entries back to APPROVED
 *   - issuing sets today's date and a due date 30 days on; marking paid sets the paid date;
 *     each transition is refused from the wrong state
 */
class Invoice_Test extends Rsx_Test_Abstract
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
        $client->name = 'Invoice Client ' . uniqid();
        $client->save();

        return $client;
    }

    private static function __project(Client_Model $client): Project_Model
    {
        $project = new Project_Model();
        $project->name = 'Invoice Project ' . uniqid();
        $project->client_id = $client->id;
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;
        $project->save();

        return $project;
    }

    /** A fixed-fee entry written directly in $status. */
    private static function __entry(Project_Model $project, string $amount, int $status = Billable_Entry_Model::STATUS_APPROVED): Billable_Entry_Model
    {
        $entry = new Billable_Entry_Model();
        $entry->type_id = Billable_Entry_Model::TYPE_FIXED_FEE;
        $entry->project_id = $project->id;
        $entry->user_id = 1;
        $entry->entry_date = '2026-11-02';
        $entry->description = 'Invoice line ' . uniqid();
        $entry->amount = $amount;
        $entry->status = $status;
        $entry->save();

        return $entry;
    }

    /** Create an invoice through the endpoint; it must succeed. */
    private static function __invoice(Client_Model $client, array $entries): Invoice_Model
    {
        $result = Frontend_Invoices_Controller::create(new Request(), [
            'client_id' => $client->id,
            'entry_ids' => array_map(fn ($e) => $e->id, $entries),
        ]);
        static::__assert_array_has_key('invoice_id', $result, 'the invoice is created: ' . json_encode($result instanceof Error_Response ? $result->get_metadata() : $result));

        return Invoice_Model::find($result['invoice_id']);
    }

    private static function __status(Billable_Entry_Model $entry): int
    {
        return (int) Billable_Entry_Model::find($entry->id)->status;
    }

    // ---------------------------------------------------------------------
    // numbering
    // ---------------------------------------------------------------------

    public static function test_numbers_are_sequential_per_site()
    {
        $client = static::__client();
        $project = static::__project($client);

        $first = static::__invoice($client, [static::__entry($project, '10')]);
        $second = static::__invoice($client, [static::__entry($project, '20')]);

        static::__assert_equals((int) $first->invoice_number + 1, (int) $second->invoice_number, 'the next invoice takes the next number');
        static::__assert_equals(
            'INV-' . str_pad((string) $second->invoice_number, 4, '0', STR_PAD_LEFT),
            $second->invoice_number_formatted,
            'printed INV-NNNN'
        );
        static::__assert_equals((int) $second->invoice_number + 1, Invoice_Model::next_invoice_number(), 'MAX + 1 on this site');
    }

    // ---------------------------------------------------------------------
    // creating
    // ---------------------------------------------------------------------

    public static function test_creating_bills_the_entries_and_totals_them()
    {
        $client = static::__client();
        $project = static::__project($client);
        $a = static::__entry($project, '100.10');
        $b = static::__entry($project, '0.20');
        $other = static::__entry($project, '5.00');

        $invoice = static::__invoice($client, [$a, $b]);

        static::__assert_equals(Invoice_Model::STATUS_DRAFT, (int) $invoice->status, 'a new invoice is a draft');
        foreach ([$a, $b] as $entry) {
            $fresh = Billable_Entry_Model::find($entry->id);
            static::__assert_equals(Billable_Entry_Model::STATUS_BILLED, (int) $fresh->status, 'the entry is billed');
            static::__assert_equals((int) $invoice->id, (int) $fresh->invoice_id, 'on this invoice');
        }
        static::__assert_equals(Billable_Entry_Model::STATUS_APPROVED, static::__status($other), 'an entry not chosen is untouched');

        static::__assert_equals('100.30', $invoice->total()->to_decimal(), 'the total is the exact sum of the lines');
        $payload_total = $invoice->toArray()['total'] ?? null;
        static::__assert_instance_of(Rsx_Money::class, $payload_total, 'the total rides the payload as money');
        static::__assert_equals('100.30', $payload_total->to_decimal(), 'with the same amount');

        $offered = Frontend_Invoices_Controller::uninvoiced_entries(new Request(), ['client_id' => $client->id]);
        static::__assert_equals([$other->id], array_column($offered['entries'], 'id'), 'only the uninvoiced entry is still offered');
        static::__assert_equals('5.00', $offered['total']->to_decimal(), 'with its total');
    }

    public static function test_only_approved_uninvoiced_entries_of_the_client_are_invoiced()
    {
        $client = static::__client();
        $project = static::__project($client);
        $foreign = static::__project(static::__client());

        $cases = [
            'a draft' => static::__entry($project, '1', Billable_Entry_Model::STATUS_DRAFT),
            'a submitted entry' => static::__entry($project, '1', Billable_Entry_Model::STATUS_SUBMITTED),
            'another client\'s entry' => static::__entry($foreign, '1'),
        ];
        $billed = static::__entry($project, '1');
        static::__invoice($client, [$billed]);
        $cases['an already billed entry'] = $billed;

        $before = Invoice_Model::count();
        foreach ($cases as $label => $entry) {
            $good = static::__entry($project, '2');
            $result = Frontend_Invoices_Controller::create(new Request(), [
                'client_id' => $client->id,
                'entry_ids' => [$good->id, $entry->id],
            ]);

            static::__assert_instance_of(Error_Response::class, $result, "{$label} is refused");
            static::__assert_array_has_key('entry_ids', $result->get_metadata(), "{$label} (on entry_ids)");
            static::__assert_equals(Billable_Entry_Model::STATUS_APPROVED, static::__status($good), "{$label}: nothing was billed");
        }
        static::__assert_equals($before, Invoice_Model::count(), 'no invoice was written');

        $empty = Frontend_Invoices_Controller::create(new Request(), ['client_id' => $client->id, 'entry_ids' => []]);
        static::__assert_instance_of(Error_Response::class, $empty, 'an invoice needs at least one entry');
    }

    // ---------------------------------------------------------------------
    // lines and voiding
    // ---------------------------------------------------------------------

    public static function test_removing_a_line_releases_the_entry()
    {
        $client = static::__client();
        $project = static::__project($client);
        $a = static::__entry($project, '10');
        $b = static::__entry($project, '15');
        $invoice = static::__invoice($client, [$a, $b]);

        $result = Frontend_Invoices_Controller::remove_line(new Request(), ['id' => $invoice->id, 'entry_id' => $a->id]);
        static::__assert_equals('15.00', $result['total']->to_decimal(), 'the total drops with the line');

        $fresh = Billable_Entry_Model::find($a->id);
        static::__assert_equals(Billable_Entry_Model::STATUS_APPROVED, (int) $fresh->status, 'the entry is approved again');
        static::__assert_null($fresh->invoice_id, 'and off the invoice');

        static::__assert_instance_of(
            Error_Response::class,
            Frontend_Invoices_Controller::remove_line(new Request(), ['id' => $invoice->id, 'entry_id' => $a->id]),
            'an entry not on the invoice is refused'
        );

        Frontend_Invoices_Controller::issue(new Request(), ['id' => $invoice->id]);
        static::__assert_instance_of(
            Error_Response::class,
            Frontend_Invoices_Controller::remove_line(new Request(), ['id' => $invoice->id, 'entry_id' => $b->id]),
            'an issued invoice keeps its lines'
        );
    }

    public static function test_voiding_releases_every_entry()
    {
        $client = static::__client();
        $project = static::__project($client);
        $a = static::__entry($project, '10');
        $b = static::__entry($project, '20');
        $invoice = static::__invoice($client, [$a, $b]);
        Frontend_Invoices_Controller::issue(new Request(), ['id' => $invoice->id]);

        $result = Frontend_Invoices_Controller::void(new Request(), ['id' => $invoice->id]);
        static::__assert_equals(Invoice_Model::STATUS_VOID, (int) $result['status'], 'void');
        static::__assert_equals(2, $result['released'], 'both entries released');

        foreach ([$a, $b] as $entry) {
            $fresh = Billable_Entry_Model::find($entry->id);
            static::__assert_equals(Billable_Entry_Model::STATUS_APPROVED, (int) $fresh->status, 'approved again');
            static::__assert_null($fresh->invoice_id, 'with no invoice');
        }
        static::__assert_equals('0.00', Invoice_Model::find($invoice->id)->total()->to_decimal(), 'a void invoice totals nothing');

        static::__invoice($client, [$a, $b]);   // the released entries can be invoiced again

        static::__assert_instance_of(Error_Response::class, Frontend_Invoices_Controller::void(new Request(), ['id' => $invoice->id]), 'a void invoice is not voided twice');
    }

    // ---------------------------------------------------------------------
    // issue and pay
    // ---------------------------------------------------------------------

    public static function test_issue_and_mark_paid_set_their_dates()
    {
        $client = static::__client();
        $invoice = static::__invoice($client, [static::__entry(static::__project($client), '50')]);

        static::__assert_instance_of(Error_Response::class, Frontend_Invoices_Controller::mark_paid(new Request(), ['id' => $invoice->id]), 'a draft is not paid');

        $today = Rsx_Date::today();
        $result = Frontend_Invoices_Controller::issue(new Request(), ['id' => $invoice->id]);
        static::__assert_equals(Invoice_Model::STATUS_ISSUED, (int) $result['status'], 'issued');
        static::__assert_equals($today, $result['issue_date'], 'issued today');
        static::__assert_equals(Rsx_Date::add_days($today, 30), $result['due_date'], 'due 30 days later');

        static::__assert_instance_of(Error_Response::class, Frontend_Invoices_Controller::issue(new Request(), ['id' => $invoice->id]), 'an issued invoice is not issued twice');

        $result = Frontend_Invoices_Controller::mark_paid(new Request(), ['id' => $invoice->id, 'paid_date' => '2026-11-20']);
        static::__assert_equals(Invoice_Model::STATUS_PAID, (int) $result['status'], 'paid');
        static::__assert_equals('2026-11-20', Invoice_Model::find($invoice->id)->paid_date, 'on the date given');

        static::__assert_instance_of(Error_Response::class, Frontend_Invoices_Controller::void(new Request(), ['id' => $invoice->id]), 'a paid invoice is not voided');

        $log = Action_Log_Model::where('subject_type', 'Invoice_Model')->where('subject_id', $invoice->id)
            ->where('type_id', Action_Log_Model::TYPE_INVOICE_PAID)->first();
        static::__assert_not_null($log, 'the payment is in the action log');
        $line = $log->render();
        static::__assert_contains('marked paid invoice <a href=', $line, 'and renders with its invoice link');
        static::__assert_contains('>' . $invoice->invoice_number_formatted() . '</a> for client <a href=', $line, 'and its client link');
    }

    public static function test_issue_takes_given_dates_and_refuses_a_due_date_before_issue()
    {
        $client = static::__client();
        $invoice = static::__invoice($client, [static::__entry(static::__project($client), '50')]);

        $refused = Frontend_Invoices_Controller::issue(new Request(), ['id' => $invoice->id, 'issue_date' => '2026-12-10', 'due_date' => '2026-12-01']);
        static::__assert_instance_of(Error_Response::class, $refused, 'due before issue is refused');
        static::__assert_array_has_key('due_date', $refused->get_metadata(), 'on due_date');
        static::__assert_equals(Invoice_Model::STATUS_DRAFT, (int) Invoice_Model::find($invoice->id)->status, 'still a draft');

        $result = Frontend_Invoices_Controller::issue(new Request(), ['id' => $invoice->id, 'issue_date' => '2026-12-10']);
        static::__assert_equals('2027-01-09', $result['due_date'], '30 days after the given issue date');
    }

    public static function test_dates_and_notes_are_edited_through_form_edit()
    {
        $client = static::__client();
        $invoice = static::__invoice($client, [static::__entry(static::__project($client), '50')]);

        $result = Frontend_Invoices_Controller::save(new Request(), [
            'id' => $invoice->id,
            'notes' => 'Net 30',
            'due_date' => '2026-12-31',
            '_changed' => ['notes', 'due_date'],
            '_loaded_updated_at' => $invoice->updated_at,
        ]);
        static::__assert_array_has_key('invoice_id', $result, 'saved');

        $fresh = Invoice_Model::find($invoice->id);
        static::__assert_equals('Net 30', $fresh->notes->to_plain_text(), 'notes saved');
        static::__assert_equals('2026-12-31', $fresh->due_date, 'due date saved');
    }
}
