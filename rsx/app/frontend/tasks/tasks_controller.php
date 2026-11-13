<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace Rsx\App\Frontend\Tasks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use Rsx\App\Frontend\Tasks\List\Tasks_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Lib\Attachments\Record_Attachment_List;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * Frontend_Tasks_Controller - Ajax endpoints for Task Management.
 *
 * A task's project_id is a derived column (Task_Model::derive_project_id()): the project its
 * taskable parent chain reaches, otherwise the user's own choice, direct_project_id - the
 * field the edit form submits.
 */
#[Auth('is_logged_in', 'can_view_data')]
class Frontend_Tasks_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data.
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Tasks_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: Get the activity feed (action log) for a task.
     *
     * Shared Activity-tab shape (id, rendered summary html, created_at, type_id).
     *
     * The action log is for administrators: the same can_view_user_activity check the
     * Action Log screen carries, so a per-record feed never shows what that screen refuses.
     */
    #[Auth('can_view_user_activity')]
    #[Ajax_Endpoint]
    public static function task_activity(Request $request, array $params = [])
    {
        $task_id = $params['id'] ?? null;
        if (!$task_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Task ID is required');
        }

        $task = Task_Model::withTrashed()->find($task_id);
        if (!$task) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        $activity = [];
        foreach (Action_Log::get_for_entity($task, 50) as $log) {
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
     * Ajax endpoint: Direct subtasks of a task (tasks whose taskable IS this task).
     *
     * Mirrors projects_controller::project_tasks so the Tasks_View Subtasks tab reuses the
     * same Record_Table row shape. Returns {subtasks: [{id, title, status, status__label,
     * status__badge, due_date, is_overdue}]} ordered by due_date. `is_overdue` is
     * server-computed = has due_date AND due_date < today AND status is OPEN.
     */
    #[Ajax_Endpoint]
    public static function task_subtasks(Request $request, array $params = [])
    {
        $task_id = $params['id'] ?? null;
        if (!$task_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Task ID is required');
        }

        $task = Task_Model::withTrashed()->find($task_id);
        if (!$task) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        $today = \App\RSpade\Core\Time\Rsx_Date::today();
        $open = [Task_Model::STATUS_PENDING, Task_Model::STATUS_IN_PROGRESS];

        $subtasks = [];
        foreach ($task->subtasks() as $sub) {
            $is_overdue = $sub->due_date && $sub->due_date < $today && in_array($sub->status, $open, true);
            $subtasks[] = [
                'id' => $sub->id,
                'title' => $sub->title,
                'status' => $sub->status,
                'status__label' => $sub->status__label,
                'status__badge' => $sub->status__badge,
                'due_date' => $sub->due_date,
                'is_overdue' => $is_overdue,
            ];
        }

        return ['subtasks' => $subtasks];
    }

    /**
     * Ajax endpoint: Resolve the derived project for a would-be parent (live edit-form check).
     *
     * Given a candidate parent `taskable` = {type, id} (the shape save() takes), returns the
     * project the task's chain WOULD reach (id + name), or derived: false when it reaches none
     * - then the task's project is its direct_project_id, which the form's picker edits. Lets
     * the edit UI show the picker disabled-and-derived or editable without saving.
     */
    #[Ajax_Endpoint]
    public static function resolve_parent_project(Request $request, array $params = [])
    {
        [$type, $id] = Task_Model::read_taskable($params['taskable'] ?? null);

        // Build a throwaway (unsaved) task carrying only the candidate parent, then reuse the
        // model's chain resolver so the rule lives in exactly one place.
        $probe = new Task_Model();
        $probe->taskable_type = $type;
        $probe->taskable_id = $id;

        $project_id = ($type && $id) ? $probe->resolve_chain_project_id() : null;

        if ($project_id === null) {
            return ['derived' => false, 'project_id' => null, 'project_name' => null];
        }

        $project = Project_Model::find($project_id);
        return [
            'derived' => true,
            'project_id' => $project_id,
            'project_name' => $project ? $project->name : null,
        ];
    }

    /**
     * Ajax endpoint: Lean typed search for the parent picker.
     *
     * ONE endpoint with a `type` discriminator (chosen over four near-identical endpoints:
     * a single switch keeps the picker's per-type ajax select pointed at one method and the
     * four branches share the same {id,label} contract). Returns [{id, label}] (max 20).
     *
     * Two optional params serve the Batch-2 pickers:
     *  - `id`: RESOLVE-ONE mode. When given, returns just that record's {id,label} (ignoring
     *    filter/limit) so a remote picker can label a preselected value on an edit form.
     *  - `exclude_id`: Task-type OR Project-type. Excludes that record AND all of its
     *    descendants (tasks whose parent chain runs through it / projects whose
     *    parent_project_id chain runs through it) so the picker can never parent a record to
     *    itself or a descendant (would create a cycle). Mirrors the respective save() guard.
     */
    #[Ajax_Endpoint]
    public static function search_parents(Request $request, array $params = [])
    {
        $type = Task_Model::normalize_parent_type($params['type'] ?? null);
        if (!$type) {
            return response_error(Ajax::ERROR_VALIDATION, 'A valid parent type is required');
        }

        $specific_id = !empty($params['id']) ? (int) $params['id'] : null;
        $exclude_id = !empty($params['exclude_id']) ? (int) $params['exclude_id'] : null;
        $filter = trim((string) ($params['filter'] ?? ''));
        $like = '%' . $filter . '%';

        $results = [];
        if ($type === 'Project_Model') {
            $q = Project_Model::query();
            if ($specific_id) {
                $q->whereKey($specific_id);
            } else {
                if ($filter !== '') {
                    $q->where('name', 'LIKE', $like);
                }
                // exclude_id (Project type): drop the project + its subtree so the
                // parent-project picker can never form a cycle (self/descendant).
                if ($exclude_id) {
                    $q->whereNotIn('id', Project_Model::self_and_descendant_ids($exclude_id));
                }
            }
            foreach ($q->orderBy('name')->limit(20)->get() as $m) {
                $results[] = ['id' => $m->id, 'label' => $m->name];
            }
        } elseif ($type === 'Task_Model') {
            $q = Task_Model::query();
            if ($specific_id) {
                $q->whereKey($specific_id);
            } else {
                if ($filter !== '') {
                    $q->where('title', 'LIKE', $like);
                }
                if ($exclude_id) {
                    $q->whereNotIn('id', static::__task_and_descendant_ids($exclude_id));
                }
            }
            foreach ($q->orderBy('title')->limit(20)->get() as $m) {
                $results[] = ['id' => $m->id, 'label' => $m->title];
            }
        } elseif ($type === 'Client_Model') {
            $q = Client_Model::query();
            if ($specific_id) {
                $q->whereKey($specific_id);
            } elseif ($filter !== '') {
                $q->where('name', 'LIKE', $like);
            }
            foreach ($q->orderBy('name')->limit(20)->get() as $m) {
                $results[] = ['id' => $m->id, 'label' => $m->name];
            }
        } elseif ($type === 'User_Model') {
            $q = User_Model::query();
            if ($specific_id) {
                $q->whereKey($specific_id);
            } elseif ($filter !== '') {
                $q->where(function ($sub) use ($like) {
                    $sub->where('first_name', 'LIKE', $like)
                        ->orWhere('last_name', 'LIKE', $like)
                        ->orWhere('email', 'LIKE', $like);
                });
            }
            foreach ($q->orderBy('first_name')->limit(20)->get() as $m) {
                $label = trim($m->first_name . ' ' . $m->last_name) ?: $m->email;
                $results[] = ['id' => $m->id, 'label' => $label];
            }
        }

        return ['results' => $results];
    }

    /**
     * Ajax endpoint: Save task (add or edit).
     *
     * The parent arrives as ONE field, `taskable` = {type, id} (type null = no parent) - the
     * value of the form's Parent_Selector_Input - and is written to the taskable_type /
     * taskable_id pair only when the submission changed it. Task_Model::EDIT_FIELDS tells
     * Form_Edit the pair is that field, and Task_Model declares the pair a fact group, so a
     * re-parent saved while this form was open is asked about rather than overwritten.
     *
     * The project picker submits `direct_project_id`, and leaves it out while the parent chain
     * reaches a project (the picker is disabled and shows the derived project then), so the
     * stored choice is kept. project_id is derived by Task_Model's save(), which also
     * rederives every descendant of a re-parented task.
     *
     * A reference the record already holds is accepted as it stands, so a task whose
     * assignee, project or parent has since been deleted still saves; a reference the
     * submission CHANGES must name a live record of this site.
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        $task_id = $params['id'] ?? null;

        if ($task_id) {
            $task = Task_Model::find($task_id);
            if (!$task) {
                return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
            }
        } else {
            $task = new Task_Model();
            $task->site_id = Session::get_site_id() ?: 1;
        }

        // The rules are Task_Model::value_errors() - the one definition the external API
        // runs too. A staff submission always carries a title, so a missing key is blank.
        $errors = Task_Model::value_errors($params + ['title' => null], $task);

        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        return Form_Edit::run($task, $params, function (Form_Edit $edit) use ($task, $task_id, $params) {
            $task->save_edit($edit, $params);

            Action_Log::record(
                $task_id ? Action_Log_Model::TYPE_TASK_UPDATED : Action_Log_Model::TYPE_TASK_CREATED,
                $task
            );

            return [
                'id' => $task->id,
                'message' => $task_id ? 'Task updated successfully' : 'Task created successfully',
                'redirect' => Rsx::Route('Tasks_View_Action', $task->id),
            ];
        }, $task->exists ? Rsx::Route('Tasks_View_Action', $task->id) : null, fields: Task_Model::EDIT_FIELDS);
    }

    /**
     * Ajax endpoint: Delete a task (soft delete).
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function delete(Request $request, array $params = [])
    {
        $task = Task_Model::find($params['id'] ?? 0);
        if (!$task) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        $task->delete();

        Action_Log::record(Action_Log_Model::TYPE_TASK_DELETED, $task);

        return ['deleted' => true];
    }

    /**
     * Ajax endpoint: the files attached to a task, for the view page's Attachments section.
     *
     * Returns {attachments: [Record_Attachment_List::row(), ...]}, oldest first.
     */
    #[Ajax_Endpoint]
    public static function task_attachments(Request $request, array $params = [])
    {
        $task = Task_Model::find($params['id'] ?? 0);
        if (!$task) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        return ['attachments' => Record_Attachment_List::rows($task)];
    }

    /**
     * Ajax endpoint: attach uploaded files to a task.
     *
     * The browser sends each file through Ajax.upload() first and posts the returned keys
     * here as `keys`. The claim is all-or-nothing, and a file the task already holds is asked
     * about first (Record_Attachment_List::claim(), the duplicate-upload question). The task's
     * realtime frame is emitted so every open view of the task picks the new files up.
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function attachments_add(Request $request, array $params = [])
    {
        $task = Task_Model::find($params['id'] ?? 0);
        if (!$task) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        $keys = $params['keys'] ?? null;
        if (!is_array($keys) || count($keys) === 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'No files were named');
        }

        $response = Record_Attachment_List::claim($params, $task, $keys);
        if ($response !== null) {
            return $response;   // a refused key, or the duplicate-upload question
        }

        $task->realtime_emit();

        return ['attachments' => Record_Attachment_List::rows($task)];
    }

    /**
     * A task id + the ids of every task descending from it (children whose taskable IS this
     * task, recursively). Used to exclude a task and its subtree from Task-parent options so
     * the picker can never form a cycle. Visited-set + depth cap guard a corrupt chain.
     *
     * @return int[]
     */
    private static function __task_and_descendant_ids(int $task_id): array
    {
        $ids = [$task_id => true];
        $queue = [$task_id];
        $depth = 0;

        while (!empty($queue)) {
            if (++$depth > Task_Model::CHAIN_DEPTH_CAP) {
                break;
            }
            $children = Task_Model::where('taskable_type', 'Task_Model')
                ->whereIn('taskable_id', $queue)
                ->pluck('id')
                ->all();

            $next = [];
            foreach ($children as $cid) {
                if (isset($ids[$cid])) {
                    continue;
                }
                $ids[$cid] = true;
                $next[] = $cid;
            }
            $queue = $next;
        }

        return array_keys($ids);
    }
}
