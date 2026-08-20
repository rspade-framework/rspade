<?php

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Session\User_Agent;

/**
 * Login History - Static service for recording login outcomes and reading them back
 *
 * TWO STORES, ON PURPOSE:
 *
 * - SUCCESSES are database rows in `_login_history` (a system table, underscore-prefixed).
 *   They grow with real logins, are readable per user, and are pruned on a retention window by
 *   Session_Cleanup_Service (rsx.sessions.login_history_retention_days).
 *
 * - FAILURES are EPHEMERAL. record_failure() writes no row at all: it increments two redis
 *   counters (per email, per IP) that expire on rsx.sessions.login_failure_window_minutes, and
 *   emits one Log::warning() line. That log line is the forensic record; the counters exist only
 *   to answer the throttling question "how many failures in the window".
 *
 *   WHY (2026-08-12 CR): /login is anonymous-reachable, so a persisted failure row was an
 *   UNAUTHENTICATED INSERT - any bot could grow the table without limit, and the only consumers
 *   of those rows were the two short-window counts below. Storing them bought unbounded growth
 *   in exchange for a number a TTL'd counter answers exactly.
 *
 *   Consequence, stated plainly: a long-horizon failure history (a 30-day failed-attempt count,
 *   "who attacked this account, from where") is no longer answerable from this API. The window
 *   is the horizon. Anything longer must come from the log.
 *
 * Throttling built on the counters FAILS OPEN: under maintenance mode redis is stopped, the
 * increments are dropped and the counts read 0. A cache outage must not lock users out.
 *
 * Similar to Session class design - static methods for developers to interact with system
 * tables without needing to know the underlying table structure.
 */
class Login_History
{
    /**
     * Status vocabulary.
     *
     * Framework producers (RsxAuth::attempt()): SUCCESS, FAILED_PASSWORD, FAILED_NOT_FOUND.
     *
     * FAILED_2FA / FAILED_LOCKED / FAILED_DISABLED have NO framework producer - account state
     * (status_id, is_activated, is_verified) and second factors are APPLICATION vocabulary, so
     * the app records those outcomes itself through record_failure(). They are kept here so
     * every app spells the same outcome the same way.
     */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED_PASSWORD = 'failed_password';
    public const STATUS_FAILED_2FA = 'failed_2fa';
    public const STATUS_FAILED_LOCKED = 'failed_locked';
    public const STATUS_FAILED_DISABLED = 'failed_disabled';
    public const STATUS_FAILED_NOT_FOUND = 'failed_not_found';

