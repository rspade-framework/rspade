<?php

namespace App\RSpade\Core\Time;

use Carbon\Carbon;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Rsx_Date - Date-only handling for RSpade (no time, no timezone)
 *
 * Dates are calendar dates without time components. They are timezone-agnostic:
 * "December 24, 2025" is the same calendar date everywhere in the world.
 *
 * Format: Always "YYYY-MM-DD" (ISO 8601 date format)
 *
 * Core Principles:
 * - Dates have NO time component and NO timezone
 * - All dates stored and transferred as "YYYY-MM-DD"
 * - Date functions THROW if passed a datetime (has time component)
 * - Use Rsx_Time for moments in time that need timezone handling
 *
 * See: php artisan rsx:man time
 */
class Rsx_Date
{
    /**
     * Regex pattern for valid date-only string
     */
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    // =========================================================================
    // PARSING & VALIDATION
    // =========================================================================

    /**
     * Parse input to date string "YYYY-MM-DD"
     * THROWS if input is a datetime (has time component)
     *
     * @param mixed $input
     * @return string|null Returns "YYYY-MM-DD" or null
     * @throws \InvalidArgumentException If input is a datetime
     */
    public static function parse($input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        // Already a valid date string
        if (is_string($input) && preg_match(self::DATE_PATTERN, $input)) {
            // Validate it's a real date
            $parts = explode('-', $input);
            if (checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                return $input;
            }
            return null;
        }

        // Reject Carbon/DateTime objects - these are datetimes
        if ($input instanceof \DateTimeInterface) {
            throw new \InvalidArgumentException(
                "Rsx_Date::parse() received DateTime object. " .
                "Use Rsx_Time::parse() for datetimes with time components."
            );
        }

        // Reject timestamps - these are datetimes
        if (is_numeric($input)) {
            throw new \InvalidArgumentException(
                "Rsx_Date::parse() received numeric timestamp '{$input}'. " .
                "Use Rsx_Time::parse() for datetimes with time components."
            );
        }

        // Reject datetime strings (contain T or time component)
        if (is_string($input)) {
            if (str_contains($input, 'T') || preg_match('/\d{2}:\d{2}/', $input)) {
                throw new \InvalidArgumentException(
                    "Rsx_Date::parse() received datetime string '{$input}'. " .
                    "Use Rsx_Time::parse() for datetimes with time components."
                );
            }
        }

        return null;
    }

