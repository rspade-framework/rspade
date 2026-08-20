<?php

namespace App\RSpade\Core\Task;

use Exception;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Task_Worker_Registry;

/**
 * Task_Instance
 *
 * Represents a single task execution instance with logging, status tracking,
 * and temp directory management. Passed to all task methods for tracking.
 */
#[Instantiatable]
class Task_Instance
{
    private ?int $id;
    private string $class;
    private string $method;
    private string $queue;
    private array $params;
    private Task_Status $status;
    private array $logs = [];
    private ?string $temp_dir = null;
    private bool $is_immediate;

    /**
     * The row's next_run_at when this instance was loaded from the database, or null.
     *
     * A non-null value means this row is a recurring #[Schedule] TRACKER, which changes
     * what a terminal outcome means (see mark_completed/mark_failed). Only find() can
     * populate it - a directly-constructed instance is immediate mode and never writes
     * to the database at all.
     */
    private ?string $next_run_at = null;

    /**
     * Create a new task instance
     *
     * @param string $class Fully qualified class name
     * @param string $method Static method name
     * @param array $params Task parameters
     * @param string $queue Queue name
     * @param bool $is_immediate True for immediate execution, false for database-backed
     */
    public function __construct(
        string $class,
        string $method,
        array $params = [],
        string $queue = 'default',
        bool $is_immediate = true
    ) {
        $this->class = $class;
        $this->method = $method;
        $this->params = $params;
        $this->queue = $queue;
        $this->is_immediate = $is_immediate;
        $this->status = new Task_Status(Task_Status::PENDING);
        $this->id = null;
    }

    /**
     * Load task instance from database by ID
     *
     * @param int $id Task ID
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        $row = DB::table('_tasks')->where('id', $id)->first();

        if (!$row) {
            return null;
        }

        $instance = new self(
            $row->class,
            $row->method,
            json_decode($row->params, true) ?? [],
            $row->queue,
            false
        );

        $instance->id = $row->id;
        $instance->status = new Task_Status($row->status);
        $instance->logs = $row->logs ? explode("\n", $row->logs) : [];
        $instance->next_run_at = $row->next_run_at;

        return $instance;
    }

    /**
     * Whether this instance is a recurring #[Schedule] tracker row.
     *
     * One tracker row exists per scheduled task and lives forever: the worker advances
     * its next_run_at BEFORE the run, so the row's only job afterward is to be pending
     * again in time for the next cadence.
     */
    public function is_cron_tracker(): bool
    {
        return $this->next_run_at !== null;
    }

    /**
     * Save task instance to database
     *
     * @return int Task ID
     */
    public function save(): int
    {
        if ($this->is_immediate) {
            throw new Exception("Cannot save immediate task to database");
        }

        $data = [
            'class' => $this->class,
            'method' => $this->method,
            'queue' => $this->queue,
            'status' => $this->status->value(),
            'params' => json_encode($this->params),
            'logs' => implode("\n", $this->logs),
            'updated_at' => now(),
        ];

        if ($this->id === null) {
            $data['created_at'] = now();
            $this->id = DB::table('_tasks')->insertGetId($data);
        } else {
            DB::table('_tasks')->where('id', $this->id)->update($data);
        }

        return $this->id;
    }

    /**
     * Update task status in database.
     *
     * A raw setter with no knowledge of what the row IS. Settling a run goes through
     * mark_completed()/mark_failed(), which are the only writers allowed to decide a
     * terminal status - a terminal status written here onto a cron tracker would stop
     * that schedule permanently.
     *
     * @param Task_Status $status New status
     */
    public function update_status(Task_Status $status): void
    {
        $this->status = $status;

        if (!$this->is_immediate && $this->id !== null) {
            DB::table('_tasks')
                ->where('id', $this->id)
                ->update(['status' => $status->value(), 'updated_at' => now()]);
        }
    }

