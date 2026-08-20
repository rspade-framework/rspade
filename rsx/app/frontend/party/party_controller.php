<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace Rsx\App\Frontend\Party;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use Rsx\App\Frontend\Party\List\Party_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Party_Model;

/**
 * Frontend_Party_Controller - Ajax endpoints for the Party CRUD demo (Class-Table
 * Inheritance reference; see man detail_tables). save() writes the base + the active detail
 * in one DB::transaction, filling the detail through the auto-vivifying accessor.
 */
#[Auth('is_logged_in')]
class Frontend_Party_Controller extends Rsx_Controller_Abstract
{
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Party_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: Get the activity feed (action log) for a party.
     *
     * Shared Activity-tab shape (id, rendered summary html, created_at, type_id).
     */
    #[Ajax_Endpoint]
    public static function party_activity(Request $request, array $params = [])
    {
        $party_id = $params['id'] ?? null;
        if (!$party_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Party ID is required');
        }

        $party = Party_Model::withTrashed()->find($party_id);
        if (!$party) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Party not found');
        }

        $activity = [];
        foreach (Action_Log::get_for_entity($party, 50) as $log) {
            $activity[] = [
                'id' => $log->id,
                'html' => $log->render(),
                'created_at' => $log->created_at,
                'type_id' => $log->type_id,
            ];
        }

        return ['activity' => $activity];
    }

    /**
     * Delete a Party (soft delete). The detail row is retained on soft delete and removed by
     * the FK cascade on a hard delete.
     */
    #[Ajax_Endpoint]
    public static function delete(Request $request, array $params = [])
    {
        $party = Party_Model::find($params['id'] ?? 0);
        if (!$party) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Party not found');
        }

        $party->delete();

        // Record action log
        Action_Log::record(Action_Log_Model::TYPE_PARTY_DELETED, $party);

        return ['deleted' => true];
    }

    /**
     * Create or update a Party (base + the type-specific detail) atomically.
     */
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        $party_id = $params['id'] ?? null;
        $type_id = (int) ($params['type_id'] ?? 0);

        $errors = static::__validate($type_id, $params);
        if (!empty($errors)) {
            return response_error(Ajax::ERROR_VALIDATION, $errors);
        }

        if ($party_id) {
            $party = Party_Model::find($party_id);
            if (!$party) {
                return response_error(Ajax::ERROR_NOT_FOUND, 'Party not found');
            }
            $type_id = (int) $party->type_id; // type_id is immutable - keep the stored value
        }

        DB::transaction(function () use (&$party, $party_id, $type_id, $params) {
            if (!$party_id) {
                $party = new Party_Model();
                $party->site_id = Session::get_site_id() ?: 1;
                $party->type_id = $type_id; // only set on create (immutable thereafter)
            }

            // Universal fields. `name` is composed for Person, the legal name for Company.
            $party->email = $params['email'] ?? null;
            $party->phone = $params['phone'] ?? null;
            $party->notes = $params['notes'] ?? null;
            $party->name = static::__compose_name($type_id, $params);
            $party->save();

            // Type-specific detail, filled through the auto-vivifying accessor.
            if ($type_id === Party_Model::TYPE_PERSON) {
                $detail = $party->party_person;
                $detail->first_name = $params['first_name'];
                $detail->last_name = $params['last_name'];
                $detail->title = $params['title'] ?? null;
                $detail->date_of_birth = !empty($params['date_of_birth']) ? $params['date_of_birth'] : null;
                $detail->save();
            } elseif ($type_id === Party_Model::TYPE_COMPANY) {
                $detail = $party->party_company;
                $detail->legal_name = $params['legal_name'];
                $detail->tax_identifier = $params['tax_identifier'] ?? null;
                $detail->industry = $params['industry'] ?? null;
                $detail->employee_count = !empty($params['employee_count']) ? (int) $params['employee_count'] : null;
                $detail->save();
            }
        });

        // Record action log
        Action_Log::record(
            $party_id ? Action_Log_Model::TYPE_PARTY_UPDATED : Action_Log_Model::TYPE_PARTY_CREATED,
            $party
        );

        return [
            'party_id' => $party->id,
            'message' => $party_id ? 'Party updated' : 'Party created',
            'redirect' => Rsx::Route('Party_View_Action', $party->id),
        ];
    }

    /**
     * Per-type validation (the "required field" rule lives at this layer, not the ORM).
     */
    private static function __validate(int $type_id, array $params): array
    {
        $errors = [];

        if ($type_id === Party_Model::TYPE_PERSON) {
            if (empty($params['first_name'])) {
                $errors['first_name'] = 'First name is required';
            }
            if (empty($params['last_name'])) {
                $errors['last_name'] = 'Last name is required';
            }
        } elseif ($type_id === Party_Model::TYPE_COMPANY) {
            if (empty($params['legal_name'])) {
                $errors['legal_name'] = 'Legal name is required';
            }
        } elseif ($type_id === Party_Model::TYPE_GROUP) {
            if (empty($params['name'])) {
                $errors['name'] = 'Group name is required';
            }
        } else {
            $errors['type_id'] = 'Unknown party type';
        }

        return $errors;
    }

    private static function __compose_name(int $type_id, array $params): string
    {
        if ($type_id === Party_Model::TYPE_PERSON) {
            return trim(($params['first_name'] ?? '') . ' ' . ($params['last_name'] ?? ''));
        }
        if ($type_id === Party_Model::TYPE_COMPANY) {
            return $params['legal_name'];
        }
        return $params['name'];
    }
}
