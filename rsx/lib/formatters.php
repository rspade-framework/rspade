<?php

namespace Rsx\Lib;

use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;
use Brick\Money\Money;
use Brick\Money\Context\CustomContext;
use Brick\Math\RoundingMode;
use Brick\Math\Exception\MathException;

/**
 * String formatting utilities
 *
 * Provides consistent formatting for common data types across the application.
 * These formatters ensure data is stored and displayed with consistent formatting.
 *
 * Usage:
 *   $formatted_phone = Formatters::phone($input);
 *   $formatted_currency = Formatters::currency($amount);
 */
class Formatters
{
    /**
     * Format a phone number
     *
     * Behavior matches Phone_Text_Input component:
     * - International mode (starts with '+'): Parsed with libphonenumber. A number
     *   whose country code is $region's own is rendered in that region's national
     *   style (so a stored E.164 value round-trips to what the user typed); any
     *   other possible number is rendered in INTERNATIONAL format; anything else is
     *   returned unchanged (never throws for a display formatter).
     * - Default US mode ($region === 'US'): Progressive formatting as
     *   (XXX) XXX-XXXX. Strips a leading "1" country code, limits to 10 digits,
     *   and formats partial input as-you-type. Deliberately NOT routed through
     *   libphonenumber so placeholder/invalid-plan numbers and partials survive.
     * - Explicit non-US $region: Parsed with libphonenumber and rendered in that
     *   region's NATIONAL format when possible; otherwise returned unchanged.
     *
     * Uses isPossibleNumber() (length/pattern-based), NOT isValidNumber(), so
     * test/placeholder numbers are accepted.
     *
     * @param string|null $input Phone number in any format
     * @param string $region ISO region for non-'+' input (default 'US')
     * @return string Formatted phone number
     *
     * @example
     * Formatters::phone('5551234567')        // "(555) 123-4567"
     * Formatters::phone('15551234567')       // "(555) 123-4567"
     * Formatters::phone('(555) 123-4567')    // "(555) 123-4567"
     * Formatters::phone('+442071234567')     // "+44 20 7123 4567"
     * Formatters::phone('+44 20 7123 4567')  // "+44 20 7123 4567"
     * Formatters::phone('+19206145140')      // "(920) 614-5140"  (US is the home region)
     * Formatters::phone('2071234567', 'GB')  // "020 7123 4567"
     */
    public static function phone(?string $input, string $region = 'US'): string
    {
        // Handle null or empty input. Note: strict null/'' check (not empty()) so
        // the string '0' proceeds to formatting, matching Phone_Text_Input.
        if ($input === null || $input === '') {
            return '';
        }

        // International mode - input carries an explicit country code ('+').
        if (str_starts_with(trim($input), '+')) {
            try {
                // Region is irrelevant when the number carries '+'.
                $proto = PhoneNumberUtil::getInstance()->parse($input, null);
                if (PhoneNumberUtil::getInstance()->isPossibleNumber($proto)) {
                    // A number belonging to $region's own country code is shown the way
                    // that region writes it, WITHOUT the country code. E.164 is a storage
                    // format - Frontend_Contacts_Controller::save() stores
                    // "+19206145140" - and the number a user typed as (920) 614-5140
                    // has to come back to them as (920) 614-5140.
                    $home_country_code = PhoneNumberUtil::getInstance()->getCountryCodeForRegion($region);

                    if ($proto->getCountryCode() === $home_country_code) {
                        return self::phone((string)$proto->getNationalNumber(), $region);
                    }

                    return PhoneNumberUtil::getInstance()->format($proto, PhoneNumberFormat::INTERNATIONAL);
                }
            } catch (NumberParseException $e) {
                // Expected for arbitrary display input - fall through to passthrough.
            }

            // Unparseable or not-possible - return unchanged.
            return $input;
        }

        // Non-US region - defer to libphonenumber's NATIONAL format.
        if ($region !== 'US') {
            try {
                $proto = PhoneNumberUtil::getInstance()->parse($input, $region);
                if (PhoneNumberUtil::getInstance()->isPossibleNumber($proto)) {
                    return PhoneNumberUtil::getInstance()->format($proto, PhoneNumberFormat::NATIONAL);
                }
            } catch (NumberParseException $e) {
                // Expected for arbitrary display input - fall through to passthrough.
            }

            return $input;
        }

        // US mode - extract digits only
        $digits = preg_replace('/[^0-9]/', '', $input);

        // Determine which digits to format
        $digits_to_format = '';

        if (strlen($digits) === 11 && $digits[0] === '1' && preg_match('/[2-9]/', $digits[1])) {
            // Exactly 11 digits starting with "1" followed by valid area code digit (2-9)
            // This is a US country code - strip the leading 1
            $digits_to_format = substr($digits, 1);
        } elseif (strlen($digits) > 10) {
            // More than 10 digits - take the first 10
            $digits_to_format = substr($digits, 0, 10);
        } else {
            // 10 or fewer digits - use as-is
            $digits_to_format = $digits;
        }

        // Format based on length
        return self::_format_us_phone($digits_to_format);
    }

