<?php

namespace App\RSpade\Core\Time;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use App\RSpade\Core\Time\Rsx_Date;

/**
 * Custom Eloquent cast for DATE columns
 *
 * Returns dates as "YYYY-MM-DD" strings, NOT Carbon objects.
 * This prevents timezone bugs - a date is just a calendar day, not a moment in time.
 *
 * Database: 2025-12-24 → PHP: "2025-12-24" → JSON: "2025-12-24"
 */
#[Instantiatable]
class Rsx_Date_Cast implements CastsAttributes
{
    /**
     * Cast the value when reading from database
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

        // Already a string in YYYY-MM-DD format from MySQL
        if (is_string($value)) {
            // Validate it's a proper date format
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return $value;
            }

            // MySQL might return datetime format for some reason - extract date part
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
                return $matches[1];
            }
        }

        // Unexpected type - fail loud
        throw new \InvalidArgumentException(
            "Rsx_Date_Cast: Expected date string, got " . gettype($value) . ": " . var_export($value, true)
        );
    }

    /**
     * Cast the value when writing to database
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

        // Accept YYYY-MM-DD strings
        if (is_string($value)) {
            // Validate format
            $parsed = Rsx_Date::parse($value);
            if ($parsed === null) {
                throw new \InvalidArgumentException(
                    "Rsx_Date_Cast: Invalid date format for '$key': '$value'. Expected YYYY-MM-DD."
                );
            }
            return $parsed;
        }

        // Reject Carbon and other types - fail loud
        throw new \InvalidArgumentException(
            "Rsx_Date_Cast: Expected date string (YYYY-MM-DD), got " . gettype($value) .
            ". Use Rsx_Date::today() or string literals, not Carbon objects."
        );
    }
}
