<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Settings\PortalUsers\List;

use Illuminate\Database\Eloquent\Builder;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Session\Session;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Theme\Components\Datagrid\DataGrid_Abstract;

/**
 * Portal_Users_DataGrid - global portal-user admin listing.
 *
 * Lists ALL portal users for the staff member's current site (across every
 * client), with status, last login, the linked contact, and the user's client
 * membership(s). Mirrors Users_DataGrid (staff user management).
 *
 * Security: rows are constrained to the logged-in staff member's site_id.
 */
class Portal_Users_DataGrid extends DataGrid_Abstract
{
    protected static ?string $default_sort = 'id';

    protected static string $default_order = 'desc';

    protected static array $sortable_columns = [
        'id',
        'email',
        'status_id',
        'last_login',
    ];

    /**
     * status_id has a handful of values and last_login repeats (every never-logged-in user
     * is NULL), so id breaks the tie and keeps paging stable.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    /**
     * Build the query for fetching portal users, scoped to the current site.
     */
    protected static function build_query(array $params): Builder
    {
        $query = Portal_User_Model::query();

        // Security: only portal users on the staff member's current site.
        $current_site_id = Session::get_site_id();
        if ($current_site_id) {
            $query->where('site_id', $current_site_id);
        }

        // Filter across email.
        if (!empty($params['filter'])) {
            $filter = $params['filter'];
            $query->where(function ($q) use ($filter) {
                $q->where('email', 'LIKE', "%{$filter}%");
            });
        }

        return $query;
    }

    /**
     * Add the linked contact name and client membership(s) to each row.
     */
    protected static function transform_records(array $records, array $params): array
    {
        foreach ($records as &$record) {
            $contact = !empty($record['contact_id']) ? Contact_Model::find($record['contact_id']) : null;
            $record['contact_name'] = $contact
                ? trim($contact->first_name . ' ' . $contact->last_name)
                : null;

            $memberships = [];
            foreach (Portal_Membership_Model::get_for_user((int) $record['id']) as $membership) {
                $client = Client_Model::find($membership->client_id);
                $memberships[] = [
                    'membership_id' => $membership->id,
                    'client_id' => $membership->client_id,
                    'client_name' => $client ? $client->name : null,
                    'role_id__label' => $membership->role_id__label,
                    'role_id__badge' => $membership->role_id__badge,
                ];
            }
            $record['memberships'] = $memberships;
        }

        return $records;
    }
}
