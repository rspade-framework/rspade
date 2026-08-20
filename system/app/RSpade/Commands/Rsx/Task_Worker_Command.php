<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Worker_Registry;
use App\RSpade\Core\Task\Cron_Parser;

/**
 * Task Worker Command
 *
 * The executor. Spawned detached by rsx:task:process (or by Task::dispatch, which fires a
 * worker on enqueue). There is ONE worker pool - workers are generic, not bound to a queue.
 *
 * On startup it self-admits against the Redis worker-slot registry (Task_Worker_Registry):
 * if the pool is at global_max_workers it exits(0) immediately; otherwise it claims a slot
 * and loops. Each iteration it refreshes its slot and claims the next task by a single
 * priority order (run-now tasks FIFO, then due cron tasks by next_run_at) under the MySQL
 * dequeue lock, runs it, and repeats until no task remains, then deregisters and exits.
 *
 * Redis is a hard framework dependency (the cache and manifest require it) and backs the
 * worker-slot registry: worker management runs entirely on Redis, and a Redis outage fails
 * the process loudly at bootstrap.
 *
 * Workers are UNGUARDED: nothing serializes them for you - they run concurrently, and each
 * #[Task] is responsible for its own critical-section locking (see RsxLocks).
 */
class Task_Worker_Command extends Command
{
    protected $signature = 'rsx:task:worker
        {--queue= : Ignored (retained for compatibility; workers are generic)}
        {--max-time=300 : Maximum execution time in seconds (default: 5 minutes)}';

    protected $description = 'Background worker for processing queued tasks';

    private int $start_time;
    private int $max_time;
    private int $tasks_processed = 0;

    /** MySQL dequeue lock name - one global lock for the single worker pool. */
    private const DEQUEUE_LOCK = 'task_queue';

    /**
     * Pending on-demand rows this worker skipped because their identity is already
     * running elsewhere (the coalesced pending run). Excluded from selection so the
     * worker doesn't busy-loop; the running instance / cron poller picks them up.
     *
     * @var int[]
     */
    private array $skip_ids = [];

    public function handle()
    {
        $this->max_time = (int) $this->option('max-time');
        $this->start_time = time();

        // Claim a slot in the shared worker pool, or exit if it is already full. Admission
        // is atomic (Task_Worker_Registry), so simultaneously-spawned workers admit exactly
        // global_max_workers and the rest exit here.
        if (!Task_Worker_Registry::admit()) {
            $this->info('[WORKER] Worker pool is full, exiting');
            return 0;
        }
        $this->info('[WORKER] Admitted to pool (' . Task_Worker_Registry::worker_id() . ')');

        try {
            while ((time() - $this->start_time) < $this->max_time) {
                // Keep this worker's registry slot alive.
                Task_Worker_Registry::heartbeat();

                if (!$this->process_next_task()) {
                    $this->info('[WORKER] No more pending tasks, exiting');
                    break;
                }

                $this->tasks_processed++;
            }
        } finally {
            Task_Worker_Registry::deregister();
        }

        $this->info("[WORKER] Worker finished. Processed {$this->tasks_processed} task(s)");

        return 0;
    }

