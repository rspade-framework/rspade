<?php

namespace App\RSpade\Core\Api;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Api_Cleanup_Service
 *
 * Scheduled retention for the _api_request_log table. Rows older than
 * config('rsx.api.log_retention_days') are pruned daily. Mirrors
 * Session_Cleanup_Service: deletes are CHUNKED (bounded DELETE ... LIMIT batches) so a
 * large backlog never issues one giant lock-holding DELETE, and the task is #[Exclusive]
 * so a slow backlog-recovery run never overlaps its own next tick.
 */
class Api_Cleanup_Service extends Rsx_Service_Abstract
{
    /**
     * Rows deleted per DELETE statement. Small enough to keep each statement's lock hold
     * trivial, large enough that a backlog clears in a few statements. Overridable per run
     * via $params['chunk_size'] (testing).
     */
    private const DELETE_CHUNK_SIZE = 10000;

    /**
     * Prune _api_request_log rows older than the configured retention window.
     *
     * @param Task_Instance $task Task instance for heartbeats
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return array Cleanup statistics
     */
    #[Task('Clean up old API request log records')]
    #[Exclusive]
    #[Schedule('daily at 3am')]
    public static function cleanup_request_log(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $retention_days = (int) config('rsx.api.log_retention_days', 30);
        $cutoff = now()->subDays($retention_days);

        $total = 0;
        while (true) {
            $deleted = DB::table('_api_request_log')
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
            $task->info("Deleted {$total} API request log rows older than {$retention_days} days");
        }

        return [
            'deleted' => $total,
            'retention_days' => $retention_days,
        ];
    }
}
