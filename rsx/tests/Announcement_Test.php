<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Announcement_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Portal_Membership_Model;

/**
 * AP-C Announcements tests.
 *
 * Focus: announcement PUBLISH + AUDIENCE resolution (the app feature). The T4
 * notification primitive itself is already covered by Portal_Notification_Test;
 * here we prove the announcement emits the right notifications to the right
 * recipients:
 *   - publish to a client -> one notification per ACTIVE member of that client
 *   - a portal user who is NOT a member of the client gets nothing
 *   - inactive/suspended members are excluded
 *   - re-publish is idempotent (no duplicate feed items)
 *   - site-wide (client_id null) targets every active member on the site
 *   - site-scoping holds (members in another site are not reached)
 *
 * Site-scoped models scope by the staff Session site_id, so seeding/querying runs
 * under __acting_as_site().
 */
class Announcement_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __make_portal_user(int $status_id = Portal_User_Model::STATUS_ACTIVE): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'announce_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = $status_id;
        $user->save();

        return $user;
    }

    private static function __make_client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Announce Client ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    private static function __add_member(Client_Model $client, Portal_User_Model $user): Portal_Membership_Model
    {
        $membership = new Portal_Membership_Model();
        $membership->portal_user_id = $user->id;
        $membership->client_id = $client->id;
        $membership->role_id = Portal_Membership_Model::ROLE_VIEWER;
        $membership->save();

        return $membership;
    }

    // =====================================================================
    // publish -> emits one notification per active member
    // =====================================================================

    public static function test_publish_emits_one_notification_per_member()
    {
        $client = static::__make_client();
        $a = static::__make_portal_user();
        $b = static::__make_portal_user();
        static::__add_member($client, $a);
        static::__add_member($client, $b);

        $announcement = new Announcement_Model();
        $announcement->client_id = $client->id;
        $announcement->title = 'Quarterly Update';
        $announcement->body = "Line one.\nLine two.";
        $announcement->save();

        $reached = $announcement->publish();
        static::__assert_equals(2, $reached, 'publish reaches both members');

        $feed_a = Portal_Notification_Model::feed($a->id);
        $feed_b = Portal_Notification_Model::feed($b->id);
        static::__assert_count(1, $feed_a, 'member A gets the announcement');
        static::__assert_count(1, $feed_b, 'member B gets the announcement');

        static::__assert_equals('announcement', $feed_a[0]->type);
        static::__assert_equals('Quarterly Update', $feed_a[0]->payload['title'] ?? null);
        static::__assert_equals("Line one.\nLine two.", $feed_a[0]->payload['body'] ?? null);
        static::__assert_equals('Announcement_Model', $feed_a[0]->subject_type, 'subject points back at the announcement');
        static::__assert_equals($announcement->id, (int) $feed_a[0]->subject_id);
    }

    public static function test_publish_stamps_published_at()
    {
        $client = static::__make_client();
        static::__add_member($client, static::__make_portal_user());

        $announcement = new Announcement_Model();
        $announcement->client_id = $client->id;
        $announcement->title = 'Hello';
        $announcement->body = 'World';
        $announcement->save();
        static::__assert_false($announcement->is_published(), 'unpublished before publish()');

        $announcement->publish();
        static::__assert_true($announcement->is_published(), 'published_at stamped after publish()');
    }

    // =====================================================================
    // audience scoping: non-members and other clients get nothing
    // =====================================================================

    public static function test_non_member_receives_nothing()
    {
        $client = static::__make_client();
        $member = static::__make_portal_user();
        static::__add_member($client, $member);

        // A portal user with NO membership for this client.
        $outsider = static::__make_portal_user();
        // A member of a DIFFERENT client.
        $other_client = static::__make_client();
        $other_member = static::__make_portal_user();
        static::__add_member($other_client, $other_member);

        $announcement = new Announcement_Model();
        $announcement->client_id = $client->id;
        $announcement->title = 'Targeted';
        $announcement->body = 'Only for this client';
        $announcement->save();

        $reached = $announcement->publish();
        static::__assert_equals(1, $reached, 'only the one client member is reached');

        static::__assert_count(1, Portal_Notification_Model::feed($member->id), 'member sees it');
        static::__assert_count(0, Portal_Notification_Model::feed($outsider->id), 'outsider sees nothing');
        static::__assert_count(0, Portal_Notification_Model::feed($other_member->id), 'other client member sees nothing');
    }

    public static function test_inactive_member_excluded()
    {
        $client = static::__make_client();
        $active = static::__make_portal_user(Portal_User_Model::STATUS_ACTIVE);
        $suspended = static::__make_portal_user(Portal_User_Model::STATUS_SUSPENDED);
        static::__add_member($client, $active);
        static::__add_member($client, $suspended);

        $announcement = new Announcement_Model();
        $announcement->client_id = $client->id;
        $announcement->title = 'Members Only';
        $announcement->body = 'Active members only';
        $announcement->save();

        $reached = $announcement->publish();
        static::__assert_equals(1, $reached, 'only the active member is reached');
        static::__assert_count(1, Portal_Notification_Model::feed($active->id));
        static::__assert_count(0, Portal_Notification_Model::feed($suspended->id), 'suspended member excluded');
    }

    // =====================================================================
    // idempotent re-publish
    // =====================================================================

    public static function test_republish_is_idempotent()
    {
        $client = static::__make_client();
        $member = static::__make_portal_user();
        static::__add_member($client, $member);

        $announcement = new Announcement_Model();
        $announcement->client_id = $client->id;
        $announcement->title = 'Once';
        $announcement->body = 'Only once';
        $announcement->save();

        $first = $announcement->publish();
        static::__assert_equals(1, $first, 'first publish reaches the member');

        $second = $announcement->publish();
        static::__assert_equals(0, $second, 're-publishing an already-published announcement emits nothing');

        static::__assert_count(1, Portal_Notification_Model::feed($member->id), 'no duplicate feed item');
    }

    // =====================================================================
    // site-wide audience (client_id null)
    // =====================================================================

    public static function test_site_wide_targets_all_active_members()
    {
        $client_a = static::__make_client();
        $client_b = static::__make_client();
        $member_a = static::__make_portal_user();
        $member_b = static::__make_portal_user();
        static::__add_member($client_a, $member_a);
        static::__add_member($client_b, $member_b);

        $announcement = new Announcement_Model();
        $announcement->client_id = null; // site-wide
        $announcement->title = 'Site Wide';
        $announcement->body = 'Everyone on the site';
        $announcement->save();

        $reached = $announcement->publish();
        static::__assert_greater_than(1, $reached, 'site-wide reaches members across clients');
        static::__assert_count(1, Portal_Notification_Model::feed($member_a->id), 'client A member reached');
        static::__assert_count(1, Portal_Notification_Model::feed($member_b->id), 'client B member reached');
    }

    // =====================================================================
    // site-scoping holds
    // =====================================================================

    public static function test_site_scoping_holds()
    {
        $client = static::__make_client();
        $member = static::__make_portal_user();
        static::__add_member($client, $member);

        $announcement = new Announcement_Model();
        $announcement->client_id = $client->id;
        $announcement->title = 'Site A Notice';
        $announcement->body = 'For site A';
        $announcement->save();
        $announcement->publish();

        // The emitted notification carries the announcement's site_id; querying from
        // another site sees nothing.
        static::__assert_count(1, Portal_Notification_Model::feed($member->id), 'visible within its own site');

        Session::set_site_id(self::SITE_ID + 999);
        static::__assert_count(0, Portal_Notification_Model::feed($member->id), 'invisible from another site');
        Session::set_site_id(self::SITE_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }
}