    /**
     * Format US phone number as (XXX) XXX-XXXX
     *
     * @param string $digits Clean numeric string (10 digits or less)
     * @return string Formatted phone number
     * @private
     */
    private static function _format_us_phone(string $digits): string
    {
        $length = strlen($digits);

        if ($length >= 10) {
            // (XXX) XXX-XXXX
            return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
        } elseif ($length >= 6) {
            // (XXX) XXX-X...
            return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        } elseif ($length >= 3) {
            // (XXX) X...
            return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3);
        } elseif ($length > 0) {
            // (X...
            return '(' . $digits;
        }

        return $digits;
    }

    /**
     * Format a currency amount
     *
     * Behavior matches Currency_Input component:
     * - Adds thousands separators (commas)
     * - Optional currency symbol (default: hidden)
     * - Optional decimal places (default: hidden)
     *
     * The rounding is done by brick/money rather than by number_format(), so the
     * amount is carried as an exact decimal from end to end: a value that does not
     * survive a float (a bare integer past 2^53, a string amount with more digits
     * than a double holds) formats to the digits it was given instead of to the
     * nearest representable double. The rounding mode is stated rather than
     * inherited - HalfUp, i.e. half away from zero, which is what number_format
     * did and what an invoice line expects.
     *
     * Presentation stays this application's decision: $symbol is prefixed verbatim
     * and the group separator is a comma. Money::formatTo($locale) is the other
     * option and renders a locale's own placement and separators - use it when the
     * app is genuinely multi-locale, not to reproduce this format.
     *
     * @param float|string|null $amount Amount to format
     * @param bool $show_symbol Show currency symbol (default: false)
     * @param bool $allow_decimals Show decimal places (default: false)
     * @param string $symbol Currency symbol to use (default: '$')
     * @param string $currency ISO 4217 code the amount is denominated in (default: 'USD')
     * @return string Formatted currency string
     *
     * @example
     * Formatters::currency(1234567)                                    // "1,234,567"
     * Formatters::currency(1234567, show_symbol: true)                 // "$1,234,567"
     * Formatters::currency(1234567, allow_decimals: true)              // "1,234,567.00"
     * Formatters::currency(1234567.89, show_symbol: true, allow_decimals: true)  // "$1,234,567.89"
     * Formatters::currency(1234567, show_symbol: true, symbol: '€')   // "€1,234,567"
     */
    public static function currency(
        $amount,
        bool $show_symbol = false,
        bool $allow_decimals = false,
        string $symbol = '$',
        string $currency = 'USD'
    ): string {
        if ($amount === null || $amount === '') {
            return '';
        }

        // Decimal places asked for. CustomContext carries it into the Money, so the
        // rounding happens once, inside the money type, at the scale being displayed.
        $decimals = $allow_decimals ? 2 : 0;

        // A non-numeric amount is not an error here - this is a display formatter, and
        // it renders a zero exactly as it always has.
        $numeric_amount = is_numeric($amount) ? (string)$amount : '0';

        try {
            $money = Money::of($numeric_amount, $currency, new CustomContext($decimals), RoundingMode::HalfUp);
        } catch (MathException $e) {
            // Reachable only for input is_numeric() accepted and BigDecimal will not
            // (hexadecimal-ish and INF/NAN spellings). Same zero as above.
            $money = Money::of('0', $currency, new CustomContext($decimals), RoundingMode::HalfUp);
        }

        $formatted = self::_group_decimal_string((string)$money->getAmount());

        // Add currency symbol if enabled
        if ($show_symbol) {
            $formatted = $symbol . $formatted;
        }

        return $formatted;
    }

    /**
     * Insert thousands separators into a plain decimal string.
     *
     * Takes "-1234567.89" to "-1,234,567.89". String in, string out - the digits are
     * never routed through a float, which is the whole point of formatting the money
     * amount rather than a cast of it.
     *
     * @param string $decimal Decimal string as produced by BigDecimal::__toString()
     * @return string
     * @private
     */
    private static function _group_decimal_string(string $decimal): string
    {
        $sign = '';

        if (str_starts_with($decimal, '-')) {
            $sign = '-';
            $decimal = substr($decimal, 1);
        }

        $fraction = '';
        $dot = strpos($decimal, '.');

        if ($dot !== false) {
            $fraction = substr($decimal, $dot);
            $decimal = substr($decimal, 0, $dot);
        }

        return $sign . strrev(implode(',', str_split(strrev($decimal), 3))) . $fraction;
    }

    /**
     * Format a percentage
     *
     * @param float|string|null $value Value to format (0-100 or 0-1 depending on $input_as_decimal)
     * @param int $decimals Number of decimal places (default: 2)
     * @param bool $input_as_decimal If true, treats 0.5 as 50% (default: false)
     * @return string Formatted percentage string
     *
     * @example
     * Formatters::percentage(75.5)           // "75.50%"
     * Formatters::percentage(0.755, 2, true) // "75.50%"
     */
    public static function percentage($value, int $decimals = 2, bool $input_as_decimal = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $numeric_value = is_numeric($value) ? (float)$value : 0.0;

        if ($input_as_decimal) {
            $numeric_value *= 100;
        }

        return number_format($numeric_value, $decimals, '.', ',') . '%';
    }

    /**
     * Format a file size in human-readable format
     *
     * @param int|null $bytes File size in bytes
     * @param int $decimals Number of decimal places (default: 2)
     * @return string Formatted file size (e.g., "1.50 MB")
     *
     * @example
     * Formatters::file_size(1536)      // "1.50 KB"
     * Formatters::file_size(1048576)   // "1.00 MB"
     */
    public static function file_size(?int $bytes, int $decimals = 2): string
    {
        if ($bytes === null || $bytes < 0) {
            return '';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $unit_index = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unit_index < count($units) - 1) {
            $size /= 1024;
            $unit_index++;
        }

        return number_format($size, $decimals, '.', ',') . ' ' . $units[$unit_index];
    }

    /**
     * Format a date in consistent format
     *
     * @param string|\DateTime|null $date Date to format
     * @param string $format Output format (default: 'M d, Y')
     * @return string Formatted date string
     *
     * @example
     * Formatters::date('2024-01-15')              // "Jan 15, 2024"
     * Formatters::date('2024-01-15', 'Y-m-d')     // "2024-01-15"
     */
    public static function date($date, string $format = 'M d, Y'): string
    {
        if (empty($date)) {
            return '';
        }

        if ($date instanceof \DateTime) {
            return $date->format($format);
        }

        try {
            $datetime = new \DateTime($date);
            return $datetime->format($format);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Format a datetime in consistent format
     *
     * @param string|\DateTime|null $datetime DateTime to format
     * @param string $format Output format (default: 'M d, Y g:i A')
     * @return string Formatted datetime string
     *
     * @example
     * Formatters::datetime('2024-01-15 14:30:00')  // "Jan 15, 2024 2:30 PM"
     */
    public static function datetime($datetime, string $format = 'M d, Y g:i A'): string
    {
        return self::date($datetime, $format);
    }
}
