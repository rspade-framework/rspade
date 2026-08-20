<?php

namespace App\RSpade\Core\Cache;

use InvalidArgumentException;
use Redis;
use RuntimeException;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Manifest\Manifest;

// Ensure helpers are loaded since we may run early in bootstrap
require_once __DIR__ . '/../../helpers.php';

/**
 * Redis-based caching system with LRU eviction
 *
 * Uses Redis database 0 with LRU eviction policy for general caching.
 * Automatically prefixes all keys with the current manifest build key
 * to ensure cache invalidation when code changes.
 */
class RsxCache
{
    // Redis configuration
    private static ?Redis $_redis = null;

    private static int $cache_db = 0;  // Database 0 for cache (with LRU eviction)

    private static bool $initialized = false;

    // Sticky maintenance bypass set when a connect FAILED with the maintenance flag on disk
    // (a process that booted before maintenance went up - see _init()).
    private static bool $maintenance_bypass_forced = false;

    // One "write skipped" warning per process (see _note_maintenance_write_skipped()).
    private static bool $maintenance_write_warned = false;

    // Request-scoped cache (static property storage)
    private static array $_once_cache = [];

    // Default expiration times
    public const NO_EXPIRATION = 0;

    public const HOUR = 3600;

    public const DAY = 86400;

    public const WEEK = 604800;

    /**
     * Initialize the cache system
     * Must be called after manifest is loaded
     */
    public static function _init()
    {
        if (self::$_redis) {
            return self::$_redis;
        }

        // Skip Redis in IDE context if extension not available
        if (self::_redis_bypass()) {
            return null;
        }

        // Maintenance mode stops the redis server. Return BEFORE connecting: the manifest
        // auto-rebuild and Type_Ref_Registry hit the cache on the first artisan boot after
        // files change, so without this even the update window's first step would fatal in
        // shouldnt_happen() below.
        if (self::_maintenance_bypass()) {
            return null;
        }

        self::$_redis = new Redis();

        // Connect to Redis (will be configured via environment)
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = env('REDIS_PORT', 6379);
        $socket = env('REDIS_SOCKET', null);

        if ($socket && file_exists($socket)) {
            $connected = self::$_redis->connect($socket);
        } else {
            $connected = self::$_redis->connect($host, $port, 2.0);
        }

        if (!$connected) {
            // STRAGGLER PATH (failure path only, one extra stat): a process that booted BEFORE
            // maintenance was enabled carries a "no maintenance" snapshot but meets a stopped
            // redis. If the flag is on disk NOW, degrade to the maintenance bypass instead of
            // fatalling. With no flag on disk this stays exactly as loud as it has always been.
            self::$_redis = null;
            if (Framework_Maintenance::is_active_on_disk()) {
                self::$maintenance_bypass_forced = true;

                return null;
            }

            shouldnt_happen('Failed to connect to Redis for caching');
        }

        // Select the cache database (with LRU eviction)
        self::$_redis->select(self::$cache_db);

        return self::$_redis;
    }

    /**
     * Check if we can skip reddis due to special circumstance
     */
    private static function _redis_bypass()
    {
        if (is_ide() && !class_exists('\Redis') && env('APP_ENV') != 'production') {
            return true;
        }

        return false;
    }

    /**
     * Is the cache running WITHOUT a redis server because maintenance mode stopped it?
     *
     * Reads the per-process RSPADE_MAINT_MODE snapshot; the sticky flag covers the straggler
     * (booted-before-enable) case detected on a connect failure in _init().
     */
    private static function _maintenance_bypass(): bool
    {
        return self::$maintenance_bypass_forced || Framework_Maintenance::is_active();
    }

    /**
     * Record that a cache WRITE was dropped because maintenance mode is up.
     *
     * Owner ruling: a write under the flag leaves ONE warning line in the Laravel log and
     * returns without error; reads are silent misses. Logged once per process - a rebuild
     * inside the window performs thousands of identical writes and repeating the same line
     * for each adds no information.
     */
    private static function _note_maintenance_write_skipped(): void
    {
        if (self::$maintenance_write_warned) {
            return;
        }

        self::$maintenance_write_warned = true;
        \Illuminate\Support\Facades\Log::warning(
            'maintenance mode: redis write skipped (RsxCache; further skipped writes in this process are not logged)'
        );
    }

    /**
     * Get a value from cache
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key not found
     * @return mixed Cached value or default
     */
    public static function get(string $key, $default = null)
    {
        return self::get_persistent(self::_transform_key_build($key), $default);
    }

