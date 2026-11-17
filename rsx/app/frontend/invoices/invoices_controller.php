<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Invoices;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Rsx;
use Rsx\App\Frontend\Invoices\List\Invoices_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Action_Log_Related_Model;
use Rsx\Models\Billable_Entry_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Invoice_Model;
use Rsx\Models\Project_Model;

/**
 * Frontend_Invoices_Controller - Ajax endpoints for client invoices.
 *
 * The rules are the model's (Invoice_Model: create_errors() / create_for_client(), the
 * *_errors() / transition pairs, value_errors() / save_edit()); this controller adds the
 * gates and the action log.
 *
 * GATES. Invoices are an approver's business: every endpoint here adds can_approve_billing
 * (Manager and above) to the class's can_view_data, so the invoice screens - gated the same
 * way - and their data reach exactly the same people. Everything that creates or changes an
 * invoice adds can_edit_data; the activity feed is the action log's (can_view_user_activity).
 *
 * Every invoice operation is ONE #[Writer]: the site's write lock serializes it against every
 * other write on the site, which is what makes the MAX+1 invoice number safe and what keeps an
 * entry from being invoiced twice.
 */
#[Auth('is_logged_in', 'can_view_data')]
class Frontend_Invoices_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: the invoices list (Invoices_DataGrid). Filters: filter, status (a status
     * id or 'overdue'), client_id.
     */
    #[Auth('can_approve_billing')]
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Invoices_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: the clients an invoice can be raised for - every live client, by name.
     *
     * Returns {clients: [{id, label, ready_count}]} - label is the name and the client's number
     * (names repeat); ready_count is how many APPROVED entries on the client's projects are
     * waiting to be invoiced.
     */
    #[Auth('can_approve_billing')]
    #[Ajax_Endpoint]
    public static function client_options(Request $request, array $params = [])
    {
        $ready = Billable_Entry_Model::query()
            ->join('projects', function ($join) {
                $join->on('billable_entries.project_id', '=', 'projects.id')
                    ->on('projects.site_id', '=', 'billable_entries.site_id');
            })
            ->where('billable_entries.status', Billable_Entry_Model::STATUS_APPROVED)
            ->whereNull('billable_entries.invoice_id')
            ->groupBy('projects.client_id')
            ->toBase()
            ->selectRaw('projects.client_id, COUNT(*) AS ready_count')
            ->pluck('ready_count', 'client_id')
            ->all();

        $clients = [];
        foreach (Client_Model::orderBy('name')->orderBy('id')->get() as $client) {
            $clients[] = [
                'id' => $client->id,
                'label' => $client->name . ' (' . $client->client_id_formatted() . ')',
                'ready_count' => (int) ($ready[$client->id] ?? 0),
            ];
        }

        return ['clients' => $clients];
    }

    /**
     * Ajax endpoint: an invoice's lines, oldest work first. Params: id.
     *
     * Returns {lines: [{id, type_id, type_id__label, type_id__badge, entry_date, description,
     * project_id, project_name, amount}]}.
     */
    #[Auth('can_approve_billing')]
    #[Ajax_Endpoint]
    public static function invoice_lines(Request $request, array $params = [])
    {
        $invoice = Invoice_Model::withTrashed()->find($params['id'] ?? 0);
        if (!$invoice) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Invoice not found');
        }

        $entries = $invoice->entries();
        $project_names = Project_Model::withTrashed()
            ->whereIn('id', $entries->pluck('project_id')->unique()->all())
            ->pluck('name', 'id')
            ->all();

        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = [
                'id' => $entry->id,
                'type_id' => $entry->type_id,
                'type_id__label' => $entry->type_id__label,
                'type_id__badge' => $entry->type_id__badge,
                'entry_date' => $entry->entry_date,
                'description' => $entry->description,
                'project_id' => $entry->project_id,
                'project_name' => $project_names[$entry->project_id] ?? null,
                'amount' => $entry->amount,
            ];
        }

        return ['lines' => $lines];
    }

    /**
     * Ajax endpoint: an invoice's activity feed (action log). Params: id.
     *
     * Shared Activity-tab shape (id, rendered summary html, created_at, type_id), behind the
     * Action Log screen's own check as well as the invoice gate.
     */
    #[Auth('can_approve_billing', 'can_view_user_activity')]
    #[Ajax_Endpoint]
    public static function invoice_activity(Request $request, array $params = [])
    {
        $invoice = Invoice_Model::withTrashed()->find($params['id'] ?? 0);
        if (!$invoice) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Invoice not found');
        }

        $activity = [];
        foreach (Action_Log::get_for_entity($invoice, 50) as $log) {
            $activity[] = [
                'id' => $log->id,
                'html' => $log->render(),
                'created_at' => $log->created_at,
                'type_id' => $log->type_id,
            ];
        }

        return ['activity' => $activity];
    }

    /**
     * Ajax endpoint: a client's invoice figures, for the client view's Invoices tab. Params: id.
     *
     * Summed by the database over the client's live invoices:
     *   invoice_count  int - every invoice, whatever its status
     *   outstanding    Rsx_Money - the totals of the ISSUED invoices (sent, not yet paid)
     *   overdue        Rsx_Money - the part of outstanding past its due date
     *   paid           Rsx_Money - the totals of the PAID invoices
     *   ready_count    int - APPROVED entries on the client's projects waiting to be invoiced
     */
    #[Auth('can_approve_billing')]
    #[Ajax_Endpoint]
    public static function client_invoice_summary(Request $request, array $params = [])
    {
        $client = Client_Model::withTrashed()->find($params['id'] ?? 0);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $invoices = fn () => Invoice_Model::where('invoices.client_id', $client->id);
        $billed_on = fn ($invoice_query) => Billable_Entry_Model::whereIn(
            'billable_entries.invoice_id',
            $invoice_query->select('invoices.id')
        )->sum_money('amount');

        $project_ids = Project_Model::withTrashed()->where('client_id', $client->id)->pluck('id')->all();

        return [
            'invoice_count' => $invoices()->count(),
            'outstanding' => $billed_on($invoices()->where('invoices.status', Invoice_Model::STATUS_ISSUED)),
            'overdue' => $billed_on(Invoice_Model::where_overdue($invoices())),
            'paid' => $billed_on($invoices()->where('invoices.status', Invoice_Model::STATUS_PAID)),
            'ready_count' => Billable_Entry_Model::whereIn('project_id', $project_ids)
                ->where('status', Billable_Entry_Model::STATUS_APPROVED)
                ->whereNull('invoice_id')
                ->count(),
        ];
    }

    /**
     * Ajax endpoint: a client's APPROVED entries not yet on an invoice - what "New invoice"
     * offers. Params: client_id.
     *
     * Returns {entries: [{id, type_id, type_id__label, entry_date, description, project_id,
     * project_name, amount}], total} - oldest work first; total is their sum (Rsx_Money).
     */
    #[Auth('can_approve_billing')]
    #[Ajax_Endpoint]
    public static function uninvoiced_entries(Request $request, array $params = [])
    {
        $client = Client_Model::find($params['client_id'] ?? 0);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $project_names = Project_Model::withTrashed()->where('client_id', $client->id)->pluck('name', 'id')->all();

        $query = Billable_Entry_Model::whereIn('project_id', array_keys($project_names))
            ->where('status', Billable_Entry_Model::STATUS_APPROVED)
            ->whereNull('invoice_id');

        $entries = [];
        foreach ((clone $query)->orderBy('entry_date')->orderBy('id')->get() as $entry) {
            $entries[] = [
                'id' => $entry->id,
                'type_id' => $entry->type_id,
                'type_id__label' => $entry->type_id__label,
                'entry_date' => $entry->entry_date,
                'description' => $entry->description,
                'project_id' => $entry->project_id,
                'project_name' => $project_names[$entry->project_id] ?? null,
                'amount' => $entry->amount,
            ];
        }

        return ['entries' => $entries, 'total' => $query->sum_money('amount')];
    }

    /**
     * Ajax endpoint: create a DRAFT invoice for a client from approved, uninvoiced entries,
     * which become BILLED on it. Params: client_id, entry_ids (list of ids).
     *
     * Field errors on client_id / entry_ids. Returns {invoice_id, invoice_number_formatted,
     * redirect (the invoice's view page)}.
     */
    #[Auth('can_edit_data', 'can_approve_billing')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function create(Request $request, array $params = [])
    {
        $errors = Invoice_Model::create_errors($params['client_id'] ?? null, $params['entry_ids'] ?? null);
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        $invoice = Invoice_Model::create_for_client(
            (int) $params['client_id'],
            Project_Model::read_id_list($params['entry_ids'])
        );
        static::__log(Action_Log_Model::TYPE_INVOICE_CREATED, $invoice);

        return [
            'invoice_id' => $invoice->id,
            'invoice_number_formatted' => $invoice->invoice_number_formatted(),
            'redirect' => Rsx::Route('Invoices_View_Action', $invoice->id),
        ];
    }

    /**
     * Ajax endpoint: issue a DRAFT invoice. Params: id, issue_date (optional, default today),
     * due_date (optional, default issue date + Invoice_Model::PAYMENT_TERMS_DAYS).
     * Returns {status, issue_date, due_date}.
     */
    #[Auth('can_edit_data', 'can_approve_billing')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function issue(Request $request, array $params = [])
    {
        $invoice = Invoice_Model::find($params['id'] ?? 0);
        if (!$invoice) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Invoice not found');
        }

        $refused = static::__refusal($invoice->issue_errors($params));
        if ($refused !== null) {
            return $refused;
        }

        $invoice->issue($params);
        static::__log(Action_Log_Model::TYPE_INVOICE_ISSUED, $invoice);

        return ['status' => $invoice->status, 'issue_date' => $invoice->issue_date, 'due_date' => $invoice->due_date];
    }

    /**
     * Ajax endpoint: record an ISSUED invoice as paid. Params: id, paid_date (optional,
     * default today). Returns {status, paid_date}.
     */
    #[Auth('can_edit_data', 'can_approve_billing')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function mark_paid(Request $request, array $params = [])
    {
        $invoice = Invoice_Model::find($params['id'] ?? 0);
        if (!$invoice) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Invoice not found');
        }

        $refused = static::__refusal($invoice->mark_paid_errors($params));
        if ($refused !== null) {
            return $refused;
        }

        $invoice->mark_paid($params);
        static::__log(Action_Log_Model::TYPE_INVOICE_PAID, $invoice);

        return ['status' => $invoice->status, 'paid_date' => $invoice->paid_date];
    }

    /**
     * Ajax endpoint: void a DRAFT or ISSUED invoice; every entry on it returns to APPROVED.
     * Params: id. Returns {status, released}.
     */
    #[Auth('can_edit_data', 'can_approve_billing')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function void(Request $request, array $params = [])
    {
        $invoice = Invoice_Model::find($params['id'] ?? 0);
        if (!$invoice) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Invoice not found');
        }

        $refused = static::__refusal($invoice->void_errors());
        if ($refused !== null) {
            return $refused;
        }

        $released = Billable_Entry_Model::where('invoice_id', $invoice->id)->count();
        $invoice->void();
        static::__log(Action_Log_Model::TYPE_INVOICE_VOIDED, $invoice, ['released_entries' => $released]);

        return ['status' => $invoice->status, 'released' => $released];
    }

    /**
     * Ajax endpoint: take one line off a DRAFT invoice; the entry returns to APPROVED.
     * Params: id (the invoice), entry_id. Returns {total} - the invoice's new total.
     */
    #[Auth('can_edit_data', 'can_approve_billing')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function remove_line(Request $request, array $params = [])
    {
        $invoice = Invoice_Model::find($params['id'] ?? 0);
        if (!$invoice) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Invoice not found');
        }

        $refused = static::__refusal($invoice->remove_line_errors($params['entry_id'] ?? null));
        if ($refused !== null) {
            return $refused;
        }

        $entry = $invoice->remove_line((int) $params['entry_id']);

        $project = Project_Model::withTrashed()->find($entry->project_id);
        Action_Log::record(
            Action_Log_Model::TYPE_BILLABLE_ENTRY_UPDATED,
            $entry,
            array_filter([
                [$invoice, Action_Log_Related_Model::ROLE_MENTIONED],
                $project ? [$project, Action_Log_Related_Model::ROLE_MENTIONED] : null,
            ]),
            ['removed_from_invoice' => $invoice->invoice_number_formatted()]
        );

        return ['total' => $invoice->total()];
    }

    /**
     * Ajax endpoint: edit an invoice's dates and notes (DRAFT or ISSUED). Params: id,
     * issue_date, due_date, notes, plus the Form_Edit fields (_changed, _loaded_updated_at).
     * Returns {invoice_id, message, redirect (the invoice's view page), updated_at}.
     */
    #[Auth('can_edit_data', 'can_approve_billing')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        $invoice = Invoice_Model::find($params['id'] ?? 0);
        if (!$invoice) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Invoice not found');
        }

        $locked = $invoice->edit_error();
        if ($locked !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $locked);
        }

        $errors = $invoice->value_errors($params);
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        return Form_Edit::run($invoice, $params, function (Form_Edit $edit) use ($invoice, $params) {
            $invoice->save_edit($edit, $params);

            return [
                'invoice_id' => $invoice->id,
                'message' => 'Invoice updated',
                'redirect' => Rsx::Route('Invoices_View_Action', $invoice->id),
            ];
        }, Rsx::Route('Invoices_View_Action', $invoice->id));
    }

    /**
     * A transition's refusal as the response to return, or null when there is none: a
     * `status` refusal is the invoice's state (a plain validation error), anything else is a
     * field error on the field it names.
     *
     * @param array<string, string> $errors
     */
    private static function __refusal(array $errors)
    {
        if (empty($errors)) {
            return null;
        }
        if (isset($errors['status'])) {
            return response_error(Ajax::ERROR_VALIDATION, $errors['status']);
        }

        return response_form_error('Please correct the errors below.', $errors);
    }

    /** Record an invoice action, with its client related so the client's activity shows it. */
    private static function __log(int $type_id, Invoice_Model $invoice, array $metadata = []): void
    {
        $client = Client_Model::withTrashed()->find($invoice->client_id);
        $related = $client ? [[$client, Action_Log_Related_Model::ROLE_MENTIONED]] : [];

        Action_Log::record($type_id, $invoice, $related, $metadata);
    }
}
