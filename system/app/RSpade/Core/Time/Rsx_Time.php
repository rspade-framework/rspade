<?php

namespace App\RSpade\Core\Time;

use Carbon\Carbon;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Time - Datetime handling for RSpade (moments in time with timezone)
 *
 * Datetimes represent specific moments in time. They always have a time component
 * and are timezone-aware. Stored in UTC, displayed in user's timezone.
 *
 * STRINGS, NOT CARBON: All external-facing methods return ISO 8601 strings
 * (format: "2024-12-24T15:30:45.123Z"). This keeps PHP and JavaScript in sync
 * with identical formats on both sides. Carbon is used internally for calculations.
 *
 * Model properties ($model->created_at) also return ISO strings via Rsx_DateTime_Cast.
 *
 * Core Principles:
 * - All datetimes stored in database as UTC
 * - All serialization uses ISO 8601 format
 * - External APIs return strings, not Carbon
 * - User timezone stored per user (login_users.timezone); a PORTAL request resolves the
 *   SITE's timezone instead - portal accounts have no personal zone (see get_user_timezone)
 * - Formatting happens on-demand, not on storage
 * - PHP and JS APIs are parallel (same method names)
 * - Datetime functions THROW if passed a date-only string
 * - Use Rsx_Date for calendar dates without time components
 *
 * See: php artisan rsx:man time
 */
class Rsx_Time
{
    // =========================================================================
    // TIMEZONE CACHING
    // =========================================================================

    /**
     * Cached user timezone
     * @var string|null
     */
    private static ?string $_cached_user_timezone = null;

    /**
     * User ID when timezone was cached (for invalidation)
     * @var int|null
     */
    private static ?int $_cached_user_id = null;

    /**
     * Site ID when timezone was cached (for invalidation)
     * @var int|null
     */
    private static ?int $_cached_site_id = null;

    /**
     * Experience the cached timezone was resolved for (for invalidation).
     *
     * Part of the cache key, not decoration: the staff and portal identities live on
     * the SAME session row, so (user_id, site_id) can repeat across a realm flip - a
     * process that serves a portal request and then a staff request (tests, a queue
     * worker asserting a request context) would otherwise be handed the other
     * experience's zone.
     *
     * @var bool|null
     */
    private static ?bool $_cached_is_portal = null;

    // =========================================================================
    // CURRENT TIME
    // =========================================================================

