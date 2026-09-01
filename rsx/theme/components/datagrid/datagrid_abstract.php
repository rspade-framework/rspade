<?php

/**
 * DataGrid_Abstract - Abstract base class for all DataGrid implementations
 *
 * **Philosophy**: Simple things simple, complex things possible.
 *
 * **Responsibilities**:
 * - Orchestration: Parameter validation, pagination math, response formatting
 * - Delegation: Query building handled by concrete implementations
 *
 * **Phase 1 Features**:
 * - Template method pattern for query customization
 * - Dynamic SQL building with full query builder access
 * - Sorting and pagination with validation
 * - Optional post-processing hooks
 *
 * **Usage**:
 * ```php
 * class My_DataGrid extends DataGrid_Abstract {
 *     protected static function build_query(array $params) {
 *         return MyModel::query()
 *             ->select(['table.*', DB::raw('COUNT(x) as total')])
 *             ->leftJoin('other', 'table.id', '=', 'other.table_id')
 *             ->where('status', $params['status'] ?? 'active')
 *             ->groupBy('table.id');
 *     }
 * }
 * ```
 */

namespace Rsx\Theme\Components\Datagrid;

use DB;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use League\Csv\Writer;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Database\Rsx_Result_Set;

abstract class DataGrid_Abstract
{
    protected static int $default_per_page = 15;

    protected static int $max_per_page = 100;

    protected static ?string $default_sort = 'id';

    protected static string $default_order = 'desc';

    protected static array $sortable_columns = [];

    /**
     * Tie-breaker applied AFTER the primary sort, or null for none.
     *
     * A sort on a low-cardinality column - a status, a type, a boolean - leaves large groups
     * of rows in whatever order the database felt like returning them, which changes between
     * pages and between requests. Naming a tie-breaker makes the ordering total, so page 2 is
     * the rows that actually follow page 1.
     *
     * Skipped when it IS the primary sort, so a column never orders by itself twice.
     *
     * @var string|null
     */
    protected static ?string $secondary_sort = null;

    /**
     * Direction for $secondary_sort.
     *
     * @var string
     */
    protected static string $secondary_order = 'desc';

    /**
     * Map sort column names to actual database columns
     *
     * Override this to map frontend column names to database columns/expressions.
     * Useful when sorting by joined table columns or computed fields.
     *
     * Example:
     * ```php
     * protected static function map_sort_column(string $column): string
     * {
     *     return match($column) {
     *         'company' => 'clients.name',
     *         'full_name' => DB::raw('CONCAT(first_name, " ", last_name)'),
     *         default => $column
     *     };
     * }
     * ```
     *
     * @param string $column Column name from request
     * @return string Actual database column/expression to sort by
     */
    #[Replaceable]
    protected static function map_sort_column(string $column): string
    {
        return $column;
    }

    /**
     * Build the base query for this datagrid
     *
     * Implement this method to define your query with full control over:
     * - SELECT columns (including computed columns)
     * - JOIN clauses
     * - WHERE conditions
     * - GROUP BY clauses
     * - HAVING clauses
     * - Dynamic filtering based on $params
     *
     * The abstract class will handle:
     * - Sorting (via orderBy)
     * - Pagination (via offset/limit)
     * - Count queries
     * - Response formatting
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    abstract protected static function build_query(array $params): Builder;

    /**
     * Public seam onto build_query() for mass-action endpoints.
     *
     * A mass action ("delete every record matching what the user is looking at") has to
     * rebuild the SAME query the grid was showing, then constrain it by the ids the
     * selection payload carries. $params is exactly the filter array build_query()
     * receives from fetch() - filter, sort, order and whatever custom filter keys the
     * concrete grid declares. Sorting and pagination are NOT applied here; the caller
     * adds its own id constraints (whereIn / whereNotIn) to the returned builder.
     *
     * @param array $params Filter params, same shape build_query() receives
     * @return Builder Query builder instance
     */
    public static function build_query_public(array $params): Builder
    {
        return static::build_query($params);
    }


