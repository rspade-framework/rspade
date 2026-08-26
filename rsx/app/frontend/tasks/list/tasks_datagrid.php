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
 *  - project_name : the derived-or-user project's name (from project_id), or null - JOINED
 *  - assigned_to_name : assignee display name, or null - JOINED
 */
class Tasks_DataGrid extends DataGrid_Abstract
{
    protected static ?string $default_sort = 'created_at';

    protected static string $default_order = 'desc';

    /**
     * created_at carries no header - it is the default sort, and a requested sort has to be
     * on this list to survive validation, including the one the grid boots with.
     */
    protected static array $sortable_columns = [
        'title',
        'project',
        'status',
        'priority',
        'assignee',
        'due_date',
        'hour_estimate',
        'created_at',
    ];

    /**
     * created_at is not unique, so rows sharing a timestamp would shuffle between pages.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    /**
     * 'project' and 'assignee' are the joined display values, selected as project_name and
     * assigned_to_name - sorting on them matches what the column shows. Everything else is
     * qualified with the tasks table: the joins bring in columns of the same name (id, title
     * has no twin but status/priority/created_at do), so an unqualified ORDER BY is ambiguous.
     */
    protected static function map_sort_column(string $column): string
    {
        return match($column) {
            'project' => 'project_name',
            'assignee' => 'assigned_to_name',
            default => 'tasks.' . $column
        };
    }

    protected static function build_query(array $params): Builder
    {
        // Project and assignee are joined rather than looked up per page: the grid both
        // displays them and sorts by them, and a lookup can only do the first of those.
        // Both joins exclude soft-deleted rows, so a task pointing at a trashed project or
        // user shows the same empty cell it showed when these were per-page lookups.
        $query = Task_Model::query()
            ->select([
                'tasks.*',
                'projects.name as project_name',
            ])
            ->selectRaw(
                "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(users.first_name, ''), ' ', "
                . "COALESCE(users.last_name, ''))), ''), users.email) AS assigned_to_name"
            )
            ->leftJoin('projects', function ($join) {
                $join->on('tasks.project_id', '=', 'projects.id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('users', function ($join) {
                $join->on('tasks.assigned_to_user_id', '=', 'users.id')
                    ->whereNull('users.deleted_at');
            });

        // Every filter column is qualified: the joined tables carry columns of the same name.
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('tasks.title', 'LIKE', "%{$filter}%")
                    ->orWhere('tasks.description', 'LIKE', "%{$filter}%")
                    ->orWhere('tasks.notes', 'LIKE', "%{$filter}%");
            });
        }

        // Quick filters from the card header. Every column is qualified: the joined tables
        // carry status/priority columns of their own.
        //
        // status_filter is either the composite word 'open' - every status that is neither
        // finished nor abandoned, which is what the list boots on - or a plain status id.
        // The composite lives here rather than on the client so the id set has ONE definition.
        if (!empty($params['status_filter'])) {
            if ($params['status_filter'] === 'open') {
                $query->whereIn('tasks.status', [
                    Task_Model::STATUS_PENDING,
                    Task_Model::STATUS_IN_PROGRESS,
                ]);
            } else {
                $query->where('tasks.status', (int) $params['status_filter']);
            }
        }

        if (!empty($params['priority'])) {
            $query->where('tasks.priority', (int) $params['priority']);
        }

        return $query;
    }

    protected static function transform_records(array $records, array $params): array
    {
        // project_name and assigned_to_name arrive from the joins in build_query().
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
        }

        return $records;
    }
}