    /**
     * Same as ::get but survives changes in the development environment
     *
     * The developer must call this function during manifest build phase, because
     * the build key to determine if the build has been updated is not available
     * until the manifest resccan is complete.  Calling get() during manifest rescan
     * will throw an exception.
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key not found
     * @return mixed Cached value or default
     */
    public static function get_persistent(string $key, $default = null)
    {
        self::_init();

        if (self::_redis_bypass()) {
            return null;
        }

        // Maintenance mode: silent cache miss (reads are never logged).
        if (self::_maintenance_bypass()) {
            return $default;
        }

        $full_key = self::_make_key_persistent($key);

        $value = self::$_redis->get($full_key);

        if ($value === false) {
            return $default;
        }

        // Counter keys hold a bare numeric string written by redis itself (INCR/DECR), never a
        // serialize() payload - see increment(). A serialize() payload always begins with a type
        // letter or 'N', so the two encodings can never be confused.
        if (self::_is_counter_payload($value)) {
            return (int) $value;
        }

        // Anything that is neither encoding was not written by this class. Reject it BEFORE
        // unserialize() so the caller gets a message naming the key, rather than a bare
        // "unserialize(): Error at offset 0" - and never a bogus false that reads as a value.
        if (!self::_is_serialized_payload($value)) {
            throw new RuntimeException(
                "RsxCache: corrupt cache payload for key '{$key}' - the stored value is neither a "
                . 'serialize() payload nor a counter value'
            );
        }

        return unserialize($value);
    }

    /**
     * Does this raw redis string carry a serialize() payload?
     *
     * Every serialize() output is 'N;' (null) or a type letter followed by ':'.
     */
    private static function _is_serialized_payload(string $value): bool
    {
        if ($value === 'N;') {
            return true;
        }

        return isset($value[1])
            && $value[1] === ':'
            && strpos('bidsaOECR', $value[0]) !== false;
    }

    /**
     * Does this raw redis string hold a counter value rather than a serialize() payload?
     *
     * A serialize() payload always starts with a type letter followed by ':' (or 'N;'), so a
     * string of digits with an optional leading '-' can only have come from INCR/DECR.
     */
    private static function _is_counter_payload(string $value): bool
    {
        $digits = (isset($value[0]) && $value[0] === '-') ? substr($value, 1) : $value;

        return $digits !== '' && ctype_digit($digits);
    }

    /**
     * Set a value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expiration Expiration time in seconds (0 = never expire)
     * @return bool Success
     */
    public static function set(string $key, $value, int $expiration = self::NO_EXPIRATION): bool
    {
        return self::set_persistent(self::_transform_key_build($key), $value, $expiration);
    }

    /**
     * Set a ::set but survives a manifest rescan
     * See ::get_persistent
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expiration Expiration time in seconds (0 = never expire)
     * @return bool Success
     */
    public static function set_persistent(string $key, $value, int $expiration = self::NO_EXPIRATION): bool
    {
        self::_init();

        if (self::_redis_bypass()) {
            return false;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return false;
        }

        $full_key = self::_make_key_persistent($key);

        $value = serialize($value);

        if ($expiration > 0) {
            return self::$_redis->setex($full_key, $expiration, $value);
        }

        return self::$_redis->set($full_key, $value);
    }

    /**
     * Delete a key from cache
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public static function delete(string $key): bool
    {
        self::_init();

        if (self::_redis_bypass()) {
            return false;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return false;
        }

        $full_key = self::_make_key_persistent(self::_transform_key_build($key));

        return self::$_redis->del($full_key) > 0;
    }

    /**
     * Check if a key exists in cache
     *
     * @param string $key Cache key
     * @return bool
     */
    public static function exists(string $key): bool
    {
        self::_init();

        if (self::_redis_bypass()) {
            return false;
        }

        // Maintenance mode: a read - silent, and nothing can be present.
        if (self::_maintenance_bypass()) {
            return false;
        }

        $full_key = self::_make_key_persistent(self::_transform_key_build($key));

        return self::$_redis->exists($full_key) > 0;
    }

    /**
     * Clear the entire cache
     * Only clears keys with the current build key prefix
     */
    public static function clear(): void
    {
        self::_init();

        if (self::_redis_bypass()) {
            return;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return;
        }

        self::$_redis->flushDb();
    }

