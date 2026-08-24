<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Clients;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Frontend\Clients\List\Clients_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Lib\Portal_Demo_Autoshare;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Announcement_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Models\Portal_Project_Model;
use Rsx\Models\Portal_Request_Document_Model;
use Rsx\Models\Portal_Request_Thread_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Shared_Item_Model;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Clients_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        // Call static fetch method on DataGrid
        return Clients_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: Get contacts for a client
     */
    #[Ajax_Endpoint]
    public static function client_contacts(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $contacts = Contact_Model::where('client_id', $client_id)
            ->orderBy('first_name')
            ->get();

        $result = [];
        foreach ($contacts as $contact) {
            $result[] = [
                'id' => $contact->id,
                'full_name' => $contact->full_name(),
                'email' => $contact->email,
                'phone_work' => $contact->phone_work,
                'phone_cell' => $contact->phone_cell,
                'title' => $contact->title,
                'is_active' => $contact->is_active,
            ];
        }

        return ['contacts' => $result];
    }

    /**
     * Ajax endpoint: Get projects for a client
     */
    #[Ajax_Endpoint]
    public static function client_projects(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $projects = Project_Model::where('client_id', $client_id)
            ->orderBy('name')
            ->get();

        $result = [];
        foreach ($projects as $project) {
            $result[] = [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'status__label' => $project->status__label,
                'status__badge' => $project->status__badge,
                'priority' => $project->priority,
                'priority__label' => $project->priority__label,
                'priority__badge' => $project->priority__badge,
                'due_date' => $project->due_date,
            ];
        }

        return ['projects' => $result];
    }

    /**
     * Ajax endpoint: Get the activity feed (action log) for a client.
     *
     * Shared Activity-tab shape (id, rendered summary html, created_at, type_id).
     */
    #[Ajax_Endpoint]
    public static function client_activity(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::withTrashed()->find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $activity = [];
        foreach (Action_Log::get_for_entity($client, 50) as $log) {
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
     * Ajax endpoint: Save client (add or edit)
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        // Validate required fields
        $errors = [];

        if (empty($params['name'])) {
            $errors['name'] = 'Company name is required';
        }

        if (!empty($params['email']) && !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        }

        // Convert website to short URL format and validate
        if (!empty($params['website'])) {
            $short_url = full_url_to_short_url($params['website']);
            if (!validate_short_url($short_url)) {
                $errors['website'] = 'Please enter a valid website URL';
            }
            // Update params with short URL for storage
            $params['website'] = $short_url;
        }

        // Return validation errors if any
        if (!empty($errors)) {
            return response_error(Ajax::ERROR_VALIDATION, $errors);
        }

        // Determine if this is create or update
        $client_id = $params['id'] ?? null;

        if ($client_id) {
            // Update existing client
            $client = Client_Model::find($client_id);
            if (!$client) {
                return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
            }
        } else {
            // Create new client
            $client = new Client_Model();
        }

        // Set all fields explicitly (no mass assignment)
        $client->name = $params['name'];
        $client->website = $params['website'] ?? null;
        $client->email = $params['email'] ?? null;
        $client->phone = $params['phone'] ?? null;
        $client->fax = $params['fax'] ?? null;

        // Address fields
        $client->address_street = $params['address_street'] ?? null;
        $client->city = $params['city'] ?? null;
        $client->state = $params['state'] ?? null;
        $client->zip = $params['zip'] ?? null;
        $client->address_country = $params['address_country'] ?? 'USA';

        // Business details
        $client->industry = $params['industry'] ?? null;
        $client->company_size = $params['company_size'] ?? null;
        $client->established_year = !empty($params['established_year']) ? (int)$params['established_year'] : null;
        $client->revenue_range = $params['revenue_range'] ?? null;

        // Social media
        $client->facebook_url = $params['facebook_url'] ?? null;
        $client->twitter_handle = $params['twitter_handle'] ?? null;
        $client->linkedin_url = $params['linkedin_url'] ?? null;
        $client->instagram_handle = $params['instagram_handle'] ?? null;

        // Additional info
        $client->status_id = $params['status_id'] ?? Client_Model::STATUS_ACTIVE;
        $client->preferred_contact_method = $params['preferred_contact_method'] ?? 'email';
        $client->newsletter_opt_in = !empty($params['newsletter_opt_in']) ? 1 : 0;
        $client->notes = $params['notes'] ?? null;

        // Tags (convert array to JSON)
        if (!empty($params['tags']) && is_array($params['tags'])) {
            $client->tags = json_encode($params['tags']);
        } else {
            $client->tags = null;
        }

        $client->save();

        // Record action log
        Action_Log::record(
            $client_id ? Action_Log_Model::TYPE_CLIENT_UPDATED : Action_Log_Model::TYPE_CLIENT_CREATED,
            $client
        );

        // Flash success message for display after redirect
        Flash_Alert::success($client_id ? 'Client updated successfully' : 'Client created successfully');

        return [
            'client_id' => $client->id,
            'redirect' => Rsx::Route('Clients_View_Action', $client->id),
        ];
    }

    /**
     * Ajax endpoint: Delete client (soft delete)
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function delete(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, ['message' => 'Client ID is required']);
        }

        $client = Client_Model::find($client_id);

        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        // Soft delete the client
        $client->delete();

        // Record action log
        Action_Log::record(Action_Log_Model::TYPE_CLIENT_DELETED, $client);

        return [
            'message' => 'Client deleted successfully',
        ];
    }

    /**
     * Ajax endpoint: Restore soft-deleted client
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function restore(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, ['message' => 'Client ID is required']);
        }

        $client = Client_Model::withTrashed()->find($client_id);

        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        if (!$client->trashed()) {
            return response_error(Ajax::ERROR_VALIDATION, ['message' => 'Client is not deleted']);
        }

        // Restore the client
        $client->restore();

        return [
            'message' => 'Client restored successfully',
        ];
    }

    /**
     * Ajax endpoint: load a SOFT-DELETED client for the client view screen.
     *
     * The generic ORM fetch (Client_Model::fetch) serves non-deleted records only, so a
     * deleted client's screen asks here instead - an explicit endpoint where the gate is
     * chosen for this specific situation. It carries the class gate the restore endpoint
     * runs under: whoever may restore a deleted client may see the deleted client they are
     * restoring. A live client is not this endpoint's business (the ORM path serves it) and
     * a missing one is indistinguishable from it: both answer with no record.
     */
    #[Ajax_Endpoint]
    public static function fetch_deleted(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, ['message' => 'Client ID is required']);
        }

        $client = Client_Model::withTrashed()->find($client_id);

        if (!$client || !$client->trashed()) {
            return ['client' => null];
        }

        return ['client' => $client->to_fetch_array()];
    }

    /**
     * Ajax endpoint: Toggle portal enabled/disabled for a client
     */
    #[Ajax_Endpoint]
    public static function toggle_portal(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        $enabled = !empty($params['enabled']);

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $client->portal_enabled = $enabled;
        $client->save();

        return ['portal_enabled' => $client->portal_enabled];
    }

    /**
     * Ajax endpoint: Get portal members for a client
     */
    #[Ajax_Endpoint]
    public static function portal_members(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $memberships = Portal_Membership_Model::get_for_client($client_id);
        $members = [];

        foreach ($memberships as $membership) {
            $portal_user = Portal_User_Model::find($membership->portal_user_id);
            if (!$portal_user) continue;

            $contact = $portal_user->contact_id ? Contact_Model::find($portal_user->contact_id) : null;

            $members[] = [
                'membership_id' => $membership->id,
                'portal_user_id' => $portal_user->id,
                'email' => $portal_user->email,
                'status_id' => $portal_user->status_id,
                'status_id__label' => $portal_user->status_id__label,
                'status_id__badge' => $portal_user->status_id__badge,
                'role_id' => $membership->role_id,
                'role_id__label' => $membership->role_id__label,
                'role_id__badge' => $membership->role_id__badge,
                'contact_name' => $contact ? trim($contact->first_name . ' ' . $contact->last_name) : null,
                'contact_id' => $portal_user->contact_id,
                'last_login' => $portal_user->last_login,
                'created_at' => $membership->created_at,
            ];
        }

        // Pending invitations for this client (live = unused, unexpired). Rendered
        // alongside active members so staff can Resend a pending invite. An invite
        // for a contact that has since become a member is filtered out.
        $pending_invites = [];
        foreach (Portal_Invitation_Model::get_pending_for_client((int) $client_id) as $invitation) {
            $contact_id = (int) $invitation->get_metadata('contact_id');
            if (!$contact_id) {
                continue;
            }
            $contact = Contact_Model::find($contact_id);
            if (!$contact) {
                continue;
            }

            $portal_user = static::_resolve_contact_portal_user($contact, (int) $client->site_id);
            if ($portal_user && Portal_Membership_Model::has_membership($portal_user->id, (int) $client_id)) {
                continue; // already a member - not a pending invite
            }

            $pending_invites[] = [
                'invitation_id' => $invitation->id,
                'contact_id' => $contact_id,
                'contact_name' => trim($contact->first_name . ' ' . $contact->last_name),
                'email' => $invitation->email,
                'has_portal_account' => $portal_user !== null,
                'expires_at' => $invitation->expires_at,
                'created_at' => $invitation->created_at,
                'url' => $invitation->get_invitation_url(),
            ];
        }

        return ['members' => $members, 'pending_invites' => $pending_invites];
    }

    /**
     * Ajax endpoint: Add a portal member to a client
     */
    #[Ajax_Endpoint]
    public static function portal_add_member(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        $contact_id = $params['contact_id'] ?? null;
        $role_id = $params['role_id'] ?? Portal_Membership_Model::ROLE_VIEWER;

        if (!$client_id || !$contact_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID and Contact ID are required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        if (!$client->portal_enabled) {
            return response_error(Ajax::ERROR_VALIDATION, 'Portal is not enabled for this client');
        }

        $contact = Contact_Model::find($contact_id);
        if (!$contact) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Contact not found');
        }

        // Find or create portal user for this contact
        $portal_user = Portal_User_Model::where('contact_id', $contact_id)->first();
        if (!$portal_user) {
            // Check if portal user exists by email
            $portal_user = Portal_User_Model::find_by_email($client->site_id, $contact->email);
        }

        if (!$portal_user) {
            return response_error(Ajax::ERROR_VALIDATION, 'This contact does not have a portal account yet. Send them an invitation first.');
        }

        // Check for existing membership
        if (Portal_Membership_Model::has_membership($portal_user->id, $client_id)) {
            return response_error(Ajax::ERROR_VALIDATION, 'This contact is already a portal member for this client');
        }

        $membership = new Portal_Membership_Model();
        $membership->site_id = $client->site_id;
        $membership->portal_user_id = $portal_user->id;
        $membership->client_id = $client_id;
        $membership->role_id = $role_id;
        $membership->save();

        return ['membership_id' => $membership->id];
    }

    /**
     * Ajax endpoint: Disable a portal member's access to a client.
     *
     * "Disable Access" = remove this client's membership. The portal account, the
     * contact, and the user's ability to log in all PERSIST - this is the everyday
     * per-client access control, NOT an account suspension (that is the global ban
     * on the Portal Users admin screen).
     */
    #[Ajax_Endpoint]
    public static function portal_remove_member(Request $request, array $params = [])
    {
        $membership_id = $params['membership_id'] ?? null;

        if (!$membership_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Membership ID is required');
        }

        $membership = Portal_Membership_Model::find($membership_id);
        if (!$membership) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Membership not found');
        }

        $membership->delete();

        return ['message' => 'Access removed'];
    }

    /**
     * Ajax endpoint: Update a portal member's role
     */
    #[Ajax_Endpoint]
    public static function portal_update_role(Request $request, array $params = [])
    {
        $membership_id = $params['membership_id'] ?? null;
        $role_id = $params['role_id'] ?? null;

        if (!$membership_id || !$role_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Membership ID and role are required');
        }

        $membership = Portal_Membership_Model::find($membership_id);
        if (!$membership) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Membership not found');
        }

        $membership->role_id = $role_id;
        $membership->save();

        return ['role_id' => $membership->role_id];
    }

    /**
     * Ajax endpoint: ALL of a client's contacts with their portal invite status.
     *
     * Powers the Add-Member modal table. Every contact carries an invite status so
     * the modal can render members as checked+disabled and pending invites as
     * "Invited":
     *   - accepted : the contact has a portal account AND an active membership for
     *                this client (the contact is already a member). last_login shown.
     *   - invited  : a live (unused, unexpired) per-client invitation exists for
     *                this contact + client.
     *   - blank    : neither - selectable to invite.
     */
    #[Ajax_Endpoint]
    public static function portal_contacts_with_status(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $contacts = Contact_Model::where('client_id', $client_id)
            ->where('is_active', true)
            ->orderBy('first_name')
            ->get();

        $result = [];
        foreach ($contacts as $contact) {
            $portal_user = static::_resolve_contact_portal_user($contact, (int) $client->site_id);

            $is_member = $portal_user
                && Portal_Membership_Model::has_membership($portal_user->id, (int) $client_id);

            $pending = Portal_Invitation_Model::find_pending_for_contact_client((int) $contact->id, (int) $client_id);

            if ($is_member) {
                $invite_status = 'accepted';
            } elseif ($pending) {
                $invite_status = 'invited';
            } else {
                $invite_status = 'blank';
            }

            $result[] = [
                'id' => $contact->id,
                'full_name' => $contact->full_name(),
                'email' => $contact->email,
                'has_portal_account' => $portal_user !== null,
                'invite_status' => $invite_status,
                'last_login' => $portal_user ? $portal_user->last_login : null,
            ];
        }

        return ['contacts' => $result];
    }

    /**
     * Ajax endpoint: Bulk-invite selected contacts to a client's portal.
     *
     * Per contact (per-client invite model):
     *   - no portal account  -> create an invitation + send the registration email.
     *     Registering onboards the account AND joins this client (the invitation
     *     metadata carries contact_id/client_id/role_id).
     *   - existing account   -> create a PENDING per-client invitation (NOT an
     *     auto-membership) + send a "you've been invited to <Client>" email. The
     *     logged-in portal user accepts it to gain the membership.
     *
     * Idempotent: contacts already members, or with a live pending invite, are
     * skipped. Returns per-bucket counts.
     */
    #[Ajax_Endpoint]
    public static function portal_bulk_invite(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        $contact_ids = $params['contact_ids'] ?? [];
        $role_id = $params['role_id'] ?? Portal_Membership_Model::ROLE_VIEWER;

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }
        if (!is_array($contact_ids) || empty($contact_ids)) {
            return response_error(Ajax::ERROR_VALIDATION, 'Select at least one contact to invite');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }
        if (!$client->portal_enabled) {
            return response_error(Ajax::ERROR_VALIDATION, 'Portal is not enabled for this client');
        }

        $invited_new = 0;     // brand-new account invitations (registration email)
        $invited_existing = 0; // pending per-client invitations for existing accounts
        $skipped = 0;          // already a member or already has a live invite

        foreach ($contact_ids as $contact_id) {
            $contact = Contact_Model::find($contact_id);
            if (!$contact || (int) $contact->client_id !== (int) $client_id) {
                $skipped++;
                continue;
            }
            if (empty($contact->email)) {
                $skipped++;
                continue;
            }

            $portal_user = static::_resolve_contact_portal_user($contact, (int) $client->site_id);

            // Already a member of this client -> nothing to do.
            if ($portal_user && Portal_Membership_Model::has_membership($portal_user->id, (int) $client_id)) {
                $skipped++;
                continue;
            }

            // Already has a live pending invite for this contact+client -> idempotent skip.
            if (Portal_Invitation_Model::find_pending_for_contact_client((int) $contact->id, (int) $client_id)) {
                $skipped++;
                continue;
            }

            $invitation = Portal_Invitation_Model::create_invitation(
                (int) $client->site_id,
                $contact->email,
                ['contact_id' => (int) $contact->id, 'client_id' => (int) $client_id, 'role_id' => (int) $role_id]
            );

            if ($portal_user) {
                // Existing account: a pending per-client invitation they will ACCEPT.
                static::_send_existing_account_invite_email($portal_user->email, $client);
                $invited_existing++;
            } else {
                // New person: registration link that onboards + joins this client.
                static::_send_new_account_invite_email($contact->email, $client, $invitation);
                $invited_new++;
            }
        }

        return [
            'invited_new' => $invited_new,
            'invited_existing' => $invited_existing,
            'skipped' => $skipped,
        ];
    }

    /**
     * Ajax endpoint: Resend a pending invitation for a contact + client.
     *
     * Re-sends the appropriate invite email (new-account registration link vs.
     * existing-account "you've been invited") and refreshes the invitation expiry.
     */
    #[Ajax_Endpoint]
    public static function portal_resend_invite(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        $contact_id = $params['contact_id'] ?? null;

        if (!$client_id || !$contact_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID and Contact ID are required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $contact = Contact_Model::find($contact_id);
        if (!$contact) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Contact not found');
        }

        $invitation = Portal_Invitation_Model::find_pending_for_contact_client((int) $contact_id, (int) $client_id);
        if (!$invitation) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'No pending invitation found for this contact');
        }

        $invitation->refresh_expiry();

        $portal_user = static::_resolve_contact_portal_user($contact, (int) $client->site_id);
        if ($portal_user) {
            static::_send_existing_account_invite_email($portal_user->email, $client);
        } else {
            static::_send_new_account_invite_email($contact->email, $client, $invitation);
        }

        return [
            'invitation_id' => $invitation->id,
            'message' => 'Invitation re-sent to ' . $contact->email,
        ];
    }

    /**
     * Ajax endpoint: Revoke (cancel) a pending portal invitation.
     *
     * Marks the invitation REVOKED so following its link shows a "cancelled" page
     * instead of registering, expiring, or hitting a dead "invalid" error. find()
     * is site-scoped, so an admin can only revoke invitations within their site.
     */
    #[Ajax_Endpoint]
    public static function portal_revoke_invite(Request $request, array $params = [])
    {
        $invitation_id = $params['invitation_id'] ?? null;
        if (!$invitation_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Invitation ID is required');
        }

        $invitation = Portal_Invitation_Model::find($invitation_id);
        if (!$invitation) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Invitation not found');
        }

        $invitation->revoke();

        return [
            'invitation_id' => $invitation->id,
            'message' => 'Invitation cancelled',
        ];
    }

    /**
     * Resolve the portal user for a contact (by FK, then by email within the site).
     *
     * @param Contact_Model $contact
     * @param int $site_id
     * @return Portal_User_Model|null
     */
    private static function _resolve_contact_portal_user(Contact_Model $contact, int $site_id): ?Portal_User_Model
    {
        $portal_user = Portal_User_Model::where('contact_id', $contact->id)->first();
        if ($portal_user) {
            return $portal_user;
        }

        if (!empty($contact->email)) {
            return Portal_User_Model::find_by_email($site_id, $contact->email);
        }

        return null;
    }

    /**
     * Send the new-account invitation email (registration link onboards + joins).
     *
     * @param string $email
     * @param Client_Model $client
     * @param Portal_Invitation_Model $invitation
     * @return void
     */
    private static function _send_new_account_invite_email(string $email, Client_Model $client, Portal_Invitation_Model $invitation): void
    {
        \App\RSpade\Core\Mail\Rsx_Mail::send(
            $email,
            'You\'re Invited to the ' . $client->name . ' Portal',
            'Portal_Invitation_Email',
            [
                'app_name' => config('rsx.name', 'RSpade'),
                'registration_url' => $invitation->get_invitation_url(),
                'expiry_days' => config('rsx.portal.invitation_expiry_days', 7),
            ],
            \App\RSpade\Core\Mail\Rsx_Mail::TRANSACTIONAL
        );
    }

    /**
     * Send the existing-account invitation email (login + accept a pending invite).
     *
     * @param string $email
     * @param Client_Model $client
     * @return void
     */
    private static function _send_existing_account_invite_email(string $email, Client_Model $client): void
    {
        \App\RSpade\Core\Mail\Rsx_Mail::send(
            $email,
            'You\'ve Been Invited to ' . $client->name . '\'s Portal',
            'Portal_Invitation_Email',
            [
                'app_name' => config('rsx.name', 'RSpade'),
                'registration_url' => rsx_absolute_url(\App\RSpade\Core\Portal\Rsx_Portal::Route('Portal_Login_Controller')),
                'expiry_days' => config('rsx.portal.invitation_expiry_days', 7),
                'existing_account' => true,
                'client_name' => $client->name,
            ],
            \App\RSpade\Core\Mail\Rsx_Mail::TRANSACTIONAL
        );
    }

    /**
     * Ajax endpoint: Get portal projects (which projects are visible on portal)
     */
    #[Ajax_Endpoint]
    public static function portal_projects(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        // Get all projects for this client
        $projects = Project_Model::where('client_id', $client_id)
            ->orderBy('name')
            ->get();

        $visible_ids = Portal_Project_Model::get_project_ids_for_client($client_id);

        $result = [];
        foreach ($projects as $project) {
            $result[] = [
                'id' => $project->id,
                'name' => $project->name,
                'status__label' => $project->status__label,
                'status__badge' => $project->status__badge,
                'portal_visible' => in_array($project->id, $visible_ids),
            ];
        }

        return ['projects' => $result];
    }

    /**
     * Ajax endpoint: Toggle project visibility on client portal
     */
    #[Ajax_Endpoint]
    public static function portal_toggle_project(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        $project_id = $params['project_id'] ?? null;

        if (!$client_id || !$project_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID and Project ID are required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $visible = Portal_Project_Model::toggle($client->site_id, $client_id, $project_id);

        return ['portal_visible' => $visible];
    }

    /**
     * Ajax endpoint: List recent announcements posted to a client's portal.
     *
     * Staff-only (gated by pre_dispatch). Returns the client's announcements newest
     * first, with publish state for display.
     */
    #[Ajax_Endpoint]
    public static function portal_announcements(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $announcements = [];
        foreach (Announcement_Model::recent_for_client((int) $client_id) as $announcement) {
            $announcements[] = [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'published_at' => $announcement->published_at,
                'is_published' => $announcement->is_published(),
                'created_at' => $announcement->created_at,
            ];
        }

        return ['announcements' => $announcements];
    }

    /**
     * Ajax endpoint: Post (compose + publish) an announcement to a client's portal.
     *
     * Staff-only. Persists the announcement then publishes it, which emits one
     * portal notification (T4) per active portal member of the client - landing the
     * announcement in each recipient's activity feed (T5).
     */
    #[Ajax_Endpoint]
    public static function portal_post_announcement(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        $title = trim($params['title'] ?? '');
        $body = trim($params['body'] ?? '');

        $errors = [];
        if (empty($title)) {
            $errors['title'] = 'Title is required';
        }
        if (empty($body)) {
            $errors['body'] = 'Message is required';
        }
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below', $errors);
        }

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        if (!$client->portal_enabled) {
            return response_error(Ajax::ERROR_VALIDATION, 'Portal is not enabled for this client');
        }

        $announcement = new Announcement_Model();
        $announcement->client_id = (int) $client_id;
        $announcement->title = $title;
        $announcement->body = $body;
        $announcement->save();

        $recipients = $announcement->publish();

        return [
            'announcement_id' => $announcement->id,
            'recipients' => $recipients,
        ];
    }

    // =====================================================================
    // Portal-user lifecycle management (shared by the global Settings >
    // Portal Users screen and the per-client portal members table).
    //
    // Staff-only: gated by this controller's pre_dispatch (Session::is_logged_in).
    // Each action loads the portal user scoped to the staff member's current
    // site, so a staff user can only act on portal users of their own site.
    // =====================================================================

    /**
     * Ajax endpoint: List ALL portal users for the current staff site.
     *
     * Powers the global Settings > Portal Users admin screen. Returns every
     * portal user on the site (across all clients) with email, status, last
     * login, the linked contact (if any), and the client membership(s) the user
     * belongs to. Newest accounts first.
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function portal_users_list(Request $request, array $params = [])
    {
        $site_id = Session::get_site_id();

        $portal_users = Portal_User_Model::where('site_id', $site_id)
            ->orderBy('id', 'desc')
            ->get();

        $users = [];
        foreach ($portal_users as $portal_user) {
            $contact = $portal_user->contact_id ? Contact_Model::find($portal_user->contact_id) : null;

            $memberships = [];
            foreach (Portal_Membership_Model::get_for_user($portal_user->id) as $membership) {
                $client = Client_Model::find($membership->client_id);
                $memberships[] = [
                    'membership_id' => $membership->id,
                    'client_id' => $membership->client_id,
                    'client_name' => $client ? $client->name : null,
                    'role_id__label' => $membership->role_id__label,
                    'role_id__badge' => $membership->role_id__badge,
                ];
            }

            $users[] = [
                'portal_user_id' => $portal_user->id,
                'email' => $portal_user->email,
                'status_id' => $portal_user->status_id,
                'status_id__label' => $portal_user->status_id__label,
                'status_id__badge' => $portal_user->status_id__badge,
                'is_verified' => $portal_user->is_verified,
                'last_login' => $portal_user->last_login,
                'contact_id' => $portal_user->contact_id,
                'contact_name' => $contact ? trim($contact->first_name . ' ' . $contact->last_name) : null,
                'memberships' => $memberships,
            ];
        }

        return ['users' => $users];
    }

    /**
     * Load a portal user scoped to the current staff site.
     *
     * Returns null if the id is missing or the row belongs to another site.
     *
     * @param mixed $portal_user_id
     * @return Portal_User_Model|null
     */
    private static function _find_site_portal_user($portal_user_id): ?Portal_User_Model
    {
        if (!$portal_user_id) {
            return null;
        }

        $site_id = Session::get_site_id();

        return Portal_User_Model::where('id', $portal_user_id)
            ->where('site_id', $site_id)
            ->first();
    }

    /**
     * Ajax endpoint: Suspend a portal user.
     *
     * Sets status_id to STATUS_SUSPENDED (which Portal_User_Model::can_login()
     * already treats as a login block) and terminates all of the user's active
     * portal sessions so the suspension takes effect immediately.
     */
    #[Ajax_Endpoint]
    public static function portal_user_suspend(Request $request, array $params = [])
    {
        $portal_user = static::_find_site_portal_user($params['portal_user_id'] ?? null);
        if (!$portal_user) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Portal user not found');
        }

        $portal_user->status_id = Portal_User_Model::STATUS_SUSPENDED;
        $portal_user->save();

        // Terminate active portal sessions so the block is effective immediately.
        $terminated = Portal_Session::terminate_all_sessions_for_user($portal_user->id);

        return [
            'portal_user_id' => $portal_user->id,
            'status_id' => $portal_user->status_id,
            'status_id__label' => $portal_user->status_id__label,
            'status_id__badge' => $portal_user->status_id__badge,
            'sessions_terminated' => $terminated,
        ];
    }

    /**
     * Ajax endpoint: Reactivate a suspended portal user.
     *
     * Sets status_id back to STATUS_ACTIVE, restoring login ability.
     */
    #[Ajax_Endpoint]
    public static function portal_user_reactivate(Request $request, array $params = [])
    {
        $portal_user = static::_find_site_portal_user($params['portal_user_id'] ?? null);
        if (!$portal_user) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Portal user not found');
        }

        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        return [
            'portal_user_id' => $portal_user->id,
            'status_id' => $portal_user->status_id,
            'status_id__label' => $portal_user->status_id__label,
            'status_id__badge' => $portal_user->status_id__badge,
        ];
    }

    // =====================================================================
    // CLIENT DOCUMENTS (firm -> client share).
    //
    // A "document" is a File_Attachment_Model attached to the client under the
    // 'documents' category. A "share" is a Shared_Item_Model row (one per
    // attachment x contact, item_type='File_Attachment_Model'). Sharing with a
    // not-yet-invited contact reuses the same invite path as portal_bulk_invite,
    // then emits a portal notification + sends the shared-content email to each
    // recipient that has a portal account.
    //
    // Staff-only: gated by this controller's pre_dispatch (Session::is_logged_in).
    // =====================================================================

    /**
     * The 'documents' category key under which client documents are attached.
     */
    const DOCUMENTS_CATEGORY = 'documents';

    /**
     * Ajax endpoint: list a client's shared documents (with share recipients).
     *
     * Each document is a File_Attachment in the client's 'documents' category. For
     * each, the active shares (Shared_Item rows for this attachment) are resolved to
     * the contacts they were shared with, noting whether that contact has a portal
     * account.
     */
    #[Ajax_Endpoint]
    public static function documents_list(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $documents = [];
        foreach ($client->get_attachments(static::DOCUMENTS_CATEGORY) as $attachment) {
            $shares = Shared_Item_Model::where('item_type', 'File_Attachment_Model')
                ->where('item_id', $attachment->id)
                ->orderBy('created_at')
                ->get();

            $shared_with = [];
            foreach ($shares as $share) {
                $contact = Contact_Model::find($share->contact_id);
                if (!$contact) {
                    continue;
                }
                $portal_user = static::_resolve_contact_portal_user($contact, (int) $client->site_id);

                $shared_with[] = [
                    'shared_item_id' => $share->id,
                    'contact_id' => $contact->id,
                    'contact_name' => $contact->full_name(),
                    'has_portal_account' => $portal_user !== null,
                    'shared_at' => $share->created_at,
                    'is_expired' => $share->is_expired(),
                ];
            }

            $documents[] = [
                'attachment_id' => $attachment->id,
                'key' => $attachment->key,
                'name' => $attachment->file_name,
                'download_url' => $attachment->get_download_url(),
                'uploaded_at' => $attachment->created_at,
                'shared_with' => $shared_with,
                'shared_count' => count($shared_with),
            ];
        }

        return ['documents' => $documents];
    }

    /**
     * Ajax endpoint: attach an uploaded file to the client as a document.
     *
     * The browser uploads the file to /_upload first (session-owned, unattached),
     * then calls this with the returned key. We validate assignability (same session
     * + site, not already attached) before attaching under the 'documents' category.
     */
    #[Ajax_Endpoint]
    public static function documents_add(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        $key = $params['key'] ?? null;

        if (!$client_id || !$key) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID and file key are required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $attachment = File_Attachment_Model::find_by_key($key);
        if (!$attachment || !$attachment->can_user_assign_this_file()) {
            return response_error(Ajax::ERROR_VALIDATION, 'File not found or access denied');
        }

        $attachment->add_to($client, static::DOCUMENTS_CATEGORY);

        // DEMO ONLY (dev sites): auto-share every uploaded document with the client so
        // the portal Documents tab is populated without manual sharing. Documents are
        // shared at the client level (visible to all the client's portal users); no-op
        // in real deployments. See Portal_Demo_Autoshare.
        Portal_Demo_Autoshare::share_document_with_client($client, $attachment);

        return [
            'attachment_id' => $attachment->id,
            'key' => $attachment->key,
            'name' => $attachment->file_name,
        ];
    }

    /**
     * Ajax endpoint: share a client document with one or more of its contacts.
     *
     * Per recipient contact:
     *   - create a Shared_Item (item_type='File_Attachment_Model') unless one already
     *     exists for this attachment + contact (idempotent skip).
     *   - if the contact is NOT yet invited (no portal account AND no live invite),
     *     trigger the same invite path portal_bulk_invite uses (per-client invitation
     *     + registration email) so they can register and view it.
     *   - if the contact has a portal account, emit a 'document_shared' notification
     *     and send the shared-content email.
     *
     * Returns per-bucket counts.
     */
    #[Ajax_Endpoint]
    public static function documents_share(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        // The DESIRED full set of contacts this document should be shared with. Set-based:
        // contacts in the set but not yet shared are added (+invited/notified); contacts
        // currently shared but absent from the set are removed. An empty set removes all.
        $contact_ids = $params['contact_ids'] ?? [];
        $message = trim($params['message'] ?? '');

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }
        if (!is_array($contact_ids)) {
            return response_error(Ajax::ERROR_VALIDATION, 'contact_ids must be an array');
        }
        $desired_ids = array_map('intval', $contact_ids);

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $attachment = static::_resolve_client_document($client, $params);
        if (!$attachment) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Document not found for this client');
        }

        $shared = 0;        // new shares created
        $already = 0;       // already shared (idempotent skip)
        $invited = 0;       // contacts newly invited (no portal account yet)
        $notified = 0;      // recipients with a portal account that got notified + emailed
        $removed = 0;       // shares removed (contacts dropped from the set)
        $shared_by = (int) Session::get_user_id();

        // Remove shares for any contact dropped from the desired set.
        $to_remove = Shared_Item_Model::where('item_type', 'File_Attachment_Model')
            ->where('item_id', $attachment->id)
            ->when(!empty($desired_ids), function ($q) use ($desired_ids) {
                $q->whereNotIn('contact_id', $desired_ids);
            })
            ->get();
        foreach ($to_remove as $share) {
            $share->forceDelete();
            $removed++;
        }

        foreach ($desired_ids as $contact_id) {
            $contact = Contact_Model::find($contact_id);
            if (!$contact || (int) $contact->client_id !== (int) $client_id || empty($contact->email)) {
                continue;
            }

            // Idempotent: skip if this exact document is already shared with the contact.
            $existing = Shared_Item_Model::where('item_type', 'File_Attachment_Model')
                ->where('item_id', $attachment->id)
                ->where('contact_id', $contact->id)
                ->first();
            if ($existing) {
                $already++;
                continue;
            }

            Shared_Item_Model::create_share(
                (int) $client->site_id,
                $shared_by,
                (int) $contact->id,
                'File_Attachment_Model',
                (int) $attachment->id,
                $message !== '' ? $message : null
            );
            $shared++;

            $portal_user = static::_resolve_contact_portal_user($contact, (int) $client->site_id);

            if ($portal_user) {
                // Existing portal account: notify in-feed + email the shared-content link.
                static::_notify_document_shared($portal_user, $client, $attachment);
                static::_send_shared_document_email($portal_user->email, $client, $attachment, $message);
                $notified++;
            } elseif (!Portal_Invitation_Model::find_pending_for_contact_client((int) $contact->id, (int) $client_id)) {
                // Not-yet-invited contact: reuse the registration invite path so they
                // can create an account (which joins this client) and view the doc.
                $invitation = Portal_Invitation_Model::create_invitation(
                    (int) $client->site_id,
                    $contact->email,
                    [
                        'contact_id' => (int) $contact->id,
                        'client_id' => (int) $client_id,
                        'role_id' => Portal_Membership_Model::ROLE_VIEWER,
                    ]
                );
                static::_send_new_account_invite_email($contact->email, $client, $invitation);
                static::_send_shared_document_email($contact->email, $client, $attachment, $message);
                $invited++;
            } else {
                // Already has a pending invite - still email the shared document link.
                static::_send_shared_document_email($contact->email, $client, $attachment, $message);
                $invited++;
            }
        }

        return [
            'shared' => $shared,
            'already_shared' => $already,
            'invited' => $invited,
            'notified' => $notified,
            'removed' => $removed,
        ];
    }

    /**
     * Ajax endpoint: remove a single share (Shared_Item) of a document.
     */
    #[Ajax_Endpoint]
    public static function documents_unshare(Request $request, array $params = [])
    {
        $shared_item_id = $params['shared_item_id'] ?? null;
        if (!$shared_item_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Shared item ID is required');
        }

        $share = Shared_Item_Model::find($shared_item_id);
        if (!$share) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Share not found');
        }

        $share->delete();

        return ['message' => 'Share removed'];
    }

    /**
     * Ajax endpoint: delete a document from the client, and remove its shares.
     *
     * Deleting SOFT-deletes the attachment into the framework retention window (delete()
     * keeps the fileable link, so the document still belongs to this client but drops out
     * of the active list and the client's portal). It is recoverable via document_restore
     * for the retention period, then permanently destroyed by the framework disposal task
     * (rsx:man file_disposal). All Shared_Item rows are removed here, so a restored document
     * comes back UNshared (re-share as needed) and no portal user can reach it meanwhile.
     */
    #[Ajax_Endpoint]
    public static function document_delete(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $attachment = static::_resolve_client_document($client, $params);
        if (!$attachment) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Document not found for this client');
        }

        // Remove every share of this attachment, then soft-delete it into retention.
        Shared_Item_Model::where('item_type', 'File_Attachment_Model')
            ->where('item_id', $attachment->id)
            ->delete();

        $attachment->delete();

        return ['message' => 'Document deleted'];
    }

    /**
     * Ajax endpoint: list this client's recently-deleted documents (in the retention
     * window - soft-deleted but not yet permanently destroyed). The recovery surface for
     * the "Recently Deleted" panel; restore one with document_restore.
     */
    #[Ajax_Endpoint]
    public static function documents_deleted_list(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $deleted = [];
        foreach ($client->get_deleted_attachments(static::DOCUMENTS_CATEGORY) as $attachment) {
            $deleted[] = [
                'attachment_id' => $attachment->id,
                'name' => $attachment->file_name,
                'deleted_at' => $attachment->deleted_at,
                // WHO deleted it - stamped automatically by the soft delete. Resolved
                // server-side for the <Record_Author> widget, like every authorship
                // display (the profile URL is gated for THIS viewer).
                'deleted_by_author' => $attachment->get_deleted_by_author(),
            ];
        }

        return ['deleted_documents' => $deleted];
    }

    /**
     * Ajax endpoint: restore a recently-deleted document out of the retention window
     * (undelete). Throws through undelete() if the document has already been permanently
     * destroyed or its bytes are gone. The restored document returns UNshared.
     */
    #[Ajax_Endpoint]
    public static function document_restore(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $attachment = static::_resolve_client_document_trashed($client, $params);
        if (!$attachment) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Deleted document not found for this client');
        }

        $attachment->undelete();

        return ['message' => 'Document restored'];
    }

    /**
     * Resolve a document attachment that belongs to this client's 'documents'
     * category, by attachment_id or key. Returns null if it is not a document of
     * this client (so a caller cannot share/delete an arbitrary attachment).
     *
     * @param Client_Model $client
     * @param array $params
     * @return File_Attachment_Model|null
     */
    private static function _resolve_client_document(Client_Model $client, array $params): ?File_Attachment_Model
    {
        $attachment = null;
        if (!empty($params['attachment_id'])) {
            $attachment = File_Attachment_Model::find($params['attachment_id']);
        } elseif (!empty($params['key'])) {
            $attachment = File_Attachment_Model::find_by_key($params['key']);
        }

        if (!$attachment) {
            return null;
        }

        // Must be a 'documents' attachment of THIS client.
        if ((string) $attachment->fileable_type !== 'Client_Model'
            || (int) $attachment->fileable_id !== (int) $client->id
            || (string) $attachment->fileable_category !== static::DOCUMENTS_CATEGORY) {
            return null;
        }

        return $attachment;
    }

    /**
     * Resolve a SOFT-DELETED (retention-window) 'documents' attachment of this client, by
     * attachment_id. Mirrors _resolve_client_document but with withTrashed() (the plain
     * find() excludes trashed rows) and gated to not-yet-destroyed rows. Used by
     * document_restore; returns null if it is not a retained document of this client.
     *
     * @param Client_Model $client
     * @param array $params
     * @return File_Attachment_Model|null
     */
    private static function _resolve_client_document_trashed(Client_Model $client, array $params): ?File_Attachment_Model
    {
        if (empty($params['attachment_id'])) {
            return null;
        }

        $attachment = File_Attachment_Model::withTrashed()
            ->whereNull('destroyed_at')
            ->find($params['attachment_id']);

        if (!$attachment || $attachment->deleted_at === null) {
            return null;
        }

        // Must be a 'documents' attachment of THIS client.
        if ((string) $attachment->fileable_type !== 'Client_Model'
            || (int) $attachment->fileable_id !== (int) $client->id
            || (string) $attachment->fileable_category !== static::DOCUMENTS_CATEGORY) {
            return null;
        }

        return $attachment;
    }

    /**
     * Emit a 'document_shared' portal notification to a recipient's activity feed.
     *
     * The payload is a self-contained snapshot (title/body/url) pointing at the
     * client workspace Documents tab.
     *
     * @param Portal_User_Model $portal_user
     * @param Client_Model $client
     * @param File_Attachment_Model $attachment
     * @return void
     */
    private static function _notify_document_shared(Portal_User_Model $portal_user, Client_Model $client, File_Attachment_Model $attachment): void
    {
        Portal_Notification_Model::emit([$portal_user->id], 'document_shared', [
            'site_id' => (int) $client->site_id,
            'subject_type' => 'File_Attachment_Model',
            'subject_id' => (int) $attachment->id,
            'payload' => [
                'title' => 'A document was shared with you',
                'body' => $attachment->file_name,
                'url' => Rsx_Portal::Route('Portal_Workspace_Documents_Action', ['id' => $client->id]),
            ],
        ]);
    }

    /**
     * Send the shared-content email for a shared document.
     *
     * @param string $email
     * @param Client_Model $client
     * @param File_Attachment_Model $attachment
     * @param string $message
     * @return void
     */
    private static function _send_shared_document_email(string $email, Client_Model $client, File_Attachment_Model $attachment, string $message): void
    {
        $view_url = rsx_absolute_url(Rsx_Portal::Route('Portal_Workspace_Documents_Action', ['id' => $client->id]));

        \App\RSpade\Core\Mail\Rsx_Mail::send(
            $email,
            $client->name . ' shared a document with you',
            'Portal_Shared_Content_Email',
            [
                'shared_by_name' => $client->name,
                'app_name' => config('rsx.name', 'RSpade'),
                'message' => $message !== '' ? $message : ('Document: ' . $attachment->file_name),
                'view_url' => $view_url,
                'expires_at' => null,
            ],
            \App\RSpade\Core\Mail\Rsx_Mail::TRANSACTIONAL
        );
    }

    // =====================================================================
    // CLIENT REQUEST THREADS (firm <-> client conversation: messages, status,
    // per-document review). Replaces the flat Portal_Request feature. A thread is
    // a Portal_Request_Thread_Model carrying a single status lifecycle; messages,
    // status events and document review-state live in sibling tables. Documents
    // attached in a thread are File_Attachments (fileable = the thread, category
    // DOCUMENTS_THREAD_CATEGORY) plus a Portal_Request_Document_Model review row.
    //
    // Staff-only: gated by this controller's pre_dispatch (Session::is_logged_in).
    // No notifications here yet (Stage 4).
    // =====================================================================

    /**
     * Category under which a thread's document File_Attachments are attached (fileable
     * = the thread). The Portal_Request_Document_Model row carries the review state.
     */
    const DOCUMENTS_THREAD_CATEGORY = 'attachment';

    /**
     * Ajax endpoint: request threads for a client, split into active (status != CLOSED)
     * and closed groups, plus a total Needs-Review count across active threads (for the
     * tab badge). Each row carries enough for the list: title, staff status label/badge,
     * last activity, message + needs-review counts.
     */
    #[Ajax_Endpoint]
    public static function request_threads_list(Request $request, array $params = [])
    {
        $client_id = $params['id'] ?? null;
        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $active = [];
        $closed = [];
        $total_needs_review = 0;

        foreach (Portal_Request_Thread_Model::for_client((int) $client_id) as $thread) {
            $needs_review_count = 0;
            $message_count = 0;
            foreach ($thread->documents() as $document) {
                if ($document->is_reviewable() && $document->is_pending()) {
                    $needs_review_count++;
                }
            }
            $message_count = count($thread->messages());

            $row = [
                'id' => $thread->id,
                'title' => $thread->title,
                'status_id' => $thread->status_id,
                'status_label' => $thread->status_label(false),
                'badge' => $thread->status_id__badge,
                'last_activity_at' => $thread->last_activity_at ?: $thread->created_at,
                'message_count' => $message_count,
                'needs_review_count' => $needs_review_count,
            ];

            if ((int) $thread->status_id === Portal_Request_Thread_Model::STATUS_CLOSED) {
                $closed[] = $row;
            } else {
                $active[] = $row;
                $total_needs_review += $needs_review_count;
            }
        }

        return [
            'active' => $active,
            'closed' => $closed,
            'needs_review_total' => $total_needs_review,
        ];
    }

    /**
     * Ajax endpoint: create a new request thread (status NEW_REQUEST) for a client, with
     * the firm's opening ask as the first message. Returns a redirect to the staff thread
     * view.
     */
    #[Ajax_Endpoint]
    public static function request_thread_create(Request $request, array $params = [])
    {
        $client_id = $params['client_id'] ?? null;
        $title = trim($params['title'] ?? '');
        $body = trim($params['body'] ?? '');

        if (!$client_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }
        if ($title === '' || $body === '') {
            return response_form_error('Validation failed', [
                'title' => $title === '' ? 'Title is required' : null,
                'body' => $body === '' ? 'Message is required' : null,
            ]);
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
        }

        $staff = Session::get_login_user();

        $thread = new Portal_Request_Thread_Model();
        $thread->site_id = (int) $client->site_id;
        $thread->client_id = (int) $client_id;
        $thread->title = $title;
        $thread->status_id = Portal_Request_Thread_Model::STATUS_NEW_REQUEST;
        // Authorship is stamped by save() into created_by_id/created_by_type.
        $thread->last_activity_at = now()->toDateTimeString();
        $thread->save();

        // The opening ask. Staff author -> no auto status transition.
        $thread->post_message($staff, $body);

        return [
            'thread_id' => $thread->id,
            'redirect' => Rsx::Route('Clients_Request_Thread_Action', [
                'id' => (int) $client_id,
                'thread_id' => (int) $thread->id,
            ]),
        ];
    }

    /**
     * Ajax endpoint: the full thread for the staff thread view - vitals, a merged
     * timeline (messages + status events ordered by created_at), the participants
     * (active portal members of the client), and the sidebar document buckets
     * (Needs Review = reviewable+pending, Accepted).
     */
    #[Ajax_Endpoint]
    public static function request_thread_get(Request $request, array $params = [])
    {
        $id = $params['id'] ?? null;
        if (!$id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Thread ID is required');
        }

        $thread = Portal_Request_Thread_Model::find($id);
        if (!$thread) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Request thread not found');
        }

        $client = Client_Model::find($thread->client_id);

        // Index every review document by message_id so messages can carry their chips,
        // and build the sidebar buckets in one pass.
        $documents = $thread->documents();
        $docs_by_message = [];
        $needs_review = [];
        $accepted = [];
        foreach ($documents as $document) {
            $doc_row = static::_thread_document_row($document);
            if ($doc_row === null) {
                continue;
            }
            $docs_by_message[(int) $document->message_id][] = $doc_row;

            if ($document->is_reviewable() && $document->is_pending()) {
                $needs_review[] = $doc_row;
            } elseif ($document->is_accepted()) {
                $accepted[] = $doc_row;
            }
        }

        // Merge messages + events into one timeline ordered by created_at (then id).
        $timeline = [];
        foreach ($thread->messages() as $message) {
            $timeline[] = [
                'sort_key' => $message->created_at . sprintf('-1-%010d', (int) $message->id),
                'entry' => [
                    'type' => 'message',
                    'id' => $message->id,
                    'author_name' => $message->author_name(),
                    'author_email' => $message->author_email(),
                    'is_from_staff' => $message->is_from_staff(),
                    'body' => $message->body,
                    'created_at' => $message->created_at,
                    'documents' => $docs_by_message[(int) $message->id] ?? [],
                ],
            ];
        }
        foreach ($thread->events() as $event) {
            $timeline[] = [
                'sort_key' => $event->created_at . sprintf('-2-%010d', (int) $event->id),
                'entry' => [
                    'type' => 'event',
                    'id' => $event->id,
                    'from_label' => $event->from_status_label(false),
                    'to_label' => $event->to_status_label(false),
                    'actor_name' => $event->actor_name(),
                    'created_at' => $event->created_at,
                ],
            ];
        }
        usort($timeline, fn ($a, $b) => strcmp($a['sort_key'], $b['sort_key']));
        $timeline = array_map(fn ($t) => $t['entry'], $timeline);

        // Participants = client members (every portal member of the client) + staff who
        // have posted in this thread. Two labelled groups, deduped within each.
        $participants = $thread->participants();

        return [
            'thread' => [
                'id' => $thread->id,
                'title' => $thread->title,
                'client_id' => $thread->client_id,
                'client_name' => $client ? $client->name : '',
                'status_id' => $thread->status_id,
                'status_label' => $thread->status_label(false),
                'badge' => $thread->status_id__badge,
                'is_closed' => $thread->is_closed(),
            ],
            'timeline' => $timeline,
            'participants' => $participants,
            'needs_review' => $needs_review,
            'accepted' => $accepted,
        ];
    }

    /**
     * Ajax endpoint: staff posts a reply to a thread. Optionally carries staff
     * attachments (review-exempt informational docs) and/or a status change applied
     * AFTER the message. The body may be empty only when there is at least one
     * attachment or a status change.
     */
    #[Ajax_Endpoint]
    public static function request_thread_reply(Request $request, array $params = [])
    {
        $thread_id = $params['thread_id'] ?? null;
        $body = trim($params['body'] ?? '');
        $status = $params['status'] ?? null;
        $attachment_keys = $params['attachment_keys'] ?? [];

        if (!$thread_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Thread ID is required');
        }
        if (!is_array($attachment_keys)) {
            $attachment_keys = [];
        }

        $thread = Portal_Request_Thread_Model::find($thread_id);
        if (!$thread) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Request thread not found');
        }

        // A status change is only honoured if it is one of the staff-settable values.
        $status_change = null;
        if ($status !== null && $status !== '' && (int) $status !== (int) $thread->status_id) {
            $valid = [
                Portal_Request_Thread_Model::STATUS_NEEDS_MORE_INFO,
                Portal_Request_Thread_Model::STATUS_APPROVED,
                Portal_Request_Thread_Model::STATUS_CLOSED,
            ];
            if (!in_array((int) $status, $valid, true)) {
                return response_error(Ajax::ERROR_VALIDATION, 'Invalid status change');
            }
            $status_change = (int) $status;
        }

        if ($body === '' && empty($attachment_keys) && $status_change === null) {
            return response_form_error('Validation failed', [
                'body' => 'Enter a message, attach a file, or change the status',
            ]);
        }

        $staff = Session::get_login_user();

        // Post the message (staff author -> no auto status transition).
        $message = $thread->post_message($staff, $body);

        // Attach staff files (review-exempt). Validate assignability before attaching.
        foreach ($attachment_keys as $key) {
            $attachment = File_Attachment_Model::find_by_key($key);
            if (!$attachment || !$attachment->can_user_assign_this_file()) {
                continue;
            }
            $attachment->add_to($thread, static::DOCUMENTS_THREAD_CATEGORY);

            $document = new Portal_Request_Document_Model();
            $document->site_id = (int) $thread->site_id;
            $document->thread_id = (int) $thread->id;
            $document->message_id = (int) $message->id;
            $document->attachment_id = (int) $attachment->id;
            $document->requires_review = 0; // staff attachment: informational
            $document->review_status = Portal_Request_Document_Model::REVIEW_PENDING;
            $document->save();
        }

        // Apply the status change AFTER the message so the event card follows the reply.
        if ($status_change !== null) {
            $thread->set_status($status_change, $staff);
        }

        // Notify the client's portal members of the staff reply (best-effort, non-fatal:
        // a notification failure must never break the reply). A REPLY notification always
        // goes out; a status change additionally emits a STATUS notification.
        try {
            static::_notify_thread_reply($thread, $body, $status_change !== null);
        } catch (\Throwable $e) {
            // swallow - the reply itself succeeded
        }

        return [
            'thread_id' => $thread->id,
            'message_id' => $message->id,
            'status_id' => $thread->status_id,
        ];
    }

    /**
     * Ajax endpoint: staff accepts or rejects a thread document. Accept -> ACCEPTED
     * (moves to the Accepted bucket); Reject -> REJECTED + optional reason (stays in
     * the thread with its badge/reason). Returns the updated review state.
     */
    #[Ajax_Endpoint]
    public static function request_document_review(Request $request, array $params = [])
    {
        $document_id = $params['document_id'] ?? null;
        $decision = $params['decision'] ?? null;
        $reason = trim($params['reason'] ?? '');

        if (!$document_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Document ID is required');
        }
        if (!in_array($decision, ['accept', 'reject'], true)) {
            return response_error(Ajax::ERROR_VALIDATION, 'Decision must be accept or reject');
        }

        $document = Portal_Request_Document_Model::find($document_id);
        if (!$document) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Document not found');
        }

        $staff = Session::get_login_user();

        if ($decision === 'accept') {
            $document->accept($staff);
        } else {
            $document->reject($staff, $reason !== '' ? $reason : null);
        }

        // Notify the client's portal members that their document was reviewed
        // (best-effort, non-fatal).
        try {
            $thread = Portal_Request_Thread_Model::find($document->thread_id);
            if ($thread) {
                static::_notify_document_decision($thread, $document);
            }
        } catch (\Throwable $e) {
            // swallow - the review itself succeeded
        }

        return [
            'id' => $document->id,
            'review_status' => $document->review_status,
            'review_label' => $document->review_status__label,
            'badge' => $document->review_status__badge,
            'reject_reason' => $document->reject_reason,
        ];
    }

    /**
     * Emit firm -> client portal notifications for a staff reply on a request thread.
     * Recipients are the thread's client members (resolve_recipient_ids). One REPLY
     * notification is always sent; when the reply carried a status change a STATUS
     * notification is sent too. The payload is a self-contained snapshot (title + body
     * snippet + the portal thread URL) so the recipient's feed never reads the thread.
     *
     * @param Portal_Request_Thread_Model $thread
     * @param string $body The staff message body (may be empty for attach-only/status-only).
     * @param bool $status_changed Whether the reply also changed the thread status.
     * @return void
     */
    private static function _notify_thread_reply(Portal_Request_Thread_Model $thread, string $body, bool $status_changed): void
    {
        $recipient_ids = $thread->resolve_recipient_ids();
        if (empty($recipient_ids)) {
            return;
        }

        $url = Rsx_Portal::Route('Portal_Request_Thread_Action', [
            'id' => (int) $thread->client_id,
            'thread_id' => (int) $thread->id,
        ]);

        // A short snippet of the staff message for the feed.
        $snippet = trim($body);
        if (mb_strlen($snippet) > 140) {
            $snippet = mb_substr($snippet, 0, 140) . '...';
        }

        Portal_Notification_Model::emit($recipient_ids, Portal_Request_Thread_Model::NOTIFICATION_TYPE_REPLY, [
            'site_id' => (int) $thread->site_id,
            'subject_type' => 'Portal_Request_Thread_Model',
            'subject_id' => (int) $thread->id,
            'payload' => [
                'title' => 'New reply on "' . $thread->title . '"',
                'body' => $snippet !== '' ? $snippet : 'The firm responded to your request.',
                'url' => $url,
            ],
        ]);

        if ($status_changed) {
            Portal_Notification_Model::emit($recipient_ids, Portal_Request_Thread_Model::NOTIFICATION_TYPE_STATUS, [
                'site_id' => (int) $thread->site_id,
                'subject_type' => 'Portal_Request_Thread_Model',
                'subject_id' => (int) $thread->id,
                'payload' => [
                    'title' => 'Status updated: "' . $thread->title . '"',
                    'body' => 'Status is now ' . $thread->status_label(true) . '.',
                    'url' => $url,
                ],
            ]);
        }
    }

    /**
     * Emit a firm -> client portal notification that a thread document was accepted or
     * rejected. Recipients are the thread's client members. Best-effort caller wraps this.
     *
     * @param Portal_Request_Thread_Model $thread
     * @param Portal_Request_Document_Model $document
     * @return void
     */
    private static function _notify_document_decision(Portal_Request_Thread_Model $thread, Portal_Request_Document_Model $document): void
    {
        $recipient_ids = $thread->resolve_recipient_ids();
        if (empty($recipient_ids)) {
            return;
        }

        $attachment = $document->attachment();
        $file_name = $attachment ? $attachment->file_name : 'A document';
        $decision = $document->is_accepted() ? 'accepted' : 'rejected';
        $body = $file_name . ' was ' . $decision . '.';
        if ($document->is_rejected() && $document->reject_reason) {
            $body .= ' Reason: ' . $document->reject_reason;
        }

        Portal_Notification_Model::emit($recipient_ids, Portal_Request_Thread_Model::NOTIFICATION_TYPE_DECISION, [
            'site_id' => (int) $thread->site_id,
            'subject_type' => 'Portal_Request_Thread_Model',
            'subject_id' => (int) $thread->id,
            'payload' => [
                'title' => 'Document ' . $decision . ': "' . $thread->title . '"',
                'body' => $body,
                'url' => Rsx_Portal::Route('Portal_Request_Thread_Action', [
                    'id' => (int) $thread->client_id,
                    'thread_id' => (int) $thread->id,
                ]),
            ],
        ]);
    }

    /**
     * Build the JS-facing row for one thread document (file metadata + review state),
     * or null if the underlying File_Attachment is missing.
     *
     * @param Portal_Request_Document_Model $document
     * @return array|null
     */
    private static function _thread_document_row(Portal_Request_Document_Model $document): ?array
    {
        $attachment = $document->attachment();
        if (!$attachment) {
            return null;
        }

        return [
            'id' => $document->id,
            'message_id' => $document->message_id,
            'name' => $attachment->file_name,
            'attachment_id' => (int) $attachment->id,
            'download_url' => $attachment->get_download_url(),
            'requires_review' => (int) $document->requires_review,
            'review_status' => $document->review_status,
            'review_label' => $document->review_status__label,
            'badge' => $document->review_status__badge,
            'reject_reason' => $document->reject_reason,
        ];
    }
}
