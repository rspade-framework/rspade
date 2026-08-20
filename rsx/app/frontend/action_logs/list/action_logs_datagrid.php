<?php


namespace Rsx\App\Frontend\ActionLogs\List;

use Illuminate\Database\Eloquent\Builder;
use Rsx\Models\Action_Log_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Action_Logs_DataGrid - Action log list datagrid implementation
 *
 * Displays action history with actor, subject, and rendered action text.
 */
class Action_Logs_DataGrid extends DataGrid_Abstract
{
    /**
     * Columns that can be sorted
     */
    protected static array $sortable_columns = [
        'id',
        'type_id',
        'created_at',
    ];

    /**
     * Default sort column
     */
    protected static ?string $default_sort = 'created_at';

    /**
     * Default sort order
     */
    protected static string $default_order = 'desc';

    /**
     * Build the query for fetching action logs
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    protected static function build_query(array $params): Builder
    {
        $query = Action_Log_Model::query();

        // Apply filter if provided - searches across type label
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            // Filter by type label would need to be done in transform_records
            // For now, we filter by type_id if numeric
            if (is_numeric($filter)) {
                $query->where('type_id', $filter);
            }
        }

        return $query;
    }

    /**
     * Transform records after fetching
     *
     * Add rendered action text, actor name, and subject info.
     *
     * @param array $records
     * @param array $params
     * @return array
     */
    protected static function transform_records(array $records, array $params): array
    {
        foreach ($records as &$record) {
            // Load the model to get render() and relationships
            $log = Action_Log_Model::find($record['id']);

            if ($log) {
                // Use model methods for computed display values
                $record['render'] = $log->render();
                $record['actor_display'] = $log->actor_display();
                $record['subject_display'] = $log->subject_display();
                $record['subject_type_display'] = class_basename($log->subject_type);
            }
        }

        return $records;
    }
}
