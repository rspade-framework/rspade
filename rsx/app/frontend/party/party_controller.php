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
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\App\Frontend\Party\List\Party_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Lib\FieldChecks\Field_Checks;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Party_Company_Detail_Model;
use Rsx\Models\Party_Model;
use Rsx\Models\Party_Person_Detail_Model;

/**
 * Frontend_Party_Controller - Ajax endpoints for the Party CRUD demo (Class-Table
 * Inheritance reference; see rsx:man detail_tables). save() writes the base + the active
 * detail inside one Form_Edit::run(), filling the detail through the auto-vivifying accessor.
 */
#[Auth('is_logged_in', 'can_view_data')]
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
     *
     * The action log is for administrators: the same can_view_user_activity check the
     * Action Log screen carries, so a per-record feed never shows what that screen refuses.
     */
    #[Auth('can_view_user_activity')]
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
    #[Auth('can_edit_data')]
    #[Writer]
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
     *
     * The type is chosen on create and immutable thereafter: an edit validates and writes
     * against the STORED type, whatever type_id the submission carries.
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        $party_id = $params['id'] ?? null;

        if ($party_id) {
            $party = Party_Model::find($party_id);
            if (!$party) {
                return response_error(Ajax::ERROR_NOT_FOUND, 'Party not found');
            }
            $type_id = (int) $party->type_id;
        } else {
            $type_id = (int) ($params['type_id'] ?? 0);
            $party = new Party_Model();
            $party->site_id = Session::get_site_id() ?: 1;
            $party->type_id = $type_id; // only set on create (immutable thereafter)
        }

        $errors = static::__validate($type_id, $params);
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        return Form_Edit::run($party, $params, function (Form_Edit $edit) use ($party, $party_id, $type_id, $params) {
            // Universal fields. `name` is composed for Person, the legal name for Company,
            // and typed directly for Group - written whenever a field it comes from changed.
            $edit->set('email', $params['email'] ?? null);
            $edit->set('phone', $params['phone'] ?? null);
            $edit->set('notes', $params['notes'] ?? null);

            foreach (static::__name_sources($type_id) as $source) {
                if ($edit->changed($source)) {
                    $party->name = static::__compose_name($type_id, $params);
                    break;
                }
            }

            $party->save();

            // Type-specific detail, filled through the auto-vivifying accessor. A detail
            // column is not on the record Form_Edit guards, so changed() decides each one.
            if ($type_id === Party_Model::TYPE_PERSON) {
                static::__write_detail($edit, $party->party_person, [
                    'first_name' => $params['first_name'],
                    'last_name' => $params['last_name'],
                    'title' => $params['title'] ?? null,
                    'date_of_birth' => !empty($params['date_of_birth']) ? $params['date_of_birth'] : null,
                ]);
            } elseif ($type_id === Party_Model::TYPE_COMPANY) {
                static::__write_detail($edit, $party->party_company, [
                    'legal_name' => $params['legal_name'],
                    'tax_identifier' => $params['tax_identifier'] ?? null,
                    'industry' => $params['industry'] ?? null,
                    'employee_count' => !empty($params['employee_count']) ? (int) $params['employee_count'] : null,
                ]);
            }

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
        }, $party->exists ? Rsx::Route('Party_View_Action', $party->id) : null);
    }

    /** Write the changed fields of a detail row. */
    private static function __write_detail(Form_Edit $edit, $detail, array $values): void
    {
        foreach ($values as $field => $value) {
            if ($edit->changed($field)) {
                $detail->{$field} = $value;
            }
        }

        $detail->save();
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
            $errors += Field_Checks::length_errors(Party_Person_Detail_Model::class, $params, [
                'first_name' => 'First name',
                'last_name' => 'Last name',
                'title' => 'Title',
            ]);
            if (!empty($params['date_of_birth']) && !Rsx_Date::is_date($params['date_of_birth'])) {
                $errors['date_of_birth'] = 'Date of birth must be a date (YYYY-MM-DD)';
            }
            // The composed name is stored on the base row too.
            if (!isset($errors['first_name']) && !isset($errors['last_name'])) {
                $errors += Field_Checks::length_errors(Party_Model::class, ['name' => static::__compose_name($type_id, $params)], [
                    'name' => 'First and last name together',
                ]);
                if (isset($errors['name'])) {
                    $errors['last_name'] = $errors['name'];
                    unset($errors['name']);
                }
            }
        } elseif ($type_id === Party_Model::TYPE_COMPANY) {
            if (empty($params['legal_name'])) {
                $errors['legal_name'] = 'Legal name is required';
            }
            $errors += Field_Checks::length_errors(Party_Company_Detail_Model::class, $params, [
                'legal_name' => 'Legal name',
                'tax_identifier' => 'Tax identifier',
                'industry' => 'Industry',
            ]);
            $employees = $params['employee_count'] ?? null;
            if ($employees !== null && $employees !== ''
                && !(is_int($employees) || (is_string($employees) && ctype_digit($employees)))) {
                $errors['employee_count'] = 'Employee count must be a whole number of zero or more';
            }
        } elseif ($type_id === Party_Model::TYPE_GROUP) {
            if (empty($params['name'])) {
                $errors['name'] = 'Group name is required';
            }
            $errors += Field_Checks::length_errors(Party_Model::class, $params, ['name' => 'Group name']);
        } else {
            $errors['type_id'] = 'Unknown party type';
        }

        // Universal fields.
        $errors += Field_Checks::length_errors(Party_Model::class, $params, [
            'email' => 'Email',
            'phone' => 'Phone',
        ]);
        if (!empty($params['email']) && !isset($errors['email']) && !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        }

        return $errors;
    }

    /** The submitted fields a party's `name` is made from, by type. */
    private static function __name_sources(int $type_id): array
    {
        return match ($type_id) {
            Party_Model::TYPE_PERSON => ['first_name', 'last_name'],
            Party_Model::TYPE_COMPANY => ['legal_name'],
            default => ['name'],
        };
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
