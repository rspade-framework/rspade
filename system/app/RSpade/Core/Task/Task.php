<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Exception;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Task_Worker_Registry;

/**
 * Task - Unified task execution system
 *
 * Handles background task execution:
 * - Internal PHP task calls (internal method)
 * - Future: Queue integration, scheduling, progress tracking
 */
class Task
{
    /**
     * Execute a task internally from PHP code
     *
     * Used for server-side code to invoke tasks without CLI overhead.
     * This is useful for calling tasks from other tasks, background jobs, etc.
     *
     * @param string $rsx_service Service name (e.g., 'Seeder_Service')
     * @param string $rsx_task Task/method name (e.g., 'seed_clients')
     * @param array $params Parameters to pass to the task
     * @return mixed The response from the task method
     * @throws Exception
     */
    public static function internal($rsx_service, $rsx_task, $params = [])
    {
        // Get manifest to find service
        $manifest = Manifest::get_all();
        $service_class = null;
        $file_info = null;

        // Search for service class in manifest
        foreach ($manifest as $file_path => $info) {
            // Skip non-PHP files or files without classes
            if (!isset($info['class']) || !isset($info['fqcn'])) {
                continue;
            }

            // Check if class name matches exactly (without namespace)
            $class_basename = basename(str_replace('\\', '/', $info['fqcn']));

            if ($class_basename === $rsx_service) {
                $service_class = $info['fqcn'];
                $file_info = $info;
                break;
            }
        }

        if (!$service_class) {
            throw new Exception("Service class not found: {$rsx_service}");
        }

        // Check if class exists
        if (!class_exists($service_class)) {
            throw new Exception("Service class does not exist: {$service_class}");
        }

        // Check if it's a subclass of Rsx_Service_Abstract
        if (!Manifest::php_is_subclass_of($service_class, Rsx_Service_Abstract::class)) {
            throw new Exception("Service {$service_class} must extend Rsx_Service_Abstract");
        }

        // Check if method exists and has Task attribute
        if (!isset($file_info['public_static_methods'][$rsx_task])) {
            throw new Exception("Task {$rsx_task} not found in service {$service_class}");
        }

        $method_info = $file_info['public_static_methods'][$rsx_task];
        $has_task = false;

        // Check for Task attribute in method metadata
        if (isset($method_info['attributes'])) {
            foreach ($method_info['attributes'] as $attr_name => $attr_instances) {
                if ($attr_name === 'Task' || str_ends_with($attr_name, '\\Task')) {
                    $has_task = true;
                    break;
                }
            }
        }

        if (!$has_task) {
            throw new Exception("Method {$rsx_task} in service {$service_class} must have #[Task] attribute");
        }

        // Create task instance for immediate execution
        $task_instance = new Task_Instance(
            $service_class,
            $rsx_task,
            $params,
            'default',
            true  // immediate execution
        );

        // Mark as started
        $task_instance->mark_started();

        try {
            // Call pre_task() if exists
            if (method_exists($service_class, 'pre_task')) {
                $pre_result = $service_class::pre_task($task_instance, $params);
                if ($pre_result !== null) {
                    // pre_task returned something, use that as response
                    $task_instance->mark_completed($pre_result);
                    return $pre_result;
                }
            }

            // Call the actual task method
            $response = $service_class::$rsx_task($task_instance, $params);

            // Mark as completed
            $task_instance->mark_completed($response);

            // Filter response through JSON encode/decode to remove PHP objects
            // (similar to Ajax behavior)
            $filtered_response = json_decode(json_encode($response), true);

            return $filtered_response;
        } catch (Exception $e) {
            // Mark as failed
            $task_instance->mark_failed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Format task response for CLI output
     * Wraps the response in a consistent format
     *
     * @param mixed $response Task return value
     * @return array Formatted response
     */
    public static function format_task_response($response): array
    {
        return [
            'success' => true,
            'result' => $response,
        ];
    }

    /**
     * Dispatch a task: enqueue it and run it promptly.
     *
     * Inserts a pending _tasks row and, when the task is due now, immediately spawns a
     * detached worker so it runs within ~a second (an over-spawned worker self-declines via
     * the Redis worker registry). The ONLY thing that defers a run is a future
     * 'scheduled_for': such a one-shot waits and is picked up by the cron tick (or a later
     * spawn) once it comes due.
     *
     * For #[Exclusive]/#[Debounce] tasks the enqueue is coalescing (at most one running +
     * one pending per identity - see Task_Concurrency); unmanaged tasks get their own row.
     * The result is pollable via status().
     *
     * @param string $rsx_service Service name (basename, e.g. 'Seeder_Service')
     * @param string $rsx_task    Static task method (e.g. 'seed_clients')
     * @param array  $params      Parameters to pass to the task
     * @param array  $options     Optional:
     *   - 'queue'         => Queue label (default: 'default')
     *   - 'scheduled_for' => Earliest run time (default: now). A future value defers the run
     *                        (unmanaged tasks only; managed tasks time themselves).
     *   - 'timeout'       => Maximum execution time in seconds (default: from config)
     * @return int|null The enqueued (or coalesced-onto) task id.
     * @throws Exception
     */
    public static function dispatch(string $rsx_service, string $rsx_task, array $params = [], array $options = []): ?int
    {
        $service_class = static::_find_task_class($rsx_service, $rsx_task);

        $queue = $options['queue'] ?? 'default';
        $scheduled_for = $options['scheduled_for'] ?? now();
        $managed = Task_Concurrency::is_managed($service_class, $rsx_task);

        if ($managed) {
            // Coalescing enqueue: at most one pending run per identity. enqueue_coalesced()
            // returns the new-or-existing pending id, or null only if a concurrent enqueuer
            // held the lock - in that race the pending row it is creating is the answer.
            $id = Task_Concurrency::enqueue_coalesced($service_class, $rsx_task, $params, $queue)
                ?? Task_Concurrency::pending_row_id($service_class, $rsx_task, $queue);
        } else {
            $id = DB::table('_tasks')->insertGetId([
                'class' => $service_class,
                'method' => $rsx_task,
                'queue' => $queue,
                'status' => Task_Status::PENDING,
                'params' => json_encode($params),
                'scheduled_for' => $scheduled_for,
                'timeout' => $options['timeout'] ?? config('rsx.tasks.default_timeout'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Run promptly. Managed tasks always spawn now (the coalesced row governs their
        // debounce timing); an unmanaged task with a future scheduled_for is deferred to the
        // cron tick. Spawning at the cap is a no-op (the worker self-declines).
        if ($managed || !\Illuminate\Support\Carbon::parse($scheduled_for)->isFuture()) {
            static::spawn_worker($queue);
        }

        return $id;
    }

    /**
     * Get the status of a task
     *
     * Returns task information including status, logs, result, and error.
     *
     * @param int $task_id Task ID
     * @return array|null Task status data or null if not found
     */
    public static function status(int $task_id): ?array
    {
        $row = DB::table('_tasks')->where('id', $task_id)->first();

        if (!$row) {
            return null;
        }

        return [
            'id' => $row->id,
            'class' => $row->class,
            'method' => $row->method,
            'queue' => $row->queue,
            'status' => $row->status,
            'params' => json_decode($row->params, true),
            'result' => json_decode($row->result, true),
            'logs' => $row->logs ? explode("\n", $row->logs) : [],
            'error' => $row->error,
            'scheduled_for' => $row->scheduled_for,
            'started_at' => $row->started_at,
            'completed_at' => $row->completed_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * Get all scheduled tasks from manifest
     *
     * Scans the manifest for methods with #[Schedule] attribute
     * and returns information about each scheduled task.
     *
     * @return array Array of scheduled task definitions
     */
    public static function get_scheduled_tasks(): array
    {
        $manifest = Manifest::get_all();
        $scheduled_tasks = [];

        foreach ($manifest as $file_path => $info) {
            // Skip non-PHP files or files without classes
            if (!isset($info['class']) || !isset($info['fqcn'])) {
                continue;
            }

            // Check if it's a service class
            if (!isset($info['public_static_methods'])) {
                continue;
            }

            foreach ($info['public_static_methods'] as $method_name => $method_info) {
                // Check for Schedule attribute
                if (!isset($method_info['attributes'])) {
                    continue;
                }

                foreach ($method_info['attributes'] as $attr_name => $attr_instances) {
                    if ($attr_name === 'Schedule' || str_ends_with($attr_name, '\\Schedule')) {
                        // Found a scheduled task
                        foreach ($attr_instances as $attr_instance) {
                            $cron_expression = $attr_instance[0] ?? null;
                            $queue = $attr_instance[1] ?? 'scheduled';

                            if ($cron_expression) {
                                $scheduled_tasks[] = [
                                    'class' => $info['fqcn'],
                                    'method' => $method_name,
                                    'cron_expression' => $cron_expression,
                                    'queue' => $queue,
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $scheduled_tasks;
    }

    /**
     * Spawn a detached background worker (fire-and-forget). The authoritative concurrency
     * gate is the worker's own atomic admission against the Redis slot registry
     * (Task_Worker_Registry) - an over-spawned worker self-declines and exits. The
     * live_count() pre-check here is a cheap optimization only, not the correctness gate,
     * and is skipped silently on any Redis hiccup. Workers are generic (one pool, no queue
     * routing); the $queue arg is accepted for compatibility and ignored.
     *
     * @param string $queue Ignored (retained for call-site compatibility).
     * @return void
     */
    public static function spawn_worker(string $queue = 'default'): void
    {
        try {
            if (Task_Worker_Registry::live_count() >= (int) config('rsx.tasks.global_max_workers', 1)) {
                return; // pool full; a spawned worker would self-decline anyway
            }
        } catch (\Throwable $e) {
            // Redis hiccup - skip the optimization; the worker's own admission is the gate.
        }

        // Fully detached: we do NOT wait for this worker, so it must NOT inherit our
        // lock group. A worker that ran concurrently while holding a lock this process
        // also holds would break the exclusion both of them think they have - which is
        // why propagation is opt-in and this caller does not opt in.
        Rsx_Artisan::dispatch_detached('rsx:task:worker');
    }

    /**
     * Resolve a service basename to its FQCN and validate it is a #[Task] method.
     *
     * @param string $rsx_service
     * @param string $rsx_task
     * @return string Fully-qualified service class
     * @throws Exception
     */
    private static function _find_task_class(string $rsx_service, string $rsx_task): string
    {
        $manifest = Manifest::get_all();

        foreach ($manifest as $info) {
            if (!isset($info['fqcn'])) {
                continue;
            }

            $class_basename = basename(str_replace('\\', '/', $info['fqcn']));
            if ($class_basename !== $rsx_service) {
                continue;
            }

            $service_class = $info['fqcn'];

            if (!Manifest::php_is_subclass_of($service_class, Rsx_Service_Abstract::class)) {
                throw new Exception("Service {$service_class} must extend Rsx_Service_Abstract");
            }

            $method_info = $info['public_static_methods'][$rsx_task] ?? null;
            $has_task = false;
            foreach ($method_info['attributes'] ?? [] as $attr_name => $attr_instances) {
                if ($attr_name === 'Task' || str_ends_with($attr_name, '\\Task')) {
                    $has_task = true;
                    break;
                }
            }
            if (!$has_task) {
                throw new Exception("Method {$rsx_task} in {$service_class} must have #[Task]");
            }

            return $service_class;
        }

        throw new Exception("Service class not found: {$rsx_service}");
    }
}
