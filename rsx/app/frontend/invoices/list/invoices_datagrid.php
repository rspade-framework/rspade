<?php

namespace Rsx\App\Frontend\Invoices\List;

use Illuminate\Database\Eloquent\Builder;
use Rsx\Models\Invoice_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Invoices_DataGrid - invoices: number, client, status, issue date, due date, total.
 *
 * Each row's `total` and `is_overdue` are the model's appended properties (the total is one
 * SUM over the invoice's entries per row - a page of rows, never the whole table).
 *
 * Joins the client's name (a same-site join: the base query is site-scoped, the clients table
 * is not, so the join also matches site_id). Every column is qualified - clients carry a name,
 * a status and dates of their own.
 *
 * Filters (top-level params): `filter` (an invoice number - "INV-0007", "7" - matched exactly,
 * otherwise a part of the client's name), `status` (an Invoice_Model::STATUS_* id, or 'overdue': issued and past due, the
 * model's where_overdue()), `client_id`.
 */
class Invoices_DataGrid extends DataGrid_Abstract
{
    protected static ?string $default_sort = 'invoice_number';

    protected static string $default_order = 'desc';

    protected static array $sortable_columns = [
        'invoice_number', 'client', 'status', 'issue_date', 'due_date',
    ];

    /** Dates and statuses are shared by many invoices: the number keeps paging stable under them. */
    protected static ?string $secondary_sort = 'invoice_number';

    protected static string $secondary_order = 'desc';

    /** Overdue: a filter value beside the status ids, not a stored status. */
    public const STATUS_FILTER_OVERDUE = 'overdue';

    protected static function map_sort_column(string $column): string
    {
        return match ($column) {
            'client' => 'client_name',
            default => 'invoices.' . $column,
        };
    }

    protected static function build_query(array $params): Builder
    {
        $query = Invoice_Model::query()
            ->select([
                'invoices.*',
                'clients.name as client_name',
            ])
            ->leftJoin('clients', function ($join) {
                $join->on('invoices.client_id', '=', 'clients.id')
                    ->on('clients.site_id', '=', 'invoices.site_id');
            });

        if (!empty($params['filter']) && is_string($params['filter'])) {
            $filter = trim($params['filter']);
            $number = preg_match('/^(?:inv-?)?0*([0-9]+)$/i', $filter, $m) ? (int) $m[1] : null;
            if ($number !== null) {
                // A search that is only a number names an invoice: "7" is INV-0007, never every
                // client whose name holds a 7.
                $query->where('invoices.invoice_number', $number);
            } else {
                $query->where('clients.name', 'LIKE', "%{$filter}%");
            }
        }

        if (!empty($params['status'])) {
            if ($params['status'] === self::STATUS_FILTER_OVERDUE) {
                Invoice_Model::where_overdue($query);
            } else {
                $query->where('invoices.status', (int) $params['status']);
            }
        }

        if (!empty($params['client_id'])) {
            $query->where('invoices.client_id', (int) $params['client_id']);
        }

        return $query;
    }
}
