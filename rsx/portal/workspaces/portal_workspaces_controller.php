<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Workspaces;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Portal_Permission;

/**
 * Portal_Workspaces_Controller - the portal-authed surface for the "My Workspaces"
 * navigation shell: the list of clients (workspaces) the logged-in portal user is a
 * member of, and the per-workspace header for the /workspace/:id detail page.
 *
 * A portal account can hold many client memberships (Portal_Membership_Model); these
 * are the user's workspaces. Both endpoints derive the user from the SESSION and
 * resolve memberships through Portal_Permission - never from a client-supplied user
 * id - so a user can only ever see their own workspaces, and get() fails closed for a
 * client the caller is not a member of.
 *
 * Authorization: the class-level #[Auth('is_logged_in')] gate (portal realm) admits
 * only a logged-in portal user, at the framework seam, before any code here runs. The
 * record layer stays inline: list() returns only the caller's memberships; get()
 * re-validates membership with Portal_Permission::has_client_access().
 *
 * Only portal-safe fields are exposed (client name, the caller's role, client status
 * label) - no staff-only client columns.
 */
#[Auth('is_logged_in')]
class Portal_Workspaces_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: the current portal user's workspaces (client memberships).
     *
     * For each membership, the client is resolved server-side and only portal-safe
     * fields are returned. The role label comes from the membership role_id enum; the
     * status label from the client status_id enum.
     */
    #[Ajax_Endpoint]
    public static function list(Request $request, array $params = [])
    {
        $portal_user_id = Portal_Permission::current_user_id();

        $workspaces = [];
        foreach (Portal_Membership_Model::get_for_user($portal_user_id) as $membership) {
            $client = Client_Model::find($membership->client_id);
            if (!$client) {
                // Membership pointing at a missing/deleted client - skip it.
                continue;
            }

            $workspaces[] = [
                'client_id' => (int) $membership->client_id,
                'name' => $client->name,
                'role_id' => (int) $membership->role_id,
                'role_label' => $membership->role_id__label,
                'status_label' => $client->status_id__label,
            ];
        }

        return ['workspaces' => $workspaces];
    }

    /**
     * Ajax endpoint: a single workspace, used by the workspace sublayout header AND
     * the Overview tab.
     *
     * Fail-closed: a caller who is not a member of the requested client gets a
     * not-found response, so the sublayout/Overview renders its access-denied error
     * state.
     *
     * The 'vitals' block is the Overview tab's "key client vitals" summary. These are
     * DEMO/placeholder values derived from portal-safe client columns - in a real app
     * each firm customizes which engagement vitals to surface here. No staff-only
     * columns are exposed.
     */
    #[Ajax_Endpoint]
    public static function get(Request $request, array $params = [])
    {
        $client_id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($client_id <= 0) {
            return response_not_found('Workspace not found');
        }

        if (!Portal_Permission::has_client_access($client_id)) {
            return response_not_found('Workspace not found');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_not_found('Workspace not found');
        }

        $role_id = Portal_Permission::client_role($client_id);
        $membership = Portal_Membership_Model::find_for_user_and_client(
            Portal_Permission::current_user_id(),
            $client_id
        );
        $role_label = $membership ? $membership->role_id__label : '';

        return [
            'client_id' => $client_id,
            'name' => $client->name,
            'role_id' => $role_id,
            'role_label' => $role_label,

            // Overview vitals (demo/placeholder - portal-safe client fields only).
            'vitals' => [
                'status_label' => $client->status_id__label,
                'status_badge' => $client->status_id__badge,
                'role_label' => $role_label,
                'client_since' => $client->created_at,
                'member_since' => $membership ? $membership->created_at : null,
                'engagement_type' => $client->industry ?: 'General engagement',
                'primary_contact' => $client->email ?: 'Not on file',
            ],
        ];
    }
}
