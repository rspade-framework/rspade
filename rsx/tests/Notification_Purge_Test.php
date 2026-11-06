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
 * Notification retention: the reads write nothing, and only the nightly task deletes.
 *
 *   - get_count and get_dropdown are readers (the site's read lock, where a site-scoped
 *     delete fatals): called through Ajax::internal(), the real dispatch path, with an
 *     expired notification and one whose entity is gone present, they answer without
 *     error, leave both rows out of what they report, and delete neither;
 *   - Notification_Service::purge deletes, on every site, a SEEN notification created more
 *     than rsx.notifications.purge_seen_after_days days ago, and keeps a seen one inside
 *     the window and every UNSEEN one - old, expired or orphaned - and reports the count in
 *     its state and summary;
 *   - purge_seen_after_days = 0 disables the purge: nothing is deleted and the run says so.
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
     * A notification for the acting login identity on the acting site, created $age_days
     * ago. $seen marks it read; $orphaned points it at a project id that does not exist.
     */
    private static function __notification(
        int $age_days,
        bool $seen = false,
        bool $orphaned = false,
        ?string $expires_at = null
    ): Notification_Model {
        $created_at = now()->subDays($age_days);

        $notification = new Notification_Model();
        $notification->site_id = (int) Session::get_site_id();
        $notification->user_id = (int) Session::get_login_user_id();
        $notification->type_id = Notification_Model::TYPE_TASK_ASSIGNED;
        $notification->metadata = json_encode(['task_title' => 'Purge probe', 'project_name' => 'Purge probe']);
        if ($orphaned) {
            $notification->entity_type = 'Project_Model';
            $notification->entity_id = 999999999;
        }
        $notification->created_at = $created_at->toDateTimeString();
        $notification->expires_at = $expires_at ?? $created_at->copy()->addDays(21)->toDateTimeString();
        if ($seen) {
            $notification->read_at = $created_at->copy()->addHour()->toDateTimeString();
        }
        $notification->save();

        return $notification;
    }

    private static function __exists(Notification_Model $notification): bool
    {
        return Notification_Model::without_site_scope(fn () => Notification_Model::find($notification->id)) !== null;
    }

    public static function test_the_reads_write_nothing()
    {
        $past = now()->subDay()->toDateTimeString();
        $future = now()->addDays(5)->toDateTimeString();

        $expired = static::__notification(0, expires_at: $past);
        $orphaned = static::__notification(0, orphaned: true, expires_at: $future);
        $valid = static::__notification(0, expires_at: $future);

        $count = Ajax::internal('Frontend_Notifications_Controller', 'get_count', []);
        static::__assert_equals(1, $count['count'] ?? null, 'get_count counts the valid unread row only: ' . json_encode($count));

        $dropdown = Ajax::internal('Frontend_Notifications_Controller', 'get_dropdown', ['limit' => 5]);
        static::__assert_equals(1, $dropdown['total'] ?? null, 'get_dropdown lists the valid row only: ' . json_encode($dropdown));
        static::__assert_equals(1, $dropdown['unread'] ?? null, 'and counts it unread');
        static::__assert_equals($valid->id, $dropdown['notifications'][0]['id'] ?? null);

        static::__assert_true(static::__exists($expired), 'the reader left the expired row in place');
        static::__assert_true(static::__exists($orphaned), 'the reader left the orphaned row in place');
    }

    public static function test_the_purge_deletes_only_seen_notifications_past_the_window_on_every_site()
    {
        $days = config('rsx.notifications.purge_seen_after_days');
        static::__assert_equals(90, $days, 'the template ships a 90-day window');

        $seen_old = static::__notification($days + 5, seen: true);
        $seen_old_orphaned = static::__notification($days + 5, seen: true, orphaned: true);
        $seen_recent = static::__notification($days - 5, seen: true);
        $seen_recent_orphaned = static::__notification($days - 5, seen: true, orphaned: true);
        $unseen_old = static::__notification($days + 5);
        $unseen_old_orphaned = static::__notification($days + 5, orphaned: true);
        $unseen_expired = static::__notification(30);
        $unseen_orphaned = static::__notification(1, orphaned: true);

        // A second tenant's seen, old row: the purge serves every site, not the declared one.
        $other_site = new Site_Model();
        $other_site->slug = 'notification-purge-' . uniqid();
        $other_site->name = 'Notification purge probe tenant';
        $other_site->save();

        static::__acting_as_site((int) $other_site->id);
        $other_seen_old = static::__notification($days + 5, seen: true);
        static::__assert_equals((int) $other_site->id, (int) $other_seen_old->site_id, 'the probe row is on the other site');

        // A scheduled run serves no tenant.
        static::__reset_session();

        $run = Task::internal('Notification_Service', 'purge');
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the purge completed');

        static::__assert_false(static::__exists($seen_old), 'a seen row older than the window is deleted');
        static::__assert_false(static::__exists($seen_old_orphaned), 'and a seen, orphaned one older than the window');
        static::__assert_false(static::__exists($other_seen_old), 'and the other site\'s seen, old row');
        static::__assert_true(static::__exists($seen_recent), 'a seen row inside the window is kept');
        static::__assert_true(static::__exists($seen_recent_orphaned), 'a seen orphan inside the window waits for the age rule');
        static::__assert_true(static::__exists($unseen_old), 'an unseen row older than the window is kept');
        static::__assert_true(static::__exists($unseen_old_orphaned), 'an unseen, old, orphaned row is kept');
        static::__assert_true(static::__exists($unseen_expired), 'an unseen, expired row is kept');
        static::__assert_true(static::__exists($unseen_orphaned), 'an unseen, orphaned row is kept');

        $state = $run->state();
        static::__assert_true(($state['deleted'] ?? 0) >= 3, 'the state reports the deleted rows: ' . json_encode($state));
        static::__assert_equals(90, $state['purge_seen_after_days'] ?? null, 'and the window');
        static::__assert_false($state['disabled'] ?? null, 'and that the purge ran');
        static::__assert_true(str_starts_with((string) $run->summary(), "Deleted {$state['deleted']} seen notification(s)"), 'the summary reports the count: ' . $run->summary());
    }

    public static function test_zero_disables_the_purge()
    {
        $seen_old = static::__notification(400, seen: true);

        static::__reset_session();

        $configured = config('rsx.notifications.purge_seen_after_days');
        config(['rsx.notifications.purge_seen_after_days' => 0]);
        try {
            $run = Task::internal('Notification_Service', 'purge');
        } finally {
            config(['rsx.notifications.purge_seen_after_days' => $configured]);
        }

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the disabled purge completes');
        static::__assert_true(static::__exists($seen_old), 'with 0, even a seen row past any window is kept');

        $state = $run->state();
        static::__assert_equals(['deleted' => 0, 'purge_seen_after_days' => 0, 'disabled' => true], $state, 'the state reports the purge disabled');
        static::__assert_true(str_contains((string) $run->summary(), 'Disabled'), 'and so does the summary: ' . $run->summary());
    }
}
