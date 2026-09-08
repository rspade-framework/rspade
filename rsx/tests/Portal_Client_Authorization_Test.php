<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Models\Portal_Project_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Shared_Item_Model;
use Rsx\Portal_Permission;

/**
 * This application's portal authorization rules: Portal_Permission (membership, role,
 * can_collaborate, accessible_client_ids) and the portal_can_read() rules its own models
 * declare - membership-scoped rows, shared items and the wrong-site denial.
 *
 * The framework half - Portal_User_Model's own-record rule and the portal_fetch() gate -
 * lives in the framework suite (portal concern).
 *
 * Site-scoped models (Client/Contact/Membership/Project/Shared) scope by the STAFF
 * Session site_id, so setup() aligns it via __acting_as_site() for seeding and queries;
 * the portal IDENTITY is set via the Portal_Session CLI setters. Runs in the default
 * per-test transaction (rolled back afterward).
 */
class Portal_Client_Authorization_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * Seed a portal user (active + verified), optionally linked to a contact.
     */
    private static function __make_portal_user(?int $contact_id = null): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'portal_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        if ($contact_id !== null) {
            $user->contact_id = $contact_id;
        }
        $user->save();

        return $user;
    }

    private static function __make_client(string $name = 'Test Client'): Client_Model
    {
        $client = new Client_Model();
        $client->name = $name;
        $client->save();

        return $client;
    }

    private static function __make_contact(int $client_id): Contact_Model
    {
        $contact = new Contact_Model();
        $contact->client_id = $client_id;
        $contact->first_name = 'Test';
        $contact->last_name = 'Contact';
        $contact->save();

        return $contact;
    }

    private static function __make_project(int $client_id, string $name = 'Test Project'): Project_Model
    {
        $project = new Project_Model();
        $project->client_id = $client_id;
        $project->name = $name;
        $project->save();

        return $project;
    }

    private static function __grant_membership(int $portal_user_id, int $client_id, int $role_id): Portal_Membership_Model
    {
        $membership = new Portal_Membership_Model();
        $membership->portal_user_id = $portal_user_id;
        $membership->client_id = $client_id;
        $membership->role_id = $role_id;
        $membership->save();

        return $membership;
    }

    /**
     * Impersonate a portal user (CLI portal session identity) on the test site.
     */
    private static function __login_portal(int $portal_user_id): void
    {
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($portal_user_id);
    }

    public static function setup(): void
    {
        // Align the staff Session site so site-scoped models seed and query on SITE_ID.
        static::__acting_as_site(self::SITE_ID);
    }

    public static function test_membership_scoped_grant_and_deny()
    {
        $client_x = static::__make_client('Client X');
        $client_y = static::__make_client('Client Y');
        $user = static::__make_portal_user();
        static::__grant_membership($user->id, $client_x->id, Portal_Membership_Model::ROLE_VIEWER);

        static::__login_portal($user->id);

        static::__assert_true(Portal_Permission::has_client_access($client_x->id), 'has access to member client');
        static::__assert_false(Portal_Permission::has_client_access($client_y->id), 'no access to non-member client');

        // Membership row visibility mirrors client access.
        $membership_x = Portal_Membership_Model::find_for_user_and_client($user->id, $client_x->id);
        static::__assert_true($membership_x->portal_can_read(), 'may read membership row for accessible client');

        // A project visibility row for the non-member client is not readable.
        $project_y = static::__make_project($client_y->id);
        $project_row = new Portal_Project_Model();
        $project_row->client_id = $client_y->id;
        $project_row->project_id = $project_y->id;
        $project_row->save();
        static::__assert_false($project_row->portal_can_read(), 'may not read membership-scoped row for non-member client');
    }
    public static function test_viewer_vs_collaborator_roles()
    {
        $client = static::__make_client('Role Client');
        $viewer = static::__make_portal_user();
        $collaborator = static::__make_portal_user();
        static::__grant_membership($viewer->id, $client->id, Portal_Membership_Model::ROLE_VIEWER);
        static::__grant_membership($collaborator->id, $client->id, Portal_Membership_Model::ROLE_COLLABORATOR);

        static::__login_portal($viewer->id);
        static::__assert_equals(Portal_Membership_Model::ROLE_VIEWER, Portal_Permission::client_role($client->id));
        static::__assert_false(Portal_Permission::can_collaborate($client->id), 'viewer cannot collaborate');

        static::__login_portal($collaborator->id);
        static::__assert_equals(Portal_Membership_Model::ROLE_COLLABORATOR, Portal_Permission::client_role($client->id));
        static::__assert_true(Portal_Permission::can_collaborate($client->id), 'collaborator can collaborate');
    }
    public static function test_can_collaborate_denies_without_membership()
    {
        $client = static::__make_client('No Membership Client');
        $user = static::__make_portal_user();

        static::__login_portal($user->id);

        static::__assert_null(Portal_Permission::client_role($client->id), 'no role without membership');
        static::__assert_false(Portal_Permission::can_collaborate($client->id), 'cannot collaborate without membership');
    }
    public static function test_accessible_client_ids_scoping()
    {
        $client_a = static::__make_client('Accessible A');
        $client_b = static::__make_client('Accessible B');
        $client_c = static::__make_client('Not Accessible C');
        $user = static::__make_portal_user();
        static::__grant_membership($user->id, $client_a->id, Portal_Membership_Model::ROLE_VIEWER);
        static::__grant_membership($user->id, $client_b->id, Portal_Membership_Model::ROLE_COLLABORATOR);

        static::__login_portal($user->id);

        $ids = Portal_Permission::accessible_client_ids();
        sort($ids);
        $expected = [$client_a->id, $client_b->id];
        sort($expected);
        static::__assert_equals($expected, $ids, 'accessible_client_ids returns only member clients');
        static::__assert_false(in_array($client_c->id, $ids, true), 'non-member client excluded');
    }
    public static function test_shared_recipient_grant_and_deny()
    {
        $client = static::__make_client('Share Client');
        $recipient_contact = static::__make_contact($client->id);
        $other_contact = static::__make_contact($client->id);

        $recipient_user = static::__make_portal_user($recipient_contact->id);
        $other_user = static::__make_portal_user($other_contact->id);
        $unlinked_user = static::__make_portal_user(); // no contact link

        $share = new Shared_Item_Model();
        $share->shared_by = 1;
        $share->contact_id = $recipient_contact->id;
        $share->item_type = 'Portal_User_Model';
        $share->item_id = 1;
        $share->token = Shared_Item_Model::generate_token();
        $share->expires_at = now()->addDays(30);
        $share->save();

        // Recipient may read.
        static::__login_portal($recipient_user->id);
        static::__assert_true($share->portal_can_read(), 'recipient may read shared item');

        // A different contact may not.
        static::__login_portal($other_user->id);
        static::__assert_false($share->portal_can_read(), 'non-recipient contact may not read shared item');

        // A portal user with no linked contact may not (fail-closed).
        static::__login_portal($unlinked_user->id);
        static::__assert_false($share->portal_can_read(), 'unlinked portal user may not read shared item');
    }
    public static function test_wrong_site_membership_denied()
    {
        $client = static::__make_client('Wrong Site Client');
        $user = static::__make_portal_user();
        static::__grant_membership($user->id, $client->id, Portal_Membership_Model::ROLE_VIEWER);

        // Log in on a different site than where the data lives.
        Portal_Session::set_site_id(self::SITE_ID + 999);
        Portal_Session::cli_set_portal_user_id($user->id);

        // Staff Session (used by site-scoped model queries) also moves to the wrong
        // site, so the membership lookup is scoped away and access is denied.
        Session::set_site_id(self::SITE_ID + 999);

        static::__assert_false(
            Portal_Permission::has_client_access($client->id),
            'membership on another site is not visible -> access denied'
        );

        // Restore staff site for any subsequent teardown/scoping.
        Session::set_site_id(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Portal_Session::cli_set_portal_user_id(0);
        static::__reset_session();
    }
}
