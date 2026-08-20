<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Task_Health_Checks - scheduler probes for rsx:health.
 *
 * Declared next to the task machinery they probe. Redis (worker-pool) connectivity is a
 * separate check on Task_Worker_Registry; these two read the recurring #[Schedule] tracker
 * rows: one asks whether the `rsx:task:process` cron is ticking at all, the other whether
 * a schedule that IS ticking keeps throwing.
 *
 * There is NO scheduler heartbeat timestamp in the system - the only durable signal is
 * the recurring #[Schedule] tracker rows (`_tasks` rows with next_run_at NOT NULL), which
 * the cron reconciles and advances each tick. Zero trackers => the cron has never run.
 * A tracker whose next_run_at is well in the past => the cron is not advancing it. That
 * staleness signal CANNOT distinguish a missing cron tick from a wedged worker pool, so
 * it is a WARN-with-caveat, never a FAIL.
 */
class Task_Health_Checks
{
    /** Minutes past next_run_at before the oldest tracker is considered stale. */
    private const STALE_MINUTES = 10;

    /** Characters of a failing schedule's explanation carried into the WARN detail. */
    private const REASON_EXCERPT_LENGTH = 60;

    /**
     * Infer scheduler liveness from #[Schedule] tracker-row staleness.
     *
     * @return array
     */
    #[Health_Check('Task Scheduler Liveness')]
    public static function task_scheduler_liveness(): array
    {
        $tracker_count = DB::table('_tasks')->whereNotNull('next_run_at')->count();

        if ($tracker_count === 0) {
            return [
                'status' => 'WARN',
                'detail' => 'no #[Schedule] tracker rows - rsx:task:process has never run',
                'remediation' => 'install the cron entry: * * * * * cd '
                    . base_path() . ' && php artisan rsx:task:process',
            ];
        }

        $oldest = DB::table('_tasks')->whereNotNull('next_run_at')->min('next_run_at');
        $stale_seconds = Rsx_Time::seconds_since($oldest);

        if ($stale_seconds > self::STALE_MINUTES * 60) {
            return [
                'status' => 'WARN',
                'detail' => $tracker_count . ' tracker(s); oldest is due ' . Rsx_Time::relative($oldest)
                    . ' (over ' . self::STALE_MINUTES . 'min stale). NOTE: this cannot distinguish a'
                    . ' missing cron tick from a stalled worker pool',
                'remediation' => 'verify the rsx:task:process cron is running and the worker pool is not wedged',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => $tracker_count . ' schedule tracker(s); next due ' . Rsx_Time::relative($oldest),
        ];
    }

    /**
     * Report #[Schedule] tracker rows whose task keeps throwing.
     *
     * A failing schedule is INVISIBLE without this. A tracker is never terminal - a run
     * that throws recycles it to PENDING so the schedule retries at its next cadence -
     * so the row looks healthy while the work never succeeds. consecutive_failures is
     * what distinguishes "retrying" from "broken every night for a week".
     *
     * WARN, never FAIL: the schedule is still running, and one repeatedly-failing task
     * is not a reason to fail the environment's health.
     *
     * @return array
     */
    #[Health_Check('Task Schedule Failures')]
    public static function task_schedule_failures(): array
    {
        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after', 3);

        $failing = DB::table('_tasks')
            ->whereNotNull('next_run_at')
            ->where('consecutive_failures', '>=', $threshold)
            ->orderByDesc('consecutive_failures')
            ->get(['class', 'method', 'consecutive_failures', 'status_reason', 'error']);

        if ($failing->isEmpty()) {
            return [
                'status' => 'OK',
                'detail' => 'no schedule failing repeatedly',
            ];
        }

        // Bounded by the number of #[Schedule] definitions in the manifest, so the whole
        // offender list is named rather than a count with a "see the logs" pointer.
        $offenders = [];
        foreach ($failing as $tracker) {
            $offender = class_basename($tracker->class) . '::' . $tracker->method
                . ' (' . (int) $tracker->consecutive_failures . ' consecutive failures';

            $reason = static::__excerpt_reason($tracker->status_reason ?? $tracker->error);
            if ($reason !== '') {
                $offender .= ', last: ' . $reason;
            }

            $offenders[] = $offender . ')';
        }

        return [
            'status' => 'WARN',
            'detail' => count($offenders) . ' schedule(s) failing every run: ' . implode('; ', $offenders),
            'remediation' => 'inspect the failure with php artisan rsx:tasks:list and fix the task;'
                . ' the schedule keeps retrying at its cadence meanwhile',
        ];
    }

    /**
     * First line of a failure explanation, capped for a one-line health detail.
     *
     * @param string|null $reason status_reason (the recycle summary) or the raw error text.
     * @return string Empty when the row carries no explanation.
     */
    private static function __excerpt_reason(?string $reason): string
    {
        $reason = trim(explode("\n", (string) $reason)[0]);

        if (mb_strlen($reason) > self::REASON_EXCERPT_LENGTH) {
            $reason = mb_substr($reason, 0, self::REASON_EXCERPT_LENGTH - 3) . '...';
        }

        return $reason;
    }
}
