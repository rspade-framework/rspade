# Redis Usage

## Overview

Redis backs the **cache**, **realtime** pub/sub + subscriber registry, and the **task
worker-slot registry**.

**Redis does NOT back locking.** `RsxLocks` left Redis entirely: web-cluster locks are held
by a live socket to the `rsx-lockd` daemon (`system/bin/rsx-lockd/`), and system locks are
`flock()` files under `storage/flock/`. A lock has no TTL, no lease and no renewal, because
its holder is a connection rather than a key with an expiry. See `php artisan rsx:man locks`
and `Core/Locks/RsxLocks.php`. Nothing in this file applies to locks.

One consequence worth stating plainly: **a Redis flush does not release any lock**, and
restarting Redis has no effect on lock state.

## RsxCache - Caching System

### Purpose
High-performance caching with automatic invalidation when code changes.

### Key Features
- **Automatic prefixing**: Uses manifest build key to invalidate on code changes
- **Persistent namespace**: `*_persistent()` keys skip the build prefix and survive a rebuild
- **LRU eviction**: 128MB cache with automatic eviction of least-used items
- **Type preservation**: Automatically serializes/deserializes complex types
- **Atomic counters**: including a windowed (TTL-on-create) counter
- **Remember pattern**: Cache-or-generate helper

### Initialization

None. The cache connects lazily on first use (`RsxCache::_init()` is internal and
idempotent) - there is no `initialize()` to call.

```php
use App\RSpade\Core\Cache\RsxCache;
```

### Basic Usage

```php
// Set a value (never expires)
RsxCache::set('user:123', $user);

// Set with expiration
RsxCache::set('api:response', $data, RsxCache::HOUR);
RsxCache::set('temp:data', $value, 300); // 5 minutes

// Get a value
$user = RsxCache::get('user:123');
$data = RsxCache::get('missing:key', 'default value');

// Delete a key
RsxCache::delete('user:123');

// Check existence
if (RsxCache::exists('user:123')) {
    // Key exists
}
```

### Advanced Operations

```php
// Remember pattern - cache or generate
$users = RsxCache::remember('all_users', function() {
    return User::all(); // Expensive operation
}, RsxCache::HOUR);

// Persistent namespace - NOT build-prefixed, so it survives a manifest rebuild
RsxCache::set_persistent('install:fingerprint', $value, RsxCache::DAY);
$value = RsxCache::get_persistent('install:fingerprint');

// Atomic counters
// COUNTER KEYS ARE THEIR OWN CONTRACT: they hold a raw numeric value maintained by redis.
// NEVER seed or overwrite one with set(), which stores a serialized payload redis cannot
// INCR - that throws, naming the key. get() reads a counter key back as an int.
$count = RsxCache::increment('page:views');   // seeds a missing key at 1, NO expiry
$count = RsxCache::increment('api:calls', 5); // +5
$count = RsxCache::decrement('stock:qty', 1);
$count = RsxCache::get('page:views');         // int

// Flush the cache database
RsxCache::clear();
```

There are no batch (`get_many` / `set_many`) or `stats()` helpers, and no
`clear_all()` - `clear()` flushes the cache database (db 0) outright.

### Windowed counters: `increment_with_ttl()` / `get_counter()`

The "how many times has this happened in the last N seconds" primitive - one redis
key instead of a growing table of rows. The framework's login-failure throttling
counters are built on it.

```php
$window = 15 * 60;

$failures = RsxCache::increment_with_ttl('login_failures:ip:' . $ip, $window);
$failures = RsxCache::get_counter('login_failures:ip:' . $ip);   // 0 when absent
```

The contract, in full:

- **FIXED window, not sliding.** The TTL is applied only on the increment that
  CREATES the key (the reply equals `$amount`); no later increment touches it. The
  window runs from the first event, then the counter disappears entirely and the
  next event opens a new one. A sliding window would let a slow attacker hold a
  counter alive forever.
