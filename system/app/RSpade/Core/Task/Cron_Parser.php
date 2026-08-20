<?php

namespace App\RSpade\Core\Task;

use DateTime;
use DateTimeZone;

/**
 * Cron_Parser
 *
 * Parses cron expressions and calculates next run times.
 * Supports standard cron syntax: minute hour day month weekday
 *
 * Examples:
 *   Every minute: "* * * * *"
 *   Every hour at minute 0: "0 * * * *"
 *   Daily at midnight: "0 0 * * *"
 *   Daily at 3 AM: "0 3 * * *"
 *   Weekly on Sunday at midnight: "0 0 * * 0"
 *   Monthly on the 1st at midnight: "0 0 1 * *"
 *
 * Human-readable syntax is also accepted (normalized to cron internally). This
 * exists so #[Schedule] never has to contain the cron "step" token (an asterisk,
 * a slash, then N) -- that asterisk-slash pair is also the terminator of a
 * C-style block comment, so writing it in any docblock closes the comment early
 * and silently corrupts the file. Prefer the phrases below in code and comments:
 *   "every minute"                -> "* * * * *"
 *   "every 5 minutes"             -> "(every-5) * * * *"
 *   "every 6 hours"               -> "0 (every-6) * * *"
 *   "hourly"                      -> "0 * * * *"
 *   "daily" / "daily at 2am"      -> "0 0 * * *" / "0 2 * * *"
 *   "daily at 14:30"              -> "30 14 * * *"
 *   "weekly" / "weekly on monday at 9:30am" -> "0 0 * * 0" / "30 9 * * 1"
 *   "monthly"                     -> "0 0 1 * *"
 */
#[Instantiatable]
class Cron_Parser
{
    private array $parts;
    private string $expression;

    /**
     * Parse a cron expression
     *
     * @param string $expression Cron expression (minute hour day month weekday)
     * @throws \InvalidArgumentException If expression is invalid
     */
    public function __construct(string $expression)
    {
        $this->expression = $expression;
        $this->parts = $this->parse(self::_normalize_expression($expression));
    }

    /**
     * Translate a human-readable schedule phrase into a standard cron expression.
     *
     * Standard cron passes through untouched. Recognized phrases are converted to
     * canonical cron so the rest of the parser has a single code path. Anything
     * unrecognized throws with the list of supported forms.
     *
     * @param string $expression Raw schedule expression (cron or phrase)
     * @return string Standard 5-field cron expression
     * @throws \InvalidArgumentException If a phrase is unrecognized or out of range
     */
    private static function _normalize_expression(string $expression): string
    {
        $raw = trim($expression);

        // Already standard cron? (only cron characters — digits, * / , - and spaces)
        if (preg_match('/^[\d*\/,\-\s]+$/', $raw)) {
            return $raw;
        }

        $text = strtolower($raw);

        // Named shortcuts
        $shortcuts = [
            'every minute' => '* * * * *',
            'hourly'       => '0 * * * *',
            'every hour'   => '0 * * * *',
            'daily'        => '0 0 * * *',
            'every day'    => '0 0 * * *',
            'nightly'      => '0 0 * * *',
            'weekly'       => '0 0 * * 0',
            'monthly'      => '0 0 1 * *',
            'yearly'       => '0 0 1 1 *',
            'annually'     => '0 0 1 1 *',
        ];
        if (isset($shortcuts[$text])) {
            return $shortcuts[$text];
        }

        // "every N minutes" / "every N hours"
        if (preg_match('/^every\s+(\d+)\s+(minutes?|mins?|hours?|hrs?)$/', $text, $m)) {
            $n = (int) $m[1];

            if (str_starts_with($m[2], 'min')) {
                if ($n < 1 || $n > 59) {
                    throw new \InvalidArgumentException("Invalid schedule '{$expression}': minute interval must be 1-59");
                }
                return "*/{$n} * * * *";
            }

            // hours
            if ($n < 1 || $n > 23) {
                throw new \InvalidArgumentException("Invalid schedule '{$expression}': hour interval must be 1-23");
            }
            return "0 */{$n} * * *";
        }

        // "daily at <time>" / "daily <time>" / "every day at <time>"
        if (preg_match('/^(?:daily|every day)\s+(?:at\s+)?(.+)$/', $text, $m)) {
            [$hour, $minute] = self::_parse_time($m[1], $expression);
            return "{$minute} {$hour} * * *";
        }

        // "weekly on <day> [at <time>]" / "every <day> [at <time>]"
        if (preg_match('/^(?:weekly\s+on|every)\s+(sunday|monday|tuesday|wednesday|thursday|friday|saturday)(?:\s+(?:at\s+)?(.+))?$/', $text, $m)) {
            $weekday = self::_parse_weekday($m[1]);
            [$hour, $minute] = empty($m[2]) ? [0, 0] : self::_parse_time($m[2], $expression);
            return "{$minute} {$hour} * * {$weekday}";
        }

        throw new \InvalidArgumentException(
            "Unrecognized schedule expression: '{$expression}'. Use standard cron " .
            "(e.g. '0 2 * * *') or a phrase like 'every 5 minutes', 'every 6 hours', " .
            "'hourly', 'daily at 2am', 'weekly on monday at 9:30am', 'monthly'."
        );
    }

