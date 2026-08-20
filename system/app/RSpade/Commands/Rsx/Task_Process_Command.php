<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Task_Killer;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Worker_Registry;
use App\RSpade\Core\Task\Cron_Parser;

/**
 * Task Process Command
 *
 * The scheduler/dispatcher tick. Run via system cron every minute:
 *   * * * * * cd /var/www/html && php artisan rsx:task:process
 *
 * Each tick it:
 * 1. Recovers unsettleable RUNNING rows: those whose worker PID is dead, and those whose
 *    live worker has outrun the task's timeout (killed via Task_Killer). This is
 *    task-level recovery only - worker concurrency is gated by the Redis worker-slot
 *    registry (Task_Worker_Registry), NOT by counting RUNNING rows.
 * 2. Revives any cron tracker found sitting in a terminal state - the backstop for the
 *    invariant that a recurring schedule is never permanently terminal.
 * 3. Reconciles #[Schedule] tracker rows against the manifest (create new, regenerate
 *    on a changed cron expression, delete removed) so schedule edits take effect within
 *    one tick.
 * 4. If there is pending work, spawns up to (global_max_workers - live_workers) detached
 *    workers. It does NOT become a worker itself.
 *
 * Workers self-admit against the registry, so an over-spawn is harmless (excess workers
 * exit cleanly).
 */
class Task_Process_Command extends Command
{
    protected $signature = 'rsx:task:process
        {--once : Process one pending task inline then exit (for testing)}
        {--force-scheduled : Force every scheduled task due now}';

    protected $description = 'Scheduler/dispatcher tick: reconcile schedules and spawn workers (run via cron every minute)';

    public function handle()
    {
        $this->info('[TASK PROCESSOR] Starting task processor');

        // Step 1: recover stuck tasks (task-level, not worker accounting)
        $this->detect_stuck_tasks();

        // Step 2: enforce the never-terminal invariant on tracker rows
        $this->revive_stranded_trackers();

        // Step 3: reconcile #[Schedule] tracker rows with the manifest
        $this->reconcile_schedules($this->option('force-scheduled'));

        // Step 4: spawn workers to cover pending work (up to the pool cap)
        $this->spawn_deficit_workers();

        // Testing aid: drain one task inline instead of relying on a spawned worker.
        if ($this->option('once')) {
            $this->process_one_task();
        }

        $this->info('[TASK PROCESSOR] Task processor complete');

        return 0;
    }

