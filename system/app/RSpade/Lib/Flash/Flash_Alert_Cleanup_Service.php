<?php

namespace App\RSpade\Lib\Flash;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Flash_Alert_Cleanup_Service
 *
 * Hourly retention sweep for _flash_alerts.
 *
 * The read path already expires alerts at one minute - but only for the session
 * doing the reading. A visitor who is handed an alert and never comes back leaves
 * the row behind, and nothing else collects it until the SESSION is deleted months
 * later by Session_Cleanup_Service (the FK cascades). This sweep is the unconditional
 * age rule that closes that gap: an alert older than 30 minutes cannot still be
 * waiting for a browser that is coming back for it.
 *
 * Deletes are CHUNKED (bounded DELETE ... LIMIT batches), like the session and API-log
 * sweeps: steady state is a handful of rows, but the FIRST run on an installation that
 * predates this task faces every abandoned alert ever queued, and no maintenance sweep
 * should hold one giant lock. #[Exclusive] so a backlog-recovery run never overlaps its
 * own next tick.
 *
 * Statement choice: this is a raw age-bounded DELETE via DB::table, not a fetch-then-
 * iterate over Flash_Alert_Model. The bulk-write mandate exists to keep per-record
 * realtime frames and after_* hooks firing; Flash_Alert_Model declares neither (no
 * $realtime, no lifecycle overrides), so a per-record walk would load rows to no
 * purpose. No result set is materialized and nothing is truncated - the loop runs until
 * the predicate matches nothing.
 */
class Flash_Alert_Cleanup_Service extends Rsx_Service_Abstract
{
    /**
     * Age at which an unclaimed alert is worthless. Far beyond the one-minute read
     * expiry and beyond any plausible redirect, so a row this old is by definition
     * one nobody is coming back for. Fixed, not configurable: there is no useful
     * operator decision here.
     */
    private const RETENTION_MINUTES = 30;

    /**
     * Rows deleted per DELETE statement. Small enough to keep each statement's lock
     * hold trivial, large enough that a backlog clears in a few statements.
     * Overridable per run via $params['chunk_size'] (testing).
     */
    private const DELETE_CHUNK_SIZE = 10000;

    /**
     * Delete flash alerts older than the retention window, in both experiences.
     *
     * Age-based and deliberately experience-agnostic: an abandoned alert is abandoned
     * whichever experience queued it. The is_portal column exists to route DELIVERY
     * (a staff page must not consume a portal page's message); it has nothing to say
     * about retention, so this sweep does not read it.
     *
     * @param Task_Instance $task Task instance for heartbeats
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return array Cleanup statistics
     */
    #[Task('Delete abandoned flash alerts (runs hourly)')]
    #[Exclusive]
    #[Schedule('hourly')]
    public static function cleanup_expired_alerts(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $cutoff = now()->subMinutes(self::RETENTION_MINUTES);

        $total = 0;

        while (true) {
            $deleted = DB::table('_flash_alerts')
                ->where('created_at', '<', $cutoff)
                ->limit($chunk_size)
                ->delete();
            $total += $deleted;

            if ($deleted < $chunk_size) {
                break;
            }

            $task->heartbeat();
        }

        if ($total > 0) {
            $task->info("Deleted {$total} flash alerts older than " . self::RETENTION_MINUTES . ' minutes');
        }

        return [
            'deleted' => $total,
            'retention_minutes' => self::RETENTION_MINUTES,
        ];
    }
}
