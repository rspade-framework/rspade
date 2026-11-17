<?php

namespace Rsx\App\Frontend\Billing\List;

use Illuminate\Database\Eloquent\Builder;
use App\RSpade\Core\Session\Session;
use Rsx\Models\Billable_Entry_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Billing_DataGrid - billable entries: date, kind, person, project, description, amount, status.
 *
 * The grid over a DETAIL-TABLES model: its query is over Billable_Entry_Model, so
 * DataGrid_Abstract::fetch() preloads every row's detail (a time entry's hours and rate, an
 * expense's vendor and cost) in one query per detail table before toArray() embeds them -
 * no query per row. The query keeps `billable_entries.*`, which carries the id and the
 * type_id discriminator the preload reads.
 *
 * Joins the project's name and the person's name (same-site joins: the base query is
 * site-scoped, the joined tables are not, so each join also matches site_id). Every column is
 * qualified - projects and users both carry an id, a name or a status of their own.
 *
 * Filters (top-level params): `filter` (description or project name), `status`, `project_id`,
 * `mine` (truthy: only the signed-in user's own entries).
 */
class Billing_DataGrid extends DataGrid_Abstract
{
    protected static ?string $default_sort = 'entry_date';

    protected static string $default_order = 'desc';

    protected static array $sortable_columns = [
        'id', 'entry_date', 'type_id', 'person', 'project', 'description', 'amount', 'status',
    ];

    /** entry_date is a day, shared by many entries: id keeps paging stable under it. */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    protected static function map_sort_column(string $column): string
    {
        return match ($column) {
            'project' => 'project_name',
            'person' => 'users.last_name',
            default => 'billable_entries.' . $column,
        };
    }

    protected static function build_query(array $params): Builder
    {
        $query = Billable_Entry_Model::query()
            ->select([
                'billable_entries.*',
                'projects.name as project_name',
                'users.first_name as user_first_name',
                'users.last_name as user_last_name',
            ])
            ->leftJoin('projects', function ($join) {
                $join->on('billable_entries.project_id', '=', 'projects.id')
                    ->on('projects.site_id', '=', 'billable_entries.site_id');
            })
            ->leftJoin('users', function ($join) {
                $join->on('billable_entries.user_id', '=', 'users.id')
                    ->on('users.site_id', '=', 'billable_entries.site_id');
            });

        if (!empty($params['filter']) && is_string($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('billable_entries.description', 'LIKE', "%{$filter}%")
                    ->orWhere('projects.name', 'LIKE', "%{$filter}%");
            });
        }

        if (!empty($params['status'])) {
            $query->where('billable_entries.status', (int) $params['status']);
        }

        if (!empty($params['project_id'])) {
            $query->where('billable_entries.project_id', (int) $params['project_id']);
        }

        if (!empty($params['mine'])) {
            $query->where('billable_entries.user_id', (int) Session::get_user_id());
        }

        return $query;
    }

    /** The person's printed name, from the two joined name columns. */
    protected static function transform_records(array $records, array $params): array
    {
        foreach ($records as &$record) {
            $record['user_name'] = trim(($record['user_first_name'] ?? '') . ' ' . ($record['user_last_name'] ?? ''));
            unset($record['user_first_name'], $record['user_last_name']);
        }

        return $records;
    }
}
