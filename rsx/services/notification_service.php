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
 * Notification_Service - nightly housekeeping for staff notifications.
 *
 * Notifications are READ by request handlers that hold only their site's read lock (the
 * header badge's get_count, the dropdown's get_dropdown), so nothing there deletes. The
 * rows those reads skip - expired ones, and ones whose referenced entity has been deleted -
 * are removed here instead, in the maintenance window, under each site's write lock.
 */
class Notification_Service extends Rsx_Service_Abstract
{
    /**
     * Expired notifications are deleted in batches of this many rows, one write section per
     * batch: an expired row is never read again, so the batch needs no per-row decision, and
     * a section of this size commits in a fraction of a second, so a web writer queued behind
     * it is barely held back.
     */
    const EXPIRE_BATCH_SIZE = 500;

    /**
     * Delete every site's expired notifications and those whose entity no longer exists.
     */
    #[Task('Delete expired notifications and those whose subject record is gone')]
    #[Exclusive('maintenance')]
    #[Schedule('maintenance')]
    public static function purge(Task_Instance $task, array $params = [])
    {
        $cutoff = now();
        $expired = 0;
        $orphaned = 0;

        // Every site: retention is install policy, and the worker running this pass serves no
        // tenant. Each site is declared in turn, so the reads below - the notifications and
        // the entities they reference - are that site's, and changes only between sections.
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
                $task->status("Site {$site_id}: deleting expired notifications");
                $expired += static::__purge_expired($task, $site_id, $cutoff);

                if ($task->is_stop_requested()) {
                    break;
                }

                $task->status("Site {$site_id}: deleting notifications whose entity is gone");
                $orphaned += static::__purge_orphaned($task, $site_id, $cutoff);
            } finally {
                Session::clear_temporary_site_id();
            }

            $task->progress_count($index + 1, $site_count);
        }

        $task->stdout(($expired + $orphaned) > 0
            ? "Deleted {$expired} expired notification(s) and {$orphaned} whose entity no longer exists."
            : 'No notification is expired or orphaned; nothing deleted.');

        $task->state(['expired' => $expired, 'orphaned' => $orphaned]);
        $task->summary("Deleted {$expired} expired and {$orphaned} orphaned notification(s).");

        return null;
    }

    /**
     * Delete the declared site's notifications that expired before $cutoff, a batch per
     * write section. The batch's delete re-states the expiry test, so the section acts on
     * what is true under the lock rather than on the list read before it.
     */
    private static function __purge_expired(Task_Instance $task, int $site_id, $cutoff): int
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
                    ->where('expires_at', '<', $cutoff)
                    ->delete();
            }, 'Delete expired notifications');
        };

        foreach (Notification_Model::where('expires_at', '<', $cutoff)->result_set() as $notification) {
            $task->heartbeat();
            if ($task->is_stop_requested()) {
                break;
            }

            $batch[] = (int) $notification->id;
            if (count($batch) >= self::EXPIRE_BATCH_SIZE) {
                $flush();
            }
        }

        $flush();

        return $deleted;
    }

    /**
     * Delete the declared site's unexpired notifications whose referenced entity has been
     * deleted. One write section per notification, re-reading it and re-checking the entity
     * inside: the entity's absence is a decision, and it is made under the lock.
     */
    private static function __purge_orphaned(Task_Instance $task, int $site_id, $cutoff): int
    {
        $deleted = 0;

        $candidates = Notification_Model::where('expires_at', '>=', $cutoff)
            ->whereNotNull('entity_type')
            ->whereNotNull('entity_id')
            ->result_set();

        foreach ($candidates as $notification) {
            $task->heartbeat();
            if ($task->is_stop_requested()) {
                break;
            }

            if ($notification->is_valid()) {
                continue;
            }

            $removed = Site_Lock::write($site_id, function () use ($notification) {
                $current = Notification_Model::find($notification->id);
                if ($current === null || $current->is_valid()) {
                    return false;
                }

                $current->delete();

                return true;
            }, "Delete notification #{$notification->id} (entity gone)");

            if ($removed) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
