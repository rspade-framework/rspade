<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Invitations;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Portal_Notification_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Portal_Permission;

/**
 * Portal_Invitations_Controller - the portal-authed surface for per-client
 * invitations the logged-in portal user must ACCEPT.
 *
 * Under the per-client access model, inviting an EXISTING portal account to a new
 * client does NOT auto-create the membership - it creates a pending invitation the
 * user accepts here (or declines). Accepting creates the Portal_Membership from the
 * invitation metadata; declining marks the invitation used.
 *
 * Authorization: the class-level #[Auth('is_logged_in')] gate (portal realm) admits
 * only a logged-in portal user. Every method resolves the pending set from the SESSION user's email + site (never
 * from a client-supplied id), and accept/decline re-validate that the target
 * invitation belongs to the caller - so a user can only act on their own invites.
 */
#[Auth('is_logged_in')]
class Portal_Invitations_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: the current portal user's pending per-client invitations.
     *
     * Pending = addressed to the caller's email + site, unused, unexpired, and for a
     * client the caller is NOT already a member of. Also returns has_client_access so
     * the dashboard can show the "no active client access" empty state.
     */
    #[Ajax_Endpoint]
    public static function pending(Request $request, array $params = [])
    {
        $invitations = [];
        foreach (static::_pending_for_current_user() as $invitation) {
            $client_id = (int) $invitation->get_metadata('client_id');
            $client = $client_id ? Client_Model::find($client_id) : null;

            $invitations[] = [
                'invitation_id' => $invitation->id,
                'client_id' => $client_id,
                'client_name' => $client ? $client->name : 'a client',
                'created_at' => $invitation->created_at,
                'expires_at' => $invitation->expires_at,
            ];
        }

        return [
            'invitations' => $invitations,
            'has_client_access' => count(Portal_Permission::accessible_client_ids()) > 0,
        ];
    }

    /**
     * Ajax endpoint: accept a pending invitation -> create the client membership.
     */
    #[Ajax_Endpoint]
    public static function accept(Request $request, array $params = [])
    {
        if (Portal_Permission::is_read_only()) {
            return response_unauthorized('This is a read-only session; changes are disabled.');
        }

        $invitation = static::_resolve_own_invitation($params['invitation_id'] ?? null);
        if ($invitation instanceof \App\RSpade\Core\Response\Error_Response) {
            return $invitation;
        }

        $portal_user_id = Portal_Permission::current_user_id();
        $client_id = (int) $invitation->get_metadata('client_id');
        $role_id = (int) ($invitation->get_metadata('role_id') ?: Portal_Membership_Model::ROLE_VIEWER);

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Invitation is missing its client');
        }

        // Idempotent: if a membership already exists, just consume the invitation.
        if (!Portal_Membership_Model::has_membership($portal_user_id, $client_id)) {
            $membership = new Portal_Membership_Model();
            $membership->site_id = (int) $invitation->site_id;
            $membership->portal_user_id = $portal_user_id;
            $membership->client_id = $client_id;
            $membership->role_id = $role_id;
            $membership->save();
        }

        $invitation->mark_used();

        // Notify the user (T4) that access is now active.
        $client = Client_Model::find($client_id);
        Portal_Notification_Model::emit(
            $portal_user_id,
            'membership_accepted',
            [
                'site_id' => (int) $invitation->site_id,
                'payload' => [
                    'title' => 'Access granted',
                    'body' => 'You now have access to ' . ($client ? $client->name : 'a client') . "'s portal.",
                ],
            ]
        );

        return [
            'invitation_id' => $invitation->id,
            'client_id' => $client_id,
            'message' => 'Invitation accepted',
        ];
    }

    /**
     * Ajax endpoint: decline a pending invitation (mark it used; no membership).
     */
    #[Ajax_Endpoint]
    public static function decline(Request $request, array $params = [])
    {
        if (Portal_Permission::is_read_only()) {
            return response_unauthorized('This is a read-only session; changes are disabled.');
        }

        $invitation = static::_resolve_own_invitation($params['invitation_id'] ?? null);
        if ($invitation instanceof \App\RSpade\Core\Response\Error_Response) {
            return $invitation;
        }

        $invitation->mark_used();

        return [
            'invitation_id' => $invitation->id,
            'message' => 'Invitation declined',
        ];
    }

    /**
     * The pending invitations addressed to the current portal user (email + site,
     * or linked contact), excluding clients they are already a member of.
     *
     * @return \Illuminate\Support\Collection
     */
    private static function _pending_for_current_user(): \Illuminate\Support\Collection
    {
        $user = Portal_Permission::current_user();
        if (!$user) {
            return new \Illuminate\Support\Collection();
        }

        // An existing account is matched to its invitations two ways, unioned and
        // de-duplicated: by the email the invite was addressed to (the contact's
        // email == the account's email) AND by its linked CRM contact_id (the
        // invitation metadata carries contact_id). Both resolve to the same person.
        $by_email = Portal_Invitation_Model::get_pending_for_email((int) $user->site_id, $user->email);

        $combined = $by_email->keyBy('id');

        if (!empty($user->contact_id)) {
            $contact_id = (int) $user->contact_id;
            foreach (Portal_Invitation_Model::get_pending_for_site((int) $user->site_id) as $invitation) {
                if ((int) $invitation->get_metadata('contact_id') === $contact_id) {
                    $combined->put($invitation->id, $invitation);
                }
            }
        }

        return $combined->reject(function ($invitation) use ($user) {
            $client_id = (int) $invitation->get_metadata('client_id');

            return $client_id && Portal_Membership_Model::has_membership($user->id, $client_id);
        })->values();
    }

    /**
     * Resolve an invitation id to a pending invitation owned by the caller.
     *
     * Returns an Error_Response if missing / not owned by the caller, so a user can
     * only accept or decline their own invitations.
     *
     * @param mixed $invitation_id
     * @return Portal_Invitation_Model|\App\RSpade\Core\Response\Error_Response
     */
    private static function _resolve_own_invitation($invitation_id)
    {
        if (!$invitation_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Invitation id is required');
        }

        $invitation = static::_pending_for_current_user()
            ->firstWhere('id', (int) $invitation_id);

        if (!$invitation) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Invitation not found');
        }

        return $invitation;
    }
}
