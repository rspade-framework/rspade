<?php



namespace Rsx\App\Frontend\Contacts\List;

use Illuminate\Database\Eloquent\Builder;
use Rsx\Models\Contact_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Contacts_DataGrid - Contacts list datagrid implementation
 *
 * **Features**:
 * - Basic contact listing with all fields
 * - Sortable columns
 * - Pagination
 *
 * **Future Enhancements** (examples of what's possible):
 * - Join with activities table to show activity count
 * - Filter by status, priority, company
 * - Search across multiple fields
 * - Computed columns (full_name, days_since_contact)
 */
class Contacts_DataGrid extends DataGrid_Abstract
{
    /**
     * Default configuration
     */
    protected static ?string $default_sort = 'id';

    protected static string $default_order = 'desc';

    /**
     * Columns that can be sorted
     * If a sort request comes in for a column not in this list, it will fall back to $default_sort
     */
    protected static array $sortable_columns = [
        'id',
        'first_name',
        'email',
        'phone_work',
        'company',
        'priority',
        'created_at',
    ];

    /**
     * priority has four values and a company groups many contacts, so id breaks the tie and
     * keeps paging stable under those sorts.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    /**
     * Map sort column names to actual database columns
     *
     * 'company' is the joined clients.name, selected as company_name. Every other column is
     * qualified with the contacts table: the query joins clients, which carries columns of
     * the same name (id, created_at, ...), so an unqualified ORDER BY is a coin toss.
     *
     * @param string $column Column name from request
     * @return string Actual database column to sort by
     */
    protected static function map_sort_column(string $column): string
    {
        return match($column) {
            'company' => 'company_name',
            default => 'contacts.' . $column
        };
    }

    /**
     * Build the query for fetching contacts
     *
     * Joins clients table to enable sorting by company name.
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    protected static function build_query(array $params): Builder
    {
        $query = Contact_Model::query()
            ->select([
                'contacts.*',
                'clients.name as company_name'
            ])
            ->leftJoin('clients', 'contacts.client_id', '=', 'clients.id');

        // Apply filter if provided - searches across multiple fields
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('contacts.first_name', 'LIKE', "%{$filter}%")
                    ->orWhere('contacts.last_name', 'LIKE', "%{$filter}%")
                    ->orWhere('contacts.email', 'LIKE', "%{$filter}%")
                    ->orWhere('contacts.email_secondary', 'LIKE', "%{$filter}%")
                    ->orWhere('contacts.phone_work', 'LIKE', "%{$filter}%")
                    ->orWhere('contacts.phone_cell', 'LIKE', "%{$filter}%")
                    ->orWhere('contacts.title', 'LIKE', "%{$filter}%")
                    ->orWhere('clients.name', 'LIKE', "%{$filter}%");
            });
        }

        // Quick filter from the card header. Qualified: the clients join carries a priority
        // column of its own.
        if (!empty($params['priority'])) {
            $query->where('contacts.priority', (int) $params['priority']);
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
     *         $record['full_name'] = trim($record['first_name'] . ' ' . $record['last_name']);
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
