<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Frontend_Bundle;

/**
 * Analytics_Test - the template's external-resources example (rsx/lib/analytics/) ships INERT.
 *
 * The framework suite proves the registry mechanics; what belongs here is the template's own
 * promise: with no measurement id configured, nothing is exported to the browser, so no tag is
 * ever appended and the app adds no CSP violation as shipped.
 *
 * Pure-logic tests - no database access, so transactions are disabled.
 */
class Analytics_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Shipped default: no measurement id, so the bundle exports no analytics key at all
     * and Analytics.on_app_ready() returns before it can reach the loader.
     */
    public static function test_analytics_is_inert_when_no_measurement_id_is_configured()
    {
        $saved = config('rsx.analytics.measurement_id');
        config(['rsx.analytics.measurement_id' => '']);

        try {
            static::__assert_false(
                array_key_exists('analytics_measurement_id', Frontend_Bundle::load_rsxapp_data()),
                'an unconfigured install exports nothing for the browser to act on'
            );
        } finally {
            config(['rsx.analytics.measurement_id' => $saved]);
        }
    }

    /**
     * Configured: the id reaches window.rsxapp.page_data, which is the ONE thing that turns
     * the loader on. (Development still declines to report - that decision lives in the JS.)
     */
    public static function test_a_configured_measurement_id_is_exported_to_the_page()
    {
        $saved = config('rsx.analytics.measurement_id');
        config(['rsx.analytics.measurement_id' => 'G-TEST12345']);

        try {
            static::__assert_equals(
                'G-TEST12345',
                Frontend_Bundle::load_rsxapp_data()['analytics_measurement_id'] ?? null
            );
        } finally {
            config(['rsx.analytics.measurement_id' => $saved]);
        }
    }

    /**
     * The loader can only ask for an identifier the app actually declared, in its own realm.
     */
    public static function test_the_analytics_identifier_is_declared_for_the_staff_app()
    {
        $entry = Rsx_Externals::get('analytics');

        static::__assert_equals('staff', $entry['realm']);
        static::__assert_equals(['https://www.googletagmanager.com/gtag/js'], $entry['js']);
    }
}