    /**
     * Increment a numeric value
     *
     * COUNTER KEYS ARE THEIR OWN CONTRACT: a key touched by increment()/decrement() holds a raw
     * numeric string maintained by redis, NOT a serialize() payload. Never mix set() and
     * increment() on the same key - set() stores 'i:100;', which redis cannot INCR. That mistake
     * throws here instead of coming back as an indistinguishable 0.
     *
     * get() reads a counter key back as an int; delete()/exists() work normally.
     *
     * @param string $key Cache key
     * @param int $amount Amount to increment by
     * @return int New value
     */
    public static function increment(string $key, int $amount = 1): int
    {
        self::_init();

        if (self::_redis_bypass()) {
            return 0;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return 0;
        }

        $full_key = self::_make_key_persistent(self::_transform_key_build($key));

        $result = ($amount === 1)
            ? self::$_redis->incr($full_key)
            : self::$_redis->incrBy($full_key, $amount);

        return self::_counter_result($result, 'increment', $key);
    }

    /**
     * Increment a counter that expires a fixed time after it was FIRST created
     *
     * The windowed-failure-counter primitive: "how many times has this happened in the last N
     * seconds", answered by one redis key instead of a growing table of rows. The framework's
     * login-failure throttling counters are built on it.
     *
     * FIXED WINDOW, NOT A SLIDING ONE: the TTL is applied only on the increment that CREATES the
     * key (the reply equals $amount), and no later increment touches it. The window therefore
     * runs from the first event to first-event + $ttl_seconds, then the counter disappears
     * entirely and the next event opens a new window. A sliding window would let a slow attacker
     * hold a counter alive forever.
     *
     * NAMESPACE: persistent (see get_persistent()), NOT build-scoped. A counter measuring real
     * time must survive a manifest rebuild - a build-scoped counter would silently reset to zero
     * on every file change. Consequence: read it with get_counter(), never with get(), which
     * looks under the build-prefixed key.
     *
     * Same counter-key contract as increment(): the key holds a raw numeric string maintained by
     * redis. NEVER mix set()/set_persistent() with this method on one key - a serialize() payload
     * cannot be INCR'd, and the attempt throws here rather than returning an indistinguishable 0.
     *
     * MAINTENANCE MODE returns 0 without touching redis (redis is stopped). Callers that throttle
     * on the result therefore fail OPEN for the window - deliberate: a stopped cache must not
     * lock users out.
     *
     * @param string $key Counter key (persistent namespace)
     * @param int $ttl_seconds Window length, applied when the key is created (must be > 0)
     * @param int $amount Amount to increment by
     * @return int New value, or 0 when the cache is unavailable
     */
    public static function increment_with_ttl(string $key, int $ttl_seconds, int $amount = 1): int
    {
        if ($ttl_seconds <= 0) {
            throw new InvalidArgumentException(
                "RsxCache::increment_with_ttl() requires a positive ttl_seconds for key '{$key}',"
                . " got {$ttl_seconds} - an unexpiring counter is what increment() is for"
            );
        }

        self::_init();

        if (self::_redis_bypass()) {
            return 0;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return 0;
        }

        $full_key = self::_make_key_persistent($key);

        $result = ($amount === 1)
            ? self::$_redis->incr($full_key)
            : self::$_redis->incrBy($full_key, $amount);

        $value = self::_counter_result($result, 'increment_with_ttl', $key);

        // The key was created by THIS call, so this is where the window opens. Any other reply
        // means the key already existed and already carries the window it was created with.
        if ($value === $amount) {
            self::$_redis->expire($full_key, $ttl_seconds);
        }

        return $value;
    }

    /**
     * Read a counter written by increment_with_ttl()
     *
     * Persistent namespace, so this is the ONLY read path for those counters (get() looks under
     * the build-prefixed key and would answer null forever).
     *
     * A missing key - never incremented, or expired with its window - is 0. So is an unavailable
     * cache (maintenance mode), which is what makes a throttle built on this fail OPEN.
     *
     * @param string $key Counter key (persistent namespace)
     * @return int Current count, 0 when absent or unreadable
     */
    public static function get_counter(string $key): int
    {
        $value = self::get_persistent($key, 0);

        // Degraded cache (no redis extension in IDE context) answers null, not the default.
        if ($value === null) {
            return 0;
        }

        if (!is_int($value)) {
            throw new RuntimeException(
                "RsxCache::get_counter() found a non-counter value at key '{$key}'"
                . ' - counter keys hold a raw numeric value and must never be written with'
                . ' RsxCache::set_persistent(), which stores a serialized payload'
            );
        }

        return $value;
    }

