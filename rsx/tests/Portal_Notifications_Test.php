<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Portal\Notifications\Portal_Notifications_Controller;
use Rsx\Portal_Main;

/**
 * This application's portal notification surface:
 *   - Portal_Notifications_Controller feed / mark_read / mark_all_read return ONLY the
 *     caller's own notifications, and refuse a foreign one
 *   - clients.portal_last_activity_at is stamped by Portal_Main::pre_dispatch on an
 *     authenticated portal request
 *
 * The framework primitive underneath (Portal_Notification_Model::emit / feed /
 * unread_count / mark_*, with its site and per-user isolation) is covered by the
 * framework suite (portal concern).
 *
 * Site-scoped models scope by the STAFF Session site_id, so setup() aligns it via
 * __acting_as_site(); the portal IDENTITY is set via the Portal_Session CLI setters.
 * Runs in the default per-test transaction.
 */
class Portal_Notifications_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'notif_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    private static function __login_portal(int $portal_user_id, int $site_id = self::SITE_ID): void
    {
        Portal_Session::set_site_id($site_id);
        Portal_Session::cli_set_portal_user_id($portal_user_id);
    }

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    // =====================================================================
    // fetch endpoint (returns only the caller's own feed)
    // =====================================================================

    public static function test_endpoint_returns_only_callers_feed()
    {
        $caller = static::__make_portal_user();
        $other = static::__make_portal_user();

        Portal_Notification_Model::emit($caller->id, 'announcement', ['payload' => ['title' => 'Mine']]);
        Portal_Notification_Model::emit($other->id, 'announcement', ['payload' => ['title' => 'Theirs']]);

        static::__login_portal($caller->id);

        $response = Portal_Notifications_Controller::feed(Request::create('/'), []);
        static::__assert_count(1, $response['notifications'], 'endpoint returns only the caller feed');
        static::__assert_equals('Mine', $response['notifications'][0]['payload']['title'] ?? null);
        static::__assert_equals(1, $response['unread_count'], 'endpoint reports caller unread count only');
    }

    public static function test_endpoint_mark_read_and_mark_all_read()
    {
        $caller = static::__make_portal_user();
        $rows = Portal_Notification_Model::emit($caller->id, 'announcement');
        Portal_Notification_Model::emit($caller->id, 'announcement');

        static::__login_portal($caller->id);

        $marked = Portal_Notifications_Controller::mark_read(Request::create('/'), ['id' => $rows[0]->id]);
        static::__assert_true($marked['changed'], 'endpoint mark_read flips the notification');
        static::__assert_equals(1, $marked['unread_count'], 'endpoint reports updated unread count');

        $all = Portal_Notifications_Controller::mark_all_read(Request::create('/'), []);
        static::__assert_equals(1, $all['count'], 'endpoint mark_all_read marks the remaining unread');
        static::__assert_equals(0, $all['unread_count']);
    }

    public static function test_endpoint_mark_read_rejects_foreign_notification()
    {
        $caller = static::__make_portal_user();
        $other = static::__make_portal_user();
        $other_rows = Portal_Notification_Model::emit($other->id, 'announcement');

        static::__login_portal($caller->id);

        // Caller tries to mark a notification that belongs to another user.
        $result = Portal_Notifications_Controller::mark_read(Request::create('/'), ['id' => $other_rows[0]->id]);
        static::__assert_false($result['changed'], 'endpoint refuses to mark a foreign notification');
        static::__assert_equals(1, Portal_Notification_Model::unread_count($other->id), 'foreign notification stays unread');
    }

    // =====================================================================
    // portal_last_activity_at stamping
    // =====================================================================

    public static function test_portal_last_activity_at_is_stamped()
    {
        $client = new Client_Model();
        $client->name = 'Activity Client';
        $client->save();
        static::__assert_null($client->portal_last_activity_at, 'starts unstamped');

        $user = static::__make_portal_user();
        $membership = new Portal_Membership_Model();
        $membership->portal_user_id = $user->id;
        $membership->client_id = $client->id;
        $membership->role_id = Portal_Membership_Model::ROLE_VIEWER;
        $membership->save();

        // Drive the REAL hook: an authenticated portal request runs
        // Portal_Main::pre_dispatch for a non-exempt handler, which stamps activity
        // on every client the portal user belongs to.
        static::__login_portal($user->id);

        $result = Portal_Main::pre_dispatch(
            Request::create('/'),
            ['_handler' => 'Portal_Notifications_Controller']
        );
        static::__assert_null($result, 'authenticated portal request continues dispatch');

        $reloaded = Client_Model::find($client->id);
        static::__assert_not_empty($reloaded->portal_last_activity_at, 'portal_last_activity_at is written after an authenticated portal request');
    }

    public static function teardown(): void
    {
        Portal_Session::cli_set_portal_user_id(0);
        static::__reset_session();
    }
}
