<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Portal\Invitations\Portal_Invitations_Controller;

/**
 * T9-REV - Portal access-model revision + admin.
 *
 * Covers the revised access model:
 *   - GLOBAL account suspend/reactivate ban toggle (kept; force-reset removed)
 *   - inviting an EXISTING portal account creates a PENDING per-client invitation
 *     (no immediate membership); accept creates the membership + consumes the
 *     invite; decline consumes it without a membership
 *   - "Disable Access" removes the client membership while the account + login
 *     persist (can_login() stays true)
 *   - resend re-sends a pending invitation (refreshes expiry)
 *
 * Site-scoped models scope by the staff Session site_id, so seeding/querying runs
 * under __acting_as_site().
 */
class Portal_User_Admin_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __make_portal_user(int $site_id = self::SITE_ID, int $status_id = Portal_User_Model::STATUS_ACTIVE, ?int $contact_id = null): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = $site_id;
        $user->email = 'admin_t9_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = $status_id;
        if ($contact_id) {
            $user->contact_id = $contact_id;
        }
        $user->save();

        return $user;
    }

    /**
     * Seed a portal session row for the user.
     *
     * A portal session is a browser's _sessions row carrying a portal identity. There
     * is no public API for minting one on another user's behalf (a session is created
     * by that user's own login), so the fixture inserts the row directly - the same
     * way a test seeds any other table it does not own the writer for.
     *
     * @return int The new session row id
     */
    private static function __make_session(Portal_User_Model $user): int
    {
        return (int) DB::table('_sessions')->insertGetId([
            'site_id'       => 0,
            'type_id'       => Session::TYPE_WEB,
            'portal_user_id' => $user->id,
            'portal_site_id' => $user->site_id,
            'session_token' => Str::random(64),
            'csrf_token'    => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'portal-user-admin-test',
            'last_active'   => now(),
            'active'        => 1,
            'version'       => 1,
        ]);
    }

    /**
     * Count a portal user's live sessions through the public facade.
     */
    private static function __session_count(int $portal_user_id): int
    {
        return count(Portal_Session::get_sessions_for_user($portal_user_id));
    }

    private static function __make_client(): Client_Model
    {
        $client = new Client_Model();
        $client->site_id = self::SITE_ID;
        $client->name = 'T9 Client ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    private static function __make_contact(Client_Model $client, ?string $email = null): Contact_Model
    {
        $contact = new Contact_Model();
        $contact->site_id = self::SITE_ID;
        $contact->client_id = $client->id;
        $contact->first_name = 'Pat';
        $contact->last_name = 'Member ' . uniqid();
        $contact->email = $email ?? ('contact_t9_' . uniqid() . '@example.com');
        $contact->is_active = true;
        $contact->save();

        return $contact;
    }

    // =====================================================================
    // GLOBAL account suspend / reactivate (kept as the account ban)
    // =====================================================================

    public static function test_suspend_blocks_login_and_terminates_sessions()
    {
        $user = static::__make_portal_user();
        static::__assert_true($user->can_login(), 'active+verified user can log in');

        static::__make_session($user);
        static::__make_session($user);
        static::__assert_equals(2, static::__session_count($user->id), 'two sessions before suspend');

        $result = Frontend_Clients_Controller::portal_user_suspend(Request::create('/'), ['portal_user_id' => $user->id]);
        static::__assert_equals(Portal_User_Model::STATUS_SUSPENDED, $result['status_id'], 'endpoint reports suspended status');
        static::__assert_equals(2, $result['sessions_terminated'], 'both sessions terminated');

        $fresh = Portal_User_Model::find($user->id);
        static::__assert_equals(Portal_User_Model::STATUS_SUSPENDED, $fresh->status_id, 'persisted as suspended');
        static::__assert_false($fresh->can_login(), 'suspended user cannot log in');
        static::__assert_equals(0, static::__session_count($user->id), 'sessions gone');
    }

    public static function test_reactivate_restores_login()
    {
        $user = static::__make_portal_user(self::SITE_ID, Portal_User_Model::STATUS_SUSPENDED);
        static::__assert_false($user->can_login(), 'suspended user cannot log in');

        $result = Frontend_Clients_Controller::portal_user_reactivate(Request::create('/'), ['portal_user_id' => $user->id]);
        static::__assert_equals(Portal_User_Model::STATUS_ACTIVE, $result['status_id'], 'endpoint reports active status');

        $fresh = Portal_User_Model::find($user->id);
        static::__assert_equals(Portal_User_Model::STATUS_ACTIVE, $fresh->status_id, 'persisted as active');
        static::__assert_true($fresh->can_login(), 'reactivated user can log in again');
    }

    public static function test_suspend_is_site_scoped()
    {
        $user = static::__make_portal_user();

        $other_site_id = self::SITE_ID + 999;
        static::__acting_as_site($other_site_id);

        $suspend = Frontend_Clients_Controller::portal_user_suspend(Request::create('/'), ['portal_user_id' => $user->id]);
        static::__assert_instance_of(Error_Response::class, $suspend, 'cross-site suspend is rejected');

        static::__acting_as_site(self::SITE_ID);

        $fresh = Portal_User_Model::find($user->id);
        static::__assert_equals(Portal_User_Model::STATUS_ACTIVE, $fresh->status_id, 'user not modified from another site');
    }

    public static function test_missing_user_returns_error()
    {
        $result = Frontend_Clients_Controller::portal_user_suspend(Request::create('/'), ['portal_user_id' => 99999999]);
        static::__assert_instance_of(Error_Response::class, $result, 'unknown user returns an error response');
    }

    // =====================================================================
    // Inviting an EXISTING account -> a PENDING invitation (no auto-membership)
    // =====================================================================

    public static function test_existing_account_invite_creates_pending_not_membership()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        // Existing portal account linked to the contact.
        $user = static::__make_portal_user(self::SITE_ID, Portal_User_Model::STATUS_ACTIVE, $contact->id);

        $result = Frontend_Clients_Controller::portal_bulk_invite(Request::create('/'), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);

        static::__assert_equals(1, $result['invited_existing'], 'one existing-account invitation created');
        static::__assert_equals(0, $result['invited_new'], 'no new-account invitation');

        // NO membership yet - it must be accepted first.
        static::__assert_false(
            Portal_Membership_Model::has_membership($user->id, $client->id),
            'existing-account invite does NOT auto-create the membership'
        );

        // A live pending invitation exists for this contact + client.
        $pending = Portal_Invitation_Model::find_pending_for_contact_client($contact->id, $client->id);
        static::__assert_not_empty($pending, 'a pending invitation was created');
    }

    public static function test_accept_creates_membership_and_consumes_invite()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user(self::SITE_ID, Portal_User_Model::STATUS_ACTIVE, $contact->id);

        Frontend_Clients_Controller::portal_bulk_invite(Request::create('/'), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);

        $invitation = Portal_Invitation_Model::find_pending_for_contact_client($contact->id, $client->id);
        static::__assert_not_empty($invitation, 'pending invitation present before accept');

        // Act as the invited portal user and accept.
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($user->id);

        $result = Portal_Invitations_Controller::accept(Request::create('/'), ['invitation_id' => $invitation->id]);
        static::__assert_equals($client->id, $result['client_id'], 'accept reports the client');

        static::__acting_as_site(self::SITE_ID);

        static::__assert_true(
            Portal_Membership_Model::has_membership($user->id, $client->id),
            'accept created the client membership'
        );
        static::__assert_true($invitation->fresh()->is_used(), 'invitation consumed by accept');
    }

    public static function test_decline_consumes_invite_without_membership()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user(self::SITE_ID, Portal_User_Model::STATUS_ACTIVE, $contact->id);

        Frontend_Clients_Controller::portal_bulk_invite(Request::create('/'), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);

        $invitation = Portal_Invitation_Model::find_pending_for_contact_client($contact->id, $client->id);

        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($user->id);

        Portal_Invitations_Controller::decline(Request::create('/'), ['invitation_id' => $invitation->id]);

        static::__acting_as_site(self::SITE_ID);

        static::__assert_false(
            Portal_Membership_Model::has_membership($user->id, $client->id),
            'decline did not create a membership'
        );
        static::__assert_true($invitation->fresh()->is_used(), 'invitation consumed by decline');
    }

    public static function test_bulk_invite_is_idempotent_for_live_invite()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        static::__make_portal_user(self::SITE_ID, Portal_User_Model::STATUS_ACTIVE, $contact->id);

        $first = Frontend_Clients_Controller::portal_bulk_invite(Request::create('/'), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);
        static::__assert_equals(1, $first['invited_existing'], 'first invite goes out');

        $second = Frontend_Clients_Controller::portal_bulk_invite(Request::create('/'), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);
        static::__assert_equals(0, $second['invited_existing'], 'no duplicate invite');
        static::__assert_equals(1, $second['skipped'], 'second is skipped as a live pending invite');
    }

    // =====================================================================
    // Disable Access = remove membership; account + login persist
    // =====================================================================

    public static function test_disable_access_removes_membership_but_login_persists()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user(self::SITE_ID, Portal_User_Model::STATUS_ACTIVE, $contact->id);

        $membership = new Portal_Membership_Model();
        $membership->site_id = self::SITE_ID;
        $membership->portal_user_id = $user->id;
        $membership->client_id = $client->id;
        $membership->role_id = Portal_Membership_Model::ROLE_VIEWER;
        $membership->save();

        static::__assert_true(Portal_Membership_Model::has_membership($user->id, $client->id), 'member before disable');

        $result = Frontend_Clients_Controller::portal_remove_member(Request::create('/'), ['membership_id' => $membership->id]);
        static::__assert_equals('Access removed', $result['message'], 'disable-access reports removal');

        static::__assert_false(Portal_Membership_Model::has_membership($user->id, $client->id), 'membership removed');

        // The account and its login both persist.
        $fresh = Portal_User_Model::find($user->id);
        static::__assert_not_empty($fresh, 'portal account still exists');
        static::__assert_equals(Portal_User_Model::STATUS_ACTIVE, $fresh->status_id, 'account still active');
        static::__assert_true($fresh->can_login(), 'user can still log in after disable access');
    }

    // =====================================================================
    // Resend a pending invitation (refreshes expiry, re-sends)
    // =====================================================================

    public static function test_resend_invite_refreshes_pending()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        static::__make_portal_user(self::SITE_ID, Portal_User_Model::STATUS_ACTIVE, $contact->id);

        Frontend_Clients_Controller::portal_bulk_invite(Request::create('/'), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);
        $invitation = Portal_Invitation_Model::find_pending_for_contact_client($contact->id, $client->id);

        // Backdate the expiry, then resend should push it back out.
        $invitation->expires_at = now()->addDay();
        $invitation->save();
        $before = $invitation->fresh()->expires_at;

        $result = Frontend_Clients_Controller::portal_resend_invite(Request::create('/'), [
            'client_id' => $client->id,
            'contact_id' => $contact->id,
        ]);
        static::__assert_equals($invitation->id, $result['invitation_id'], 'resend targets the pending invite');

        $after = $invitation->fresh()->expires_at;
        static::__assert_true(strtotime($after) > strtotime($before), 'resend refreshed (extended) the expiry');
    }

    // =====================================================================
    // Revoke (cancel) a pending invitation
    // =====================================================================

    public static function test_revoke_invite_marks_it_revoked()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);

        Frontend_Clients_Controller::portal_bulk_invite(Request::create('/'), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);
        $invitation = Portal_Invitation_Model::find_pending_for_contact_client($contact->id, $client->id);
        static::__assert_not_null($invitation, 'invite created');

        $result = Frontend_Clients_Controller::portal_revoke_invite(Request::create('/'), [
            'invitation_id' => $invitation->id,
        ]);
        static::__assert_equals($invitation->id, $result['invitation_id'], 'revoke targets the invite');

        static::__assert_equals(
            Portal_Invitation_Model::STATUS_REVOKED,
            (int) $invitation->fresh()->status_id,
            'invite is now revoked'
        );
        static::__assert_null(
            Portal_Invitation_Model::find_pending_for_contact_client($contact->id, $client->id),
            'a revoked invite is no longer pending'
        );
    }

    public static function teardown(): void
    {
        Portal_Session::reset();
        static::__reset_session();
    }
}
