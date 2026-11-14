<?php

namespace Rsx\App\Api\V1;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Time\Rsx_Time;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Lib\TextTypes\RichText\Rich_Text;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Task_Model;

/**
 * Tasks API - external REST reads and writes for to-do task records, plus the worked example
 * of ATTACHING FILES to a record over the API.
 *
 * WHY THIS CONTROLLER EXISTS. The framework ships the file endpoints that move bytes
 * (POST /api/v1/files and friends) but deliberately does NOT ship "attach this file to that
 * record": which record, which category and who may do it are application policy. This is
 * the second of the template's two demonstrations of the app-owned half - Clients_Api_Controller
 * is the other, attaching into the same category its staff UI reads. Tasks is the simpler
 * shape: a category nothing else writes to.
 *
 * Create and update run the staff save's own rules and write: Task_Model::value_errors()
 * checks every value (the parent, direct project and assignee resolved through the site
 * scope), and Task_Model::save_edit() writes it inside Form_Edit::run() - changed fields
 * only, under a row lock.
 *
 * THE PROJECT. A task's `project_id` is derived and never written: it is the project the
 * parent chain reaches, otherwise `direct_project_id`. A write sends `direct_project_id`;
 * `project_id` is not a write parameter, so sending it is a 422 "Unknown parameter". Every
 * response carries both columns, and the list filters on `project_id`.
 *
 * THE DESCRIPTION. `description` is a Rich_Text column: HTML a WYSIWYG authored. The API
 * carries plain values, so a read answers it as its HTML string, and a write takes HTML in a
 * plain string param and passes it through Rich_Text::from_untrusted_encoded() - the type's
 * filter (HTMLPurifier) runs, exactly as for the staff editor. A client that reads a task and
 * sends `description` back unchanged stores the same value. Text meant literally ("a < b")
 * is sent as HTML text, escaped by the client.
 *
 * All endpoints operate within the authenticated key's site. The Bearer key establishes a
 * headless Session identity, so the global site scope filters every read automatically -
 * there is NO manual site_id handling here, and a cross-site id simply comes back null.
 *
 * AUTH. The Bearer key establishes a headless staff identity; the class gates are
 * can_use_api (PERM_API_ACCESS, also required of every bearer request by Main::pre_dispatch)
 * and can_view_data, and every write adds can_edit_data - the same data checks the staff
 * screens apply. The automatic site scope bounds every record.
 */
