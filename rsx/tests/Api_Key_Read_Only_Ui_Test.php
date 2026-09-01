<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Settings\ApiKeys\Frontend_Settings_Api_Keys_Controller;

/**
 * The APP-OWNED half of read-only API keys: the Settings > API Keys screen.
 *
 * The framework owns the column, the dispatcher's refusal and the /me report (all framework
 * -tested). What this template owns is the mint form's contract and what the screen SHOWS,
 * and these are the properties that make it trustworthy:
 *
 *   - the checkbox is honoured: a ticked box mints a key that cannot write;
 *   - the DEFAULT is read+write, and it is stated rather than inferred - a garbled or
 *     truncated form must never mint the WIDER key by accident, so only the literal '1'
 *     means read-only;
 *   - read-only and the scope mode are independent, because they narrow different things:
 *     one names verbs, the other names paths;
 *   - the grid payload carries the flag, so the Access column can never quietly describe a
 *     key as writable when it is not;
 *   - the view modal reports it for an existing key;
 *   - the effective-access preview narrows by verb, so the panel never advertises a call
 *     the dispatcher would refuse with read_only_key.
 *
 * Endpoints are invoked directly at the controller layer; the returned value is the
 * endpoint's own contract.
 */
class Api_Key_Read_Only_Ui_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_user(self::USER_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    /**
     * Every endpoint re-asks Session::has_api_access(), which reads
     * users.is_api_access_enabled. Each test runs in its own rolled-back transaction, so
     * turning it on here never escapes the test.
     */
    private static function __enable_api_access(): void
    {
        $user = User_Model::without_site_scope(function () {
            return User_Model::find(self::USER_ID);
        });

        $user->is_api_access_enabled = true;
        $user->save();
    }

    private static function __create(array $params)
    {
        return Frontend_Settings_Api_Keys_Controller::create_key(new Request(), $params);
    }

    // ============================================================================
    // MINTING
    // ============================================================================

    public static function test_a_ticked_checkbox_mints_a_read_only_key()
    {
        static::__enable_api_access();

        $result = static::__create([
            'name' => 'Reporting integration',
            'access_mode' => 'unrestricted',
            // What Checkbox_Input serializes when it is ticked.
            'read_only' => '1',
        ]);

        static::__assert_true($result['read_only'], 'The mint reports the key it made');
        static::__assert_true(
            (bool) Api_Key_Model::find($result['id'])->read_only,
            'and the stored row carries the flag'
        );
    }

    public static function test_an_unticked_checkbox_mints_a_read_write_key()
    {
        static::__enable_api_access();

        $result = static::__create([
            'name' => 'Ordinary integration',
            'access_mode' => 'unrestricted',
            'read_only' => '0',
        ]);

        static::__assert_false($result['read_only']);
        static::__assert_false((bool) Api_Key_Model::find($result['id'])->read_only);
    }

    public static function test_an_absent_or_garbled_value_mints_a_read_write_key()
    {
        static::__enable_api_access();

        // Only the literal '1' narrows. Anything else - an absent key, a truncated form, a
        // value from some future widget - reads as read+write, because the NARROW value is
        // the one that has to be stated: mistaking "on" for "off" mints a key that cannot
        // write and is noticed at once, while the reverse hands out authority nobody asked
        // for and is noticed by nobody.
        foreach ([[], ['read_only' => ''], ['read_only' => 'yes'], ['read_only' => 'true']] as $index => $extra) {
            $result = static::__create([
                'name' => 'Absent flag ' . $index,
                'access_mode' => 'unrestricted',
            ] + $extra);

            static::__assert_false($result['read_only'], 'only the literal 1 means read-only');
        }
    }

    public static function test_read_only_and_the_scope_mode_are_independent()
    {
        static::__enable_api_access();

        $both = static::__create([
            'name' => 'Read-only and scoped',
            'access_mode' => 'scoped',
            'presets' => [],
            'scopes' => '/api/v1/contacts/*',
            'read_only' => '1',
        ]);

        static::__assert_true($both['read_only'], 'the verb axis');
        static::__assert_equals('/api/v1/contacts/*', $both['scopes'], 'the path axis, unaffected');

        $writer = static::__create([
            'name' => 'Scoped writer',
            'access_mode' => 'scoped',
            'presets' => [],
            'scopes' => '/api/v1/contacts/*',
        ]);

        static::__assert_false($writer['read_only'], 'a scoped key may still write');
    }

    // ============================================================================
    // WHAT THE SCREEN SHOWS
    // ============================================================================

    public static function test_the_grid_payload_carries_the_flag_and_its_label()
    {
        static::__enable_api_access();

        $created = static::__create([
            'name' => 'Grid read-only key',
            'access_mode' => 'unrestricted',
            'read_only' => '1',
        ]);

        $grid = Frontend_Settings_Api_Keys_Controller::datagrid_fetch(new Request(), ['per_page' => 100]);

        $row = null;
        foreach ($grid['records'] as $record) {
            if ((int) $record['id'] === (int) $created['id']) {
                $row = $record;
            }
        }

        static::__assert_not_null($row, 'The new key is in the grid');
        static::__assert_true($row['read_only'], 'The row carries the flag');
        static::__assert_equals('Read-only', $row['access_label'], 'and the Access column label');
    }

    public static function test_the_grid_labels_a_read_write_key()
    {
        static::__enable_api_access();

        $created = static::__create(['name' => 'Grid writer key', 'access_mode' => 'unrestricted']);

        $grid = Frontend_Settings_Api_Keys_Controller::datagrid_fetch(new Request(), ['per_page' => 100]);

        foreach ($grid['records'] as $record) {
            if ((int) $record['id'] === (int) $created['id']) {
                static::__assert_false($record['read_only']);
                static::__assert_equals('Read + write', $record['access_label']);
            }
        }
    }

    public static function test_the_view_modal_endpoint_reports_the_flag()
    {
        static::__enable_api_access();

        $created = static::__create([
            'name' => 'Viewed read-only key',
            'access_mode' => 'unrestricted',
            'read_only' => '1',
        ]);

        $viewed = Frontend_Settings_Api_Keys_Controller::get_key_scopes(new Request(), ['id' => $created['id']]);

        static::__assert_true($viewed['read_only'], 'The read-only view of a key says it may only read');
    }

    // ============================================================================
    // THE EFFECTIVE-ACCESS PREVIEW
    // ============================================================================

    public static function test_the_preview_lists_only_get_endpoints_for_a_read_only_key()
    {
        static::__enable_api_access();

        $preview = Frontend_Settings_Api_Keys_Controller::preview_scopes(new Request(), [
            'scopes' => '/api/v1/*',
            'read_only' => '1',
        ]);

        static::__assert_null($preview['error']);
        static::__assert_not_empty($preview['groups'], 'a read-only key still reaches the reads');

        foreach ($preview['groups'] as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                static::__assert_equals(
                    'GET',
                    $endpoint['method'],
                    'the panel must never offer a call the dispatcher would refuse'
                );
            }
        }
    }

    public static function test_the_preview_lists_writes_for_a_read_write_key()
    {
        static::__enable_api_access();

        $preview = Frontend_Settings_Api_Keys_Controller::preview_scopes(new Request(), [
            'scopes' => '/api/v1/*',
        ]);

        $has_post = false;

        foreach ($preview['groups'] as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                if ($endpoint['method'] === 'POST') {
                    $has_post = true;
                }
            }
        }

        static::__assert_true($has_post, 'the default preview is the read+write one');
    }
}
