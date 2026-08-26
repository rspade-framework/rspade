<?php



namespace Rsx\App\Frontend\Settings\UserManagement\List;

use Illuminate\Database\Eloquent\Builder;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Users_DataGrid - User management datagrid implementation
 *
 * **Features**:
 * - User listing filtered by site (via site_users relationship)
 * - Sortable columns
 * - Pagination
 * - Search/filter across name and email
 *
 * **Security**:
 * - Only shows users from the same site as the logged-in user
 */
class Users_DataGrid extends DataGrid_Abstract
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
        'first_name',
        'email',
        'invitation_status',
        'phone',
        'role_id',
        'created_at',
    ];

    /**
     * role_id and the computed status have a handful of values each, so id breaks the tie
     * and keeps paging stable under those sorts.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    /**
     * The Status column is computed, not stored - see build_query's invitation_rank.
     */
    protected static function map_sort_column(string $column): string
    {
        return $column === 'invitation_status' ? 'invitation_rank' : $column;
    }

    /**
     * Build the query for fetching users
     *
     * Filters users by the same site_id as the logged-in user for security.
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    protected static function build_query(array $params): Builder
    {
        // invitation_rank makes the computed Status column sortable in SQL, in the order the
        // badges read: 0 accepted, 1 pending, 2 expired. It mirrors get_invitation_status(),
        // which is still what transform_records() puts on the row for display - this one
        // exists only to order by, which is why it is a rank rather than a label.
        $query = User_Model::query()
            ->select('users.*')
            ->selectRaw(
                'CASE WHEN invite_accepted_at IS NOT NULL THEN 0 '
                . 'WHEN invite_expires_at IS NOT NULL AND invite_expires_at < NOW() THEN 2 '
                . 'ELSE 1 END AS invitation_rank'
            );

        // Security: Only show users from the same site
        // User_Model now has site_id column directly (site-specific user profiles)
        $current_site_id = Session::get_site_id();
        if ($current_site_id) {
            $query->where('site_id', $current_site_id);
        }

        // Apply filter if provided - searches across multiple fields
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('first_name', 'LIKE', "%{$filter}%")
                    ->orWhere('last_name', 'LIKE', "%{$filter}%")
                    ->orWhere('email', 'LIKE', "%{$filter}%");
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
            // Add invitation status as a computed field
            $user = User_Model::find($record['id']);
            $record['invitation_status'] = $user->get_invitation_status();
        }

        return $records;
    }
}