#[Auth('is_logged_in', 'can_use_api', 'can_view_data')]
class Tasks_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * The attachment category task files live under.
     *
     * Unlike the clients example, nothing else reads this category - it exists purely so
     * task attachments are namespaced away from any other file a task might one day carry.
     */
    private const ATTACHMENTS_CATEGORY = 'attachments';

    /**
     * List tasks.
     *
     * Returns a paginated list of tasks for the current site, newest first. Optional
     * filters narrow by status and by the project the task belongs to.
     *
     * @api-response
     * {
     *   "items": [
     *     {
     *       "id": 12,
     *       "site_id": 1,
     *       "title": "Draft the statement of work",
     *       "status": 1,
     *       "status__label": "Pending",
     *       "priority": 2,
     *       "priority__label": "Medium",
     *       "due_date": "2026-09-04",
     *       "project_id": 4,
     *       "direct_project_id": null,
     *       "__MODEL": "Task_Model"
     *     }
     *   ],
     *   "meta": { "page": 1, "per_page": 20, "total": 1, "total_pages": 1 }
     * }
     */
    #[Api_Endpoint('/api/v1/tasks', methods: ['GET'])]
    #[Api_Param('page', type: 'int', default: 1, description: 'Page number (1-based)')]
    #[Api_Param('per_page', type: 'int', default: 20, description: 'Results per page (max 100)')]
    #[Api_Param('status', type: 'int', description: 'Filter by status id (1 Pending, 2 In Progress, 3 Completed, 4 Cancelled)')]
    #[Api_Param('project_id', type: 'int', description: 'Filter to one project (the derived project_id)')]
    public static function list(Request $request, array $params = [])
    {
        $page = max(1, (int) $params['page']);
        $per_page = min(100, max(1, (int) $params['per_page']));

        $query = Task_Model::query();

        if (!empty($params['status'])) {
            $query->where('status', (int) $params['status']);
        }

        if (!empty($params['project_id'])) {
            $query->where('project_id', (int) $params['project_id']);
        }

        $total = $query->count();
        $tasks = $query->orderByDesc('id')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get();

        return [
            'items' => $tasks,
            'meta' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ];
    }

    /**
     * Get a single task.
     *
     * Returns the full task record. A soft-deleted or cross-site id is not found.
     * `description` is the task's rich text as an HTML string.
     *
     * @api-response
     * {
     *   "id": 12,
     *   "site_id": 1,
     *   "title": "Draft the statement of work",
     *   "description": "<p>Include the payment schedule.</p>",
     *   "status": 1,
     *   "status__label": "Pending",
     *   "priority": 2,
     *   "priority__label": "Medium",
     *   "due_date": "2026-09-04",
     *   "taskable_type": "Project_Model",
     *   "taskable_id": 4,
     *   "project_id": 4,
     *   "direct_project_id": null,
     *   "assigned_to_user_id": 3,
     *   "__MODEL": "Task_Model"
     * }
     */
    #[Api_Endpoint('/api/v1/tasks/:id', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Task ID')]
    public static function get(Request $request, array $params = [])
    {
        $task = Task_Model::find($params['id']);

        if (!$task) {
            return Rsx_Api::not_found('Task not found');
        }

        return $task;
    }

    /**
     * Create a task.
     *
     * Requires a non-blank title. Every other field is optional: a blank status is Pending
     * and a blank priority Medium. The parent is the pair taskable_type (Task_Model,
     * Project_Model, Client_Model or User_Model) + taskable_id; it, direct_project_id and the
     * assignee must each name a live record of the key's site. The task's project_id is
     * derived: the project the parent chain reaches, otherwise direct_project_id (stored
     * either way, and used once the chain reaches no project). project_id itself is not a
     * parameter. A value the task cannot hold is a 422 naming the field (a parent problem is
     * named `taskable`).
     *
     * `description` is HTML: it is sanitized (the staff editor's allow-list) and stored, and a
     * description read from a task is sent back as it was read.
     *
     * @api-response
     * {
     *   "id": 57,
     *   "site_id": 1,
     *   "title": "Draft the statement of work",
     *   "description": "<p>Include the payment schedule.</p>",
     *   "status": 1,
     *   "status__label": "Pending",
     *   "priority": 3,
     *   "priority__label": "High",
     *   "due_date": "2026-09-04",
     *   "taskable_type": "Project_Model",
     *   "taskable_id": 4,
     *   "project_id": 4,
     *   "direct_project_id": null,
     *   "assigned_to_user_id": 3,
     *   "__MODEL": "Task_Model"
     * }
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Api_Endpoint('/api/v1/tasks/create', methods: ['POST'])]
    #[Api_Param('title', type: 'string', required: true, description: 'Title')]
    #[Api_Param('description', type: 'string', description: 'Description, HTML (sanitized on save); send back the string a read returned to keep it unchanged')]
    #[Api_Param('status', type: 'int', description: 'Status id (1 Pending, 2 In Progress, 3 Completed, 4 Cancelled); default Pending')]
    #[Api_Param('priority', type: 'int', description: 'Priority id (1 Low, 2 Medium, 3 High, 4 Urgent); default Medium')]
    #[Api_Param('due_date', type: 'string', description: 'Due date, YYYY-MM-DD')]
    #[Api_Param('completed_date', type: 'string', description: 'Completed date, YYYY-MM-DD')]
    #[Api_Param('hour_estimate', type: 'string', description: 'Estimated hours, a number of zero or more')]
    #[Api_Param('assigned_to_user_id', type: 'int', description: 'Assigned user id')]
    #[Api_Param('direct_project_id', type: 'int', description: 'Project chosen for the task; its project_id while the parent chain reaches no project')]
    #[Api_Param('taskable_type', type: 'string', description: 'Parent type: Task_Model, Project_Model, Client_Model or User_Model')]
    #[Api_Param('taskable_id', type: 'int', description: 'Parent id, required with taskable_type')]
    #[Api_Param('notes', type: 'string', description: 'Internal notes')]
    public static function create(Request $request, array $params = [])
    {
        $values = static::__values($params);

        $errors = static::__api_errors(Task_Model::value_errors($values), $params);
        if (!empty($errors)) {
            return Rsx_Api::validation_error($errors);
        }

        // site_id is stamped by the site-scoped model on save.
        $task = new Task_Model();

        Form_Edit::run($task, $values, function (Form_Edit $edit) use ($task, $values) {
            $task->save_edit($edit, $values);
            Action_Log::record(Action_Log_Model::TYPE_TASK_CREATED, $task);
        }, fields: Task_Model::EDIT_FIELDS);

        return Rsx_Api::created($task->refresh());
    }

    /**
     * Update a task.
     *
     * Applies only the fields present in the request; omitted fields are left unchanged.
     * The value rules of create apply to every field sent, and a title sent must be
     * non-blank. Send a blank date or hour_estimate, or 0 for assigned_to_user_id or
     * direct_project_id, to clear it. The parent is changed by sending taskable_type with
     * taskable_id, and removed by sending a blank taskable_type. A reference the task already
     * holds is accepted as it stands, even when its record has since been deleted.
     *
     * @api-response
     * {
     *   "id": 57,
     *   "site_id": 1,
     *   "title": "Draft the statement of work",
     *   "status": 2,
     *   "status__label": "In Progress",
     *   "priority": 3,
     *   "priority__label": "High",
     *   "due_date": "2026-09-11",
     *   "taskable_type": "Project_Model",
     *   "taskable_id": 4,
     *   "project_id": 4,
     *   "direct_project_id": null,
     *   "assigned_to_user_id": 3,
     *   "__MODEL": "Task_Model"
     * }
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Api_Endpoint('/api/v1/tasks/:id/update', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Task ID')]
    #[Api_Param('title', type: 'string', description: 'Title')]
    #[Api_Param('description', type: 'string', description: 'Description, HTML (sanitized on save); send back the string a read returned to keep it unchanged')]
    #[Api_Param('status', type: 'int', description: 'Status id (1 Pending, 2 In Progress, 3 Completed, 4 Cancelled)')]
    #[Api_Param('priority', type: 'int', description: 'Priority id (1 Low, 2 Medium, 3 High, 4 Urgent)')]
    #[Api_Param('due_date', type: 'string', description: 'Due date, YYYY-MM-DD; blank clears')]
    #[Api_Param('completed_date', type: 'string', description: 'Completed date, YYYY-MM-DD; blank clears')]
    #[Api_Param('hour_estimate', type: 'string', description: 'Estimated hours, a number of zero or more; blank clears')]
    #[Api_Param('assigned_to_user_id', type: 'int', description: 'Assigned user id; 0 clears')]
    #[Api_Param('direct_project_id', type: 'int', description: 'Project chosen for the task; its project_id while the parent chain reaches no project; 0 clears')]
    #[Api_Param('taskable_type', type: 'string', description: 'Parent type: Task_Model, Project_Model, Client_Model or User_Model; blank removes the parent')]
    #[Api_Param('taskable_id', type: 'int', description: 'Parent id, sent with taskable_type')]
    #[Api_Param('notes', type: 'string', description: 'Internal notes')]
    public static function update(Request $request, array $params = [])
    {
        $task = Task_Model::find($params['id']);

        if (!$task) {
            return Rsx_Api::not_found('Task not found');
        }

        $values = static::__values($params);

        $errors = static::__api_errors(Task_Model::value_errors($values, $task), $params);
        if (!empty($errors)) {
            return Rsx_Api::validation_error($errors);
        }

        // An API caller edits the task as it stands now: the fields it sent are the changed
        // set, and the version it edits from is the one just read. Form_Edit writes only
        // those fields, under the row lock.
        $values['_changed'] = array_keys($values);
        $values['_loaded_updated_at'] = $task->updated_at;

        $result = Form_Edit::run($task, $values, function (Form_Edit $edit) use ($task, $values) {
            $task->save_edit($edit, $values);
            Action_Log::record(Action_Log_Model::TYPE_TASK_UPDATED, $task);

            return [];
        }, fields: Task_Model::EDIT_FIELDS);

        // A save that landed between the read above and the lock, on a field this request
        // also sent, is answered as a conflict rather than overwritten.
        if ($result instanceof Error_Response) {
            if ($result->get_error_code() === Ajax::ERROR_NOT_FOUND) {
                return Rsx_Api::not_found('Task not found');
            }

            return Rsx_Api::error('conflict', 'The task was changed by another save while this update was applied. Fetch it and retry.', 409);
        }

        return $task->refresh();
    }

    /**
     * The request's task values, keyed as Task_Model::value_errors() and save_edit() read
     * them: only the fields sent, with the taskable_type / taskable_id pair as the one
     * `taskable` field {type, id}.
     */
    private static function __values(array $params): array
    {
        $values = $params;
        unset($values['id'], $values['taskable_type'], $values['taskable_id']);

        // The API's door for the rich-text column: the string IS the HTML, so it is passed
        // through the type's filter as encoded content. Assigned bare it would be plain
        // text, and a description read and sent back would be stored as visible tags.
        if (isset($values['description'])) {
            $values['description'] = Rich_Text::from_untrusted_encoded($values['description']);
        }

        if (array_key_exists('taskable_type', $params) || array_key_exists('taskable_id', $params)) {
            $type = $params['taskable_type'] ?? null;
            $values['taskable'] = ($type === null || $type === '')
                ? null
                : ['type' => $type, 'id' => $params['taskable_id'] ?? null];
        }

        return $values;
    }

    /**
     * The API's own pairing rule beside the model's: a taskable_id means nothing without the
     * taskable_type it belongs to.
     */
    private static function __api_errors(array $errors, array $params): array
    {
        if (array_key_exists('taskable_id', $params) && !array_key_exists('taskable_type', $params)) {
            $errors['taskable_type'] = 'Send taskable_type with taskable_id';
        }

        return $errors;
    }

    // =====================================================================
    // ATTACHMENTS
    //
    // The three-endpoint shape an app writes for any record that carries files:
    // list, attach (claim a key from POST /api/v1/files), remove.
    // =====================================================================

    /**
     * List a task's attachments.
     *
     * Every file attached to this task, with the framework's file URLs. Those URLs accept
     * the same `Authorization: Bearer` key this endpoint did, so bytes are reachable
     * without a browser session.
     *
     * @api-response
     * {
     *   "items": [
     *     {
     *       "attachment_id": 15,
     *       "key": "8fbe1a5d1c0f4e2b9a7d6c5b4a39281706f5e4d3c2b1a09f8e7d6c5b4a392817",
     *       "file_name": "spec.docx",
     *       "mime_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
     *       "size": 24576,
     *       "uploaded_at": "2026-08-27T15:30:00.000Z",
     *       "urls": { "download": "...", "inline": "...", "thumbnail": "...", "preview": "..." }
     *     }
     *   ],
     *   "meta": { "total": 1 }
     * }
     */
    #[Api_Endpoint('/api/v1/tasks/:id/attachments', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Task ID')]
    public static function attachments_list(Request $request, array $params = [])
    {
        $task = Task_Model::find($params['id']);

        if (!$task) {
            return Rsx_Api::not_found('Task not found');
        }

        // get_attachments() returns an Rsx_Result_Set - iterate it. It is not a Collection
        // and has no ->map(); the set is walked a page at a time because one record's
        // attachment count has no ceiling.
        $items = [];
        foreach ($task->get_attachments(self::ATTACHMENTS_CATEGORY) as $attachment) {
            $items[] = static::__attachment_payload($attachment);
        }

        return [
            'items' => $items,
            'meta' => ['total' => count($items)],
        ];
    }

    /**
     * Attach an uploaded file to a task.
     *
     * Two-step, and the first step is the framework's: POST the bytes to /api/v1/files,
     * then pass the `key` it returned here. A key may be claimed ONCE.
     *
     * A file whose bytes the task already holds, under any name, answers 409 duplicate_upload
     * unless duplicate_upload says what to do: true attaches it again, false attaches
     * nothing and answers {"skipped": true, "existing": <that attachment>}.
     *
     * @api-response
     * {
     *   "attachment_id": 15,
     *   "key": "8fbe1a5d1c0f4e2b9a7d6c5b4a39281706f5e4d3c2b1a09f8e7d6c5b4a392817",
     *   "file_name": "spec.docx",
     *   "mime_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
     *   "size": 24576,
     *   "uploaded_at": "2026-08-27T15:30:00.000Z",
     *   "urls": { "download": "...", "inline": "...", "thumbnail": "...", "preview": "..." }
     * }
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Api_Endpoint('/api/v1/tasks/:id/attachments/attach', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Task ID')]
    #[Api_Param('key', type: 'string', required: true, description: 'File key returned by POST /api/v1/files')]
    #[Api_Param('duplicate_upload', type: 'bool', required: false, description: 'When the task already holds a file with identical bytes: true attaches this one anyway, false attaches nothing (answers skipped: true). Omitted, such a file is refused with 409 duplicate_upload.')]
    public static function attachments_attach(Request $request, array $params = [])
    {
        $task = Task_Model::find($params['id']);

        if (!$task) {
            return Rsx_Api::not_found('Task not found');
        }

        $attachment = File_Attachment_Model::find_by_key($params['key']);

        // can_user_assign_this_file() is STRUCTURAL: the file is still unclaimed and is in
        // this tenant. It is not a per-user permission check - that is this endpoint's job.
        // A missing key and an already-claimed one are ONE answer, so a caller cannot probe
        // for which keys exist.
        if (!$attachment || !$attachment->can_user_assign_this_file()) {
            return Rsx_Api::validation_error(
                ['key' => 'Not found, or already attached to something else'],
                'File not available to attach'
            );
        }

        // THE DUPLICATE QUESTION, answered by a parameter. A file whose bytes the task already
        // holds (under any name) is a likely retry of an upload whose answer was lost - a
        // retry re-uploads and gets a new key - so it is refused unless the caller says what
        // to do: duplicate_upload true attaches it, false attaches nothing.
        $existing = $attachment->duplicates_on($task)->first();
        if ($existing !== null) {
            $decision = $params['duplicate_upload'] ?? null;
            if ($decision === null) {
                return Rsx_Api::error(
                    'duplicate_upload',
                    "This file's contents are already attached to this task as attachment {$existing->id} ({$existing->file_name}). "
                        . 'Send duplicate_upload=true to attach it again, or duplicate_upload=false to skip it.',
                    409
                );
            }
            if ($decision === false) {
                return ['skipped' => true, 'existing' => static::__attachment_payload($existing)];
            }
        }

        // add_to(), not attach_to(): a task may carry MANY files. attach_to() is the
        // single-file form and would detach whatever was already there.
        $attachment->add_to($task, self::ATTACHMENTS_CATEGORY);

        return Rsx_Api::created(static::__attachment_payload($attachment));
    }

    /**
     * Remove an attachment from a task.
     *
     * Soft-deletes it into the framework's retention window - the bytes are not destroyed
     * and the attachment remains recoverable. Answers {"result": "success"}.
     *
     * @api-response
     * {"result": "success"}
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Api_Endpoint('/api/v1/tasks/:id/attachments/:attachment_id/delete', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Task ID')]
    #[Api_Param('attachment_id', type: 'int', required: true, description: 'Attachment ID to remove')]
    public static function attachments_delete(Request $request, array $params = [])
    {
        $task = Task_Model::find($params['id']);

        if (!$task) {
            return Rsx_Api::not_found('Task not found');
        }

        // THE OWNERSHIP RE-VERIFICATION. find_attachment() returns null unless this
        // attachment belongs to THIS task in THIS category - without it, any attachment id
        // in the tenant could be deleted through any task's URL.
        $attachment = $task->find_attachment($params['attachment_id'], self::ATTACHMENTS_CATEGORY);

        if (!$attachment) {
            return Rsx_Api::not_found('Attachment not found for this task');
        }

        $attachment->delete();

        return ['result' => 'success'];
    }

    /**
     * One attachment's wire shape.
     *
     * Field names deliberately echo the framework's own file payload
     * (Files_Api_Controller), so an integration reads one vocabulary across both.
     *
     * @param File_Attachment_Model $attachment
     * @return array
     */
    private static function __attachment_payload($attachment): array
    {
        return [
            'attachment_id' => (int) $attachment->id,
            'key' => $attachment->key,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->get_size(),
            // A hand-built API payload: the timestamp and its localized companions, as the
            // model serializer gives every DATETIME column (rsx:man external_api).
            ...Rsx_Time::encode_for_output('uploaded_at', $attachment->created_at),
            'urls' => [
                'download' => $attachment->get_download_url(),
                'inline' => $attachment->get_url(),
                'thumbnail' => $attachment->get_thumbnail_url(),
                'preview' => $attachment->get_preview_url(),
            ],
        ];
    }
}
