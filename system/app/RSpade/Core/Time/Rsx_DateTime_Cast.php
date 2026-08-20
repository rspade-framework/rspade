<?php

namespace App\RSpade\Core\Time;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Custom Eloquent cast for DATETIME/TIMESTAMP columns
 *
 * Converts between:
 * - Database: MySQL format "YYYY-MM-DD HH:MM:SS" (stored as UTC)
 * - PHP/JSON: ISO 8601 format "YYYY-MM-DDTHH:MM:SS.sssZ" (UTC)
 *
 * Returns strings, NOT Carbon objects. This keeps PHP/JS in sync
 * with identical string formats on both sides.
 */
#[Instantiatable]
class Rsx_DateTime_Cast implements CastsAttributes
{
    /**
     * Cast the value when reading from database
     *
     * Converts MySQL format to ISO 8601 UTC string
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array $attributes
     * @return string|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Already ISO format (from cached value or manual set)
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
            // Normalize to consistent format with milliseconds and Z
            return Rsx_Time::to_iso(Rsx_Time::parse($value));
        }

        // MySQL format "YYYY-MM-DD HH:MM:SS" - convert to ISO
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value)) {
            // Database stores in UTC, parse as UTC
            // Handle fractional seconds: truncate to 3 digits for .v format (milliseconds)
            // This handles microseconds (6 digits) from Laravel/Carbon after save()
            if (strlen($value) > 19) {
                $value = preg_replace('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.(\d{3})\d*/', '$1.$2', $value);
                $format = 'Y-m-d H:i:s.v';
            } else {
                $format = 'Y-m-d H:i:s';
            }
            $carbon = Carbon::createFromFormat($format, $value, 'UTC');
            return $carbon->format('Y-m-d\TH:i:s.v\Z');
        }

        // Carbon object (shouldn't happen but handle gracefully during transition)
        if ($value instanceof Carbon) {
            return $value->setTimezone('UTC')->format('Y-m-d\TH:i:s.v\Z');
        }

        // Unexpected type - fail loud
        throw new \InvalidArgumentException(
            "Rsx_DateTime_Cast: Expected datetime string, got " . gettype($value) . ": " . var_export($value, true)
        );
    }

    /**
     * Cast the value when writing to database
     *
     * Converts ISO 8601 format to MySQL format for storage
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array $attributes
     * @return string|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // ISO 8601 format - convert to MySQL format
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
            $carbon = Carbon::parse($value)->setTimezone('UTC');
            return $carbon->format('Y-m-d H:i:s');
        }

        // MySQL format already - validate and pass through
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value)) {
            return $value;
        }

        // Unix timestamp (seconds or milliseconds)
        if (is_int($value)) {
            // Detect milliseconds vs seconds
            if ($value > 10000000000) {
                $carbon = Carbon::createFromTimestampMs($value, 'UTC');
            } else {
                $carbon = Carbon::createFromTimestamp($value, 'UTC');
            }
            return $carbon->format('Y-m-d H:i:s');
        }

        // Carbon object (shouldn't happen but handle gracefully during transition)
        if ($value instanceof Carbon) {
            return $value->setTimezone('UTC')->format('Y-m-d H:i:s');
        }

        // Reject other types - fail loud
        throw new \InvalidArgumentException(
            "Rsx_DateTime_Cast: Invalid datetime format for '$key': " . var_export($value, true) .
            ". Expected ISO 8601 string (YYYY-MM-DDTHH:MM:SS.sssZ) or Unix timestamp."
        );
    }
}
