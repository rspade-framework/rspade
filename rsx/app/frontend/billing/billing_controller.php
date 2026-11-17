<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Billing;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use Rsx\App\Frontend\Billing\Approvals\Billing_Approvals_DataGrid;
use Rsx\App\Frontend\Billing\List\Billing_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Action_Log_Related_Model;
use Rsx\Models\Billable_Entry_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;
use Rsx\Permission;

/**
 * Frontend_Billing_Controller - Ajax endpoints for billable entries (time, expenses, fixed
 * fees) and their approval.
 *
 * The rules are the model's (Billable_Entry_Model: value_errors(), save_edit(), the
 * *_error() / transition pairs), so a future API shares them; this controller adds the gates,
 * the record rule and the action log.
 *
 * GATES. Every endpoint reads data (can_view_data, class level). A write adds can_edit_data;
 * approving and rejecting add can_approve_billing (Manager and above), and so does the
 * approvals queue's grid. The activity feed is the action log's (can_view_user_activity).
 *
 * RECORD RULE. An existing entry is saved, submitted, deleted or given a receipt only by its
 * author (the person whose work it records) or an approver -
 * Billable_Entry_Model::is_changeable_by_current_user(). Self-approval is allowed: an approver
 * may approve an entry they logged.
 */
#[Auth('is_logged_in', 'can_view_data')]
class Frontend_Billing_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: the entries list (Billing_DataGrid). Filters: filter, status,
     * project_id, mine.
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Billing_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: the approvals queue (Billing_Approvals_DataGrid) - the SUBMITTED entries,
     * for the approvers who act on them.
     */
    #[Auth('can_edit_data', 'can_approve_billing')]
    #[Ajax_Endpoint]
    public static function approvals_datagrid_fetch(Request $request, array $params = [])
    {
        return Billing_Approvals_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: an entry's activity feed (action log). Params: id.
     *
     * Shared Activity-tab shape (id, rendered summary html, created_at, type_id), behind the
     * Action Log screen's own check, so a per-record feed never shows what that screen refuses.
     */
    #[Auth('can_view_user_activity')]
    #[Ajax_Endpoint]
    public static function billing_activity(Request $request, array $params = [])
    {
        $entry = Billable_Entry_Model::withTrashed()->find($params['id'] ?? 0);
        if (!$entry) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Entry not found');
        }

        $activity = [];
        foreach (Action_Log::get_for_entity($entry, 50) as $log) {
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
     * Ajax endpoint: a project's billing figures, for its view page's Billing tab. Params: id.
     *
     * Every figure is summed by the database over the project's live (not deleted) entries:
     *   hours_logged      decimal string - the hours of every time entry, whatever its status
     *   approved_amount   Rsx_Money - APPROVED and BILLED entries (what the client is charged)
     *   billed_amount     Rsx_Money - BILLED entries (on an invoice)
     *   budget_remaining  Rsx_Money|null - the project's budget less approved_amount; null when
     *                     the project has no budget. Negative = over budget.
     *   entry_count       int
     */
    #[Ajax_Endpoint]
    public static function project_billing_summary(Request $request, array $params = [])
    {
        $project = Project_Model::withTrashed()->find($params['id'] ?? 0);
        if (!$project) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Project not found');
        }

        $entries = fn () => Billable_Entry_Model::where('billable_entries.project_id', $project->id);

        $approved = $entries()
            ->whereIn('billable_entries.status', [Billable_Entry_Model::STATUS_APPROVED, Billable_Entry_Model::STATUS_BILLED])
            ->sum_money('amount');

        return [
            'hours_logged' => static::__hours_logged($entries()),
            'approved_amount' => $approved,
            'billed_amount' => $entries()->where('billable_entries.status', Billable_Entry_Model::STATUS_BILLED)->sum_money('amount'),
            'budget_remaining' => $project->budget !== null ? $project->budget->subtract($approved) : null,
            'entry_count' => $entries()->count(),
        ];
    }

    /**
     * Ajax endpoint: the hours logged against a task - the hours of every live time entry
     * naming it, whatever its status. Params: id. Returns {hours_logged: decimal string}.
     */
    #[Ajax_Endpoint]
    public static function task_billing_summary(Request $request, array $params = [])
    {
        $task = Task_Model::withTrashed()->find($params['id'] ?? 0);
        if (!$task) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        return [
            'hours_logged' => static::__hours_logged(Billable_Entry_Model::where('billable_entries.task_id', $task->id)),
        ];
    }

    /**
     * The total hours of the time entries $entries selects, as a decimal string ("12.50"),
     * summed by the database over the time detail table. The base query keeps its site scope
     * and soft-delete filter; the detail table has neither and is reached only through it.
     */
    private static function __hours_logged($entries): string
    {
        $total = $entries
            ->join('billable_entry_time_details', 'billable_entry_time_details.billable_entry_id', '=', 'billable_entries.id')
            ->toBase()
            ->selectRaw('COALESCE(SUM(billable_entry_time_details.hours), 0) AS hours_logged')
            ->value('hours_logged');

        // SUM over a DECIMAL column comes back as an exact decimal string - never a float.
        return (string) $total;
    }

    /**
     * Ajax endpoint: create or update a billable entry (base + its kind's detail).
     *
     * Params: id (absent = create), type_id (create only: Billable_Entry_Model::TYPE_*; the
     * stored type is used on an edit), project_id, task_id, user_id (approvers may name
     * another person; absent on create = the signed-in user), entry_date, description, and the
     * kind's figures - TIME: hours, hourly_rate (absent on create = the project's rate);
     * EXPENSE: vendor, cost; FIXED_FEE: amount. An edit also carries the Form_Edit fields
     * (_changed, _loaded_updated_at).
     *
     * Returns {billable_entry_id, message, redirect (the entry's view page), updated_at}.
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        $entry_id = $params['id'] ?? null;

        if ($entry_id) {
            $entry = Billable_Entry_Model::find($entry_id);
            if (!$entry) {
                return response_error(Ajax::ERROR_NOT_FOUND, 'Entry not found');
            }
            if (!$entry->is_changeable_by_current_user()) {
                return response_error(Ajax::ERROR_UNAUTHORIZED, 'Only the person who logged this entry or an approver can change it');
            }
            $locked = $entry->edit_error();
            if ($locked !== null) {
                return response_error(Ajax::ERROR_VALIDATION, $locked);
            }
            $type_id = (int) $entry->type_id;
        } else {
            $type_id = (int) ($params['type_id'] ?? 0);
            $entry = new Billable_Entry_Model();
        }

        $errors = Billable_Entry_Model::value_errors($params, $type_id, $entry);
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        if (!$entry->exists) {
            $entry->type_id = $type_id; // set on create only - the discriminator never changes
        }

        return Form_Edit::run($entry, $params, function (Form_Edit $edit) use ($entry, $entry_id, $params) {
            $errors = $entry->save_edit($edit, $params);
            if (!empty($errors)) {
                // Refused before anything was written.
                return response_form_error('Please correct the errors below.', $errors);
            }

            static::__log(
                $entry_id ? Action_Log_Model::TYPE_BILLABLE_ENTRY_UPDATED : Action_Log_Model::TYPE_BILLABLE_ENTRY_CREATED,
                $entry
            );

            return [
                'billable_entry_id' => $entry->id,
                'message' => $entry_id ? 'Entry updated' : 'Entry created',
                'redirect' => Rsx::Route('Billing_View_Action', $entry->id),
            ];
        }, $entry->exists ? Rsx::Route('Billing_View_Action', $entry->id) : null);
    }

    /**
     * Ajax endpoint: submit a DRAFT or REJECTED entry for approval. Params: id.
     * Author or approver. Returns {status}.
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function submit(Request $request, array $params = [])
    {
        $entry = static::__changeable_entry($params);
        if (!$entry instanceof Billable_Entry_Model) {
            return $entry;
        }

        $error = $entry->submit_error();
        if ($error !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $error);
        }

        $entry->submit();
        static::__log(Action_Log_Model::TYPE_BILLABLE_ENTRY_SUBMITTED, $entry);

        return ['status' => $entry->status];
    }

    /**
     * Ajax endpoint: approve a SUBMITTED entry. Params: id. Returns {status}.
     */
    #[Auth('can_edit_data', 'can_approve_billing')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function approve(Request $request, array $params = [])
    {
        $entry = Billable_Entry_Model::find($params['id'] ?? 0);
        if (!$entry) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Entry not found');
        }

        $error = $entry->approve_error();
        if ($error !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $error);
        }

        $entry->approve();
        static::__log(Action_Log_Model::TYPE_BILLABLE_ENTRY_APPROVED, $entry);

        return ['status' => $entry->status];
    }

    /**
     * Ajax endpoint: reject a SUBMITTED entry back to its author. Params: id,
     * rejection_reason (required - a field error on `rejection_reason` when blank).
     * Returns {status}.
     */
    #[Auth('can_edit_data', 'can_approve_billing')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function reject(Request $request, array $params = [])
    {
        $entry = Billable_Entry_Model::find($params['id'] ?? 0);
        if (!$entry) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Entry not found');
        }

        $reason = $params['rejection_reason'] ?? null;
        $errors = $entry->reject_errors($reason);
        if (isset($errors['status'])) {
            return response_error(Ajax::ERROR_VALIDATION, $errors['status']);
        }
        if (!empty($errors)) {
            return response_form_error('Please give a reason.', $errors);
        }

        $entry->reject($reason);
        static::__log(Action_Log_Model::TYPE_BILLABLE_ENTRY_REJECTED, $entry, ['reason' => $entry->rejection_reason]);

        return ['status' => $entry->status];
    }

    /**
     * Ajax endpoint: delete (soft) a DRAFT or REJECTED entry. Params: id. Author or approver.
     * The detail row and any receipt stay with the soft-deleted entry.
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function delete(Request $request, array $params = [])
    {
        $entry = static::__changeable_entry($params);
        if (!$entry instanceof Billable_Entry_Model) {
            return $entry;
        }

        $error = $entry->delete_error();
        if ($error !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $error);
        }

        $entry->delete();
        static::__log(Action_Log_Model::TYPE_BILLABLE_ENTRY_DELETED, $entry);

        return ['deleted' => true];
    }

    /**
     * Ajax endpoint: attach an uploaded file as the entry's receipt. Params: id, key (the
     * attachment key /_upload answered).
     *
     * The receipt is ONE file in category Billable_Entry_Model::RECEIPT_CATEGORY on the BASE
     * entry (never on a detail row): attach_to() replaces whatever receipt was there, and a
     * file with the same bytes as the current receipt changes nothing. Author or approver,
     * while the entry can still be edited.
     *
     * Returns {attachment_id, name, unchanged}.
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function attach_receipt(Request $request, array $params = [])
    {
        $entry = static::__changeable_entry($params);
        if (!$entry instanceof Billable_Entry_Model) {
            return $entry;
        }

        $locked = $entry->edit_error();
        if ($locked !== null) {
            return response_error(Ajax::ERROR_VALIDATION, $locked);
        }

        // can_user_assign_this_file() is STRUCTURAL: the upload is unclaimed and on this site.
        // Who may claim it is this endpoint's decision - its gates and the record rule above.
        $attachment = !empty($params['key']) ? File_Attachment_Model::find_by_key($params['key']) : null;
        if (!$attachment || !$attachment->can_user_assign_this_file()) {
            return response_error(Ajax::ERROR_VALIDATION, 'File not found or access denied');
        }

        $current = $entry->get_attachment(Billable_Entry_Model::RECEIPT_CATEGORY);
        if ($current && $attachment->has_same_content_as($current)) {
            return ['attachment_id' => $current->id, 'name' => $current->file_name, 'unchanged' => true];
        }

        $attachment->attach_to($entry, Billable_Entry_Model::RECEIPT_CATEGORY);
        static::__log(Action_Log_Model::TYPE_BILLABLE_ENTRY_UPDATED, $entry, ['receipt' => $attachment->file_name]);

        return ['attachment_id' => $attachment->id, 'name' => $attachment->file_name, 'unchanged' => false];
    }

    /**
     * Ajax endpoint: an entry's receipt. Params: id.
     * Returns {receipt: null | {attachment_id, name, download_url}}.
     */
    #[Ajax_Endpoint]
    public static function receipt(Request $request, array $params = [])
    {
        $entry = Billable_Entry_Model::withTrashed()->find($params['id'] ?? 0);
        if (!$entry) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Entry not found');
        }

        $file = $entry->get_attachment(Billable_Entry_Model::RECEIPT_CATEGORY);

        return [
            'receipt' => $file ? [
                'attachment_id' => $file->id,
                'name' => $file->file_name,
                'download_url' => $file->get_download_url(),
            ] : null,
        ];
    }

    /**
     * Ajax endpoint: the choices the entry form offers.
     *
     * Params: project_id (optional - the tasks and default rate of that project), entry_id
     * (optional - the entry being edited, whose current project, task and person are offered
     * even when since deleted, marked "(deleted)").
     *
     * Returns:
     *   projects             [{id, label, client_name, hourly_rate}] - every live project, by name
     *   tasks                [{id, label}] - the tasks whose (derived) project is project_id
     *   users                [{id, label}] - every user when the caller may choose who did the
     *                        work, otherwise only the caller (and the entry's own person)
     *   can_choose_user      bool - can_approve_billing
     *   default_hourly_rate  Rsx_Money|null - project_id's rate, what a new time entry copies
     */
    #[Ajax_Endpoint]
    public static function form_options(Request $request, array $params = [])
    {
        $project_id = !empty($params['project_id']) ? (int) $params['project_id'] : null;
        $entry = !empty($params['entry_id']) ? Billable_Entry_Model::withTrashed()->find((int) $params['entry_id']) : null;

        $client_names = Client_Model::withTrashed()->pluck('name', 'id')->all();

        $projects = [];
        $seen = [];
        foreach (Project_Model::orderBy('name')->get() as $project) {
            $projects[] = static::__project_option($project, $client_names);
            $seen[$project->id] = true;
        }
        if ($entry && !isset($seen[$entry->project_id])) {
            $held = Project_Model::withTrashed()->find($entry->project_id);
            if ($held) {
                $projects[] = static::__project_option($held, $client_names);
            }
        }

        $tasks = [];
        if ($project_id) {
            $seen = [];
            foreach (Task_Model::where('project_id', $project_id)->orderBy('title')->get() as $task) {
                $tasks[] = ['id' => $task->id, 'label' => $task->title];
                $seen[$task->id] = true;
            }
            if ($entry && $entry->task_id && (int) $entry->project_id === $project_id && !isset($seen[$entry->task_id])) {
                $held = Task_Model::withTrashed()->find($entry->task_id);
                if ($held) {
                    $tasks[] = ['id' => $held->id, 'label' => $held->title . ($held->trashed() ? ' (deleted)' : '')];
                }
            }
        }

        $can_choose_user = Permission::can_approve_billing();
        $user_query = User_Model::orderBy('first_name')->orderBy('last_name');
        if (!$can_choose_user) {
            $user_query->where('id', (int) Session::get_user_id());
        }
        $users = [];
        $seen = [];
        foreach ($user_query->get() as $user) {
            $users[] = ['id' => $user->id, 'label' => $user->get_printed_name()];
            $seen[$user->id] = true;
        }
        if ($entry && !isset($seen[$entry->user_id])) {
            $held = User_Model::withTrashed()->find($entry->user_id);
            if ($held) {
                $users[] = ['id' => $held->id, 'label' => $held->get_printed_name() . ($held->trashed() ? ' (deleted)' : '')];
            }
        }

        return [
            'projects' => $projects,
            'tasks' => $tasks,
            'users' => $users,
            'can_choose_user' => $can_choose_user,
            'default_hourly_rate' => $project_id ? Project_Model::withTrashed()->find($project_id)?->hourly_rate : null,
        ];
    }

    /** One project option: {id, label, client_name, hourly_rate}. */
    private static function __project_option(Project_Model $project, array $client_names): array
    {
        return [
            'id' => $project->id,
            'label' => $project->name . ($project->trashed() ? ' (deleted)' : ''),
            'client_name' => $client_names[$project->client_id] ?? null,
            'hourly_rate' => $project->hourly_rate,
        ];
    }

    /**
     * The entry $params['id'] names, when the caller may change it (author or approver);
     * otherwise the error response to return.
     */
    private static function __changeable_entry(array $params)
    {
        $entry = Billable_Entry_Model::find($params['id'] ?? 0);
        if (!$entry) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Entry not found');
        }
        if (!$entry->is_changeable_by_current_user()) {
            return response_error(Ajax::ERROR_UNAUTHORIZED, 'Only the person who logged this entry or an approver can change it');
        }

        return $entry;
    }

    /** Record an entry action, with the entry's project related so the project's activity shows it. */
    private static function __log(int $type_id, Billable_Entry_Model $entry, array $metadata = []): void
    {
        $project = Project_Model::withTrashed()->find($entry->project_id);
        $related = $project ? [[$project, Action_Log_Related_Model::ROLE_MENTIONED]] : [];

        Action_Log::record($type_id, $entry, $related, $metadata);
    }
}
