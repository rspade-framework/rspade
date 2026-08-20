<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Status;

/**
 * Task_Concurrency - per-task-identity concurrency control for the task queue.
 *
 * A task method may carry (mutually exclusive) markers:
 *   #[Exclusive]          - at most one instance runs at a time (== #[Debounce(0)])
 *   #[Debounce(seconds)]  - same single-instance guarantee, and the coalesced
 *                           follow-up run fires `seconds` after the prior run COMPLETED.
 *
 * Both mean the SAME invariant, a distributed translation of the JS debounce()
 * (Core/Js/async.js): per identity (class::method), AT MOST ONE RUNNING + AT MOST
 * ONE PENDING ("queued") execution. State is durable (`_tasks` rows) + cluster-safe
 * (MySQL advisory Task_Lock), so correctness never depends on an in-memory trigger:
 *   - running  -> the identity run-lock is held by a worker
 *   - queued   -> exactly one PENDING execution row for the identity
 *   - last_end -> the last run's completed_at
 *   - timer    -> the pending row's scheduled_for (= last completed + delay)
 * The cron poller (rsx:task:process) is the backstop that runs a due pending row
 * even if the completing worker's eager re-check races or misses it.
 */
class Task_Concurrency
{
    /**
     * Resolve the concurrency policy for a task method from its manifest attributes.
     *
     * @param string $class  Fully-qualified service class
     * @param string $method Static task method
     * @return array{mode: ?string, delay: int}  mode = 'exclusive' | 'debounce' | null
     */
    public static function get_policy(string $class, string $method): array
    {
        $attributes = static::_method_attributes($class, $method);

        $has_exclusive = static::_has_attribute($attributes, 'Exclusive');
        $debounce = static::_attribute_args($attributes, 'Debounce');

        if ($has_exclusive) {
            return ['mode' => 'exclusive', 'delay' => 0];
        }

        if ($debounce !== null) {
            $delay = (int) ($debounce[0] ?? 0);

            return ['mode' => 'debounce', 'delay' => max(0, $delay)];
        }

        return ['mode' => null, 'delay' => 0];
    }

    /**
     * Whether a task method is single-instance (Exclusive or Debounce).
     */
    public static function is_managed(string $class, string $method): bool
    {
        return static::get_policy($class, $method)['mode'] !== null;
    }

    /**
     * MySQL advisory lock name for "this identity is running" (bounded to 64 chars).
     */
    public static function run_lock_name(string $class, string $method): string
    {
        return 'rsxtask_run_' . md5($class . '::' . $method);
    }

    /**
     * MySQL advisory lock name guarding the coalescing check-and-enqueue section.
     */
    public static function enqueue_lock_name(string $class, string $method): string
    {
        return 'rsxtask_enq_' . md5($class . '::' . $method);
    }

    /**
     * Coalescing enqueue: ensure AT MOST ONE pending execution row exists for the
     * identity. Returns the (new or existing) pending row id, or null if it could
     * not take the enqueue lock (a concurrent enqueuer is handling it).
     *
     * scheduled_for honors the debounce delay measured from the last completion:
     * max(now, last_completed_at + delay). Wrapped in a cluster-safe advisory lock
     * so two enqueuers can never both insert.
     *
     * @return int|null
     */
    public static function enqueue_coalesced(string $class, string $method, array $params, string $queue): ?int
    {
        $policy = static::get_policy($class, $method);

        $lock = new Task_Lock(static::enqueue_lock_name($class, $method), 5);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            $existing = static::pending_row_id($class, $method, $queue);
            if ($existing !== null) {
                return $existing; // already coalesced
            }

            $scheduled_for = static::_next_scheduled_for($class, $method, $policy['delay']);

            return DB::table('_tasks')->insertGetId([
                'class' => $class,
                'method' => $method,
                'queue' => $queue,
                'status' => Task_Status::PENDING,
                'params' => json_encode($params),
                'scheduled_for' => $scheduled_for,
                'lock_key' => static::run_lock_name($class, $method),
                'timeout' => config('rsx.tasks.default_timeout'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * The id of the single pending execution row for an identity, or null.
     * (Excludes recurring-schedule tracker rows, which carry next_run_at.)
     */
    public static function pending_row_id(string $class, string $method, string $queue): ?int
    {
        $row = DB::table('_tasks')
            ->where('class', $class)
            ->where('method', $method)
            ->where('queue', $queue)
            ->where('status', Task_Status::PENDING)
            ->whereNull('next_run_at')
            ->orderBy('created_at', 'asc')
            ->first();

        return $row?->id;
    }

    /**
     * Non-blocking attempt to acquire the identity run-lock. Returns the held lock
     * (keep it and release when the run finishes) or null if another worker is
     * already running this identity.
     */
    public static function try_acquire_run_lock(string $class, string $method): ?Task_Lock
    {
        $lock = new Task_Lock(static::run_lock_name($class, $method), 0); // 0 = non-blocking
        if ($lock->acquire()) {
            return $lock;
        }

        return null;
    }

    /**
     * Re-anchor a coalesced pending run after a run of the same identity completes:
     * its scheduled_for becomes completed_at + delay (matching the JS debounce
     * `finally { if queued -> setTimeout(delay) }`). No-op if nothing is pending.
     */
    public static function reschedule_pending_after_completion(string $class, string $method, string $queue): void
    {
        $policy = static::get_policy($class, $method);

        $pending_id = static::pending_row_id($class, $method, $queue);
        if ($pending_id === null) {
            return;
        }

        DB::table('_tasks')->where('id', $pending_id)->update([
            'scheduled_for' => now()->addSeconds($policy['delay']),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // internals
    // =========================================================================

    /**
     * scheduled_for for a fresh coalesced enqueue: not before the debounce delay
     * has elapsed since the last completion of this identity.
     */
    private static function _next_scheduled_for(string $class, string $method, int $delay)
    {
        if ($delay <= 0) {
            return now();
        }

        $last = DB::table('_tasks')
            ->where('class', $class)
            ->where('method', $method)
            ->whereNotNull('completed_at')
            ->orderBy('completed_at', 'desc')
            ->value('completed_at');

        if (!$last) {
            return now();
        }

        $earliest = \Illuminate\Support\Carbon::parse($last)->addSeconds($delay);

        return $earliest->isFuture() ? $earliest : now();
    }

    /**
     * Method attribute map from the manifest (keyed by simple attribute name).
     */
    private static function _method_attributes(string $class, string $method): array
    {
        $manifest = Manifest::get_all();

        foreach ($manifest as $info) {
            if (!isset($info['fqcn']) || $info['fqcn'] !== $class) {
                continue;
            }
            return $info['public_static_methods'][$method]['attributes'] ?? [];
        }

        return [];
    }

    private static function _has_attribute(array $attributes, string $name): bool
    {
        foreach ($attributes as $attr_name => $instances) {
            if ($attr_name === $name || str_ends_with($attr_name, '\\' . $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Arguments of the first instance of a named attribute, or null if absent.
     */
    private static function _attribute_args(array $attributes, string $name): ?array
    {
        foreach ($attributes as $attr_name => $instances) {
            if ($attr_name === $name || str_ends_with($attr_name, '\\' . $name)) {
                return $instances[0] ?? [];
            }
        }

        return null;
    }
}