- **PERSISTENT namespace, deliberately.** A counter measuring real time must survive
  a manifest rebuild; a build-scoped one would silently reset to zero on every file
  change. Consequence: read it with `get_counter()`, NEVER with `get()`, which looks
  under the build-prefixed key and would answer 0 forever.
- **Same counter-key contract as `increment()`.** Never mix `set()`/`set_persistent()`
  with either on one key - a serialize() payload cannot be INCR'd, and the attempt
  throws (naming the key) rather than returning an indistinguishable 0. `get_counter()`
  throws symmetrically if it finds a non-counter value.
- `$ttl_seconds` must be positive - a zero/negative TTL throws
  (`InvalidArgumentException`); an unexpiring counter is what `increment()` is for.
- **Maintenance mode returns 0** without touching redis (redis is stopped), so a
  caller that throttles on the result fails OPEN for that window. That is the
  intended behavior: a stopped cache must never lock users out.

### The stampede lock is NOT a Redis lock

`RsxCache::remember()` guards a miss with `RsxLocks::system_lock('cache_build:' . $key)` -
an `flock()` on THIS BOX. That is deliberate: the cache is per-box, so a duplicate build on
another server is wasted work rather than corruption, and paying a network round trip to
prevent it would cost more than it saves. The callback may run for as long as it likes; the
lock cannot expire underneath it.

### Expiration Constants

```php
RsxCache::NO_EXPIRATION = 0;  // Never expire (default)
RsxCache::HOUR = 3600;
RsxCache::DAY = 86400;
RsxCache::WEEK = 604800;
```

## Other Redis consumers

- **Realtime** - publish/subscribe frames plus the subscriber registry the relay maintains
  (`Core/Realtime/`). See `php artisan rsx:man realtime`.
- **Task worker slots** - `Task_Worker_Registry` claims a slot per spawned worker to cap the
  shared pool at `rsx.tasks.global_max_workers`. Slots ARE heartbeat-refreshed with a TTL, and
  that is correct: a slot is an accounting record, not a mutual-exclusion guarantee.
- **Full Page Cache** - the Node FPC proxy stores rendered pages in Redis and reads `FPC_*` /
  `REDIS_*` straight from `.env`.

## Integration Examples

### Example: Cached API Responses
```php
class Api_Controller
{
    public static function get_users(Request $request)
    {
        $cache_key = 'api:users:' . md5($request->getQueryString());

        return RsxCache::remember($cache_key, function() {
            // Expensive database query
            return User::with(['posts', 'comments'])
                ->where('active', true)
                ->get();
        }, RsxCache::HOUR);
    }
}
```

## Best Practices

### Caching
1. **Use descriptive keys** - include context (e.g., 'user:123:profile')
2. **Set appropriate expiration** - don't cache forever unless needed
3. **Clear selectively** - use delete() for specific keys vs clear() for all
4. **Monitor memory usage** - `redis-cli info memory` (there is no stats() helper)
5. **Handle cache misses** - always provide defaults or regeneration logic

## Troubleshooting

### Cache Issues
```php
// Clear and rebuild the cache - it repopulates on subsequent requests
RsxCache::clear();
```

### Redis Connection Issues
RsxCache throws a clear exception if Redis is unavailable:
- `shouldnt_happen("Failed to connect to Redis for caching")`

The ONE exception is maintenance mode, where Redis is deliberately stopped: reads miss
silently and writes are dropped with one log warning per process (see
`php artisan rsx:man maintenance_mode`).

Ensure Redis is running and accessible via environment variables:
- `REDIS_HOST` (default: 127.0.0.1)
- `REDIS_PORT` (default: 6379)
- `REDIS_SOCKET` (optional, preferred for performance)

## Performance Considerations

### Caching Performance
- **Unix socket**: ~150k ops/sec
- **TCP localhost**: ~80-100k ops/sec
- **Serialization overhead**: ~10-20% for complex objects
- **LRU eviction**: Automatic, sub-millisecond

## Redis Database Assignment

- **Database 0**: RsxCache (128MB, LRU eviction)
- **Databases 1-15**: Laravel's own Redis connections and custom use

Database 1 was formerly the lock database. It is no longer used for locks.
