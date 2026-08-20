<?php

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Status;

/**
 * Force-kills a running task's worker process and settles its _tasks row.
 *
 * Sequence: SIGTERM (15) -> up to 5s grace -> SIGKILL (9), using the row's worker_pid and the
 * same posix_kill($pid, 0) liveness probe the stuck-task reaper uses. Signal numbers are literal
 * (15/9) so no pcntl constants are required.
 *
 * Row settle: an ON-DEMAND row (next_run_at IS NULL) goes terminal KILLED with the explanation on
 * status_reason. A CRON TRACKER row (next_run_at set) is RECYCLED to PENDING instead - a terminal
 * status on a tracker silently stops the recurring schedule - with the explanation still recorded.
 * MySQL GET_LOCK run-locks auto-release when the worker's connection dies; the optional
 * RELEASE_LOCK is a best-effort no-op. The Redis worker slot self-heals within worker_heartbeat_ttl.
 */
class Task_Killer
{
    /** Seconds to wait for a graceful SIGTERM exit before escalating to SIGKILL. */
    private const GRACE_SECONDS = 5;

    private const SIGTERM = 15;
    private const SIGKILL = 9;

    /**
     * @param object $row A RUNNING _tasks row (id, worker_pid, next_run_at, lock_key).
     * @return string 'killed' | 'killed_no_process' | 'recycled'
     */
    public static function kill(object $row, string $explanation): string
    {
        $pid = $row->worker_pid ? (int) $row->worker_pid : 0;
        $signalled = false;

        if ($pid > 0 && @posix_kill($pid, 0)) {
            @posix_kill($pid, self::SIGTERM);
            $signalled = true;

            $deadline = time() + self::GRACE_SECONDS;
            while (time() < $deadline && @posix_kill($pid, 0)) {
                usleep(200000); // 0.2s
            }

            if (@posix_kill($pid, 0)) {
                @posix_kill($pid, self::SIGKILL);
                for ($i = 0; $i < 10 && @posix_kill($pid, 0); $i++) {
                    usleep(100000); // 0.1s, up to ~1s for the OS to reap
                }
            }
        }

        // Best-effort: release the identity run-lock. No-ops if the worker's DB connection (which
        // held it) has already closed - which is the normal case once the worker is dead.
        if (!empty($row->lock_key)) {
            try {
                DB::select('SELECT RELEASE_LOCK(?)', [$row->lock_key]);
            } catch (\Throwable $e) {
                // ignore - the lock is gone with the dead connection
            }
        }

        if ($row->next_run_at !== null) {
            DB::table('_tasks')->where('id', $row->id)->update([
                'status'        => Task_Status::PENDING,
                'worker_pid'    => null,
                'status_reason' => 'killed (recycled): ' . $explanation,
                'updated_at'    => now(),
            ]);
            return 'recycled';
        }

        DB::table('_tasks')->where('id', $row->id)->update([
            'status'        => Task_Status::KILLED,
            'status_reason' => $explanation,
            'worker_pid'    => null,
            'completed_at'  => now(),
            'updated_at'    => now(),
        ]);

        return $signalled ? 'killed' : 'killed_no_process';
    }
}
