<?php



namespace Rsx\App\Frontend\Projects\List;

use Illuminate\Database\Eloquent\Builder;
use Rsx\Models\Client_Model;
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
        'client_id',
        'status',
        'priority',
        'start_date',
        'due_date',
        'created_at',
        'updated_at',
    ];

    /**
     * Build the query for fetching projects
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    protected static function build_query(array $params): Builder
    {
        $query = Project_Model::query();

        // Apply filter if provided - searches across multiple fields
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('name', 'LIKE', "%{$filter}%")
                    ->orWhere('description', 'LIKE', "%{$filter}%")
                    ->orWhere('notes', 'LIKE', "%{$filter}%");
            });
        }

        return $query;
    }

    /**
     * Transform records after fetching
     * Load related client names manually
     */
    protected static function transform_records(array $records, array $params): array
    {
        // Get all unique client IDs
        $client_ids = array_unique(array_column($records, 'client_id'));

        // Load all clients at once
        $clients = Client_Model::whereIn('id', $client_ids)->get()->keyBy('id');

        // Attach client name to each record
        // Note: Use 'client_name' not 'client' to avoid conflict with
        // the auto-generated client() relationship method in JS
        foreach ($records as &$record) {
            $record['client_name'] = isset($clients[$record['client_id']])
                ? $clients[$record['client_id']]->name
                : null;
        }

        return $records;
    }
}
