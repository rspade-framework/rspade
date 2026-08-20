<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Example_Test - Runnable reference test for the RSX test framework.
 *
 * Lives in /rsx/tests/ (a manifest-discovered location), so it is picked up by
 * `php artisan rsx:test` with no extra configuration. Use it as a template.
 *
 * Test methods start with `test_` and call the framework helpers, which are all
 * prefixed with a double underscore: static::__assert_*(), static::__acting_as_*().
 *
 * Each test runs inside its own database transaction that is rolled back when
 * the method returns, so DB writes in one test never leak into another. Set
 * `protected static $use_database_transactions = false;` to opt a class out.
 */
class Example_Test extends Rsx_Test_Abstract
{
    /**
     * Runs once before the test methods. Fixtures created here are OUTSIDE the
     * per-test transaction and persist for the whole class.
     */
    public static function setup()
    {
        // e.g. create shared fixtures here
    }

    /**
     * Runs once after all test methods.
     */
    public static function teardown()
    {
        // e.g. clean up shared fixtures here
    }

    /**
     * Basic boolean / equality / null assertions.
     */
    public static function test_basic_assertions()
    {
        static::__assert_true(true, 'true should be true');
        static::__assert_false(false, 'false should be false');
        static::__assert_equals('hello world', 'hello ' . 'world');
        static::__assert_not_equals('foo', 'bar');
        static::__assert_null(null);
        static::__assert_not_null('something');
        static::__assert_contains('quick', 'the quick brown fox');
        static::__assert_array_has_key('name', ['name' => 'John']);
    }

    /**
     * The additional assertions.
     */
    public static function test_extended_assertions()
    {
        static::__assert_count(3, [1, 2, 3]);
        static::__assert_greater_than(10, 11);
        static::__assert_less_than(10, 9);
        static::__assert_instance_of(\Exception::class, new \RuntimeException('x'));
        static::__assert_empty([]);
        static::__assert_empty('');
        static::__assert_not_empty('value');
        static::__assert_equals_approx(0.1 + 0.2, 0.3);          // default epsilon
        static::__assert_equals_approx(100.0, 100.4, 0.5);       // custom epsilon
    }

    /**
     * __assert_throws captures an expected exception so it can be asserted on.
     * Returns the caught throwable for further inspection.
     */
    public static function test_assert_throws()
    {
        $e = static::__assert_throws(
            \RuntimeException::class,
            function () {
                throw new \RuntimeException('boom: bad input');
            },
            'bad input' // optional message substring
        );

        static::__assert_equals('boom: bad input', $e->getMessage());
    }

    /**
     * Headless site/user context. __acting_as_site makes Session::get_site_id()
     * return the impersonated id; __reset_session clears it.
     */
    public static function test_acting_as_site()
    {
        static::__acting_as_site(42);
        static::__assert_equals(42, Session::get_site_id());

        static::__reset_session();
        static::__assert_equals(0, Session::get_site_id());
    }
}
