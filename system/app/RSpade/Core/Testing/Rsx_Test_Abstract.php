<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Testing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;

/**
 * Rsx_Test_Abstract - Base class for RSX framework tests
 *
 * Provides simple test framework in the RSX spirit - no complex dependencies,
 * just straightforward testing with clear pass/fail results.
 *
 * Assertion / control / context methods are all prefixed with a double
 * underscore (framework-internal convention) and are protected. Test methods
 * call them via static::__assert_*(), static::__acting_as_*(), etc.
 *
 * Each test_* method is wrapped in a database transaction that is rolled back
 * when the method returns (see $use_database_transactions). setup() and
 * teardown() run OUTSIDE the per-test transaction, so fixtures created there
 * persist across the tests in the class. The test runner (rsx:test) swaps the
 * default connection to the test database for the whole run, so the developer
 * database is never touched.
 */
abstract class Rsx_Test_Abstract
{
    /**
     * Test results storage
     */
    protected static $results = [];
    protected static $current_test = null;

    /**
     * Whether to wrap each test_* method in a database transaction that is
     * rolled back afterwards. Set to false in a child class to opt out (for
     * tests that must commit, or that manage their own transactions).
     * @var bool
     */
    protected static $use_database_transactions = true;

    /**
     * When true, the test runner re-provisions the test database to a clean,
     * freshly-migrated baseline ONCE before this class runs (a "blank slate").
     *
     * Use for tests that exercise the build/migration procedure, or that commit
     * data they then read back. Such classes should normally also set
     * $use_database_transactions = false (they commit, so per-test rollback
     * isolation does not apply). The runner restores the clean baseline before
     * the next class, so transaction-based classes are never affected by a
     * reset class that committed data.
     * @var bool
     */
    protected static $requires_db_reset = false;

    /**
     * Runner-facing accessor for the per-class reset flag.
     * @return bool
     */
    public static function requires_db_reset(): bool
    {
        return static::$requires_db_reset;
    }

    /**
     * Initialize the test class
     * Called before running tests
     */
    #[Replaceable]
    public static function setup()
    {
        // Override in child classes for test setup
    }
    
    /**
     * Clean up after tests
     * Called after all tests complete
     */
    #[Replaceable]
    public static function teardown()
    {
        // Override in child classes for cleanup
    }
    
    /**
     * Run all test methods in this class
     *
     * $results / $current_test are declared on THIS abstract base, so late static binding
     * gives every subclass the SAME storage. A test that runs another test class inside one
     * of its test_* methods would therefore wipe the caller's results. Both statics are
     * saved on entry and restored on exit so a nested run() is fully isolated; the helpers
     * that record into them (__pass/__fail/__skip) keep working unchanged.
     *
     * @return array Test results
     */
    public static function run()
    {
        $outer_results = static::$results;
        $outer_current_test = static::$current_test;

        static::$results = [];
        static::$current_test = null;

        try {
            return static::_run_tests();
        } finally {
            static::$results = $outer_results;
            static::$current_test = $outer_current_test;
        }
    }

