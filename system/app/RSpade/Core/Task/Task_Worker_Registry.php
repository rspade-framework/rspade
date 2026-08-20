<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use RuntimeException;

/**
 * Task_Worker_Registry - Redis-backed registry of live task workers.
 *
 * The task worker pool is capped at rsx.tasks.global_max_workers. This registry is
 * the authoritative admission gate: each worker atomically claims a slot on startup
 * (admit()) and self-declines if the pool is full. Slots are a Redis ZSET member per
 * live worker, scored by last-heartbeat epoch; a stale (crashed) slot is pruned once
 * its score falls below now - rsx.tasks.worker_heartbeat_ttl. This replaces the old
 * "count _tasks rows in status=running" gate, which mis-counted between tasks and
 * wedged on SIGKILLed workers until the 30-minute stuck sweep.
 *
 * Redis owns ONLY this ephemeral worker-slot state. The durable queue (_tasks), the
 * atomic dequeue lock, and per-identity run-locks stay in MySQL. Losing just this registry
 * key is safe: it self-heals (stale slots expire, admission recomputes from scratch). Redis
 * itself is a hard framework dependency (cache + manifest), so a full Redis outage fails the
 * process loudly at bootstrap; admit()/live_count() throwing is a fatal error.
 */
class Task_Worker_Registry
{
    /** Redis ZSET holding one member per live worker (score = last heartbeat epoch). */
    private const ZSET_KEY = 'rsx:tasks:workers';

    /** Lock database (no eviction) - shared numbering with RsxLocks; keys are namespaced. */
    private static int $redis_db = 1;

    private static ?\Redis $redis = null;

    /** This process's worker id, set by admit(). Null until/unless this process is an admitted worker. */
    private static ?string $worker_id = null;

    /** Guard so the shutdown deregister handler is only registered once. */
    private static bool $shutdown_registered = false;

    /**
     * Atomically claim a worker slot. Prunes stale slots, then admits iff the live
     * count is under rsx.tasks.global_max_workers - all in one Lua script, so N
     * simultaneous starters admit EXACTLY the cap and the rest decline. On success the
     * slot id is stored on this process and a shutdown handler is registered to release
     * it.
     *
     * @return bool True if admitted (proceed to work), false if the pool is full (exit).
     * @throws RuntimeException If Redis is unreachable (a hard error - Redis is required).
     */
    public static function admit(): bool
    {
        $redis = self::_redis();

        $now = time();
        $ttl = self::_ttl();
        $worker_id = self::_new_worker_id();

        $lua = <<<'LUA'
            redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[2])
            if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[3]) then return 0 end
            redis.call('ZADD', KEYS[1], ARGV[1], ARGV[4])
            redis.call('EXPIRE', KEYS[1], ARGV[5])
            return 1
LUA;

        $admitted = (int) $redis->eval(
            $lua,
            [self::ZSET_KEY, $now, $now - $ttl, self::_max_workers(), $worker_id, $ttl * 4],
            1
        );

        if ($admitted !== 1) {
            return false;
        }

        self::$worker_id = $worker_id;

        if (!self::$shutdown_registered) {
            register_shutdown_function([self::class, 'deregister']);
            self::$shutdown_registered = true;
        }

        return true;
    }

    /**
     * Refresh this worker's slot (keep it alive) and return the current live count.
     *
     * Called at the top of each worker loop iteration and from Task_Instance::heartbeat()
     * so a long-running task keeps its slot alive across a run longer than the TTL. A
     * no-op (returns 0) when this process holds no slot or Redis is unreachable - losing
     * the slot is safe, so heartbeat never throws.
     *
     * @return int Live worker count, or 0 if not applicable.
     */
    public static function heartbeat(): int
    {
        if (self::$worker_id === null) {
            return 0;
        }

        try {
            $redis = self::_redis();
            $now = time();
            $ttl = self::_ttl();

            $lua = <<<'LUA'
                redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[2])
                redis.call('ZADD', KEYS[1], ARGV[1], ARGV[3])
                redis.call('EXPIRE', KEYS[1], ARGV[4])
                return redis.call('ZCARD', KEYS[1])
LUA;

            return (int) $redis->eval(
                $lua,
                [self::ZSET_KEY, $now, $now - $ttl, self::$worker_id, $ttl * 4],
                1
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Live worker count after pruning stale slots. Used by the processor to compute the
     * spawn deficit and by Task::spawn_worker() for a cheap pre-check.
     *
     * @return int
     * @throws RuntimeException If Redis is unreachable (a hard error - Redis is required).
     */
    public static function live_count(): int
    {
        $redis = self::_redis();

        $lua = <<<'LUA'
            redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
            return redis.call('ZCARD', KEYS[1])
LUA;

        return (int) $redis->eval($lua, [self::ZSET_KEY, time() - self::_ttl()], 1);
    }

    /**
     * Release this process's worker slot. Called on graceful exit and as a shutdown
     * handler (covers a fatal that is not a SIGKILL); SIGKILL is covered by TTL expiry.
     * Best-effort and idempotent.
     */
    public static function deregister(): void
    {
        if (self::$worker_id === null) {
            return;
        }

        $worker_id = self::$worker_id;
        self::$worker_id = null;

        try {
            self::_redis()->zRem(self::ZSET_KEY, $worker_id);
        } catch (\Throwable $e) {
            // Slot will expire via TTL; nothing else to do.
        }
    }

    /**
     * This process's worker id, or null if it is not an admitted worker.
     */
    public static function worker_id(): ?string
    {
        return self::$worker_id;
    }

    /**
     * rsx:health probe: is Redis reachable? Redis backs locks, realtime, and the task
     * worker-slot registry - a hard framework dependency. A public static
     * `#[Health_Check('label')]` (bare marker attribute - never a defined class) that
     * exercises the same connection admit()/live_count() use.
     *
     * @return array
     */
    #[Health_Check('Redis Connectivity')]
    public static function redis_connectivity(): array
    {
        try {
            $live = self::live_count();
        } catch (\Throwable $e) {
            return [
                'status' => 'FAIL',
                'detail' => 'cannot reach Redis: ' . $e->getMessage(),
                'remediation' => 'check REDIS_* in .env and that redis-server is running',
            ];
        }

        return ['status' => 'OK', 'detail' => 'connected; ' . $live . ' live worker(s)'];
    }

    // =========================================================================
    // internals
    // =========================================================================

    private static function _max_workers(): int
    {
        return max(1, (int) config('rsx.tasks.global_max_workers', 1));
    }

    private static function _ttl(): int
    {
        return max(1, (int) config('rsx.tasks.worker_heartbeat_ttl', 90));
    }

    /**
     * A unique slot id for this worker process.
     */
    private static function _new_worker_id(): string
    {
        return random_hash(8) . ':' . gethostname() . ':' . getmypid();
    }

    /**
     * Connect to Redis (the no-eviction lock DB). Independent of the cache/manifest so
     * it is usable from bare CLI workers.
     *
     * @throws RuntimeException If phpredis is missing or the connection fails.
     */
    private static function _redis(): \Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        if (!class_exists('\Redis')) {
            throw new RuntimeException('phpredis extension not available for task worker registry');
        }

        $redis = new \Redis();

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);
        $socket = env('REDIS_SOCKET', null);

        $connected = ($socket && file_exists($socket))
            ? $redis->connect($socket)
            : $redis->connect($host, $port, 2.0);

        if (!$connected) {
            throw new RuntimeException('Failed to connect to Redis for task worker registry');
        }

        $redis->select(self::$redis_db);
        self::$redis = $redis;

        return self::$redis;
    }
}
