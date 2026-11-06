<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Services;

use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use Rsx\Models\Notification_Model;

/**
 * Notification_Service - nightly retention for staff notifications.
 *
 * Two settings, two jobs. rsx.notifications.default_expiry_days decides what is SHOWN: the
 * badge and the dropdown leave out an expired notification, and one whose referenced entity
 * has been deleted. rsx.notifications.purge_seen_after_days decides what is DELETED, and only
 * this task deletes: a notification the recipient has SEEN (read_at set) once it is older
 * than that many days, measured from created_at. An unseen notification is never deleted -
 * expired, orphaned or old - and 0 switches the purge off, keeping every notification.
 *
 * The notification reads run in request handlers that hold only their site's read lock, so
 * nothing there deletes; this pass runs in the maintenance window, under each site's write
 * lock.
 */
class Notification_Service extends Rsx_Service_Abstract
{
    /**
     * Seen notifications are deleted in batches of this many rows, one write section per
     * batch: the delete re-states the whole rule, so the batch needs no per-row decision, and
     * a section of this size commits in a fraction of a second, so a web writer queued behind
     * it is barely held back.
     */
    const PURGE_BATCH_SIZE = 500;

    /**
     * Delete every site's SEEN notifications older than rsx.notifications.purge_seen_after_days
     * days (from created_at). Unseen notifications are never deleted. 0 disables the purge.
     *
     * Reports the cutoff per site, progress over the sites, a stdout line, and as its state
     * {deleted, purge_seen_after_days, disabled} plus a one-line summary.
     */
    #[Task('Delete seen notifications older than the retention window')]
    #[Exclusive('maintenance')]
    #[Schedule('maintenance')]
    public static function purge(Task_Instance $task, array $params = [])
    {
        $days = config('rsx.notifications.purge_seen_after_days');
        if (!is_int($days) || $days < 0) {
            throw new \RuntimeException(
                'rsx.notifications.purge_seen_after_days must be a whole number of days, 0 or more (0 disables the purge); got '
                . json_encode($days)
            );
        }

        if ($days === 0) {
            $task->stdout('Notification purge is disabled (rsx.notifications.purge_seen_after_days = 0); nothing deleted.');
            $task->state(['deleted' => 0, 'purge_seen_after_days' => 0, 'disabled' => true]);
            $task->summary('Disabled (purge_seen_after_days = 0); nothing deleted.');

            return null;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        // Every site: retention is install policy, and the worker running this pass serves no
        // tenant. Each site is declared in turn, so the reads below are that site's, and
        // changes only between sections.
        $site_ids = [];
        foreach (Site_Model::query()->orderBy('id')->result_set() as $site) {
            $site_ids[] = (int) $site->id;
        }

        $site_count = count($site_ids);
        foreach ($site_ids as $index => $site_id) {
            if ($task->is_stop_requested()) {
                break;
            }

            Session::set_temporary_site_id($site_id);
            try {
                $task->status("Site {$site_id}: deleting notifications seen and created before {$cutoff->toDateTimeString()}");
                $deleted += static::__purge_seen($task, $site_id, $cutoff);
            } finally {
                Session::clear_temporary_site_id();
            }

            $task->progress_count($index + 1, $site_count);
        }

        $task->stdout($deleted > 0
            ? "Deleted {$deleted} seen notification(s) older than {$days} day(s)."
            : "No seen notification is older than {$days} day(s); nothing deleted.");

        $task->state(['deleted' => $deleted, 'purge_seen_after_days' => $days, 'disabled' => false]);
        $task->summary("Deleted {$deleted} seen notification(s) older than {$days} day(s).");

        return null;
    }

    /**
     * Delete the declared site's seen notifications created before $cutoff, a batch per
     * write section. The batch's delete re-states the rule (seen, created before the cutoff),
     * so the section acts on what is true under the lock rather than on the list read before
     * it.
     */
    private static function __purge_seen(Task_Instance $task, int $site_id, $cutoff): int
    {
        $deleted = 0;
        $batch = [];

        $flush = function () use (&$batch, &$deleted, $site_id, $cutoff) {
            if (empty($batch)) {
                return;
            }

            $ids = $batch;
            $batch = [];

            $deleted += (int) Site_Lock::write($site_id, function () use ($ids, $cutoff) {
                return Notification_Model::whereIn('id', $ids)
                    ->whereNotNull('read_at')
                    ->where('created_at', '<', $cutoff)
                    ->delete();
            }, 'Delete seen notifications past retention');
        };

        $candidates = Notification_Model::whereNotNull('read_at')
            ->where('created_at', '<', $cutoff)
            ->result_set();

        foreach ($candidates as $notification) {
            $task->heartbeat();
            if ($task->is_stop_requested()) {
                break;
            }

            $batch[] = (int) $notification->id;
            if (count($batch) >= self::PURGE_BATCH_SIZE) {
                $flush();
            }
        }

        $flush();

        return $deleted;
    }
}