    /**
     * The run() body, split out so run() can own the save/restore of the shared statics.
     *
     * @return array Test results
     */
    private static function _run_tests()
    {
        // Call setup
        static::setup();

        // Get all methods starting with 'test_'
        $reflection = new \ReflectionClass(static::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        
        foreach ($methods as $method) {
            if (strpos($method->getName(), 'test_') === 0) {
                static::$current_test = $method->getName();

                $use_transaction = static::$use_database_transactions;

                if ($use_transaction) {
                    DB::beginTransaction();
                }

                try {
                    // Run the test
                    $method->invoke(null);

                    // If we got here without an exception, test passed
                    if (!isset(static::$results[static::$current_test])) {
                        static::$results[static::$current_test] = [
                            'status' => 'passed',
                            'message' => 'Test completed successfully',
                        ];
                    }
                } catch (\Throwable $e) {
                    // __skip() throws a sentinel exception - record as skipped, not failed
                    if (strpos($e->getMessage(), '__SKIP__:') === 0) {
                        static::$results[static::$current_test] = [
                            'status' => 'skipped',
                            'message' => substr($e->getMessage(), strlen('__SKIP__:')),
                        ];
                    } else {
                        static::$results[static::$current_test] = [
                            'status' => 'failed',
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ];
                    }
                } finally {
                    // Roll back the per-test transaction so each test is isolated.
                    // Rolled back even on failure - never leave a dangling transaction.
                    if ($use_transaction) {
                        DB::rollBack();
                    }

                    // Drop any CLI session row the test demanded. A session minted
                    // inside the transaction just above no longer exists, so the
                    // static handle must not survive into the next test pointing at
                    // a vanished row. No-op unless a session was actually minted.
                    Session::_cli_end_session();

                    // Clear the portal facade's process-global CLI state (declared site,
                    // portal user, impersonator). It is a static, not a DB row, so the
                    // transaction rollback above does not touch it and a declaration made
                    // by one test would otherwise be borrowed by every later test - in
                    // this class AND in every class that runs after it. Each test declares
                    // its own portal site (setup() runs once per CLASS, so a declaration
                    // there would not survive this reset).
                    Portal_Session::reset();

                    // Clear the Turnstile per-request validation latch so a controller
                    // call in one test cannot satisfy the completeness guard for the next.
                    Rsx_Turnstile::_reset_request_state();
                }
            }
        }

        // Call teardown
        static::teardown();

        return static::$results;
    }
    
    /**
     * Assert that a condition is true
     * 
     * @param bool $condition
     * @param string $message
     */
    protected static function __assert_true($condition, $message = 'Assertion failed')
    {
        if (!$condition) {
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that a condition is false
     * 
     * @param bool $condition
     * @param string $message
     */
    protected static function __assert_false($condition, $message = 'Assertion failed')
    {
        static::__assert_true(!$condition, $message);
    }
    
    /**
     * Assert that two values are equal
     * 
     * @param mixed $expected
     * @param mixed $actual
     * @param string $message
     */
    protected static function __assert_equals($expected, $actual, $message = null)
    {
        if ($expected !== $actual) {
            $message = $message ?: "Expected '" . var_export($expected, true) . 
                                   "' but got '" . var_export($actual, true) . "'";
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that two values are not equal
     * 
     * @param mixed $expected
     * @param mixed $actual
     * @param string $message
     */
    protected static function __assert_not_equals($expected, $actual, $message = null)
    {
        if ($expected === $actual) {
            $message = $message ?: "Values should not be equal: '" . var_export($expected, true) . "'";
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that a string contains a substring
     * 
     * @param string $needle
     * @param string $haystack
     * @param string $message
     */
    protected static function __assert_contains($needle, $haystack, $message = null)
    {
        if (strpos($haystack, $needle) === false) {
            $message = $message ?: "String does not contain '{$needle}'";
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that an array has a key
     * 
     * @param string $key
     * @param array $array
     * @param string $message
     */
    protected static function __assert_array_has_key($key, $array, $message = null)
    {
        if (!array_key_exists($key, $array)) {
            $message = $message ?: "Array does not have key '{$key}'";
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that a value is null
     * 
     * @param mixed $value
     * @param string $message
     */
    protected static function __assert_null($value, $message = 'Value should be null')
    {
        static::__assert_true($value === null, $message);
    }
    
    /**
     * Assert that a value is not null
     * 
     * @param mixed $value
     * @param string $message
     */
    protected static function __assert_not_null($value, $message = 'Value should not be null')
    {
        static::__assert_true($value !== null, $message);
    }
    
    /**
     * Assert that calling $fn throws an exception of the given class.
     *
     * The runner marks a test passed when it does NOT throw, so there is no way
     * to assert "this should throw" without capturing the throw locally. This
     * method does exactly that: it catches \Throwable (so Errors and custom
     * exceptions are both covered), verifies the class and optional message
     * substring, and returns the caught exception for further inspection.
     *
     * @param string $exception_class Expected class (or parent class) of the throwable
     * @param callable $fn Callable that is expected to throw
     * @param string|null $message_contains Optional substring the message must contain
     * @return \Throwable The caught exception
     */
    protected static function __assert_throws(string $exception_class, callable $fn, ?string $message_contains = null): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!($e instanceof $exception_class)) {
                throw new \Exception(
                    "Expected exception of type '{$exception_class}' but got '" . get_class($e) .
                    "' with message: " . $e->getMessage()
                );
            }

            if ($message_contains !== null && strpos($e->getMessage(), $message_contains) === false) {
                throw new \Exception(
                    "Exception message '" . $e->getMessage() . "' does not contain '{$message_contains}'"
                );
            }

            return $e;
        }

        throw new \Exception("Expected exception of type '{$exception_class}' but none was thrown");
    }

    /**
     * Assert that a countable (array or Countable) has the expected count
     *
     * @param int $expected
     * @param array|\Countable $countable
     * @param string|null $message
     */
    protected static function __assert_count($expected, $countable, $message = null)
    {
        if (!is_array($countable) && !($countable instanceof \Countable)) {
            throw new \Exception($message ?: 'Value passed to __assert_count is not countable');
        }

        $actual = count($countable);
        if ($actual !== $expected) {
            throw new \Exception($message ?: "Expected count {$expected} but got {$actual}");
        }
    }

    /**
     * Assert that $actual is strictly greater than $threshold
     *
     * @param int|float $threshold
     * @param int|float $actual
     * @param string|null $message
     */
    protected static function __assert_greater_than($threshold, $actual, $message = null)
    {
        if (!($actual > $threshold)) {
            throw new \Exception($message ?: "Expected value greater than {$threshold} but got {$actual}");
        }
    }

    /**
     * Assert that $actual is strictly less than $threshold
     *
     * @param int|float $threshold
     * @param int|float $actual
     * @param string|null $message
     */
    protected static function __assert_less_than($threshold, $actual, $message = null)
    {
        if (!($actual < $threshold)) {
            throw new \Exception($message ?: "Expected value less than {$threshold} but got {$actual}");
        }
    }

    /**
     * Assert that $object is an instance of $class
     *
     * @param string $class
     * @param mixed $object
     * @param string|null $message
     */
    protected static function __assert_instance_of(string $class, $object, $message = null)
    {
        if (!($object instanceof $class)) {
            $actual = is_object($object) ? get_class($object) : gettype($object);
            throw new \Exception($message ?: "Expected instance of '{$class}' but got '{$actual}'");
        }
    }

    /**
     * Assert that a value is empty (null, false, 0, '', '0', empty array)
     *
     * @param mixed $value
     * @param string|null $message
     */
    protected static function __assert_empty($value, $message = null)
    {
        if (!empty($value)) {
            throw new \Exception($message ?: 'Value should be empty: ' . var_export($value, true));
        }
    }

    /**
     * Assert that a value is not empty
     *
     * @param mixed $value
     * @param string|null $message
     */
    protected static function __assert_not_empty($value, $message = null)
    {
        if (empty($value)) {
            throw new \Exception($message ?: 'Value should not be empty');
        }
    }

    /**
     * Assert that two floats are equal within a tolerance (epsilon)
     *
     * @param float $expected
     * @param float $actual
     * @param float $epsilon Maximum allowed absolute difference
     * @param string|null $message
     */
    protected static function __assert_equals_approx($expected, $actual, $epsilon = 0.005, $message = null)
    {
        if (abs($expected - $actual) > $epsilon) {
            throw new \Exception(
                $message ?: "Expected {$expected} (+/- {$epsilon}) but got {$actual}"
            );
        }
    }

    // =========================================================================
    // SESSION / SITE / USER CONTEXT (headless impersonation)
    //
    // A CLI run has no browser session, but site-scoped models read
    // Session::get_site_id() / get_user() and audit code reads
    // Session::get_user_id(). These helpers set the impersonated context as
    // static overrides; they create no session row of their own (a test that
    // wants one calls Session::get_session_id(), which mints a TYPE_CLI row for
    // the process). __reset_session() clears the overrides AND drops any minted
    // row. They are only effective in CLI/test context.
    // =========================================================================

    /**
     * Impersonate a site for the current test. Session::get_site_id() (and the
     * site scope on site-scoped models) will return this site id.
     *
     * @param int $site_id
     */
    protected static function __acting_as_site(int $site_id)
    {
        Session::set_site_id($site_id);
    }

    /**
     * Impersonate a site-specific user for the current test. Resolves the
     * User_Model (bypassing the site scope), then sets the site, login identity
     * and user id so every Session getter is coherent and audit attribution
     * (Session::get_user_id()) returns this user.
     *
     * @param int $user_id users.id (site-specific user)
     */
    protected static function __acting_as_user(int $user_id)
    {
        $user = User_Model::without_site_scope(function () use ($user_id) {
            return User_Model::find($user_id);
        });

        if (!$user) {
            shouldnt_happen("__acting_as_user: no User_Model with id {$user_id} in the test database");
        }

        Session::impersonate((int)$user->site_id, (int)$user->login_user_id, (int)$user->id);
    }

    /**
     * Clear all impersonated site/user/login context for the current test.
     */
    protected static function __reset_session()
    {
        Session::reset_impersonation();
    }

    /**
     * Make a test HTTP request
     *
     * @param string $url
     * @param string $method
     * @param array $data
     * @return \Illuminate\Http\Client\Response
     */
    protected static function __request($url, $method = 'GET', $data = [])
    {
        $full_url = 'http://localhost' . $url;
        
        switch (strtoupper($method)) {
            case 'POST':
                return Http::post($full_url, $data);
            case 'GET':
                return Http::get($full_url, $data);
            default:
                throw new \Exception("Unsupported HTTP method: {$method}");
        }
    }
    
    /**
     * Mark current test as passed with optional message
     * 
     * @param string $message
     */
    protected static function __pass($message = 'Test passed')
    {
        static::$results[static::$current_test] = [
            'status' => 'passed',
            'message' => $message
        ];
    }
    
    /**
     * Mark current test as failed
     * 
     * @param string $message
     */
    protected static function __fail($message = 'Test failed')
    {
        throw new \Exception($message);
    }
    
    /**
     * Skip current test
     * 
     * @param string $reason
     */
    protected static function __skip($reason = 'Test skipped')
    {
        static::$results[static::$current_test] = [
            'status' => 'skipped',
            'message' => $reason
        ];
        
        // Throw special exception to stop test execution but not mark as failed
        throw new \Exception('__SKIP__:' . $reason);
    }
}