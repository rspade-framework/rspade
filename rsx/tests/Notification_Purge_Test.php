<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Notification_Model;

/**
 * Notification housekeeping happens in the nightly task, never in the reads.
 *
 *   - get_count and get_dropdown are readers (the site's read lock, where a site-scoped
 *     delete fatals): called through Ajax::internal(), the real dispatch path, with an
 *     expired notification and one whose entity is gone present, they answer without
 *     error, leave both rows out of what they report, and delete neither;
 *   - Notification_Service::purge deletes every site's expired notifications and the
 *     unexpired ones whose entity is gone, and keeps a valid one.
 *
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Notification_Purge_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const USER_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_user(self::USER_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    /**
     * A notification for the acting login identity on the acting site. $orphaned points it
     * at a project id that does not exist.
     */
    private static function __notification(string $expires_at, bool $orphaned = false): Notification_Model
    {
        $notification = new Notification_Model();
        $notification->site_id = (int) Session::get_site_id();
        $notification->user_id = (int) Session::get_login_user_id();
        $notification->type_id = Notification_Model::TYPE_TASK_ASSIGNED;
        $notification->metadata = json_encode(['task_title' => 'Purge probe', 'project_name' => 'Purge probe']);
        if ($orphaned) {
            $notification->entity_type = 'Project_Model';
            $notification->entity_id = 999999999;
        }
        $notification->expires_at = $expires_at;
        $notification->save();

        return $notification;
    }

    private static function __exists(Notification_Model $notification): bool
    {
        return Notification_Model::without_site_scope(fn () => Notification_Model::find($notification->id)) !== null;
    }

    public static function test_the_reads_write_nothing_and_the_purge_cleans_every_site()
    {
        $past = now()->subDay()->toDateTimeString();
        $future = now()->addDays(5)->toDateTimeString();

        $expired = static::__notification($past);
        $orphaned = static::__notification($future, orphaned: true);
        $valid = static::__notification($future);

        $count = Ajax::internal('Frontend_Notifications_Controller', 'get_count', []);
        static::__assert_equals(1, $count['count'] ?? null, 'get_count counts the valid unread row only: ' . json_encode($count));

        $dropdown = Ajax::internal('Frontend_Notifications_Controller', 'get_dropdown', ['limit' => 5]);
        static::__assert_equals(1, $dropdown['total'] ?? null, 'get_dropdown lists the valid row only: ' . json_encode($dropdown));
        static::__assert_equals(1, $dropdown['unread'] ?? null, 'and counts it unread');
        static::__assert_equals($valid->id, $dropdown['notifications'][0]['id'] ?? null);

        static::__assert_true(static::__exists($expired), 'the reader left the expired row in place');
        static::__assert_true(static::__exists($orphaned), 'the reader left the orphaned row in place');

        // A second tenant's expired row: the purge serves every site, not the declared one.
        $other_site = new Site_Model();
        $other_site->slug = 'notification-purge-' . uniqid();
        $other_site->name = 'Notification purge probe tenant';
        $other_site->save();

        static::__acting_as_site((int) $other_site->id);
        $other_expired = static::__notification($past);
        static::__assert_equals((int) $other_site->id, (int) $other_expired->site_id, 'the probe row is on the other site');

        // A scheduled run serves no tenant.
        static::__reset_session();

        $run = Task::internal('Notification_Service', 'purge');
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the purge completed');

        static::__assert_false(static::__exists($expired), 'the expired row is deleted');
        static::__assert_false(static::__exists($other_expired), 'and the other site\'s expired row');
        static::__assert_false(static::__exists($orphaned), 'the orphaned row is deleted');
        static::__assert_true(static::__exists($valid), 'the valid row is kept');

        $state = $run->state();
        static::__assert_true(($state['expired'] ?? 0) >= 2, 'the state reports the expired rows: ' . json_encode($state));
        static::__assert_true(($state['orphaned'] ?? 0) >= 1, 'and the orphaned one');
    }
}