    /**
     * Constrain a rebuilt grid query by a footer-action selection payload.
     *
     * The client sends {mode, ids, total, filter_params}; filter_params has already gone to
     * build_query_public() by the time this is called, so all that is left is the id set:
     *
     *   additive    - ids ARE the selection            -> whereIn
     *   subtractive - everything EXCEPT ids            -> whereNotIn (skipped when ids is empty)
     *   all         - everything the filters matched   -> no constraint
     *
     * $id_column is ALWAYS table-qualified ('clients.id', 'contacts.id'): several of these
     * queries join a table that carries an id column of its own, and an unqualified one is
     * an ambiguous-column error at best and the wrong table's rows at worst.
     *
     * Returns the constrained builder, or an Error_Response when the payload is malformed -
     * the caller returns that verbatim.
     *
     * @param Builder $query Query from build_query_public()
     * @param string $id_column Table-qualified primary key column
     * @param array $selection Selection payload: mode, ids
     * @return Builder|Error_Response
     */
    public static function apply_selection(Builder $query, string $id_column, array $selection)
    {
        $mode = $selection['mode'] ?? null;

        if (!in_array($mode, ['additive', 'subtractive', 'all'], true)) {
            return response_error(Ajax::ERROR_VALIDATION, 'Unknown selection mode');
        }

        $ids = $selection['ids'] ?? [];

        if (!is_array($ids)) {
            return response_error(Ajax::ERROR_VALIDATION, 'Selection ids must be an array');
        }

        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                return response_error(Ajax::ERROR_VALIDATION, 'Selection ids must be integers');
            }
        }

        $ids = array_map('intval', array_values($ids));

        if ($mode === 'additive') {
            // An additive selection with no ids selects NOTHING - whereIn([]) is exactly that,
            // and is deliberately not shortcut into "everything".
            $query->whereIn($id_column, $ids);
        } elseif ($mode === 'subtractive' && !empty($ids)) {
            $query->whereNotIn($id_column, $ids);
        }

        return $query;
    }



    /**
     * Iterate the WHOLE of a constrained grid query, one keyset page at a time.
     *
     * NOT ->result_set(). Rsx_Result_Set calls lazyById() with no column, and Laravel's
     * default key name is UNQUALIFIED ('id') - which MySQL rejects as ambiguous the moment
     * the query joins a table carrying an id of its own, exactly what the contacts and
     * projects grids do. Passing the table-qualified column (the same one apply_selection()
     * constrained on) removes the ambiguity; the alias reads the cursor value back off the
     * model, where the property is still plain 'id'.
     *
     * The set is walked, never truncated: there is no LIMIT here beyond the page size.
     *
     * @param Builder $query Query returned by apply_selection()
     * @param string $id_column Table-qualified primary key column
     * @return LazyCollection
     */
    public static function iterate_selection(Builder $query, string $id_column): LazyCollection
    {
        return $query->lazyById(Rsx_Result_Set::DEFAULT_CHUNK_SIZE, $id_column, 'id');
    }

    /**
     * Render a header row plus data rows as one RFC4180 CSV string.
     *
     * league/csv is the framework's CSV library, reading and writing alike - see
     * `rsx:man csv_exports`. Nothing here escapes a field by hand: Writer owns quoting,
     * doubled quotes, embedded newlines and delimiters, and it is the same library an
     * import reads through, so one implementation defines what a CSV field means in
     * both directions.
     *
     * The stream is php://temp, which keeps a small export in memory and spills a large
     * one to disk on its own - so a grid with a lot of selected rows does not decide how
     * much memory the request needs.
     *
     * @param array $headers Column headings
     * @param array $rows List of rows, each a flat list of scalar values
     * @return string
     */
    public static function build_csv(array $headers, array $rows): string
    {
        $writer = Writer::from(fopen('php://temp', 'r+'));

        $writer->insertOne($headers);
        $writer->insertAll($rows);

        return $writer->toString();
    }

    /**
     * Transform records after fetching (optional override)
     *
     * Use this to add computed fields, format data, or perform any
     * post-processing that can't be done in SQL.
     *
     * @param array $records Raw records from database
     * @param array $params Request parameters
     * @return array Transformed records
     */
    #[Replaceable]
    protected static function transform_records(array $records, array $params): array
    {
        return $records;
    }

    /**
     * Fetch paginated, sorted data for the datagrid
     *
     * This method orchestrates the entire fetch process:
     * 1. Validates and normalizes parameters
     * 2. Calls build_query() to get base query
     * 3. Applies sorting
     * 4. Calculates pagination
     * 5. Executes query
     * 6. Transforms records (if overridden)
     * 7. Returns formatted response
     *
     * @param array $params Request parameters (page, per_page, sort, order, filters)
     * @return array Response with records, pagination metadata
     */
    public static function fetch(array $params = []): array
    {
        // Extract and validate parameters
        $page = max(1, (int)($params['page'] ?? 1));

        $per_page = (int)($params['per_page'] ?? static::$default_per_page);

        // Clamp into the legal range rather than snapping an out-of-range value to the ceiling:
        // a per_page of 0 is a nonsense request for nothing, not a request for the maximum.
        $per_page = min(max(1, $per_page), static::$max_per_page);

        $sort = $params['sort'] ?? static::$default_sort;

        $order = strtolower($params['order'] ?? static::$default_order);

        // Validate sort column (must be in sortable_columns if defined, otherwise use default)
        if (!empty(static::$sortable_columns) && !in_array($sort, static::$sortable_columns)) {
            $sort = static::$default_sort;
        }

        // Validate order (must be 'asc' or 'desc')
        if (!in_array($order, ['asc', 'desc'])) {
            $order = static::$default_order;
        }

        // Build base query (delegated to concrete implementation)
        $query = static::build_query($params);

        // Clone query for counting (before sorting/pagination)
        $count_query = clone $query;

        // Apply sorting if specified
        if ($sort !== null) {
            $sort_column = static::map_sort_column($sort);
            $query->orderBy($sort_column, $order);
        }

        // Tie-breaker, so rows sharing a primary sort value have a stable order across pages.
        if (static::$secondary_sort !== null && static::$secondary_sort !== $sort) {
            $query->orderBy(
                static::map_sort_column(static::$secondary_sort),
                static::$secondary_order
            );
        }

        // Get total count
        $total = $count_query->count();
        $total_pages = (int)ceil($total / $per_page);

        // Ensure page is within valid range
        $page = min($page, max(1, $total_pages));

        // Apply pagination
        $offset = ($page - 1) * $per_page;
        $query->offset($offset)->limit($per_page);

        // Execute query
        $records = $query->get()->toArray();

        // Transform records (if overridden)
        $records = static::transform_records($records, $params);

        // Return response
        return [
            'records' => $records,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
            'sort' => $sort,
            'order' => $order,
        ];
    }
}
