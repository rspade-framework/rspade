<?php

namespace App\RSpade\Core\FPC;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Full Page Cache management utility
 *
 * Provides methods to clear FPC entries from Redis.
 * The FPC is a Node.js reverse proxy that caches responses
 * marked with #[FPC] attribute in Redis.
 *
 * Cache key format: fpc:{build_key}:{sha1(url)}
 * Redis DB: 0 (shared cache database). Entries carry a TTL only when
 * FPC_TTL_MINS > 0; otherwise they persist until the build key rotates,
 * an explicit clear, or rsx:clean flushes the DB.
 */
class Rsx_FPC
{
    /**
     * Whether the FPC subsystem is active for this deployment.
     *
     * Backed by SSR_FPC_ENABLED in .env (config('rsx.fpc.enabled')). When
     * disabled, the clear methods are clean no-ops that never touch Redis.
     */
    public static function is_enabled(): bool
    {
        return (bool) config('rsx.fpc.enabled');
    }

    /**
     * Clear all FPC cache entries for the current build key
     *
     * @return int Number of deleted entries (0 when FPC is disabled)
     */
    public static function clear(): int
    {
        // FPC disabled: nothing is cached, so there is nothing to purge. Clean
        // no-op WITHOUT touching Redis (and without failing loud - see _get_redis).
        if (!self::is_enabled()) {
            return 0;
        }

        $redis = self::_get_redis();

        $build_key = Manifest::get_build_key();
        $pattern = "fpc:{$build_key}:*";
        $count = 0;

        // Use SCAN to avoid blocking Redis with KEYS
        $iterator = null;
        do {
            $keys = $redis->scan($iterator, $pattern, 100);
            if ($keys !== false && count($keys) > 0) {
                $count += $redis->del($keys);
            }
        } while ($iterator > 0);

        return $count;
    }

    /**
     * Clear FPC cache for a specific URL
     *
     * @param string $url The URL path with optional query string (e.g., '/about' or '/search?q=test')
     * @return bool True if entry was deleted (false when FPC is disabled)
     */
    public static function clear_url(string $url): bool
    {
        // FPC disabled: nothing to purge. Clean no-op, no Redis contact.
        if (!self::is_enabled()) {
            return false;
        }

        $redis = self::_get_redis();

        $build_key = Manifest::get_build_key();

        // Parse and sort query params to match the proxy's key generation
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? $url;

        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            ksort($params);
            $full_url = $path . '?' . http_build_query($params);
        } else {
            $full_url = $path;
        }

        $hash = sha1($full_url);
        $key = "fpc:{$build_key}:{$hash}";

        return $redis->del($key) > 0;
    }

    /**
     * rsx:health probe: FPC subsystem status. A public static
     * `#[Health_Check('label')]` (bare marker attribute - never a defined class).
     *
     * When FPC is disabled it is an INFO, not a FAIL. When enabled it verifies
     * Redis reachability + auth (via the fail-loud _get_redis path, whose throw is
     * caught here and reported as a FAIL row rather than crashing the runner) and a
     * PING, plus an advisory liveness probe of the Node proxy port.
     *
     * @return array
     */
    #[Health_Check('FPC')]
    public static function fpc_health(): array
    {
        if (!self::is_enabled()) {
            return ['status' => 'INFO', 'detail' => 'disabled (SSR_FPC_ENABLED=false)'];
        }

        $rows = [];

        // Redis reachability + auth. _get_redis() fails loud on any connect/auth
        // failure; catch it here so a broken Redis reports FAIL, not a crash.
        try {
            $redis = self::_get_redis();
            $pong = $redis->ping();
            $ok = ($pong === true || $pong === 'PONG' || $pong === '+PONG');
            $rows[] = $ok
                ? ['status' => 'OK', 'detail' => 'Redis reachable (DB 0), auth OK']
                : ['status' => 'FAIL', 'detail' => 'Redis PING returned an unexpected value', 'remediation' => 'check Redis health and REDIS_PASSWORD'];
        } catch (\Throwable $e) {
            $rows[] = [
                'status' => 'FAIL',
                'detail' => 'Redis unreachable: ' . $e->getMessage(),
                'remediation' => 'verify REDIS_HOST/REDIS_PORT/REDIS_PASSWORD and that redis-server is running',
            ];
        }

        // Advisory: is the Node proxy listening? A down proxy is a WARN (nginx
        // falls back to PHP), so it never fails the health run on its own.
        $port = (int) env('FPC_PROXY_PORT', 3200);
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);

        if ($socket === false) {
            $rows[] = [
                'label' => 'FPC Proxy',
                'status' => 'WARN',
                'detail' => 'proxy not listening on 127.0.0.1:' . $port . ' (' . trim($errstr) . ')',
                'remediation' => 'start the FPC proxy - check supervisor [program:fpc-proxy] (node system/bin/fpc-proxy.js)',
            ];
        } else {
            fclose($socket);
            $rows[] = ['label' => 'FPC Proxy', 'status' => 'OK', 'detail' => 'proxy listening on 127.0.0.1:' . $port];
        }

        return $rows;
    }

    /**
     * Get Redis connection for FPC operations (DB 0).
     *
     * Fails loud on any connect/auth/select failure - a developer-invoked purge
     * that cannot reach Redis MUST surface, never silently report "nothing to
     * clear" (which would let stale pages serve forever). A failed handle is never
     * cached; only a healthy connection is memoized. Callers must gate on
     * is_enabled() first (a disabled subsystem has nothing to reach).
     */
    private static function _get_redis(): \Redis
    {
        static $redis = null;

        if ($redis instanceof \Redis) {
            return $redis;
        }

        $connection = new \Redis();
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);
        $password = env('REDIS_PASSWORD');

        try {
            if (!$connection->connect($host, $port, 2.0)) {
                throw new \RuntimeException('connect() returned false');
            }

            // Authenticate only when a real password is configured. The literal
            // string 'null' means "no password" (mirrors the Node proxy's
            // REDIS_PASSWORD handling in system/bin/fpc-proxy.js).
            if ($password && $password !== 'null') {
                $connection->auth($password);
            }

            $connection->select(0);
        } catch (\Throwable $e) {
            shouldnt_happen("FPC: cannot reach Redis for cache purge at {$host}:{$port} ({$e->getMessage()})");
        }

        $redis = $connection;

        return $redis;
    }
}
