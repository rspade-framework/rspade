<?php

namespace Rsx\App\Frontend\Settings\ApiKeys;

use Illuminate\Database\Eloquent\Builder;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;
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
     * Each row as PLAIN FIELDS - exactly what the list renders, and nothing else.
     *
     * The grid's query is an Api_Key_Model query, and a model row serialized as it stands
     * arrives in the browser as a model record (__MODEL: Api_Key_Model), which the browser
     * refuses: Api_Key_Model is a framework system model with no JS class in this bundle, and
     * the page needs fields, not the record. So every row is rebuilt from scratch here - an
     * allowlist, never the row minus some columns - which is also what keeps key_hash
     * (never exported by the model either) and the scope text off the wire. The scopes are
     * fetched by the view modal (get_key_scopes), which is also where the endpoint list is
     * resolved, so they do not ride along on every row of every page.
     *
     * @param array $records Raw records from database
     * @param array $params Request parameters
     * @return array The rows, as {id, name, key_prefix, read_only, is_scoped, scope_summary,
     *               status, created_at, last_used_at}
     */
    protected static function transform_records(array $records, array $params): array
    {
        $rows = [];

        foreach ($records as $record) {
            // Scoping in one column: unrestricted, or how many scopes narrow the key. The
            // COUNT and not the scopes - a table cell is not where anyone reads a scope set,
            // and the question a listing answers is whether the key is narrowed at all.
            // ACCESS (read_only) is a separate column because it answers a separate question:
            // which VERBS the key may use, where Scope is which PATHS it may reach.
            $scopes = $record['scopes'] ?? null;
            $is_scoped = !Api_Scopes::is_unrestricted($scopes);
            $scope_count = $is_scoped ? Api_Scopes::count_scopes((string) $scopes) : 0;

            if ($record['is_revoked']) {
                $status = 'revoked';
            } elseif ($record['expires_at'] && Rsx_Time::parse($record['expires_at'])->isPast()) {
                $status = 'expired';
            } else {
                $status = 'active';
            }

            $rows[] = [
                'id' => (int) $record['id'],
                'name' => (string) $record['name'],
                'key_prefix' => (string) $record['key_prefix'],
                'read_only' => (bool) $record['read_only'],
                'is_scoped' => $is_scoped,
                'scope_summary' => $is_scoped
                    ? $scope_count . ' scope' . ($scope_count === 1 ? '' : 's')
                    : 'Unrestricted',
                'status' => $status,
                'created_at' => Rsx_Time::to_iso($record['created_at']),
                'last_used_at' => Rsx_Time::to_iso($record['last_used_at']),
            ];
        }

        return $rows;
    }
}
