<?php


namespace Rsx\App\Frontend\Clients\List;

use Illuminate\Database\Eloquent\Builder;
use Rsx\Models\Client_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Clients_DataGrid - Clients list datagrid implementation
 *
 * **Features**:
 * - Basic client listing with all fields
 * - Sortable columns
 * - Pagination
 *
 * **Future Enhancements** (examples of what's possible):
 * - Join with contacts table to show contact count
 * - Filter by status, priority
 * - Search across multiple fields
 * - Computed columns (full address, contact count)
 */
class Clients_DataGrid extends DataGrid_Abstract
{
    /**
     * Columns that can be sorted
     * If a sort request comes in for a column not in this list, it will fall back to $default_sort
     */
    protected static array $sortable_columns = [
        'id',
        'name',
        'city',
        'phone',
        'priority',
        'created_at',
    ];

    /**
     * priority has four values, so id breaks the tie and keeps paging stable under it.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    /**
     * Build the query for fetching clients
     *
     * This is a simple implementation that just selects all clients.
     * You can customize this to add:
     * - JOINs (e.g., to count contacts)
     * - WHERE clauses (e.g., filter by status)
     * - HAVING clauses (e.g., clients with > 5 contacts)
     * - Computed columns (e.g., DB::raw expressions)
     *
     * Example with joins and filters:
     * ```php
     * $query = Client_Model::query()
     *     ->select([
     *         'clients.*',
     *         DB::raw('COUNT(contacts.id) as contact_count'),
     *         DB::raw('MAX(contacts.created_at) as last_contact_added')
     *     ])
     *     ->leftJoin('contacts', 'clients.id', '=', 'contacts.client_id')
     *     ->groupBy('clients.id');
     *
     * // Apply dynamic filters from frontend
     * if (!empty($params['status'])) {
     *     $query->where('clients.status', $params['status']);
     * }
     *
     * if (!empty($params['search'])) {
     *     $search = $params['search'];
     *     $query->where(function($q) use ($search) {
     *         $q->where('name', 'LIKE', "%{$search}%")
     *           ->orWhere('city', 'LIKE', "%{$search}%")
     *           ->orWhere('phone', 'LIKE', "%{$search}%");
     *     });
     * }
     *
     * return $query;
     * ```
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    protected static function build_query(array $params): Builder
    {
        $query = Client_Model::query();

        // Apply filter if provided - searches across multiple fields
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('name', 'LIKE', "%{$filter}%")
                    ->orWhere('address', 'LIKE', "%{$filter}%")
                    ->orWhere('city', 'LIKE', "%{$filter}%")
                    ->orWhere('state', 'LIKE', "%{$filter}%")
                    ->orWhere('phone', 'LIKE', "%{$filter}%")
                    ->orWhere('phone_secondary', 'LIKE', "%{$filter}%")
                    ->orWhere('website', 'LIKE', "%{$filter}%");
            });
        }

        // Quick filters from the card header. No join here, so unqualified names are
        // unambiguous - keep it that way if a join is ever added.
        if (!empty($params['status_id'])) {
            $query->where('status_id', (int) $params['status_id']);
        }

        if (!empty($params['priority'])) {
            $query->where('priority', (int) $params['priority']);
        }

        return $query;
    }

    /**
     * Transform records after fetching (optional)
     *
     * Use this to add computed fields or format data that can't be done in SQL.
     *
     * Example:
     * ```php
     * protected static function transform_records(array $records, array $params): array
     * {
     *     foreach ($records as &$record) {
     *         // Add computed field
     *         $record['full_address'] = trim($record['address'] . ', ' . $record['city'] . ', ' . $record['state'] . ' ' . $record['zip']);
     *
     *         // Format dates
     *         $record['created_at_formatted'] = date('M j, Y', strtotime($record['created_at']));
     *
     *         // Add status badge class
     *         $record['status_class'] = match($record['status']) {
     *             'active' => 'badge-success',
     *             'inactive' => 'badge-secondary',
     *             default => 'badge-warning'
     *         };
     *     }
     *     return $records;
     * }
     * ```
     */
}
