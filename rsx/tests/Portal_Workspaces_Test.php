<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Portal\Workspaces\Portal_Workspaces_Controller;

/**
 * GATE:UI - My Workspaces home + workspace detail.
 *
 * Covers Portal_Workspaces_Controller, the portal-authed surface behind the
 * "My Workspaces" list and the /workspace/:id detail header:
 *   - list() returns exactly the caller's client memberships (name + role), and no
 *     client the caller is not a member of
 *   - get() allows a member, fails closed for a non-member, and fails closed for a
 *     wrong-site user (site scoping)
 *
 * Both endpoints derive the user from the portal SESSION, so the tests run under a
 * portal session (Portal_Session::cli_set_*), exactly as the invitation accept/decline
 * tests do in Portal_User_Admin_Test.
 */
class Portal_Workspaces_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __make_portal_user(int $site_id = self::SITE_ID): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = $site_id;
        $user->email = 'ws_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    private static function __make_client(int $site_id = self::SITE_ID): Client_Model
    {
        $client = new Client_Model();
        $client->site_id = $site_id;
        $client->name = 'WS Client ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    private static function __make_membership(
        Portal_User_Model $user,
        Client_Model $client,
        int $role_id
    ): Portal_Membership_Model {
        $membership = new Portal_Membership_Model();
        $membership->site_id = $user->site_id;
        $membership->portal_user_id = $user->id;
        $membership->client_id = $client->id;
        $membership->role_id = $role_id;
        $membership->save();

        return $membership;
    }

    private static function __act_as(Portal_User_Model $user): void
    {
        Portal_Session::set_site_id($user->site_id);
        Portal_Session::cli_set_portal_user_id($user->id);
    }

    // =====================================================================
    // list() - only the caller's memberships
    // =====================================================================

    public static function test_list_returns_only_callers_memberships()
    {
        $user = static::__make_portal_user();
        $client_a = static::__make_client();
        $client_b = static::__make_client();
        // A third client the user is NOT a member of.
        $client_other = static::__make_client();

        static::__make_membership($user, $client_a, Portal_Membership_Model::ROLE_VIEWER);
        static::__make_membership($user, $client_b, Portal_Membership_Model::ROLE_COLLABORATOR);

        static::__act_as($user);
        $result = Portal_Workspaces_Controller::list(Request::create('/'), []);
        static::__acting_as_site(self::SITE_ID);

        $workspaces = $result['workspaces'];
        static::__assert_count(2, $workspaces, 'exactly the two memberships are returned');

        $ids = array_map(fn ($w) => $w['client_id'], $workspaces);
        static::__assert_true(in_array($client_a->id, $ids, true), 'client A present');
        static::__assert_true(in_array($client_b->id, $ids, true), 'client B present');
        static::__assert_false(in_array($client_other->id, $ids, true), 'non-member client excluded');

        // Name + role label are exposed.
        $by_id = [];
        foreach ($workspaces as $w) {
            $by_id[$w['client_id']] = $w;
        }
        static::__assert_equals($client_a->name, $by_id[$client_a->id]['name'], 'client A name returned');
        static::__assert_equals('Viewer', $by_id[$client_a->id]['role_label'], 'viewer role label');
        static::__assert_equals('Collaborator', $by_id[$client_b->id]['role_label'], 'collaborator role label');
    }

    public static function test_list_empty_for_user_with_no_memberships()
    {
        $user = static::__make_portal_user();

        static::__act_as($user);
        $result = Portal_Workspaces_Controller::list(Request::create('/'), []);
        static::__acting_as_site(self::SITE_ID);

        static::__assert_count(0, $result['workspaces'], 'no memberships -> empty list');
    }

    // =====================================================================
    // get() - member allowed, non-member + wrong-site denied
    // =====================================================================

    public static function test_get_allows_member()
    {
        $user = static::__make_portal_user();
        $client = static::__make_client();
        static::__make_membership($user, $client, Portal_Membership_Model::ROLE_COLLABORATOR);

        static::__act_as($user);
        $result = Portal_Workspaces_Controller::get(Request::create('/'), ['id' => $client->id]);
        static::__acting_as_site(self::SITE_ID);

        static::__assert_false($result instanceof Error_Response, 'member is allowed');
        static::__assert_equals($client->id, $result['client_id'], 'returns the requested client');
        static::__assert_equals($client->name, $result['name'], 'returns the client name');
        static::__assert_equals('Collaborator', $result['role_label'], 'returns the caller role label');
    }

    public static function test_get_returns_overview_vitals_for_member()
    {
        // The Overview tab renders the 'vitals' block get() returns for a member.
        $user = static::__make_portal_user();
        $client = static::__make_client();
        static::__make_membership($user, $client, Portal_Membership_Model::ROLE_VIEWER);

        static::__act_as($user);
        $result = Portal_Workspaces_Controller::get(Request::create('/'), ['id' => $client->id]);
        static::__acting_as_site(self::SITE_ID);

        static::__assert_false($result instanceof Error_Response, 'member is allowed');
        static::__assert_true(isset($result['vitals']), 'a vitals block is returned for the Overview tab');
        $vitals = $result['vitals'];
        static::__assert_equals('Viewer', $vitals['role_label'], 'vitals carry the caller role label');
        static::__assert_true(array_key_exists('status_label', $vitals), 'vitals carry the client status label');
        static::__assert_true(array_key_exists('client_since', $vitals), 'vitals carry a client-since date');
        static::__assert_true(array_key_exists('member_since', $vitals), 'vitals carry a member-since date');
        static::__assert_true(array_key_exists('engagement_type', $vitals), 'vitals carry an engagement type');
        static::__assert_true(array_key_exists('primary_contact', $vitals), 'vitals carry a primary contact');
    }

    public static function test_get_denies_non_member()
    {
        $user = static::__make_portal_user();
        // A client the user has NO membership for.
        $client = static::__make_client();

        static::__act_as($user);
        $result = Portal_Workspaces_Controller::get(Request::create('/'), ['id' => $client->id]);
        static::__acting_as_site(self::SITE_ID);

        static::__assert_instance_of(Error_Response::class, $result, 'non-member is denied (not the row)');
    }

    public static function test_get_denies_wrong_site_user()
    {
        // A client + membership on the default site.
        $owner = static::__make_portal_user(self::SITE_ID);
        $client = static::__make_client(self::SITE_ID);
        static::__make_membership($owner, $client, Portal_Membership_Model::ROLE_VIEWER);

        // A user on a DIFFERENT site, with no membership for that client.
        $other_site_id = self::SITE_ID + 999;
        $intruder = static::__make_portal_user($other_site_id);

        static::__act_as($intruder);
        $result = Portal_Workspaces_Controller::get(Request::create('/'), ['id' => $client->id]);
        static::__acting_as_site(self::SITE_ID);

        static::__assert_instance_of(Error_Response::class, $result, 'wrong-site user is denied (site scoping holds)');
    }

    public static function teardown(): void
    {
        Portal_Session::reset();
        static::__reset_session();
    }
}