    /**
     * Recover RUNNING rows that will never settle on their own. Two arms:
     *
     * 1. DEAD WORKER - started longer ago than cleanup_stuck_after and the worker process is
     *    gone. On-demand rows are failed; cron tracker rows are recycled to pending (their
     *    next_run_at was already advanced before the run, so they simply fire again on
     *    schedule) - failing them would silently kill the cron.
     *
     * 2. TIMED OUT - the worker is alive but has exceeded its execution cap
     *    (the row's own timeout, else rsx.tasks.default_timeout). Task_Killer settles it the
     *    same way rsx:tasks:kill does: SIGTERM -> 5s -> SIGKILL, then KILLED (on-demand) or
     *    recycled to PENDING (cron tracker). This is the ONLY enforcement of tasks.timeout, so
     *    the cap's granularity is one cron tick.
     *
     * A row with neither a row timeout nor a configured default is never timeout-killed.
     */
    private function detect_stuck_tasks(): void
    {
        $cleanup_after = (int) config('rsx.tasks.cleanup_stuck_after', 1800);
        $default_timeout = (int) config('rsx.tasks.default_timeout', 0);

        $running_tasks = DB::table('_tasks')
            ->where('status', Task_Status::RUNNING)
            ->get();

        foreach ($running_tasks as $task) {
            $worker_alive = $task->worker_pid && posix_kill($task->worker_pid, 0);

            if ($worker_alive) {
                $this->enforce_task_timeout($task, $default_timeout);
                continue;
            }

            if ($task->started_at === null || strtotime($task->started_at) > time() - $cleanup_after) {
                // Worker is gone but the row has not aged past the stuck threshold yet.
                continue;
            }

            if ($task->next_run_at !== null) {
                // Cron tracker row: recycle it, do not fail it.
                $this->warn("[STUCK TASK] Recycling stuck cron tracker {$task->id} (worker PID {$task->worker_pid} gone)");
                DB::table('_tasks')->where('id', $task->id)->update([
                    'status' => Task_Status::PENDING,
                    'worker_pid' => null,
                    'updated_at' => now(),
                ]);
                continue;
            }

            $this->warn("[STUCK TASK] Failing stuck task {$task->id} (worker PID {$task->worker_pid} not responding)");
            DB::table('_tasks')->where('id', $task->id)->update([
                'status' => Task_Status::FAILED,
                'error' => 'Task stuck - worker process not responding',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Kill a live-worker task that has outrun its execution cap.
     *
     * The cap is the row's own timeout, or the configured default when the row carries none.
     * Zero/absent on both means no cap is defined for this task and it runs unbounded.
     *
     * @param object $task A RUNNING _tasks row whose worker process is alive.
     * @param int $default_timeout Seconds from rsx.tasks.default_timeout (0 = none configured).
     */
    private function enforce_task_timeout(object $task, int $default_timeout): void
    {
        $timeout = (int) ($task->timeout ?? 0);
        if ($timeout <= 0) {
            $timeout = $default_timeout;
        }

        if ($timeout <= 0 || $task->started_at === null) {
            return;
        }

        $ran_for = time() - strtotime($task->started_at);
        if ($ran_for <= $timeout) {
            return;
        }

        $explanation = "timed out after {$ran_for}s (cap {$timeout}s)";
        $outcome = Task_Killer::kill($task, $explanation);

        $this->warn("[TASK TIMEOUT] {$task->class}::{$task->method} (task {$task->id}, worker PID {$task->worker_pid}) {$explanation} -> {$outcome}");
    }

    /**
     * Revive cron tracker rows found sitting in a terminal state.
     *
     * INVARIANT: a cron tracker is never permanently terminal. One tracker row exists per
     * #[Schedule] and IS that schedule - park it in FAILED or KILLED and the schedule stops
     * forever, silently, while the row still looks like a registered task. This is the
     * failure the dead-worker reaper already names in its own comment: "failing them would
     * silently kill the cron."
     *
     * The terminal writers (Task_Instance::mark_failed/mark_completed) and Task_Killer now
     * enforce that rule at the source, so this sweep should never fire. It exists for the
     * strands they cannot reach: rows stranded by a pre-fix release and carried in on a
     * framework pull, rows edited by hand in SQL, and whatever crash window has not been
     * imagined yet. Enforced, not merely intended.
     *
     * The failure record is preserved: only status and worker_pid are touched, so error,
     * status_reason, last_error_at and consecutive_failures still say what went wrong. The
     * row's next_run_at was advanced before its run, so reviving it simply fires the next
     * cadence - it never re-runs immediately.
     */
    private function revive_stranded_trackers(): void
    {
        $stranded = DB::table('_tasks')
            ->whereNotNull('next_run_at')
            ->whereIn('status', [Task_Status::FAILED, Task_Status::KILLED])
            ->get(['id', 'class', 'method', 'status']);

        foreach ($stranded as $tracker) {
            DB::table('_tasks')->where('id', $tracker->id)->update([
                'status' => Task_Status::PENDING,
                'worker_pid' => null,
                'updated_at' => now(),
            ]);

            $message = "Revived cron tracker {$tracker->id} ({$tracker->class}::{$tracker->method}) "
                . "stranded in status '{$tracker->status}' - a recurring schedule must never be terminal";

            $this->warn("[STRANDED SCHEDULE] {$message}");
            Log::warning("[STRANDED SCHEDULE] {$message}");
        }
    }

    /**
     * Reconcile recurring #[Schedule] tracker rows against the manifest.
     *
     * For each scheduled task: create a tracker if none exists; if one exists but its
     * stored cron_expression differs from the manifest, delete and regenerate it so the
     * next_run_at is recomputed from the new schedule immediately (a stale next_run_at
     * from the old expression can never linger). Tracker rows whose (class, method) no
     * longer appears in the manifest are deleted.
     *
     * @param bool $force_all If true, mark every tracker due now.
     */
    private function reconcile_schedules(bool $force_all): void
    {
        $scheduled_tasks = Task::get_scheduled_tasks();
        $seen = [];

        foreach ($scheduled_tasks as $task_def) {
            $class = $task_def['class'];
            $method = $task_def['method'];
            $cron_expression = $task_def['cron_expression'];
            $queue = $task_def['queue'];
            $seen[$class . '::' . $method] = true;

            $existing = DB::table('_tasks')
                ->where('class', $class)
                ->where('method', $method)
                ->whereNotNull('next_run_at')
                ->first();

            if ($existing && $existing->cron_expression === $cron_expression) {
                if ($force_all) {
                    DB::table('_tasks')->where('id', $existing->id)->update([
                        'next_run_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                continue;
            }

            // Missing, or the schedule changed: (re)create the tracker from scratch.
            if ($existing) {
                $this->info("[SCHEDULED] Schedule changed for {$class}::{$method}, regenerating tracker");
                DB::table('_tasks')
                    ->where('class', $class)
                    ->where('method', $method)
                    ->whereNotNull('next_run_at')
                    ->delete();
            } else {
                $this->info("[SCHEDULED] Registering new scheduled task {$class}::{$method} ({$cron_expression})");
            }

            $next_run_at = $force_all ? time() : (new Cron_Parser($cron_expression))->get_next_run_time();

            DB::table('_tasks')->insert([
                'class' => $class,
                'method' => $method,
                'queue' => $queue,
                'status' => Task_Status::PENDING,
                'params' => json_encode([]),
                'next_run_at' => date('Y-m-d H:i:s', $next_run_at),
                'cron_expression' => $cron_expression,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Delete tracker rows whose schedule was removed from the manifest.
        $trackers = DB::table('_tasks')->whereNotNull('next_run_at')->get(['id', 'class', 'method']);
        foreach ($trackers as $tracker) {
            if (!isset($seen[$tracker->class . '::' . $tracker->method])) {
                $this->info("[SCHEDULED] Removing tracker for deleted schedule {$tracker->class}::{$tracker->method}");
                DB::table('_tasks')->where('id', $tracker->id)->delete();
            }
        }
    }

    /**
     * Spawn detached workers to cover pending work, up to the pool cap.
     *
     * deficit = global_max_workers - live_workers (from the Redis registry). Each spawned
     * worker self-admits against the registry, so over-spawning is safe. Redis is required;
     * if the registry cannot be read this tick, we skip spawning and retry next tick.
     */
    private function spawn_deficit_workers(): void
    {
        if (!$this->has_pending_work()) {
            return;
        }

        $max = (int) config('rsx.tasks.global_max_workers', 1);

        try {
            $deficit = $max - Task_Worker_Registry::live_count();
        } catch (\Throwable $e) {
            // Redis is a hard dependency; if the registry is unreadable mid-tick, skip
            // spawning (a spawned worker would fail at bootstrap too). The next tick retries.
            $this->warn('[WORKER SPAWN] Could not read worker registry (' . $e->getMessage() . '); skipping this tick');
            return;
        }

        if ($deficit <= 0) {
            return;
        }

        $this->info("[WORKER SPAWN] Pending work present; spawning {$deficit} worker(s)");
        for ($i = 0; $i < $deficit; $i++) {
            Task::spawn_worker();
        }
    }

    /**
     * Whether any task is due to run now (either tier of the priority order).
     */
    private function has_pending_work(): bool
    {
        $on_demand_due = DB::table('_tasks')
            ->where('status', Task_Status::PENDING)
            ->whereNull('next_run_at')
            ->where(function ($query) {
                $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            })
            ->exists();

        if ($on_demand_due) {
            return true;
        }

        return DB::table('_tasks')
            ->where('status', Task_Status::PENDING)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->exists();
    }

    /**
     * Process one on-demand (tier-1) task inline. Testing aid for --once; the normal
     * path spawns detached workers. Does not handle cron-tier rows (use a worker).
     */
    private function process_one_task(): void
    {
        $lock = new Task_Lock('task_queue', 5);
        if (!$lock->acquire()) {
            $this->warn('[ONCE MODE] Could not acquire dequeue lock');
            return;
        }

        try {
            $task_row = DB::table('_tasks')
                ->where('status', Task_Status::PENDING)
                ->whereNull('next_run_at')
                ->where(function ($query) {
                    $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
                })
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$task_row) {
                $this->info('[ONCE MODE] No pending tasks');
                $lock->release();
                return;
            }

            DB::table('_tasks')->where('id', $task_row->id)->update([
                'status' => Task_Status::RUNNING,
                'started_at' => now(),
                'worker_pid' => getmypid(),
                'updated_at' => now(),
            ]);

            $lock->release();

            $this->info("[ONCE MODE] Executing task {$task_row->id}: {$task_row->class}::{$task_row->method}");

            $task_instance = Task_Instance::find($task_row->id);

            try {
                $class = $task_row->class;
                $method = $task_row->method;
                $params = json_decode($task_row->params, true) ?? [];

                $result = $class::$method($task_instance, $params);
                $task_instance->mark_completed($result);

                $this->info("[ONCE MODE] Task {$task_row->id} completed successfully");
            } catch (\Throwable $e) {
                // Throwable, not Exception: a TypeError in a task must record its error and
                // settle the row like any exception (same widening as the worker's catch).
                $task_instance->mark_failed($e->getMessage());
                $this->error("[ONCE MODE] Task {$task_row->id} failed: " . $e->getMessage());
            }
        } finally {
            if ($lock->is_locked()) {
                $lock->release();
            }
        }
    }
}
