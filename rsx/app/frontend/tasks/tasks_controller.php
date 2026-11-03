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
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\App\Frontend\Tasks\List\Tasks_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Lib\Attachments\Record_Attachment_List;
use Rsx\Lib\FieldChecks\Field_Checks;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * Frontend_Tasks_Controller - Ajax endpoints for Task Management.
 *
 * The DERIVED project_id (see docs.dev/BACKLOG.md B-1) is computed in save() from the task's
 * taskable parent chain and cascaded to descendants; a user-supplied project value is honored
 * only when the chain does NOT resolve to a project.
 */
#[Auth('is_logged_in', 'can_view_data')]
class Frontend_Tasks_Controller extends Rsx_Controller_Abstract
{
    /** Polymorphic parent types a task may be attached to. */
    private const PARENT_TYPES = ['Task_Model', 'Project_Model', 'Client_Model', 'User_Model'];

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
     * Given a candidate parent `taskable` = {type, id} (the shape save() takes), returns the project the task's
     * chain WOULD derive to (id + name), or null (project field is then user-editable). Lets
     * the edit UI render the project field read-only-derived vs editable without saving.
     */
    #[Ajax_Endpoint]
    public static function resolve_parent_project(Request $request, array $params = [])
    {
        [$type, $id] = static::__read_taskable($params['taskable'] ?? null);

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
        $type = static::__normalize_parent_type($params['type'] ?? null);
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
     * taskable_id pair only when the submission changed it.
     *
     * project_id is DERIVED: after the parent is set, the taskable chain is resolved; if it
     * reaches a project, project_id is that project (any user-supplied value ignored). If it
     * does not, project_id takes the user value (nullable). Descendants are cascaded. See
     * docs.dev/BACKLOG.md B-1 (this application-level derivation is that item's case study).
     *
     * A reference the record already holds is accepted as it stands, so a task whose
     * assignee, project or parent has since been deleted still saves; a reference the
     * submission CHANGES must name a live record of this site.
     */
    #[Auth('can_edit_data')]
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

        $errors = [];

        if (!isset($params['title']) || !is_string($params['title']) || trim($params['title']) === '') {
            $errors['title'] = 'Title is required';
        }

        $errors += Field_Checks::length_errors(Task_Model::class, $params, ['title' => 'Title']);

        foreach (['status' => 'status', 'priority' => 'priority'] as $field => $noun) {
            if (!empty($params[$field]) && !Field_Checks::is_enum_value(Task_Model::class, $field, $params[$field])) {
                $errors[$field] = "Choose a {$noun} from the list";
            }
        }

        foreach (['due_date' => 'Due date', 'completed_date' => 'Completed date'] as $field => $label) {
            if (!empty($params[$field]) && !Rsx_Date::is_date($params[$field])) {
                $errors[$field] = "{$label} must be a date (YYYY-MM-DD)";
            }
        }

        // hour_estimate: optional, but numeric >= 0 when present.
        $has_hours = isset($params['hour_estimate']) && $params['hour_estimate'] !== '' && $params['hour_estimate'] !== null;
        if ($has_hours && (!is_numeric($params['hour_estimate']) || (float) $params['hour_estimate'] < 0)) {
            $errors['hour_estimate'] = 'Estimated hours must be a number of zero or more';
        }

        // Parent: optional; when provided the type must be known and the entity must exist.
        [$parent_type, $parent_id, $parent_error] = static::__read_taskable($params['taskable'] ?? null);
        if ($parent_error !== null) {
            $errors['taskable'] = $parent_error;
        }

        $parent_unchanged = $task->exists
            && $task->taskable_type === $parent_type
            && (int) $task->taskable_id === (int) $parent_id;

        if ($parent_error === null && $parent_type && !$parent_unchanged
            && !static::__parent_exists($parent_type, $parent_id)) {
            $errors['taskable'] = 'The selected parent does not exist';
        }

        // Every other referenced id must name a record of this site - the site-scoped find
        // is the tenant check, so an id from another site misses.
        $user_id = !empty($params['assigned_to_user_id']) ? (int) $params['assigned_to_user_id'] : null;
        if ($user_id && !static::__holds($task, 'assigned_to_user_id', $user_id) && !User_Model::find($user_id)) {
            $errors['assigned_to_user_id'] = 'The selected user does not exist';
        }
        $project_id = !empty($params['project_id']) ? (int) $params['project_id'] : null;
        if ($project_id && !static::__holds($task, 'project_id', $project_id) && !Project_Model::find($project_id)) {
            $errors['project_id'] = 'The selected project does not exist';
        }

        // Cycle guard: a task cannot be parented (directly or transitively) to itself.
        if (!$errors && $task_id && $parent_type === 'Task_Model' && $parent_id
            && static::__would_create_cycle((int) $task_id, $parent_id)) {
            $errors['taskable'] = 'That parent would create a circular task chain';
        }

        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        return Form_Edit::run($task, $params, function (Form_Edit $edit) use ($task, $task_id, $params, $parent_type, $parent_id, $has_hours, $user_id, $project_id) {
            $edit->set('title', $params['title']);
            $edit->set('description', $params['description'] ?? null);
            $edit->set('status', !empty($params['status']) ? (int) $params['status'] : Task_Model::STATUS_PENDING);
            $edit->set('priority', !empty($params['priority']) ? (int) $params['priority'] : Task_Model::PRIORITY_MEDIUM);
            $edit->set('due_date', !empty($params['due_date']) ? $params['due_date'] : null);
            $edit->set('completed_date', !empty($params['completed_date']) ? $params['completed_date'] : null);
            $edit->set('assigned_to_user_id', $user_id);
            $edit->set('notes', $params['notes'] ?? null);
            $edit->set('hour_estimate', $has_hours ? (float) $params['hour_estimate'] : null);

            // Polymorphic parent (nullable): one form field, two columns.
            if ($edit->changed('taskable')) {
                $task->taskable_type = $parent_type;
                $task->taskable_id = $parent_type ? $parent_id : null;
            }

            // DERIVED project_id: chain wins; else the user value (nullable).
            $chain_project_id = $task->resolve_chain_project_id();
            if ($chain_project_id !== null) {
                $task->project_id = $chain_project_id;
            } else {
                $edit->set('project_id', $project_id);
            }

            $task->save();

            // Re-derive descendants whose chain runs through this task.
            $task->recompute_descendant_project_ids();


            Action_Log::record(
                $task_id ? Action_Log_Model::TYPE_TASK_UPDATED : Action_Log_Model::TYPE_TASK_CREATED,
                $task
            );

            return [
                'id' => $task->id,
                'message' => $task_id ? 'Task updated successfully' : 'Task created successfully',
                'redirect' => Rsx::Route('Tasks_View_Action', $task->id),
            ];
        }, $task->exists ? Rsx::Route('Tasks_View_Action', $task->id) : null);
    }

    /**
     * Read the `taskable` field - {type, id}, type null for no parent - into
     * [type, id, error]. An absent or null field is no parent.
     *
     * @return array{0: ?string, 1: ?int, 2: ?string}
     */
    private static function __read_taskable($taskable): array
    {
        if ($taskable === null || $taskable === '') {
            return [null, null, null];
        }
        if (!is_array($taskable)) {
            return [null, null, 'Unknown parent'];
        }

        $raw_type = $taskable['type'] ?? null;
        $type = static::__normalize_parent_type($raw_type);
        $id = !empty($taskable['id']) ? (int) $taskable['id'] : null;

        if (!empty($raw_type) && $raw_type !== 'None' && !$type) {
            return [null, null, 'Unknown parent type'];
        }
        if ($type && !$id) {
            return [$type, null, 'A parent must be selected for the chosen parent type'];
        }

        return [$type, $type ? $id : null, null];
    }

    /** Does the stored record already hold $id in $column? Such a reference is not re-validated. */
    private static function __holds(Task_Model $task, string $column, int $id): bool
    {
        return $task->exists && (int) $task->{$column} === $id;
    }

    /**
     * Ajax endpoint: Delete a task (soft delete).
     */
    #[Auth('can_edit_data')]
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
     * here as `keys`. The claim is all-or-nothing. The task's realtime frame is emitted so
     * every open view of the task picks the new files up.
     */
    #[Auth('can_edit_data')]
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

        if (!Record_Attachment_List::claim($task, $keys)) {
            return response_error(Ajax::ERROR_VALIDATION, 'A file could not be attached. Please upload it again.');
        }

        $task->realtime_emit();

        return ['attachments' => Record_Attachment_List::rows($task)];
    }