    /**
     * Get current time as UTC Carbon instance
     *
     * @return Carbon
     */
    public static function now(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * Get current time as ISO 8601 UTC string
     * Format: "2024-12-24T15:30:45.123Z"
     *
     * @return string
     */
    public static function now_iso(): string
    {
        return static::now()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Get current time as Unix timestamp (milliseconds)
     *
     * @return int
     */
    public static function now_ms(): int
    {
        return (int) (microtime(true) * 1000);
    }

    // =========================================================================
    // PARSING & VALIDATION
    // =========================================================================

    /**
     * Check if input is a valid datetime (not a date-only value)
     *
     * @param mixed $input
     * @return bool
     */
    public static function is_datetime($input): bool
    {
        if ($input instanceof Carbon || $input instanceof \DateTimeInterface) {
            return true;
        }

        if (is_numeric($input)) {
            return true; // Timestamps are datetimes
        }

        if (is_string($input)) {
            // Date-only strings are NOT datetimes
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
                return false;
            }

            // Has time component (T separator or HH:MM pattern)
            if (str_contains($input, 'T') || preg_match('/\d{2}:\d{2}/', $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse any reasonable datetime input to Carbon (UTC)
     * THROWS if passed a date-only string - use Rsx_Date for dates
     *
     * Accepts:
     * - Carbon instance (returned as copy, converted to UTC)
     * - ISO 8601 string: "2024-12-24T15:30:45Z" or "2024-12-24T15:30:45.123Z"
     * - Database format: "2024-12-24 15:30:45" (assumed UTC unless source_timezone specified)
     * - Unix timestamp (seconds or milliseconds - auto-detected)
     * - null (returns null)
     *
     * @param mixed $input
     * @param string|null $source_timezone If input has no timezone indicator, assume this (default: UTC)
     * @return Carbon|null
     * @throws \InvalidArgumentException If input is a date-only string
     */
    public static function parse($input, ?string $source_timezone = 'UTC'): ?Carbon
    {
        if ($input === null || $input === '') {
            return null;
        }

        // REJECT date-only strings - these should use Rsx_Date
        if (is_string($input) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            throw new \InvalidArgumentException(
                "Rsx_Time::parse() received date-only string '{$input}'. " .
                "Use Rsx_Date::parse() for dates without time components."
            );
        }

        if ($input instanceof Carbon) {
            return $input->copy()->setTimezone('UTC');
        }

        if ($input instanceof \DateTimeInterface) {
            return Carbon::instance($input)->setTimezone('UTC');
        }

        if (is_numeric($input)) {
            // Detect milliseconds vs seconds (after year 2001, ms > 10 digits)
            if ($input > 10000000000) {
                return Carbon::createFromTimestampMs((int) $input, 'UTC');
            }
            return Carbon::createFromTimestamp((int) $input, 'UTC');
        }

        if (is_string($input)) {
            // ISO 8601 with timezone indicator - parse directly
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $input)) {
                return Carbon::parse($input)->setTimezone('UTC');
            }

            // Database format (no timezone indicator) - use source_timezone
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $input)) {
                // Handle optional milliseconds
                $format = strlen($input) > 19 ? 'Y-m-d H:i:s.v' : 'Y-m-d H:i:s';
                return Carbon::createFromFormat(
                    $format,
                    $input,
                    $source_timezone
                )->setTimezone('UTC');
            }

            // Unrecognized format - this is a bug in calling code
            throw new \InvalidArgumentException(
                "Rsx_Time::parse() received unrecognized datetime format: '{$input}'. " .
                "Supported formats: ISO 8601 (2024-12-24T15:30:45Z) or database (2024-12-24 15:30:45)."
            );
        }

        return null;
    }

    // =========================================================================
    // TIMEZONE CONVERSION
    // =========================================================================

    /**
     * Convert time to a specific timezone
     *
     * @param mixed $time Parseable time input
     * @param string $timezone IANA timezone (e.g., "America/Chicago")
     * @return Carbon
     * @throws \InvalidArgumentException If time cannot be parsed
     */
    public static function to_timezone($time, string $timezone): Carbon
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            throw new \InvalidArgumentException("Cannot parse time: " . print_r($time, true));
        }
        return $carbon->setTimezone($timezone);
    }

    /**
     * Convert time to current user's timezone
     * Falls back to site default, then system default
     *
     * @param mixed $time
     * @return Carbon
     */
    public static function to_user_timezone($time): Carbon
    {
        return static::to_timezone($time, static::get_user_timezone());
    }

    /**
     * Get the current user's timezone
     *
     * Resolution, STAFF request/CLI: user setting -> site default -> config default
     * Resolution, PORTAL request:                   site default -> config default
     *
     * The portal has no user tier because portal_users carries no timezone column: a
     * portal account has no personal zone to prefer, so the SITE is the portal's only
     * meaningful source - and reading the STAFF user's personal zone for a client-portal
     * page (which is what asking the staff facade did, whenever the same browser also
     * held a staff session) is exactly the defect being closed. If portal users ever get
     * a timezone preference, this is the one place that changes.
     *
     * Result is cached and invalidated when the experience, user or site changes.
     *
     * @return string IANA timezone identifier
     */
    public static function get_user_timezone(): string
    {
        $is_portal = Rsx_Portal::is_portal_request();
        $raw_user_id = $is_portal ? Portal_Session::get_portal_user_id() : Session::get_login_user_id();
        $current_user_id = $raw_user_id === null ? null : (int) $raw_user_id;
        $current_site_id = (int) ($is_portal ? Portal_Session::get_site_id() : Session::get_site_id());

        // Check if cache is valid
        if (static::$_cached_user_timezone !== null
            && static::$_cached_is_portal === $is_portal
            && static::$_cached_user_id === $current_user_id
            && static::$_cached_site_id === $current_site_id) {
            return static::$_cached_user_timezone;
        }

        // Cache miss - recalculate
        $timezone = null;

        // Check logged-in user's preference (staff only - see the docblock)
        if (!$is_portal) {
            $login_user = Session::get_login_user();
            if ($login_user && !empty($login_user->timezone)) {
                $timezone = $login_user->timezone;
            }
        }

        // Check site default
        if ($timezone === null) {
            $site = static::__current_site();
            if ($site && !empty($site->timezone)) {
                $timezone = $site->timezone;
            }
        }

        // Config default
        if ($timezone === null) {
            $timezone = config('rsx.datetime.default_timezone', 'America/Chicago');
        }

        // Cache the result
        static::$_cached_user_timezone = $timezone;
        static::$_cached_is_portal = $is_portal;
        static::$_cached_user_id = $current_user_id;
        static::$_cached_site_id = $current_site_id;

        return $timezone;
    }

    /**
     * Set the signed-in user's timezone preference (STAFF only).
     *
     * Writes login_users.timezone (and optionally login_users.timezone_auto), then
     * INVALIDATES the resolution cache below - without that, code running later in
     * this same request (including the Ajax envelope's own _user_timezone field,
     * Ajax.php:552) would keep serving the zone resolved before the write.
     *
     * REFUSALS:
     * - Unknown IANA identifier -> InvalidArgumentException. This is the one refusal
     *   driven by user-supplied data; a controller converts it to a validation error.
     * - Portal request, or no signed-in login user -> shouldnt_happen(). Both are
     *   broken assumptions, not input: get_user_timezone() gives a portal request no
     *   user tier at all (portal accounts have no personal zone), so there is nothing
     *   for a portal caller to set, and the surfaces that call this are gated
     *   is_logged_in.
     *
     * @param string $timezone IANA timezone identifier (e.g. "America/Chicago")
     * @param bool|null $timezone_auto Also write the auto-set preference when non-null
     * @return bool Whether the RESOLVED user timezone changed. A timezone_auto-only
     *              change returns false: only the zone affects rendering, and this
     *              flag is what drives a client reload.
     * @throws \InvalidArgumentException If $timezone is not a known identifier
     */
    public static function set_user_timezone(string $timezone, ?bool $timezone_auto = null): bool
    {
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException(
                "Rsx_Time::set_user_timezone() received unknown timezone identifier '{$timezone}'."
            );
        }

        if (Rsx_Portal::is_portal_request()) {
            shouldnt_happen('Rsx_Time::set_user_timezone() called on a portal request - portal accounts have no personal timezone');
        }

        $login_user = Session::get_login_user();
        if (!$login_user) {
            shouldnt_happen('Rsx_Time::set_user_timezone() called with no signed-in login user');
        }

        $before = static::get_user_timezone();

        // Explicit assignment - no mass assignment.
        $login_user->timezone = $timezone;
        if ($timezone_auto !== null) {
            $login_user->timezone_auto = $timezone_auto;
        }
        $login_user->save();

        static::_clear_user_timezone_cache();

        return static::get_user_timezone() !== $before;
    }

    /**
     * Drop the memoized user-timezone resolution, forcing the next
     * get_user_timezone() call to resolve from scratch.
     *
     * Public because it is also the seam a test uses to prove the cache is honest
     * across a write it did not make itself.
     */
    public static function _clear_user_timezone_cache(): void
    {
        static::$_cached_user_timezone = null;
        static::$_cached_is_portal = null;
        static::$_cached_user_id = null;
        static::$_cached_site_id = null;
    }

    /**
     * Every IANA timezone identifier, mapped to a selectable label carrying its
     * CURRENT UTC offset: "America/Chicago" => "America/Chicago (UTC-05:00)".
     *
     * Offsets are computed at call time, so the label is DST-honest for the moment
     * the list is requested (Chicago reads -06:00 in January, -05:00 in July).
     *
     * @return array<string,string> identifier => label, sorted by identifier
     */
    public static function timezone_options(): array
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $options = [];

        foreach (timezone_identifiers_list() as $identifier) {
            $offset_seconds = (new \DateTimeZone($identifier))->getOffset($now);
            $sign = $offset_seconds < 0 ? '-' : '+';
            $offset_seconds = abs($offset_seconds);
            $hours = intdiv($offset_seconds, 3600);
            $minutes = intdiv($offset_seconds % 3600, 60);

            $options[$identifier] = sprintf('%s (UTC%s%02d:%02d)', $identifier, $sign, $hours, $minutes);
        }

        ksort($options);

        return $options;
    }

    /**
     * Get the current site's timezone (ignoring user preference)
     * Resolution: site default → config default → America/Chicago
     *
     * @return string IANA timezone identifier
     */
    public static function get_site_timezone(): string
    {
        $site = static::__current_site();
        if ($site && !empty($site->timezone)) {
            return $site->timezone;
        }

        return config('rsx.datetime.default_timezone', 'America/Chicago');
    }

    /**
     * The site whose timezone this request lives under, resolved by the EXPERIENCE of
     * the request (Rsx_Portal::is_portal_request()) rather than by who is signed in -
     * one browser session carries both identities, so an identity test picks the wrong
     * tenant in both directions.
     *
     * A portal request with no declared site throws (Portal_Session::get_site_id's
     * contract): the same refusal the ORM's tenant boundary makes, for the same reason.
     *
     * @return \App\RSpade\Core\Models\Site_Model|null
     */
    private static function __current_site()
    {
        if (Rsx_Portal::is_portal_request()) {
            return Portal_Session::get_site();
        }

        return Session::get_site();
    }

    /**
     * Get timezone abbreviation for a time (e.g., "CST", "CDT")
     * Handles DST correctly based on the actual date
     *
     * @param mixed $time
     * @param string|null $timezone If null, uses user's timezone
     * @return string
     */
    public static function get_timezone_abbr($time, ?string $timezone = null): string
    {
        $tz = $timezone ?? static::get_user_timezone();
        try {
            $carbon = static::to_timezone($time, $tz);
            return $carbon->format('T');
        } catch (\Exception $e) {
            return '';
        }
    }

    // =========================================================================
    // SERIALIZATION (for JSON/API responses)
    // =========================================================================

    /**
     * Serialize time to ISO 8601 UTC string for JSON
     * Format: "2024-12-24T15:30:45.123Z"
     *
     * @param mixed $time
     * @return string|null
     */
    public static function to_iso($time): ?string
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return null;
        }
        return $carbon->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Serialize time to Unix milliseconds for JavaScript
     *
     * @param mixed $time
     * @return int|null
     */
    public static function to_ms($time): ?int
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return null;
        }
        return (int) ($carbon->timestamp * 1000 + (int) ($carbon->micro / 1000));
    }

    // =========================================================================
    // DURATION HANDLING
    // =========================================================================

    /**
     * Calculate duration between two times in seconds
     *
     * @param mixed $start
     * @param mixed $end
     * @return int Seconds (can be negative if end < start)
     */
    public static function diff_seconds($start, $end): int
    {
        $start_carbon = static::parse($start);
        $end_carbon = static::parse($end);

        if (!$start_carbon || !$end_carbon) {
            return 0;
        }

        return $start_carbon->diffInSeconds($end_carbon, false);
    }

    /**
     * Seconds until a future time (negative if past)
     *
     * @param mixed $time
     * @return int
     */
    public static function seconds_until($time): int
    {
        return static::diff_seconds(static::now(), $time);
    }

    /**
     * Seconds since a past time (negative if future)
     *
     * @param mixed $time
     * @return int
     */
    public static function seconds_since($time): int
    {
        return static::diff_seconds($time, static::now());
    }

    /**
     * Format duration as human-readable string
     *
     * @param int $seconds
     * @param bool $short Use short format ("2h 30m") vs long ("2 hours and 30 minutes")
     * @return string
     */
    public static function duration_to_human(int $seconds, bool $short = false): string
    {
        $negative = $seconds < 0;
        $seconds = abs($seconds);

        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];

        if ($short) {
            if ($days > 0) $parts[] = "{$days}d";
            if ($hours > 0) $parts[] = "{$hours}h";
            if ($minutes > 0) $parts[] = "{$minutes}m";
            if ($secs > 0 && empty($parts)) $parts[] = "{$secs}s";
            $result = implode(' ', $parts) ?: '0s';
        } else {
            if ($days > 0) $parts[] = $days . ' ' . ($days === 1 ? 'day' : 'days');
            if ($hours > 0) $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
            if ($minutes > 0) $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
            if ($secs > 0 && empty($parts)) $parts[] = $secs . ' ' . ($secs === 1 ? 'second' : 'seconds');

            if (count($parts) > 1) {
                $last = array_pop($parts);
                $result = implode(', ', $parts) . ' and ' . $last;
            } else {
                $result = $parts[0] ?? '0 seconds';
            }
        }

        return $negative ? '-' . $result : $result;
    }

    /**
     * Format relative time ("2 hours ago", "in 3 days")
     *
     * @param mixed $time
     * @return string
     */
    public static function relative($time): string
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return '';
        }
        return $carbon->diffForHumans();
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    /**
     * Add duration to time
     *
     * @param mixed $time
     * @param int $seconds
     * @return string ISO 8601 UTC string
     */
    public static function add($time, int $seconds): string
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            throw new \InvalidArgumentException("Cannot parse time");
        }
        return $carbon->addSeconds($seconds)->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Subtract duration from time
     *
     * @param mixed $time
     * @param int $seconds
     * @return string ISO 8601 UTC string
     */
    public static function subtract($time, int $seconds): string
    {
        return static::add($time, -$seconds);
    }

    // =========================================================================
    // COMPARISON
    // =========================================================================

    /**
     * Check if time is in the past
     *
     * @param mixed $time
     * @return bool
     */
    public static function is_past($time): bool
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return false;
        }
        return $carbon->isPast();
    }

    /**
     * Check if time is in the future
     *
     * @param mixed $time
     * @return bool
     */
    public static function is_future($time): bool
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return false;
        }
        return $carbon->isFuture();
    }

    /**
     * Check if time is today (in user's timezone)
     *
     * @param mixed $time
     * @return bool
     */
    public static function is_today($time): bool
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return false;
        }
        return static::to_user_timezone($carbon)->isToday();
    }

    // =========================================================================
    // FORMATTING (PHP-side - prefer client-side formatting when possible)
    // =========================================================================

    /**
     * Format time using pattern (internal helper)
     *
     * @param mixed $time
     * @param string $format PHP date() format string
     * @param string|null $timezone If null, uses user's timezone
     * @return string
     */
    private static function _format($time, string $format, ?string $timezone = null): string
    {
        $tz = $timezone ?? static::get_user_timezone();
        try {
            $carbon = static::to_timezone($time, $tz);
            return $carbon->format($format);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Format as date: "Dec 24, 2024"
     *
     * @param mixed $time
     * @param string|null $timezone
     * @return string
     */
    public static function format_date($time, ?string $timezone = null): string
    {
        return static::_format($time, 'M j, Y', $timezone);
    }

    /**
     * Format as time: "3:30 PM"
     *
     * @param mixed $time
     * @param string|null $timezone
     * @return string
     */
    public static function format_time($time, ?string $timezone = null): string
    {
        return static::_format($time, 'g:i A', $timezone);
    }

    /**
     * Format as datetime: "Dec 24, 2024 3:30 PM"
     *
     * @param mixed $time
     * @param string|null $timezone
     * @return string
     */
    public static function format_datetime($time, ?string $timezone = null): string
    {
        return static::_format($time, 'M j, Y g:i A', $timezone);
    }

    /**
     * Format as datetime with timezone: "Dec 24, 2024 3:30 PM CST"
     *
     * @param mixed $time
     * @param string|null $timezone
     * @return string
     */
    public static function format_datetime_with_tz($time, ?string $timezone = null): string
    {
        return static::_format($time, 'M j, Y g:i A T', $timezone);
    }

    // =========================================================================
    // COMPONENT EXTRACTORS
    // =========================================================================

    /**
     * Parse and convert to user's timezone (internal helper)
     * Returns null if invalid, throws on date-only input
     */
    private static function _to_user_carbon($time): ?Carbon
    {
        $carbon = static::parse($time);
        return $carbon ? static::to_user_timezone($carbon) : null;
    }

    /**
     * Get day of month (1-31). Uses user's timezone.
     */
    public static function day($time): ?int
    {
        return static::_to_user_carbon($time)?->day;
    }

    /**
     * Get day of week (0=Sunday, 6=Saturday). Uses user's timezone.
     */
    public static function dow($time): ?int
    {
        return static::_to_user_carbon($time)?->dayOfWeek;
    }

    /**
     * Get full day name ("Monday", "Tuesday", etc.). Uses user's timezone.
     */
    public static function dow_human($time): string
    {
        return static::_to_user_carbon($time)?->format('l') ?? '';
    }

    /**
     * Get short day name ("Mon", "Tue", etc.). Uses user's timezone.
     */
    public static function dow_short($time): string
    {
        return static::_to_user_carbon($time)?->format('D') ?? '';
    }

    /**
     * Get month (1-12). Uses user's timezone.
     */
    public static function month($time): ?int
    {
        return static::_to_user_carbon($time)?->month;
    }

    /**
     * Get full month name ("January", "February", etc.). Uses user's timezone.
     */
    public static function month_human($time): string
    {
        return static::_to_user_carbon($time)?->format('F') ?? '';
    }

    /**
     * Get short month name ("Jan", "Feb", etc.). Uses user's timezone.
     */
    public static function month_human_short($time): string
    {
        return static::_to_user_carbon($time)?->format('M') ?? '';
    }

    /**
     * Get year (e.g., 2025). Uses user's timezone.
     */
    public static function year($time): ?int
    {
        return static::_to_user_carbon($time)?->year;
    }

    /**
     * Get hour (0-23). Uses user's timezone.
     */
    public static function hour($time): ?int
    {
        return static::_to_user_carbon($time)?->hour;
    }

    /**
     * Get minute (0-59). Uses user's timezone.
     */
    public static function minute($time): ?int
    {
        return static::_to_user_carbon($time)?->minute;
    }

    // =========================================================================
    // DATABASE HELPERS
    // =========================================================================

    /**
     * Format time for database storage (UTC)
     * Returns "2024-12-24 15:30:45.123" format
     *
     * @param mixed $time
     * @return string|null
     */
    public static function to_database($time): ?string
    {
        $carbon = static::parse($time);
        if (!$carbon) {
            return null;
        }
        return $carbon->format('Y-m-d H:i:s.v');
    }
}
