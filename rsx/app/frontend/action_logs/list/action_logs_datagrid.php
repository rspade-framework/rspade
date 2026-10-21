<?php


namespace Rsx\App\Frontend\ActionLogs\List;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use Rsx\Models\Action_Log_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Action_Logs_DataGrid - Action log list datagrid implementation
 *
 * Displays action history with actor, subject, and rendered action text.
 */
class Action_Logs_DataGrid extends DataGrid_Abstract
{
    /**
     * Columns that can be sorted
     */
    protected static array $sortable_columns = [
        'created_at',
        'subject_type',
    ];

    /**
     * subject_type is a type-ref id, so sorting by it groups the log by the kind of record
     * that was acted on - which is what the Target Type column is for. Neither it nor
     * created_at is unique, so id breaks the tie and a group reads newest-first.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    /**
     * Default sort column
     */
    protected static ?string $default_sort = 'created_at';

    /**
     * Default sort order
     */
    protected static string $default_order = 'desc';

    /**
     * Build the query for fetching action logs
     *
     * @param array $params Request parameters (filters, search, etc.)
     * @return Builder Query builder instance
     */
    protected static function build_query(array $params): Builder
    {
        $query = Action_Log_Model::query();

        // The search box matches the ACTION (a type label, e.g. "client created") and the
        // ACTOR (the login identity's email, or this site's name for that person).
        if (!empty($params['filter'])) {
            $filter = trim((string) $params['filter']);
            $like = '%' . $filter . '%';

            $type_ids = static::_type_ids_matching($filter);

            // Actors are login identities. A person's NAME lives on their membership of
            // this site (users is site-scoped by its model), their address on either row.
            $actor_ids_by_name = User_Model::query()
                ->select('login_user_id')
                ->whereNotNull('login_user_id')
                ->where(function ($q) use ($like) {
                    $q->where(DB::raw("CONCAT_WS(' ', first_name, last_name)"), 'LIKE', $like)
                        ->orWhere('email', 'LIKE', $like);
                });
            $actor_ids_by_email = Login_User_Model::query()
                ->select('id')
                ->where('email', 'LIKE', $like);

            $actor_type = Type_Ref_Registry::class_to_id('Login_User_Model');

            $query->where(function ($q) use ($type_ids, $actor_type, $actor_ids_by_name, $actor_ids_by_email) {
                $q->whereIn('type_id', $type_ids)
                    ->orWhere(function ($actor) use ($actor_type, $actor_ids_by_name, $actor_ids_by_email) {
                        $actor->where('actor_type', $actor_type)
                            ->where(function ($ids) use ($actor_ids_by_name, $actor_ids_by_email) {
                                $ids->whereIn('actor_id', $actor_ids_by_name)
                                    ->orWhereIn('actor_id', $actor_ids_by_email);
                            });
                    });
            });
        }

        return $query;
    }

    /**
     * The action types whose label contains the search term, case-insensitively.
     *
     * @param string $term
     * @return int[]
     */
    private static function _type_ids_matching(string $term): array
    {
        $ids = [];
        foreach (Action_Log_Model::$enums['type_id'] as $id => $definition) {
            if (stripos($definition['label'], $term) !== false) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Transform records after fetching
     *
     * Add rendered action text, actor name, and subject info.
     *
     * @param array $records
     * @param array $params
     * @return array
     */
    protected static function transform_records(array $records, array $params): array
    {
        foreach ($records as &$record) {
            // Load the model to get render() and relationships
            $log = Action_Log_Model::find($record['id']);

            if ($log) {
                // Use model methods for computed display values
                $record['render'] = $log->render();
                $record['actor_display'] = $log->actor_display();
                $record['subject_display'] = $log->subject_display();
                $record['subject_type_display'] = class_basename($log->subject_type);
            }
        }

        return $records;
    }
}
