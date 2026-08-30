<?php

namespace Rsx\App\Frontend\Settings\ApiKeys;

use Illuminate\Database\Eloquent\Builder;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scopes;
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
    /**
     * Usable keys first, newest first within each group.
     *
     * Status leads because it is the question the page is usually answering - "which of my
     * keys still work" - and a revoked key from last year has no business sitting above a
     * live one just because it happens to be newer.
     */
    protected static ?string $default_sort = 'status';

    protected static string $default_order = 'asc';

    /**
     * Newest first within a status group, and the tie-breaker under every other sort - a
     * status column has three values, so without it the order inside a group is arbitrary
     * and shuffles between pages.
     */
    protected static ?string $secondary_sort = 'created_at';

    protected static string $secondary_order = 'desc';

    /**
     * 25 per page. Keys accumulate slowly and are read as a whole list, so a page that shows
     * most of them beats one that hides them behind a pager.
     */
    protected static int $default_per_page = 25;

    /**
     * Columns that can be sorted.
     *
     * Key and Actions are absent deliberately: the key column shows a masked prefix whose
     * order means nothing, and Actions is not data.
     */
    protected static array $sortable_columns = [
        'id',
        'name',
        'status',
        'created_at',
        'last_used_at',
    ];

    /**
     * 'status' is computed, not stored - see build_query's status_rank.
     */
    protected static function map_sort_column(string $column): string
    {
        return $column === 'status' ? 'status_rank' : $column;
    }

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
        // status_rank makes the computed status sortable in SQL: 0 usable, 1 not. The same
        // three-way state is spelled out for display in transform_records() - this one exists
        // only to order by, which is why it is a rank rather than a label.
        $query = Api_Key_Model::query()
            ->select('_api_keys.*')
            ->selectRaw(
                'CASE WHEN is_revoked = 0 AND (expires_at IS NULL OR expires_at > NOW()) '
                . 'THEN 0 ELSE 1 END AS status_rank'
            );

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

            // Scoping in one column: unrestricted, or how many rules narrow the key. The
            // COUNT and not the rules - a table cell is not where anyone reads a rule set,
            // and the question a listing answers is whether the key is narrowed at all. The
            // rules themselves are one click away, in the view modal.
            $scopes = $record['scopes'] ?? null;
            $record['is_scoped'] = !Api_Scopes::is_unrestricted($scopes);
            $rule_count = $record['is_scoped'] ? count(Api_Scopes::parse((string) $scopes)) : 0;
            $record['scope_summary'] = $record['is_scoped']
                ? $rule_count . ' rule' . ($rule_count === 1 ? '' : 's')
                : 'Unrestricted';

            // The rule text itself is fetched by the view modal (get_key_scopes), which is
            // also where the endpoint list is resolved - so it does not ride along on every
            // row of every page.
            unset($record['scopes']);

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
