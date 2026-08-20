<?php



namespace Rsx\App\Frontend\Tasks\List;

use Illuminate\Database\Eloquent\Builder;
use App\RSpade\Core\Models\User_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Tasks_DataGrid - Tasks list datagrid.
 *
 * transform_records attaches FLAT display names (lessons: flat names avoid clashing with
 * the auto-generated JS relationship accessors and keep row payloads plain):
 *  - parent_type  : simple class name of the taskable parent (Project_Model/Task_Model/...) or null
 *  - parent_label : the parent entity's display name (project name / task title / client name / user)
 *  - project_name : the derived-or-user project's name (from project_id), or null
 *  - assigned_to_name : assignee display name, or null
 */
class Tasks_DataGrid extends DataGrid_Abstract
{
    protected static ?string $default_sort = 'created_at';

    protected static string $default_order = 'desc';

    protected static array $sortable_columns = [
        'id',
        'title',
        'status',
        'priority',
        'due_date',
        'hour_estimate',
        'created_at',
        'updated_at',
    ];

    protected static function build_query(array $params): Builder
    {
        $query = Task_Model::query();

        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('title', 'LIKE', "%{$filter}%")
                    ->orWhere('description', 'LIKE', "%{$filter}%")
                    ->orWhere('notes', 'LIKE', "%{$filter}%");
            });
        }

        // Optional server-side filters used by the list page.
        if (!empty($params['status'])) {
            $query->where('status', (int) $params['status']);
        }
        if (!empty($params['priority'])) {
            $query->where('priority', (int) $params['priority']);
        }

        return $query;
    }

    protected static function transform_records(array $records, array $params): array
    {
        // --- Resolve project names (project_id, the derived-or-user column) ---
        $project_ids = array_filter(array_unique(array_column($records, 'project_id')));
        $projects = $project_ids
            ? Project_Model::whereIn('id', $project_ids)->get()->keyBy('id')
            : collect();

        // --- Resolve assignee names ---
        $user_ids = array_filter(array_unique(array_column($records, 'assigned_to_user_id')));
        $users = $user_ids
            ? User_Model::whereIn('id', $user_ids)->get()->keyBy('id')
            : collect();

        // --- Resolve parent (taskable) labels, bucketed by simple type name ---
        // taskable_type is already the SIMPLE class name in the record array (type-ref accessor).
        $by_type = [];
        foreach ($records as $r) {
            $type = $r['taskable_type'] ?? null;
            $id = $r['taskable_id'] ?? null;
            if ($type && $id) {
                $by_type[$type][] = $id;
            }
        }

        $parent_labels = []; // [type][id] => label
        foreach ($by_type as $type => $ids) {
            $ids = array_unique($ids);
            if ($type === 'Project_Model') {
                foreach (Project_Model::whereIn('id', $ids)->get() as $m) {
                    $parent_labels[$type][$m->id] = $m->name;
                }
            } elseif ($type === 'Task_Model') {
                foreach (Task_Model::whereIn('id', $ids)->get() as $m) {
                    $parent_labels[$type][$m->id] = $m->title;
                }
            } elseif ($type === 'Client_Model') {
                foreach (Client_Model::whereIn('id', $ids)->get() as $m) {
                    $parent_labels[$type][$m->id] = $m->name;
                }
            } elseif (str_starts_with($type, 'User')) {
                foreach (User_Model::whereIn('id', $ids)->get() as $m) {
                    $parent_labels[$type][$m->id] = trim($m->first_name . ' ' . $m->last_name) ?: $m->email;
                }
            }
        }

        foreach ($records as &$record) {
            $type = $record['taskable_type'] ?? null;
            $tid = $record['taskable_id'] ?? null;

            $record['parent_type'] = $type;
            $record['parent_label'] = ($type && $tid && isset($parent_labels[$type][$tid]))
                ? $parent_labels[$type][$tid]
                : null;

            $record['project_name'] = (!empty($record['project_id']) && isset($projects[$record['project_id']]))
                ? $projects[$record['project_id']]->name
                : null;

            $assignee = $record['assigned_to_user_id'] ?? null;
            $record['assigned_to_name'] = ($assignee && isset($users[$assignee]))
                ? (trim($users[$assignee]->first_name . ' ' . $users[$assignee]->last_name) ?: $users[$assignee]->email)
                : null;
        }

        return $records;
    }
}
