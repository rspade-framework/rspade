<?php

namespace App\RSpade\Core\Files;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Zip_Download_Cleanup_Service
 *
 * Scheduled retention for the _zip_download_requests table. Rows older than
 * config('rsx.attachments.zip_request_retention_hours') are pruned every six hours -
 * the same window is_expired() enforces at serve time, so this only reclaims rows a
 * download will already refuse. Mirrors Api_Cleanup_Service: deletes are CHUNKED
 * (bounded DELETE ... LIMIT batches) so a large backlog never issues one giant
 * lock-holding DELETE, and the task is #[Exclusive] so a slow backlog-recovery run
 * never overlaps its own next tick.
 */
class Zip_Download_Cleanup_Service extends Rsx_Service_Abstract
{
    /**
     * Rows deleted per DELETE statement. Small enough to keep each statement's lock hold
     * trivial, large enough that a backlog clears in a few statements. Overridable per run
     * via $params['chunk_size'] (testing).
     */
    private const DELETE_CHUNK_SIZE = 10000;

    /**
     * Prune _zip_download_requests rows older than the configured validity window.
     *
     * @param Task_Instance $task Task instance for heartbeats
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return array Cleanup statistics
     */
    #[Task('Clean up expired zip download requests')]
    #[Exclusive]
    #[Schedule('every 6 hours')]
    public static function cleanup_expired_requests(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $retention_hours = (int) config('rsx.attachments.zip_request_retention_hours', 24);
        $cutoff = now()->subHours($retention_hours);

        $total = 0;
        while (true) {
            $deleted = DB::table('_zip_download_requests')
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
            $task->info("Deleted {$total} zip download requests older than {$retention_hours} hours");
        }

        return [
            'deleted' => $total,
            'retention_hours' => $retention_hours,
        ];
    }
}
