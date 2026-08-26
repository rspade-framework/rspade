<?php

namespace Rsx\App\Frontend\Settings\GroupManagement\List;

use Illuminate\Database\Eloquent\Builder;
use App\RSpade\Core\Session\Session;
use Rsx\Models\User_Group_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Groups_DataGrid - Group management datagrid implementation
 *
 * **Features**:
 * - Group listing filtered by site
 * - Sortable columns
 * - Pagination
 * - Search/filter across name and description
 *
 * **Security**:
 * - Only shows groups from the same site as the logged-in user
 */
class Groups_DataGrid extends DataGrid_Abstract
{
    /**
     * Default configuration
     */
    protected static ?string $default_sort = 'id';

    protected static string $default_order = 'desc';

    /**
     * Columns that can be sorted
     */
    protected static array $sortable_columns = [
        'id',
        'name',
        'created_at',
    ];

    /**
     * created_at is not unique, so id breaks the tie and keeps paging stable.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    /**
     * Build the query for fetching groups
     *
     * Filters groups by the same site_id as the logged-in user for security.
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    protected static function build_query(array $params): Builder
    {
        $query = User_Group_Model::query();

        // Security: Only show groups from the same site
        $current_site_id = Session::get_site_id();
        if ($current_site_id) {
            $query->where('site_id', $current_site_id);
        }

        // Apply filter if provided - searches across multiple fields
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('name', 'LIKE', "%{$filter}%")
                    ->orWhere('description', 'LIKE', "%{$filter}%");
            });
        }

        return $query;
    }

    /**
     * Transform records to add computed fields
     *
     * @param array $records Raw records from database
     * @param array $params Request parameters
     * @return array Transformed records
     */
    protected static function transform_records(array $records, array $params): array
    {
        foreach ($records as &$record) {
            // Add member count as a computed field
            $group = User_Group_Model::find($record['id']);
            $record['member_count'] = $group->member_count();
        }

        return $records;
    }
}
