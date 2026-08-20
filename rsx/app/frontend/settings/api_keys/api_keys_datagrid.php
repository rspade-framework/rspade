<?php

namespace Rsx\App\Frontend\Settings\ApiKeys;

use Illuminate\Database\Eloquent\Builder;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Session\Session;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Api_Keys_DataGrid - API key management datagrid implementation
 *
 * Features:
 * - Lists API keys for the current user
 * - Sortable columns
 * - Pagination
 * - Search by name
 *
 * Security:
 * - Only shows keys belonging to the logged-in user
 */
class Api_Keys_DataGrid extends DataGrid_Abstract
{
    /**
     * Default configuration
     */
    protected static ?string $default_sort = 'created_at';

    protected static string $default_order = 'desc';

    /**
     * Columns that can be sorted
     */
    protected static array $sortable_columns = [
        'id',
        'name',
        'created_at',
        'last_used_at',
    ];

    /**
     * Build the query for fetching API keys
     *
     * Filters keys by the logged-in user for security.
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    protected static function build_query(array $params): Builder
    {
        $query = Api_Key_Model::query();

        // Security: Only show keys for the current user
        $user = Session::get_user();
        if ($user) {
            $query->where('user_id', $user->id);
        } else {
            // No user = no results
            $query->whereRaw('1 = 0');
        }

        // Apply filter if provided - searches name
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where('name', 'LIKE', "%{$filter}%");
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
            // Security: Remove sensitive fields from response
            unset($record['key_hash']);

            // Format last_used_at for display
            // Add status label
            if ($record['is_revoked']) {
                $record['status'] = 'revoked';
                $record['status_label'] = 'Revoked';
            } elseif ($record['expires_at'] && \Carbon\Carbon::parse($record['expires_at'])->isPast()) {
                $record['status'] = 'expired';
                $record['status_label'] = 'Expired';
            } else {
                $record['status'] = 'active';
                $record['status_label'] = 'Active';
            }
        }

        return $records;
    }
}
