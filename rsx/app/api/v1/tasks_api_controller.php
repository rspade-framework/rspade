<?php

namespace Rsx\App\Api\V1;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use Rsx\Models\Task_Model;

/**
 * Tasks API - external REST reads, plus the worked example of ATTACHING FILES to a record
 * over the API.
 *
 * WHY THIS CONTROLLER EXISTS. The framework ships the file endpoints that move bytes
 * (POST /api/v1/files and friends) but deliberately does NOT ship "attach this file to that
 * record": which record, which category and who may do it are application policy. This is
 * the second of the template's two demonstrations of the app-owned half - Clients_Api_Controller
 * is the other, attaching into the same category its staff UI reads. Tasks is the simpler
 * shape: a category nothing else writes to.
 *
 * The reads (list/get) exist so the resource is usable from outside: an integration has no
 * other way to discover a task id. Writes to the task itself are not exposed - the staff app
 * owns task authoring, and adding CRUD here would be inventing a contract nothing asked for.
 *
 * All endpoints operate within the authenticated key's site. The Bearer key establishes a
 * headless Session identity, so the global site scope filters every read automatically -
 * there is NO manual site_id handling here, and a cross-site id simply comes back null.
 *
 * AUTH. Gated 'is_logged_in', matching the other v1 controllers: the key's staff identity
 * plus the automatic site scope IS the authorization boundary for v1.
 */
#[Auth('is_logged_in')]
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
    #[Api_Param('project_id', type: 'int', description: 'Filter to one project')]
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
     *
     * @api-response
     * {
     *   "id": 12,
     *   "site_id": 1,
     *   "title": "Draft the statement of work",
     *   "description": "Include the payment schedule.",
     *   "status": 1,
     *   "status__label": "Pending",
     *   "priority": 2,
     *   "priority__label": "Medium",
     *   "due_date": "2026-09-04",
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
    #[Api_Endpoint('/api/v1/tasks/:id/attachments/attach', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Task ID')]
    #[Api_Param('key', type: 'string', required: true, description: 'File key returned by POST /api/v1/files')]
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

        // add_to(), not attach_to(): a task may carry MANY files. attach_to() is the
        // single-file form and would detach whatever was already there.
        $attachment->add_to($task, self::ATTACHMENTS_CATEGORY);

        return Rsx_Api::created(static::__attachment_payload($attachment));
    }

    /**
     * Remove an attachment from a task.
     *
     * Soft-deletes it into the framework's retention window - the bytes are not destroyed
     * and the attachment remains recoverable. Returns 204 No Content.
     *
     * @api-response
     * (empty body, HTTP 204)
     */
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

        return Rsx_Api::no_content();
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
            'uploaded_at' => $attachment->created_at,
            'urls' => [
                'download' => $attachment->get_download_url(),
                'inline' => $attachment->get_url(),
                'thumbnail' => $attachment->get_thumbnail_url(),
                'preview' => '/_preview/pdf/' . $attachment->key,
            ],
        ];
    }
}
