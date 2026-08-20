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
 * international ('+') and explicit non-US region paths, and the preserved
 * progressive US formatting logic.
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
     * Empty handling: null/'' -> '', but '0' now proceeds to formatting.
     */
    public static function test_phone_empty_and_zero()
    {
        static::__assert_equals('', Formatters::phone(''));
        static::__assert_equals('', Formatters::phone(null));
        static::__assert_equals('(0', Formatters::phone('0'));
    }
}