    /**
     * Mark task as started
     */
    public function mark_started(): void
    {
        $this->update_status(new Task_Status(Task_Status::RUNNING));

        if (!$this->is_immediate && $this->id !== null) {
            DB::table('_tasks')
                ->where('id', $this->id)
                ->update([
                    'started_at' => now(),
                    'worker_pid' => getmypid(),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Mark task as completed.
     *
     * A ONE-SHOT row goes terminal COMPLETED.
     *
     * A CRON TRACKER goes straight back to PENDING - it is not "done", it is waiting for
     * the next cadence its already-advanced next_run_at names - and its failure counter is
     * cleared. completed_at is still written, and on a tracker it means LAST SUCCESSFUL RUN
     * (the row itself is never terminal, so nothing else would record that).
     *
     * The row write is a single UPDATE either way: there is no COMPLETED-then-PENDING
     * window for a SIGKILL to strand the schedule in.
     *
     * @param mixed $result Optional result data
     */
    public function mark_completed($result = null): void
    {
        $is_tracker = $this->is_cron_tracker();

        // Assigned rather than routed through update_status(), which would issue its own
        // status UPDATE ahead of the one below - the settle is deliberately a single write.
        $this->status = new Task_Status($is_tracker ? Task_Status::PENDING : Task_Status::COMPLETED);

        if (!$this->is_immediate && $this->id !== null) {
            $update = [
                'status' => $this->status->value(),
                'completed_at' => now(),
                'updated_at' => now(),
            ];

            if ($result !== null) {
                $update['result'] = json_encode($result);
            }

            if ($is_tracker) {
                $update['worker_pid'] = null;
                $update['consecutive_failures'] = 0;
            }

            DB::table('_tasks')->where('id', $this->id)->update($update);
        }

        $this->cleanup_temp_dir();
    }

    /**
     * Mark task as failed.
     *
     * INVARIANT: a cron tracker is never permanently terminal. A #[Schedule] whose task
     * throws retries at its next cadence, exactly like cron itself - one bad night does
     * not silently stop the schedule. (This is the same rule the dead-worker reaper states
     * as "failing them would silently kill the cron"; it now holds on EVERY path.) So a
     * tracker is recycled to PENDING here, with the failure recorded on the row - error,
     * a status_reason naming the recycle, last_error_at, and consecutive_failures+1 - and
     * NO completed_at, which on a tracker means "last successful run".
     *
     * A ONE-SHOT row goes terminal FAILED with completed_at, as always.
     *
     * The row write is ONE atomic UPDATE: the row never passes through FAILED on its way
     * back to PENDING, so a worker SIGKILLed mid-settle cannot strand a schedule in a
     * terminal state that nothing would ever revive.
     *
     * @param string $error Error message
     */
    public function mark_failed(string $error): void
    {
        $is_tracker = $this->is_cron_tracker();

        // Assigned rather than routed through update_status(), which would issue its own
        // status UPDATE ahead of the one below - the settle is deliberately a single write.
        $this->status = new Task_Status($is_tracker ? Task_Status::PENDING : Task_Status::FAILED);
        $this->error("Task failed: {$error}");

        if (!$this->is_immediate && $this->id !== null) {
            $update = [
                'status' => $this->status->value(),
                'error' => $error,
                'last_error_at' => now(),
                'consecutive_failures' => DB::raw('consecutive_failures + 1'),
                'updated_at' => now(),
            ];

            if ($is_tracker) {
                $update['worker_pid'] = null;
                $update['status_reason'] = 'failed (recycled): ' . self::__summarize_error($error);
            } else {
                $update['completed_at'] = now();
            }

            DB::table('_tasks')->where('id', $this->id)->update($update);
        }

        $this->cleanup_temp_dir();
    }

    /**
     * One-line, length-capped rendering of an error for the status_reason column.
     *
     * status_reason is the at-a-glance column (rsx:tasks:list, the kill reason); the full
     * text always remains on `error`.
     *
     * @param string $error
     * @return string
     */
    private static function __summarize_error(string $error): string
    {
        $first_line = trim(explode("\n", $error)[0]);

        if (mb_strlen($first_line) > 200) {
            $first_line = mb_substr($first_line, 0, 197) . '...';
        }

        return $first_line;
    }

    /**
     * Add a log message
     *
     * @param string $level Log level (info, error, debug)
     * @param string $message Log message
     */
    public function log(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_line = "[{$timestamp}] [{$level}] {$message}";
        $this->logs[] = $log_line;

        if (!$this->is_immediate && $this->id !== null) {
            DB::table('_tasks')
                ->where('id', $this->id)
                ->update([
                    'logs' => implode("\n", $this->logs),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Log an info message
     *
     * @param string $message
     */
    public function info(string $message): void
    {
        $this->log('info', $message);
    }

    /**
     * Log an error message
     *
     * @param string $message
     */
    public function error(string $message): void
    {
        $this->log('error', $message);
    }

    /**
     * Log a debug message
     *
     * @param string $message
     */
    public function debug(string $message): void
    {
        $this->log('debug', $message);
    }

    /**
     * Update task progress percentage
     *
     * @param int $percent Progress percentage (0-100)
     * @param string|null $message Optional progress message
     */
    public function update_progress(int $percent, ?string $message = null): void
    {
        $percent = max(0, min(100, $percent));

        if ($message) {
            $this->info("Progress: {$percent}% - {$message}");
        } else {
            $this->info("Progress: {$percent}%");
        }
    }

    /**
     * Set task result data
     *
     * @param mixed $result Result data (will be JSON-encoded)
     */
    public function set_result($result): void
    {
        if (!$this->is_immediate && $this->id !== null) {
            DB::table('_tasks')
                ->where('id', $this->id)
                ->update([
                    'result' => json_encode($result),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Send heartbeat to indicate task is still running.
     *
     * Long-running tasks should call this periodically. Besides recording the DB
     * heartbeat, it refreshes this worker's Redis slot so a task running longer than
     * rsx.tasks.worker_heartbeat_ttl is not pruned from the worker registry as dead.
     * The registry call is a no-op outside an admitted worker (e.g. immediate runs).
     */
    public function heartbeat(): void
    {
        Task_Worker_Registry::heartbeat();

        if (!$this->is_immediate && $this->id !== null) {
            DB::table('_tasks')
                ->where('id', $this->id)
                ->update([
                    'last_heartbeat_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Get or create temporary directory for this task
     *
     * @return string Absolute path to temp directory
     */
    public function get_temp_dir(): string
    {
        if ($this->temp_dir !== null) {
            return $this->temp_dir;
        }

        $base_temp_dir = storage_path('rsx-tmp/tasks');

        if (!is_dir($base_temp_dir)) {
            mkdir($base_temp_dir, 0755, true);
        }

        if ($this->is_immediate) {
            $dir_name = 'immediate_' . uniqid();
        } else {
            $dir_name = 'task_' . $this->id;
        }

        $this->temp_dir = $base_temp_dir . '/' . $dir_name;

        if (!is_dir($this->temp_dir)) {
            mkdir($this->temp_dir, 0755, true);
        }

        return $this->temp_dir;
    }

    /**
     * Clean up temporary directory
     */
    public function cleanup_temp_dir(): void
    {
        if ($this->temp_dir === null || !is_dir($this->temp_dir)) {
            return;
        }

        $this->delete_directory_recursive($this->temp_dir);
        $this->temp_dir = null;
    }

    /**
     * Recursively delete directory and contents
     *
     * @param string $dir Directory path
     */
    private function delete_directory_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->delete_directory_recursive($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Get task ID
     *
     * @return int|null
     */
    public function get_id(): ?int
    {
        return $this->id;
    }

    /**
     * Get task class name
     *
     * @return string
     */
    public function get_class(): string
    {
        return $this->class;
    }

    /**
     * Get task method name
     *
     * @return string
     */
    public function get_method(): string
    {
        return $this->method;
    }

    /**
     * Get task parameters
     *
     * @return array
     */
    public function get_params(): array
    {
        return $this->params;
    }

    /**
     * Get task queue name
     *
     * @return string
     */
    public function get_queue(): string
    {
        return $this->queue;
    }

    /**
     * Get task status
     *
     * @return Task_Status
     */
    public function get_status(): Task_Status
    {
        return $this->status;
    }

    /**
     * Get all task logs
     *
     * @return array
     */
    public function get_logs(): array
    {
        return $this->logs;
    }

    /**
     * Check if task is immediate (not database-backed)
     *
     * @return bool
     */
    public function is_immediate(): bool
    {
        return $this->is_immediate;
    }
}
