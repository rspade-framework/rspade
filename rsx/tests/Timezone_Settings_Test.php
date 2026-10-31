<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * The template's user-settings timezone section, exercised the way the screen uses it.
 *
 * The screen (rsx/app/frontend/settings/user_settings/) posts straight to the
 * FRAMEWORK's Rsx_Timezone_Controller with ONE value: the zone the user picked, or '' -
 * the select's "Automatic - follow my browser" option - for automatic. What this app-suite
 * test proves is that round trip: a picked zone lands on the row and moves the rendered
 * zone (so the screen makes the next navigation a full page load), Automatic stores NULL and
 * renders in the browser's zone, and the form loads '' for an automatic user.
 *
 * The framework suite covers the setter and the resolution order (tests/time/). The
 * browser's zone is the framework's test seam, Rsx_Time::$browser_timezone_cookie_for_tests.
 */
class Timezone_Settings_Test extends Rsx_Test_Abstract
{
    const ZONE = 'Europe/Berlin';
    const BROWSER = 'Asia/Tokyo';

    /**
     * Sign in as the first user in the test database, in a browser reporting BROWSER.
     *
     * @return int|null the user id, or null when the database has no user
     */
    private static function __sign_in(): ?int
    {
        $user = User_Model::without_site_scope(function () {
            return User_Model::orderBy('id')->first();
        });

        if (!$user) {
            return null;
        }

        static::__acting_as_user((int) $user->id);
        Rsx_Time::$browser_timezone_cookie_for_tests = static::BROWSER;

        return (int) $user->id;
    }

    /**
     * What the screen posts when the user picks a zone.
     */
    public static function test_picking_a_zone_persists_it()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', ['timezone' => '']);
        $result = Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', ['timezone' => static::ZONE]);

        static::__assert_true($result['changed'], 'the rendered zone moved, so the screen makes the next navigation a full load');
        static::__assert_equals(static::ZONE, $result['timezone']);
        static::__assert_equals(static::ZONE, Session::get_login_user()->timezone);
    }

    /**
     * What the screen posts for the Automatic option: '' - stored as NULL, rendered in the
     * browser's zone.
     */
    public static function test_choosing_automatic_follows_the_browser()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', ['timezone' => static::ZONE]);
        $result = Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', ['timezone' => '']);

        static::__assert_true($result['changed'], 'Berlin to the browser\'s Tokyo');
        static::__assert_null(Session::get_login_user()->timezone, 'automatic stores no zone');
        static::__assert_equals(static::BROWSER, Rsx_Time::get_user_timezone());
    }

    /**
     * What the screen loads into the form: '' for automatic (the select's Automatic option),
     * the identifier otherwise.
     */
    public static function test_get_settings_feeds_the_form()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', ['timezone' => '']);
        $settings = Ajax::internal('Rsx_Timezone_Controller', 'get_settings');
        static::__assert_null($settings['timezone'], 'automatic: the screen selects its Automatic option');
        static::__assert_equals(static::BROWSER, $settings['resolved_timezone']);

        Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', ['timezone' => static::ZONE]);
        $settings = Ajax::internal('Rsx_Timezone_Controller', 'get_settings');
        static::__assert_equals(static::ZONE, $settings['timezone']);

        // The selector's option list is what the stored preference is matched against.
        $options = Ajax::internal('Rsx_Timezone_Controller', 'timezone_options');
        static::__assert_true(in_array(static::ZONE, array_column($options, 'value'), true), 'the saved zone is selectable');
    }
}
