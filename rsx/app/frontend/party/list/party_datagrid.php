<?php

namespace Rsx\App\Frontend\Party\List;

use Illuminate\Database\Eloquent\Builder;
use Rsx\Models\Party_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Party_DataGrid - lists parties (base columns only; type-specific fields live on the view
 * page). Demonstrates that a CTI base model is an ordinary model for set-based queries.
 */
class Party_DataGrid extends DataGrid_Abstract
{
    protected static ?string $default_sort = 'created_at';

    protected static string $default_order = 'desc';

    protected static array $sortable_columns = [
        'id', 'name', 'type_id', 'email', 'phone', 'created_at',
    ];

    /**
     * created_at is not unique, so rows sharing a timestamp would shuffle between pages.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    protected static function build_query(array $params): Builder
    {
        $query = Party_Model::query();

        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('name', 'LIKE', "%{$filter}%")
                    ->orWhere('email', 'LIKE', "%{$filter}%")
                    ->orWhere('phone', 'LIKE', "%{$filter}%");
            });
        }

        // Quick filter from the card header.
        if (!empty($params['type_id'])) {
            $query->where('type_id', (int) $params['type_id']);
        }

        return $query;
    }
}