    /**
     * Parse and convert to Carbon object (internal helper)
     * Returns null if invalid, throws on datetime input
     *
     * @param mixed $date
     * @return Carbon|null
     */
    private static function _to_carbon($date): ?Carbon
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return null;
        }
        return Carbon::createFromFormat('Y-m-d', $parsed);
    }

    /**
     * Check if input is a valid date-only value (not datetime)
     *
     * @param mixed $input
     * @return bool
     */
    public static function is_date($input): bool
    {
        if (!is_string($input)) {
            return false;
        }

        if (!preg_match(self::DATE_PATTERN, $input)) {
            return false;
        }

        // Validate it's a real date
        $parts = explode('-', $input);
        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
    }

    // =========================================================================
    // CURRENT DATE
    // =========================================================================

    /**
     * Alias for today()
     *
     * @return string
     */
    public static function now(): string
    {
        return static::today();
    }

    /**
     * Get today's date as "YYYY-MM-DD"
     * Uses user → site → default timezone resolution
     *
     * @return string
     */
    public static function today(): string
    {
        $tz = Rsx_Time::get_user_timezone();
        return Carbon::now($tz)->format('Y-m-d');
    }

    // =========================================================================
    // FORMATTING
    // =========================================================================

    /**
     * Format date for display: "Dec 24, 2025"
     *
     * @param mixed $date
     * @return string
     */
    public static function format($date): string
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return '';
        }

        $carbon = Carbon::createFromFormat('Y-m-d', $parsed);
        return $carbon->format('M j, Y');
    }

    /**
     * Ensure date is in ISO format "YYYY-MM-DD"
     * Alias for parse() that always returns string
     *
     * @param mixed $date
     * @return string
     */
    public static function format_iso($date): string
    {
        return static::parse($date) ?? '';
    }

    // =========================================================================
    // COMPARISON
    // =========================================================================

    /**
     * Check if date is today (in user's timezone)
     *
     * @param mixed $date
     * @return bool
     */
    public static function is_today($date): bool
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return false;
        }
        return $parsed === static::today();
    }

    /**
     * Check if date is in the past
     *
     * @param mixed $date
     * @return bool
     */
    public static function is_past($date): bool
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return false;
        }
        return $parsed < static::today();
    }

    /**
     * Check if date is in the future
     *
     * @param mixed $date
     * @return bool
     */
    public static function is_future($date): bool
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return false;
        }
        return $parsed > static::today();
    }

    /**
     * Calculate days between two dates
     * Returns positive if date2 > date1
     *
     * @param mixed $date1
     * @param mixed $date2
     * @return int
     */
    public static function diff_days($date1, $date2): int
    {
        $d1 = static::parse($date1);
        $d2 = static::parse($date2);

        if (!$d1 || !$d2) {
            return 0;
        }

        $carbon1 = Carbon::createFromFormat('Y-m-d', $d1)->startOfDay();
        $carbon2 = Carbon::createFromFormat('Y-m-d', $d2)->startOfDay();

        return $carbon1->diffInDays($carbon2, false);
    }

    /**
     * Format as relative date ("Today", "Yesterday", "3 days ago", "in 5 days")
     *
     * @param mixed $date
     * @return string
     */
    public static function relative($date): string
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return '';
        }

        $days = static::diff_days(static::today(), $parsed);

        if ($days === 0) {
            return 'Today';
        } elseif ($days === 1) {
            return 'Tomorrow';
        } elseif ($days === -1) {
            return 'Yesterday';
        } elseif ($days > 1) {
            return "in {$days} days";
        } else {
            return abs($days) . ' days ago';
        }
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    /**
     * Add days to a date
     *
     * @param mixed $date
     * @param int $days Can be negative to subtract
     * @return string|null "YYYY-MM-DD" or null if invalid input
     */
    public static function add_days($date, int $days): ?string
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return null;
        }

        $carbon = Carbon::createFromFormat('Y-m-d', $parsed)->addDays($days);
        return $carbon->format('Y-m-d');
    }

    // =========================================================================
    // WEEK/MONTH BOUNDARIES
    // =========================================================================

    /**
     * Get the Monday of the week containing the date
     *
     * @param mixed $date
     * @return string|null "YYYY-MM-DD" or null if invalid input
     */
    public static function start_of_week($date): ?string
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return null;
        }

        $carbon = Carbon::createFromFormat('Y-m-d', $parsed)->startOfWeek(Carbon::MONDAY);
        return $carbon->format('Y-m-d');
    }

    /**
     * Get the Sunday of the week containing the date
     *
     * @param mixed $date
     * @return string|null "YYYY-MM-DD" or null if invalid input
     */
    public static function end_of_week($date): ?string
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return null;
        }

        $carbon = Carbon::createFromFormat('Y-m-d', $parsed)->endOfWeek(Carbon::SUNDAY);
        return $carbon->format('Y-m-d');
    }

    /**
     * Get the first day of the month containing the date
     *
     * @param mixed $date
     * @return string|null "YYYY-MM-DD" or null if invalid input
     */
    public static function start_of_month($date): ?string
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return null;
        }

        $carbon = Carbon::createFromFormat('Y-m-d', $parsed)->startOfMonth();
        return $carbon->format('Y-m-d');
    }

    /**
     * Get the last day of the month containing the date
     *
     * @param mixed $date
     * @return string|null "YYYY-MM-DD" or null if invalid input
     */
    public static function end_of_month($date): ?string
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return null;
        }

        $carbon = Carbon::createFromFormat('Y-m-d', $parsed)->endOfMonth();
        return $carbon->format('Y-m-d');
    }

    /**
     * Check if date falls on a weekend (Saturday or Sunday)
     *
     * @param mixed $date
     * @return bool
     */
    public static function is_weekend($date): bool
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return false;
        }

        $carbon = Carbon::createFromFormat('Y-m-d', $parsed);
        return $carbon->isWeekend();
    }

    /**
     * Check if date falls on a weekday (Monday-Friday)
     *
     * @param mixed $date
     * @return bool
     */
    public static function is_weekday($date): bool
    {
        $parsed = static::parse($date);
        if (!$parsed) {
            return false;
        }

        $carbon = Carbon::createFromFormat('Y-m-d', $parsed);
        return $carbon->isWeekday();
    }

    // =========================================================================
    // COMPONENT EXTRACTORS
    // =========================================================================

    /**
     * Get day of month (1-31)
     */
    public static function day($date): ?int
    {
        $parsed = static::parse($date);
        return $parsed ? (int) explode('-', $parsed)[2] : null;
    }

    /**
     * Get day of week (0=Sunday, 6=Saturday)
     */
    public static function dow($date): ?int
    {
        return static::_to_carbon($date)?->dayOfWeek;
    }

    /**
     * Get full day name ("Monday", "Tuesday", etc.)
     */
    public static function dow_human($date): string
    {
        return static::_to_carbon($date)?->format('l') ?? '';
    }

    /**
     * Get short day name ("Mon", "Tue", etc.)
     */
    public static function dow_short($date): string
    {
        return static::_to_carbon($date)?->format('D') ?? '';
    }

    /**
     * Get month (1-12)
     */
    public static function month($date): ?int
    {
        $parsed = static::parse($date);
        return $parsed ? (int) explode('-', $parsed)[1] : null;
    }

    /**
     * Get full month name ("January", "February", etc.)
     */
    public static function month_human($date): string
    {
        return static::_to_carbon($date)?->format('F') ?? '';
    }

    /**
     * Get short month name ("Jan", "Feb", etc.)
     */
    public static function month_human_short($date): string
    {
        return static::_to_carbon($date)?->format('M') ?? '';
    }

    /**
     * Get year (e.g., 2025)
     */
    public static function year($date): ?int
    {
        $parsed = static::parse($date);
        return $parsed ? (int) explode('-', $parsed)[0] : null;
    }

    // =========================================================================
    // DATABASE
    // =========================================================================

    /**
     * Format for database storage
     * Same as ISO format: "YYYY-MM-DD"
     *
     * @param mixed $date
     * @return string|null
     */
    public static function to_database($date): ?string
    {
        return static::parse($date);
    }
}