    /**
     * Claim and run the next task by the single priority order:
     *   Tier 1 - run-now rows (dispatched): next_run_at IS NULL, FIFO by created_at.
     *   Tier 2 - due cron rows: next_run_at IS NOT NULL AND due, by next_run_at ascending.
     * Selection + status transition happen under the global MySQL dequeue lock; the task
     * runs after the lock is released.
     *
     * @return bool True if work was done (or should be retried), false if nothing remains.
     */
    private function process_next_task(): bool
    {
        $lock = new Task_Lock(self::DEQUEUE_LOCK, 5);

        if (!$lock->acquire()) {
            $this->warn('[WORKER] Could not acquire dequeue lock, retrying...');
            sleep(1);
            return true;
        }

        try {
            // Tier 1: run-now tasks.
            $is_cron = false;
            $task_row = DB::table('_tasks')
                ->where('status', Task_Status::PENDING)
                ->whereNull('next_run_at')
                ->where(function ($query) {
                    $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
                })
                ->when($this->skip_ids, function ($query) {
                    $query->whereNotIn('id', $this->skip_ids);
                })
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            // Tier 2: due cron tasks (only when no run-now work remains).
            if (!$task_row) {
                $is_cron = true;
                $task_row = DB::table('_tasks')
                    ->where('status', Task_Status::PENDING)
                    ->whereNotNull('next_run_at')
                    ->whereNotNull('cron_expression')
                    ->where('next_run_at', '<=', now())
                    ->orderBy('next_run_at', 'asc')
                    ->lockForUpdate()
                    ->first();
            }

            if (!$task_row) {
                $lock->release();
                return false;
            }

            // Per-identity guard for Exclusive/Debounce tasks: at most one instance runs
            // at a time (cluster-wide) via the MySQL run-lock.
            $run_lock = null;
            if (Task_Concurrency::is_managed($task_row->class, $task_row->method)) {
                $run_lock = Task_Concurrency::try_acquire_run_lock($task_row->class, $task_row->method);
                if (!$run_lock) {
                    if ($is_cron) {
                        // Identity already running (an on-demand run): coalesce this tick
                        // into it - advance the schedule and leave the tracker pending.
                        DB::table('_tasks')->where('id', $task_row->id)->update([
                            'next_run_at' => date('Y-m-d H:i:s', (new Cron_Parser($task_row->cron_expression))->get_next_run_time()),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $this->skip_ids[] = $task_row->id;
                    }
                    $lock->release();
                    return true;
                }
            }

            if ($is_cron) {
                // Advance next_run_at BEFORE running so the cadence holds even if the run
                // is slow or crashes, and mark running - atomically, under the lock.
                DB::table('_tasks')->where('id', $task_row->id)->update([
                    'next_run_at' => date('Y-m-d H:i:s', (new Cron_Parser($task_row->cron_expression))->get_next_run_time()),
                    'status' => Task_Status::RUNNING,
                    'started_at' => now(),
                    'worker_pid' => getmypid(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('_tasks')->where('id', $task_row->id)->update([
                    'status' => Task_Status::RUNNING,
                    'started_at' => now(),
                    'worker_pid' => getmypid(),
                    'updated_at' => now(),
                ]);
            }

            $lock->release();

            $this->execute_task($task_row, $run_lock);

            return true;
        } catch (\Exception $e) {
            $this->error('[WORKER] Error processing task: ' . $e->getMessage());

            if ($lock->is_locked()) {
                $lock->release();
            }

            return false;
        }
    }

    /**
     * Execute a claimed task row.
     *
     * The worker does not settle the row itself: Task_Instance::mark_completed() /
     * mark_failed() are the sole terminal writers and already know whether the row is an
     * on-demand row (terminal completed/failed) or a cron tracker (recycled to pending in
     * the SAME update, so its already-advanced next_run_at fires the next cadence). The
     * worker used to re-flip trackers here afterward; two implementations of one rule left
     * a window where a killed worker stranded the schedule in a terminal state.
     *
     * @param object    $task_row Task database row
     * @param Task_Lock|null $run_lock Held identity run-lock for managed tasks (else null)
     */
    private function execute_task(object $task_row, ?Task_Lock $run_lock): void
    {
        $this->info("[WORKER] Executing task {$task_row->id}: {$task_row->class}::{$task_row->method}");

        $task_instance = Task_Instance::find($task_row->id);

        if (!$task_instance) {
            $this->error("[WORKER] Could not load task instance for task {$task_row->id}");
            if ($run_lock) {
                $run_lock->release();
            }
            return;
        }

        // Locks taken from here on belong to THIS TASK, and are released when it ends (see
        // the finally below). Anything the worker itself already holds is in the
        // checkpoint and is left alone.
        $lock_checkpoint = RsxLocks::_checkpoint();

        try {
            $class = $task_row->class;
            $method = $task_row->method;
            $params = json_decode($task_row->params, true) ?? [];

            if (!class_exists($class)) {
                throw new \Exception("Class not found: {$class}");
            }
            if (!method_exists($class, $method)) {
                throw new \Exception("Method not found: {$class}::{$method}");
            }

            $result = $class::$method($task_instance, $params);
            $task_instance->mark_completed($result);

            $this->info("[WORKER] Task {$task_row->id} completed successfully");
        } catch (\Throwable $e) {
            // Throwable, not Exception: a TypeError inside a task must record its error
            // on the row like any other failure, not vanish unrecorded.
            $task_instance->mark_failed($e->getMessage());
            $this->error("[WORKER] Task {$task_row->id} failed: " . $e->getMessage());
        } finally {
            // Hand back every lock this task took. A worker is a LONG-LIVED process running
            // unrelated tasks back to back, but an ordinary application lock is held until
            // the PROCESS exits (Rsx_Site_Model_Abstract registers a shutdown handler and
            // nothing else ever lets go). Without this, the first task to write to a site
            // would hold that tenant's write lock against the whole cluster for the rest of
            // the worker's lifetime, and every later task in this worker would silently
            // inherit it instead of contending for it.
            //
            // Loud on purpose: a task that ends still holding a lock is a defect in that
            // task. This is a safety net, not a licence.
            $leaked = RsxLocks::_release_since($lock_checkpoint);
            foreach ($leaked as $lock_name) {
                $this->warn(
                    "[WORKER] Task {$task_row->id} ({$task_row->class}::{$task_row->method}) "
                    . "ended still holding {$lock_name} - released by the worker. "
                    . 'Release your locks in a finally block.'
                );
            }

            if ($run_lock) {
                // Release the identity run-lock and re-anchor any coalesced pending run
                // (mirrors the JS debounce finally{}).
                $run_lock->release();
                Task_Concurrency::reschedule_pending_after_completion(
                    $task_row->class,
                    $task_row->method,
                    $task_row->queue
                );
            }
        }
    }
}