    /**
     * Decrement a numeric value
     *
     * Same counter-key contract as increment().
     *
     * @param string $key Cache key
     * @param int $amount Amount to decrement by
     * @return int New value
     */
    public static function decrement(string $key, int $amount = 1): int
    {
        self::_init();

        if (self::_redis_bypass()) {
            return 0;
        }

        if (self::_maintenance_bypass()) {
            self::_note_maintenance_write_skipped();

            return 0;
        }

        $full_key = self::_make_key_persistent(self::_transform_key_build($key));

        $result = ($amount === 1)
            ? self::$_redis->decr($full_key)
            : self::$_redis->decrBy($full_key, $amount);

        return self::_counter_result($result, 'decrement', $key);
    }

    /**
     * Convert a redis INCR/DECR reply into an int, or fail loud.
     *
     * phpredis answers false when the key does not hold an integer - overwhelmingly because the
     * key was seeded through set() (which serializes). Coercing that false through the ': int'
     * return type would produce a 0 that is indistinguishable from a genuine count.
     */
    private static function _counter_result($result, string $operation, string $key): int
    {
        if ($result === false) {
            $redis_error = self::$_redis->getLastError();
            self::$_redis->clearLastError();

            throw new RuntimeException(
                "RsxCache::{$operation}() failed for key '{$key}'"
                . ($redis_error ? " (redis: {$redis_error})" : '')
                . ' - counter keys hold a raw numeric value and must never be seeded or overwritten'
                . ' with RsxCache::set(), which stores a serialized payload'
            );
        }

        return (int) $result;
    }

    // Build-scoped key: manifest build key + test-run namespace + user key.
    private static function _transform_key_build(string $key): string
    {
        return Manifest::get_build_key() . static::_test_run_suffix() . '_' . $key;
    }

    /**
     * Create a full cache key with build prefix
     *
     * @param string $key User-provided key
     * @return string Full cache key
     */
    private static function _make_key_persistent(string $key): string
    {
        return 'cache:' . static::_test_run_suffix() . sha1($key);
    }

    /**
     * Namespace suffix separating a TEST RUN's cache from the developer's.
     *
     * rsx:test swaps the default DB connection to 'test' for the whole run, and the file
     * subsystem is redirected to a test-scoped root - but Redis used to be SHARED, so any
     * cache write during a test persisted test-database state into the key the next dev
     * request reads (found 2026-08-18: a test run left _type_refs ids from rspade_test in
     * 'type_refs_map', and rsx:health then reported the developer database's registry as a
     * single entry). Keying off the default connection makes the split self-detecting for
     * every build-scoped AND persistent key, in-process and in any subprocess that swaps
     * the connection the same way.
     */
    private static function _test_run_suffix(): string
    {
        return config('database.default') === 'test' ? '_test' : '';
    }

    /**
     * Request-scoped cache - stores value in static property for request duration
     *
     * Simplest caching - no Redis, no locks, just memory. Perfect for expensive
     * calculations that might be called multiple times in a single request.
     *
     * @param string $key Cache key (request-scoped)
     * @param callable $callback Callback to generate value if not cached
     * @return mixed Cached or generated value
     */
    public static function once(string $key, callable $callback)
    {
        if (array_key_exists($key, self::$_once_cache)) {
            return self::$_once_cache[$key];
        }

        $value = $callback();
        self::$_once_cache[$key] = $value;

        return $value;
    }

    /**
     * Build-scoped cache with advisory locking
     *
     * Caches value in Redis with build key prefix. Uses advisory write lock
     * during cache building to prevent stampede (multiple processes building
     * same cache simultaneously). Cache survives until manifest rebuild.
     *
     * @param string $key Cache key (build-scoped)
     * @param callable $callback Callback to generate value if not cached
     * @param int|null $seconds Expiration in seconds (null = never expire)
     * @return mixed Cached or generated value
     */
    public static function remember(string $key, callable $callback, ?int $seconds = null)
    {
        self::_init();

        // Check cache first (fast path - no lock needed)
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }

        // Cache miss - acquire write lock to build cache
        // This prevents multiple processes from building the same cache
        // SYSTEM lock: the cache is per-box, so another server building the same value is
        // duplicated work rather than corruption. Waits forever - the callback owns the
        // duration, and waiting for it is exactly what prevents the stampede.
        $lock_token = RsxLocks::system_lock('cache_build:' . $key);

        try {
            // Check cache again after acquiring lock
            // Another process may have built it while we were waiting
            $value = self::get($key);
            if ($value !== null) {
                return $value;
            }

            // Build the cache
            $value = $callback();

            // Store in cache
            $expiration = $seconds ?? self::NO_EXPIRATION;
            self::set($key, $value, $expiration);

            return $value;
        } finally {
            RsxLocks::release_lock($lock_token);
        }
    }
}