    /**
     * Normalize a caller-supplied parent type string to a known type, or null.
     * Accepts empty/"None" as "no parent".
     */
    private static function __normalize_parent_type($type): ?string
    {
        if (empty($type) || $type === 'None') {
            return null;
        }
        return in_array($type, self::PARENT_TYPES, true) ? $type : null;
    }

    /**
     * Does the given parent entity exist?
     */
    private static function __parent_exists(string $type, int $id): bool
    {
        return match ($type) {
            'Task_Model' => Task_Model::whereKey($id)->exists(),
            'Project_Model' => Project_Model::whereKey($id)->exists(),
            'Client_Model' => Client_Model::whereKey($id)->exists(),
            'User_Model' => User_Model::whereKey($id)->exists(),
            default => false,
        };
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

    /**
     * Would parenting $task_id to task $parent_id create a cycle? Walks the prospective
     * parent's taskable chain; a cycle exists if it reaches $task_id (or the parent is the
     * task itself). Visited-set + depth cap guard against an already-corrupt chain.
     */
    private static function __would_create_cycle(int $task_id, int $parent_id): bool
    {
        if ($parent_id === $task_id) {
            return true;
        }

        $visited = [];
        $current = Task_Model::find($parent_id);
        $depth = 0;

        while ($current) {
            if (++$depth > Task_Model::CHAIN_DEPTH_CAP) {
                return true; // treat a runaway chain as unsafe
            }
            if ($current->id === $task_id) {
                return true;
            }
            if (isset($visited[$current->id])) {
                return false; // pre-existing cycle upstream, but not through $task_id
            }
            $visited[$current->id] = true;

            if ($current->taskable_type !== 'Task_Model' || empty($current->taskable_id)) {
                return false;
            }
            $current = Task_Model::find($current->taskable_id);
        }

        return false;
    }
}
