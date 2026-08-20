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

abstract class DataGrid_Abstract
{
    protected static int $default_per_page = 15;

    protected static int $max_per_page = 100;

    protected static ?string $default_sort = 'id';

    protected static string $default_order = 'desc';

    protected static array $sortable_columns = [];

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

        if ($per_page > static::$max_per_page || $per_page < 1) {
            $per_page = static::$max_per_page;
        }

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
