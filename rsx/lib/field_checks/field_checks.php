<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace Rsx\Lib\FieldChecks;

/**
 * Field_Checks - the server-side value checks every save endpoint in this application shares.
 *
 * Validation lives on the server, once. Each endpoint still decides WHICH fields it checks
 * and what a missing required field means; this class only answers the questions that would
 * otherwise be hand-written identically in every controller and every API endpoint:
 *
 *   - length_errors(): does each submitted value fit its column? The limit is the model's
 *     own Model::field_length() - the same number the form's $max_length reads - so a rule
 *     never restates a schema literal. Under STRICT_TRANS_TABLES an over-long value is a
 *     database exception; here it is a field error naming the limit.
 *   - is_enum_value(): is a submitted id one of the column's declared enum values?
 *   - is_web_url(): is a value an absolute http(s) address with a real host? Anything else
 *     (javascript:, data:, ftp:, a bare word) is refused before it can reach an <a href>.
 *   - website_short_url(): the client "website" spelling - scheme stripped for storage, and
 *     refused unless it is an http(s) address.
 *
 * A date is checked with Rsx_Date::is_date() directly, and an amount of money with
 * Model::money_from_submitted('column', $value) - the framework already answers both.
 *
 * Every method answers; none of them writes, and none of them throws for bad INPUT. A
 * column that is not a character column passed to length_errors() is a programming error
 * and fails loud.
 */
class Field_Checks
{
    /**
     * Length errors for the submitted values of a model's character columns.
     *
     * Only the columns named in $labels are checked, and only when $values carries them; a
     * null value is "no value" and fits. A value that is not text at all (an array or object
     * posted where a string belongs) is an error of its own rather than a database exception.
     *
     * @param string $model_class The model whose columns define the limits
     * @param array $values Submitted values keyed by column name (the value to be STORED)
     * @param array $labels column => human label, used in the message
     * @return array column => message, empty when every value fits
     */
    public static function length_errors(string $model_class, array $values, array $labels): array
    {
        $errors = [];

        foreach ($labels as $column => $label) {
            if (!array_key_exists($column, $values) || $values[$column] === null) {
                continue;
            }

            $value = $values[$column];

            if (!is_scalar($value)) {
                $errors[$column] = "{$label} must be text";
                continue;
            }

            $max_length = $model_class::field_length($column);

            if ($max_length === null) {
                shouldnt_happen("Field_Checks::length_errors(): {$model_class}.{$column} is not a character column");
            }

            if (mb_strlen((string) $value) > $max_length) {
                $errors[$column] = "{$label} must be {$max_length} characters or fewer";
            }
        }

        return $errors;
    }

    /**
     * Whether a submitted value is one of a column's declared enum ids.
     *
     * Accepts an integer or an all-digits string (an Ajax form posts strings); anything else
     * - a label, a negative number, an array - is not.
     *
     * @param string $model_class The model declaring the enum in $columns
     * @param string $column The enum column
     * @param mixed $value Submitted value
     * @return bool
     */
    public static function is_enum_value(string $model_class, string $column, $value): bool
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
        } else {
            return false;
        }

        $ids_method = "{$column}__enum_ids";
        $ids = $model_class::$ids_method();

        if ($ids === null) {
            shouldnt_happen("Field_Checks::is_enum_value(): {$model_class}.{$column} declares no enum");
        }

        return in_array($id, array_map('intval', $ids), true);
    }

    /**
     * Whether a value is an absolute http:// or https:// address with a dotted host name.
     *
     * The scheme allow-list is the point: a stored address is printed into an href, and
     * javascript:, data: and every other scheme are refused here rather than trusted to the
     * page's CSP.
     *
     * @param string $url
     * @return bool
     */
    public static function is_web_url(string $url): bool
    {
        if (preg_match('/[\s\x00-\x1f\x7f]/u', $url)) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = $parts['host'] ?? '';

        return preg_match('/^[\p{L}\p{N}-]+(\.[\p{L}\p{N}-]+)+$/u', $host) === 1;
    }

    /**
     * A client website in its stored short form ("example.com/about"), or null when the
     * value is not an http(s) address.
     *
     * The http:// or https:// prefix is stripped for storage (full_url_to_short_url()); what
     * remains must read as an http address once the prefix is put back, so another scheme
     * ("ftp://", "javascript:") never survives as a "short" URL.
     *
     * @param string $value Submitted, non-blank website
     * @return string|null
     */
    public static function website_short_url(string $value): ?string
    {
        $short = full_url_to_short_url(trim($value));

        if (!validate_short_url($short) || str_contains($short, '://')) {
            return null;
        }

        return static::is_web_url('http://' . $short) ? $short : null;
    }
}
