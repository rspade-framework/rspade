<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Lib\Formatters;

/**
 * Formatters_Test - Covers Formatters::phone() including the libphonenumber-backed
 * international ('+') and explicit non-US region paths, the home-region E.164 round trip,
 * and the preserved progressive US formatting logic; plus Formatters::currency() on its
 * brick/money implementation.
 *
 * Pure-logic tests - no database access, so transactions are disabled.
 */
class Formatters_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * US mode: complete numbers, leading-1 strip, and idempotent re-format.
     */
    public static function test_phone_us_complete()
    {
        static::__assert_equals('(555) 123-4567', Formatters::phone('5551234567'));
        static::__assert_equals('(555) 123-4567', Formatters::phone('15551234567'));
        static::__assert_equals('(555) 123-4567', Formatters::phone('(555) 123-4567'));
    }

    /**
     * US mode: input with extension digits gets stripped to first 10 digits.
     * '555.123.4567 x99' -> digits '555123456799' (12) -> first 10 '5551234567'.
     */
    public static function test_phone_us_truncation()
    {
        static::__assert_equals('(555) 123-4567', Formatters::phone('555.123.4567 x99'));
    }

    /**
     * US mode: progressive partial formatting preserves the trailing space quirk.
     */
    public static function test_phone_us_partial()
    {
        static::__assert_equals('(123) ', Formatters::phone('123'));
    }

    /**
     * International mode ('+'): libphonenumber INTERNATIONAL format, stable when
     * already formatted, and unchanged passthrough when not possible.
     */
    public static function test_phone_international()
    {
        static::__assert_equals('+44 20 7123 4567', Formatters::phone('+442071234567'));
        static::__assert_equals('+44 20 7123 4567', Formatters::phone('+44 20 7123 4567'));
        static::__assert_equals('+9999999', Formatters::phone('+9999999'));
    }

    /**
     * Explicit non-US region: libphonenumber NATIONAL format for that region.
     */
    public static function test_phone_non_us_region()
    {
        static::__assert_equals('020 7123 4567', Formatters::phone('2071234567', 'GB'));
    }

    /**
     * A '+' number belonging to the region's OWN country code is shown the way that region
     * writes it. This is what makes E.164 usable as a storage format: what
     * Frontend_Contacts_Controller::save() writes has to come back as what the user typed.
     */
    public static function test_phone_home_region_e164_round_trip()
    {
        static::__assert_equals('(920) 614-5140', Formatters::phone('+19206145140'));
        static::__assert_equals('(555) 123-4567', Formatters::phone('+15551234567'));

        // An unassigned range survives the trip too - isPossibleNumber(), not isValidNumber().
        static::__assert_equals('(871) 350-8072', Formatters::phone('+18713508072'));

        // Another country's number keeps its country code.
        static::__assert_equals('+44 20 7123 4567', Formatters::phone('+442071234567'));

        // ... and the home region is whichever one was asked for.
        static::__assert_equals('020 7123 4567', Formatters::phone('+442071234567', 'GB'));
    }

    /**
     * Empty handling: null/'' -> '', but '0' now proceeds to formatting.
     */
    public static function test_phone_empty_and_zero()
    {
        static::__assert_equals('', Formatters::phone(''));
        static::__assert_equals('', Formatters::phone(null));
        static::__assert_equals('(0', Formatters::phone('0'));
    }

    /**
     * currency(): the four display switches.
     */
    public static function test_currency_display()
    {
        static::__assert_equals('1,234,567', Formatters::currency(1234567));
        static::__assert_equals('$1,234,567', Formatters::currency(1234567, show_symbol: true));
        static::__assert_equals('1,234,567.00', Formatters::currency(1234567, allow_decimals: true));
        static::__assert_equals(
            '$1,234,567.89',
            Formatters::currency(1234567.89, show_symbol: true, allow_decimals: true)
        );
        static::__assert_equals(
            'EUR1,234,567',
            Formatters::currency(1234567, show_symbol: true, symbol: 'EUR', currency: 'EUR')
        );
    }

    /**
     * currency(): empty in, empty out - and a non-numeric value renders a zero rather than
     * throwing. This is a display formatter; validation is somebody else's job.
     */
    public static function test_currency_empty_and_non_numeric()
    {
        static::__assert_equals('', Formatters::currency(null));
        static::__assert_equals('', Formatters::currency(''));
        static::__assert_equals('0', Formatters::currency('abc'));
        static::__assert_equals('0', Formatters::currency(0));
    }

    /**
     * currency(): rounding is HalfUp - half away from zero - at the displayed scale, and the
     * sign is preserved. PHP renders no "-0", and neither does this.
     */
    public static function test_currency_rounding()
    {
        static::__assert_equals('1', Formatters::currency(0.5));
        static::__assert_equals('0.01', Formatters::currency(0.005, allow_decimals: true));
        static::__assert_equals('1,234.57', Formatters::currency('1234.567', allow_decimals: true));
        static::__assert_equals('$-1,234.50', Formatters::currency(-1234.5, show_symbol: true, allow_decimals: true));
        static::__assert_equals('0.00', Formatters::currency('-0.004', allow_decimals: true));
    }

    /**
     * currency(): a string amount is carried as an exact decimal, so digits past what a
     * double holds survive. This is the reason the formatter is built on brick/money.
     */
    public static function test_currency_exact_beyond_float_precision()
    {
        static::__assert_equals('9,007,199,254,740,993', Formatters::currency('9007199254740993'));
        static::__assert_equals('12,345,678,901,234,567.89', Formatters::currency('12345678901234567.89', allow_decimals: true));
    }
}