    /**
     * Record a successful login
     *
     * The only thing that writes a `_login_history` row.
     *
     * @param int $login_user_id The authenticated user's ID
     * @param string $email The email used to log in
     * @return void
     */
    public static function record_success(int $login_user_id, string $email): void
    {
        DB::table('_login_history')->insert([
            'login_user_id' => $login_user_id,
            'email_attempted' => $email,
            'ip_address' => self::_get_client_ip(),
            'user_agent' => self::_get_user_agent(),
            'status' => self::STATUS_SUCCESS,
            'failure_reason' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Record a failed login attempt
     *
     * Writes NO database row (see the class docblock - 2026-08-12 CR). It increments the
     * per-email and per-IP throttle counters, both expiring on the configured window, and logs
     * one warning line carrying the whole event for forensics.
     *
     * Safe to call with anything a stranger typed: nothing here is trusted, and no secret ever
     * reaches this function (the password is never a parameter).
     *
     * @param string $email The email attempted
     * @param string $status One of the STATUS_FAILED_* constants
     * @param string|null $reason Optional failure reason details
     * @param int|null $login_user_id Optional user ID if known
     * @return void
     */
    public static function record_failure(
        string $email,
        string $status = self::STATUS_FAILED_PASSWORD,
        ?string $reason = null,
        ?int $login_user_id = null
    ): void {
        $ip_address = self::_get_client_ip();
        $window_seconds = self::_failure_window_seconds();

        RsxCache::increment_with_ttl(self::_email_counter_key($email), $window_seconds);
        RsxCache::increment_with_ttl(self::_ip_counter_key($ip_address), $window_seconds);

        $context = [
            'email' => $email,
            'ip_address' => $ip_address,
            'status' => $status,
            'reason' => $reason,
        ];

        if ($login_user_id !== null) {
            $context['login_user_id'] = $login_user_id;
        }

        Log::warning('Login failure', $context);
    }

    /**
     * Length of the failure-counter window, in seconds.
     *
     * @return int
     */
    private static function _failure_window_seconds(): int
    {
        $minutes = (int) config('rsx.sessions.login_failure_window_minutes', 15);

        if ($minutes <= 0) {
            shouldnt_happen('rsx.sessions.login_failure_window_minutes must be a positive number of minutes');
        }

        return $minutes * 60;
    }

    /**
     * Counter key for an email address.
     *
     * Hashed: the raw address would otherwise sit in redis for the window, readable by anything
     * that can list the keyspace. Normalized first so casing and stray whitespace cannot be used
     * to open a fresh window per attempt.
     *
     * @param string $email
     * @return string
     */
    private static function _email_counter_key(string $email): string
    {
        return 'login_failures:email:' . sha1(strtolower(trim($email)));
    }

    /**
     * Counter key for a client IP.
     *
     * @param string $ip_address
     * @return string
     */
    private static function _ip_counter_key(string $ip_address): string
    {
        return 'login_failures:ip:' . $ip_address;
    }

    /**
     * Get login history for a specific user
     *
     * Successes only - failures are not stored (see the class docblock). The status/label and
     * failure_reason keys remain in the returned shape because an application may record its own
     * outcomes here through record_success(); framework-written rows are all SUCCESS.
     *
     * @param int $login_user_id
     * @param int $limit Maximum number of records to return
     * @return array Array of login history records
     */
    public static function get_history_for_user(int $login_user_id, int $limit = 10): array
    {
        return DB::table('_login_history')
            ->where('login_user_id', $login_user_id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($record) {
                return [
                    'id' => $record->id,
                    'email' => $record->email_attempted,
                    'ip_address' => $record->ip_address,
                    'user_agent' => $record->user_agent,
                    'user_agent_parsed' => User_Agent::parse($record->user_agent),
                    'location' => self::_format_location($record),
                    'status' => $record->status,
                    'status_label' => self::_get_status_label($record->status),
                    'failure_reason' => $record->failure_reason,
                    'created_at' => $record->created_at,
                ];
            })
            ->toArray();
    }

    /**
     * Get count of failed login attempts for an email within the failure window
     * Useful for rate limiting / lockout logic
     *
     * THE ANSWER IS BOUNDED BY rsx.sessions.login_failure_window_minutes. Failures live in a
     * counter that expires with that window, so a request for a LARGER window returns the
     * window's count, not a longer history: the 30-day failed-attempt count that a row-backed
     * table could answer is not answerable from an ephemeral store. $minutes is retained so
     * existing call sites keep working; shrink such a stat to the window rather than trusting a
     * number that cannot mean what it says.
     *
     * Returns 0 when the cache is unavailable (maintenance mode) - throttling fails open.
     *
     * @param string $email
     * @param int $minutes Requested time window in minutes (see above - the configured window wins)
     * @return int Number of failed attempts inside the configured window
     */
    public static function get_failed_attempts_count(string $email, int $minutes = 15): int
    {
        return RsxCache::get_counter(self::_email_counter_key($email));
    }

    /**
     * Get count of failed login attempts from an IP within the failure window
     * Useful for IP-based rate limiting
     *
     * Same window semantics as get_failed_attempts_count().
     *
     * @param string $ip_address
     * @param int $minutes Requested time window in minutes (see above - the configured window wins)
     * @return int Number of failed attempts inside the configured window
     */
    public static function get_failed_attempts_count_by_ip(string $ip_address, int $minutes = 15): int
    {
        return RsxCache::get_counter(self::_ip_counter_key($ip_address));
    }

    /**
     * Get client IP address, handling proxies
     *
     * @return string
     */
    private static function _get_client_ip(): string
    {
        if (php_sapi_name() === 'cli') {
            return 'CLI';
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get user agent string
     *
     * @return string|null
     */
    private static function _get_user_agent(): ?string
    {
        if (php_sapi_name() === 'cli') {
            return 'CLI';
        }

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $user_agent ? substr($user_agent, 0, 512) : null;
    }

    /**
     * Format location from record fields
     *
     * @param object $record
     * @return string|null
     */
    private static function _format_location(object $record): ?string
    {
        $parts = array_filter([
            $record->location_city,
            $record->location_region,
            $record->location_country,
        ]);

        return !empty($parts) ? implode(', ', $parts) : null;
    }

    /**
     * Get human-readable status label
     *
     * @param string $status
     * @return string
     */
    private static function _get_status_label(string $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESS => 'Success',
            self::STATUS_FAILED_PASSWORD => 'Failed - Invalid Password',
            self::STATUS_FAILED_2FA => 'Failed - 2FA Verification',
            self::STATUS_FAILED_LOCKED => 'Failed - Account Locked',
            self::STATUS_FAILED_DISABLED => 'Failed - Account Disabled',
            self::STATUS_FAILED_NOT_FOUND => 'Failed - User Not Found',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
