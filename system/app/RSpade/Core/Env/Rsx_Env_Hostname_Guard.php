<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Env;

use RuntimeException;
use App\RSpade\Core\Rsx;

/**
 * Dev-mode .env hostname tripwire.
 *
 * A copy-pasted .env can declare an APP_URL host that belongs to a DIFFERENT
 * instance than the one the site is actually browsed under. When both names
 * resolve to the same outer reverse proxy, every observable signal looks healthy
 * while generated URLs (and the derived realtime relay URL wss://{host}/ws) point
 * at the wrong box. This guard fails loud on that mismatch during development.
 *
 * check() runs once per web request (immediately before route dispatch). It is a
 * DEVELOPMENT-mode tripwire only - production sites may legitimately answer on
 * many hostnames / behind CDNs, so the guard is gated on RSX_MODE, not on
 * is_dev_site(). Loopback REQUESTS (localhost / 127.* / ::1 - the curl/rsx:debug
 * testing channel) never trip it. A loopback-VALUED APP_URL is NOT exempted: an
 * APP_URL=https://localhost browsed under a real hostname is exactly the pasted
 * .env this guard exists to catch, so it fatals.
 *
 * The pure comparison core (build_declared + find_mismatch) takes plain inputs and
 * touches neither env nor $_SERVER, so it is unit-testable in isolation.
 */
class Rsx_Env_Hostname_Guard
{
    /**
     * Memoize the per-request decision. A passing (or bailed) check sets this so
     * repeat calls in one request are free; a mismatch throws before it is set, so
     * the fatal is raised on every call until .env is fixed.
     */
    private static bool $_checked = false;

    /**
     * Per-request memo of the collected declared hosts (env is read once).
     * @var array<int,array{var:string,host:string}>|null
     */
    private static ?array $_declared_cache = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Per-request entry point. Silently returns when the guard does not apply
     * (CLI, non-development mode, no HTTP host, or a loopback request); otherwise
     * throws RuntimeException when the request host disagrees with the APP_URL host.
     */
    public static function check(): void
    {
        if (self::$_checked) {
            return;
        }

        // CLI has no request host to check against.
        if (php_sapi_name() === 'cli') {
            self::$_checked = true;
            return;
        }

        // Development mode only: prod sites may answer on many hostnames / CDNs.
        if (!Rsx::is_development()) {
            self::$_checked = true;
            return;
        }

        if (!isset($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] === '') {
            self::$_checked = true;
            return;
        }

        $request_host = self::normalize_request_host($_SERVER['HTTP_HOST']);

        // The localhost testing channel (curl / rsx:debug) is always exempt.
        if (self::is_loopback_host($request_host)) {
            self::$_checked = true;
            return;
        }

        $declared = self::__collect_declared_hosts();
        $mismatch = self::find_mismatch($request_host, $declared);

        if ($mismatch !== null) {
            // NOTE: do NOT memoize on a mismatch - keep failing loud until fixed.
            throw new RuntimeException(
                'Dev-mode .env hostname mismatch: ' . $mismatch['var'] . ' host "'
                . $mismatch['env_host'] . '" != request host "' . $mismatch['request_host']
                . '". The .env of this instance declares a different hostname than the one'
                . ' it is being browsed on - fix APP_URL in .env to match.'
                . ' [rsx:man realtime]'
            );
        }

        self::$_checked = true;
    }

    /**
     * Pure comparison core: given the (already normalized) request host and the
     * collected declared entries, return the first exact mismatch or null.
     *
     * The match is EXACT - a sub-host of the APP_URL host does NOT satisfy it (the
     * old suffix rule is gone with RSX_HOSTNAME). Each declared entry is {var, host}.
     *
     * @param array<int,array{var:string,host:string}> $declared
     * @return array{var:string,env_host:string,request_host:string}|null
     */
    public static function find_mismatch(string $request_host, array $declared): ?array
    {
        foreach ($declared as $entry) {
            if ($request_host !== $entry['host']) {
                return [
                    'var' => $entry['var'],
                    'env_host' => $entry['host'],
                    'request_host' => $request_host,
                ];
            }
        }

        return null;
    }

    /**
     * Pure declared-host builder: given the relevant raw .env values, produce the
     * normalized comparison entries. Only APP_URL is consulted now. An empty
     * APP_URL yields no entry; a non-empty but unparseable APP_URL is a fatal
     * misconfiguration (fail loud). A loopback-VALUED APP_URL is NOT skipped.
     *
     * @param array{APP_URL?: ?string} $env
     * @return array<int,array{var:string,host:string}>
     */
    public static function build_declared(array $env): array
    {
        $declared = [];

        $app_url = trim((string) ($env['APP_URL'] ?? ''));
        if ($app_url !== '') {
            $host = parse_url($app_url, PHP_URL_HOST);
            if ($host === null || $host === false || $host === '') {
                throw new RuntimeException(
                    'Dev-mode .env hostname guard: APP_URL is set to "' . $app_url
                    . '" but no host could be parsed from it - fix APP_URL in .env'
                    . ' (expected e.g. https://host). [rsx:man realtime]'
                );
            }
            $declared[] = ['var' => 'APP_URL', 'host' => strtolower(trim($host))];
        }

        return $declared;
    }

    /**
     * A host is loopback when it is exactly "localhost", a 127.* IPv4 address, or
     * the IPv6 loopback "::1". Only loopback REQUEST hosts are exempted now; a
     * loopback-valued APP_URL is a real mismatch when browsed under a real host.
     */
    public static function is_loopback_host(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === 'localhost'
            || str_starts_with($host, '127.')
            || $host === '::1';
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Gather the live .env value and build the declared entry once per request.
     *
     * @return array<int,array{var:string,host:string}>
     */
    private static function __collect_declared_hosts(): array
    {
        if (self::$_declared_cache !== null) {
            return self::$_declared_cache;
        }

        self::$_declared_cache = self::build_declared([
            'APP_URL' => env('APP_URL'),
        ]);

        return self::$_declared_cache;
    }

    /**
     * Normalize a raw HTTP_HOST to a bare lowercased hostname (port stripped),
     * matching the normalization Rsx::get_hostname() applies. Handles the IPv6
     * bracket form ("[::1]:6200"). Public so the comparison path is fully
     * unit-testable (the PHP test runner is CLI, where check() itself bails).
     */
    public static function normalize_request_host(string $raw): string
    {
        $host = strtolower(trim($raw));

        if ($host !== '' && $host[0] === '[') {
            // IPv6 literal, possibly with a port: "[::1]:6200" -> "::1".
            $close = strpos($host, ']');
            if ($close !== false) {
                return substr($host, 1, $close - 1);
            }
        }

        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        return $host;
    }

    /**
     * Reset the per-request memo. Used by tests so successive cases each run the
     * full check; never needed in normal request flow.
     */
    public static function _testing_reset(): void
    {
        self::$_checked = false;
        self::$_declared_cache = null;
    }
}
