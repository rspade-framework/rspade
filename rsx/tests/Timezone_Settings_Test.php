<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The template's user-settings timezone section, exercised the way the screen uses it.
 *
 * The screen (rsx/app/frontend/settings/user_settings/) posts straight to the
 * FRAMEWORK's Rsx_Timezone_Controller, so what this app-suite test proves is the
 * round trip the UI actually performs: a manual save carries timezone_auto=false and
 * both halves land on the row, and re-enabling the toggle with the same zone is not a
 * change (so the screen shows plain success rather than arming its forced navigation).
 *
 * The framework suite covers the setter's own semantics (tests/time/).
 */
class Timezone_Settings_Test extends Rsx_Test_Abstract
{
    const ZONE = 'Europe/Berlin';

    /**
     * Sign in as the first user in the test database.
     *
     * The setter invalidates the timezone resolution cache itself (framework-side), so
     * nothing here has to reach into it.
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
        Auth_Gates::reset_memo();

        return (int) $user->id;
    }

    private static function __sign_out(): void
    {
        static::__reset_session();
        Auth_Gates::reset_memo();
    }

    /**
     * What the screen posts when the user picks a zone with the toggle off.
     */
    public static function test_manual_save_persists_the_zone_and_turns_auto_off()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        $result = Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', [
            'timezone' => static::ZONE,
            'timezone_auto' => false,
        ]);

        static::__assert_true($result['changed'], 'the resolved zone moved, so the screen forces a navigation');
        static::__assert_equals(static::ZONE, $result['timezone']);

        $login_user = Session::get_login_user();
        static::__assert_equals(static::ZONE, $login_user->timezone);
        static::__assert_false((bool) $login_user->timezone_auto);

        static::__sign_out();
    }

    /**
     * Re-checking "Automatically set timezone" without touching the selector: the flag
     * goes back on, and because the zone did not move the save reports no change.
     */
    public static function test_re_enabling_auto_with_the_same_zone_is_not_a_change()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', [
            'timezone' => static::ZONE,
            'timezone_auto' => false,
        ]);

        $result = Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', [
            'timezone' => static::ZONE,
            'timezone_auto' => true,
        ]);

        static::__assert_false($result['changed'], 'only the zone drives a re-render');
        static::__assert_true((bool) Session::get_login_user()->timezone_auto);

        static::__sign_out();
    }

    /**
     * What the screen loads into the form: the raw preference, the effective zone the
     * selector displays when no preference exists, and the toggle state.
     */
    public static function test_get_settings_feeds_the_form()
    {
        if (static::__sign_in() === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        Ajax::internal('Rsx_Timezone_Controller', 'set_timezone', [
            'timezone' => static::ZONE,
            'timezone_auto' => false,
        ]);

        $settings = Ajax::internal('Rsx_Timezone_Controller', 'get_settings');

        static::__assert_equals(static::ZONE, $settings['timezone']);
        static::__assert_equals(static::ZONE, $settings['resolved_timezone']);
        static::__assert_false($settings['timezone_auto']);

        // The selector's option list is what the raw preference is matched against.
        $options = Ajax::internal('Rsx_Timezone_Controller', 'timezone_options');
        $values = array_column($options, 'value');
        static::__assert_true(in_array(static::ZONE, $values, true), 'the saved zone is selectable');

        static::__sign_out();
    }
}
