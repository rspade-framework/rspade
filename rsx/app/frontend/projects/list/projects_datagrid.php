<?php



namespace Rsx\App\Frontend\Projects\List;

use Illuminate\Database\Eloquent\Builder;
use Rsx\Models\Project_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Projects_DataGrid - Projects list datagrid implementation
 *
 * **Features**:
 * - Basic project listing with all fields
 * - Sortable columns
 * - Pagination
 *
 * **Future Enhancements** (examples of what's possible):
 * - Join with tasks table to show task count
 * - Filter by status, priority, client
 * - Search across multiple fields
 * - Computed columns (days remaining, progress percentage)
 */
class Projects_DataGrid extends DataGrid_Abstract
{
    /**
     * Default configuration
     */
    protected static ?string $default_sort = 'created_at';

    protected static string $default_order = 'desc';

    /**
     * Columns that can be sorted
     * If a sort request comes in for a column not in this list, it will fall back to $default_sort
     */
    protected static array $sortable_columns = [
        'id',
        'name',
        'client',
        'status',
        'priority',
        'start_date',
        'due_date',
        'created_at',
    ];

    /**
     * created_at is not unique, so rows sharing a timestamp would shuffle between pages.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    /**
     * 'client' is the joined clients.name, selected as client_name - the column the grid
     * actually displays, so sorting matches what the user sees. Everything else is qualified
     * with the projects table: the join brings in columns of the same name (id, name,
     * created_at, ...), so an unqualified ORDER BY is ambiguous.
     */
    protected static function map_sort_column(string $column): string
    {
        return match($column) {
            'client' => 'client_name',
            default => 'projects.' . $column
        };
    }

    /**
     * Build the query for fetching projects
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    protected static function build_query(array $params): Builder
    {
        // The client name is joined rather than looked up per page: the grid both displays it
        // and sorts by it, and a lookup can only do the first of those.
        $query = Project_Model::query()
            ->select([
                'projects.*',
                'clients.name as client_name',
            ])
            ->leftJoin('clients', 'projects.client_id', '=', 'clients.id');

        // Apply filter if provided - searches across multiple fields.
        // Every column is qualified: clients carries name/id of its own.
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('projects.name', 'LIKE', "%{$filter}%")
                    ->orWhere('projects.description', 'LIKE', "%{$filter}%")
                    ->orWhere('projects.notes', 'LIKE', "%{$filter}%");
            });
        }

        // Quick filters from the card header. Qualified for the same reason as the search
        // above: the clients join carries columns of the same name.
        if (!empty($params['status'])) {
            $query->where('projects.status', (int) $params['status']);
        }

        if (!empty($params['priority'])) {
            $query->where('projects.priority', (int) $params['priority']);
        }

        return $query;
    }
}