    /**
     * Parse a clock-time phrase into [hour, minute].
     *
     * Accepts "2am", "2pm", "2:30pm", "14:30", "09:00", "9", "noon", "midnight".
     *
     * @param string $time Time phrase
     * @param string $expression Original schedule (for error messages)
     * @return array{0:int,1:int} [hour (0-23), minute (0-59)]
     * @throws \InvalidArgumentException If the time cannot be parsed
     */
    private static function _parse_time(string $time, string $expression): array
    {
        $time = trim($time);

        if ($time === 'noon') {
            return [12, 0];
        }
        if ($time === 'midnight') {
            return [0, 0];
        }

        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/', $time, $m)) {
            $hour = (int) $m[1];
            $minute = ($m[2] ?? '') !== '' ? (int) $m[2] : 0;
            $meridiem = $m[3] ?? '';

            if ($meridiem !== '') {
                if ($hour < 1 || $hour > 12) {
                    throw new \InvalidArgumentException("Invalid time in schedule '{$expression}': '{$time}'");
                }
                if ($meridiem === 'am') {
                    $hour = ($hour === 12) ? 0 : $hour;
                } else {
                    $hour = ($hour === 12) ? 12 : $hour + 12;
                }
            }

            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                throw new \InvalidArgumentException("Invalid time in schedule '{$expression}': '{$time}'");
            }

            return [$hour, $minute];
        }

        throw new \InvalidArgumentException("Could not parse time in schedule '{$expression}': '{$time}'");
    }

    /**
     * Map a weekday name to its cron number (0 = Sunday .. 6 = Saturday).
     *
     * @param string $day Lowercase weekday name (guaranteed valid by caller regex)
     * @return int
     */
    private static function _parse_weekday(string $day): int
    {
        $map = [
            'sunday'    => 0,
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
        ];

        return $map[$day];
    }

    /**
     * Parse cron expression into components
     *
     * @param string $expression
     * @return array ['minute' => [...], 'hour' => [...], 'day' => [...], 'month' => [...], 'weekday' => [...]]
     * @throws \InvalidArgumentException
     */
    private function parse(string $expression): array
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Invalid cron expression: {$expression}. Expected 5 parts (minute hour day month weekday)");
        }

        return [
            'minute'  => $this->parse_field($parts[0], 0, 59),
            'hour'    => $this->parse_field($parts[1], 0, 23),
            'day'     => $this->parse_field($parts[2], 1, 31),
            'month'   => $this->parse_field($parts[3], 1, 12),
            'weekday' => $this->parse_field($parts[4], 0, 6),  // 0 = Sunday
        ];
    }

    /**
     * Parse a single cron field
     *
     * Supports: asterisk, numbers, step values, ranges, lists
     *
     * @param string $field Field value from cron expression
     * @param int $min Minimum allowed value
     * @param int $max Maximum allowed value
     * @return array Array of allowed values
     */
    private function parse_field(string $field, int $min, int $max): array
    {
        // Asterisk means all values
        if ($field === '*') {
            return range($min, $max);
        }

        // Step values (e.g., */15)
        if (preg_match('/^\*\/(\d+)$/', $field, $matches)) {
            $step = (int) $matches[1];
            return range($min, $max, $step);
        }

        // Range (e.g., 1-5)
        if (preg_match('/^(\d+)-(\d+)$/', $field, $matches)) {
            $start = (int) $matches[1];
            $end = (int) $matches[2];

            if ($start < $min || $end > $max || $start > $end) {
                throw new \InvalidArgumentException("Invalid range in cron expression: {$field}");
            }

            return range($start, $end);
        }

        // List (e.g., 1,3,5)
        if (str_contains($field, ',')) {
            $values = array_map('intval', explode(',', $field));

            foreach ($values as $value) {
                if ($value < $min || $value > $max) {
                    throw new \InvalidArgumentException("Invalid value in cron expression: {$value}");
                }
            }

            return $values;
        }

        // Single value
        $value = (int) $field;

        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("Invalid value in cron expression: {$value}");
        }

        return [$value];
    }

    /**
     * Calculate next run time from a given timestamp
     *
     * @param int|null $from_timestamp Start time (default: current time)
     * @return int Next run timestamp
     */
    public function get_next_run_time(?int $from_timestamp = null): int
    {
        if ($from_timestamp === null) {
            $from_timestamp = time();
        }

        // Start from the next minute (cron runs at most once per minute)
        $current = new DateTime('@' . $from_timestamp);
        $current->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $current->modify('+1 minute');
        $current->setTime((int) $current->format('H'), (int) $current->format('i'), 0);

        // Try up to 4 years in the future (should be more than enough)
        $max_iterations = 60 * 24 * 365 * 4;
        $iterations = 0;

        while ($iterations < $max_iterations) {
            if ($this->matches($current)) {
                return $current->getTimestamp();
            }

            $current->modify('+1 minute');
            $iterations++;
        }

        throw new \RuntimeException("Could not calculate next run time for cron expression: {$this->expression}");
    }

    /**
     * Check if a datetime matches the cron expression
     *
     * @param DateTime $datetime
     * @return bool
     */
    private function matches(DateTime $datetime): bool
    {
        $minute = (int) $datetime->format('i');
        $hour = (int) $datetime->format('H');
        $day = (int) $datetime->format('d');
        $month = (int) $datetime->format('n');
        $weekday = (int) $datetime->format('w');

        return in_array($minute, $this->parts['minute'])
            && in_array($hour, $this->parts['hour'])
            && in_array($day, $this->parts['day'])
            && in_array($month, $this->parts['month'])
            && in_array($weekday, $this->parts['weekday']);
    }

    /**
     * Validate a cron expression without creating an instance
     *
     * @param string $expression
     * @return bool
     */
    public static function is_valid(string $expression): bool
    {
        try {
            new self($expression);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Get the original cron expression
     *
     * @return string
     */
    public function get_expression(): string
    {
        return $this->expression;
    }
}
