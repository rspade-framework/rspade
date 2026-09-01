<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Settings\ApiKeys\Frontend_Settings_Api_Keys_Controller;

/**
 * The APP-OWNED half of API key scoping: the Settings > API Keys endpoints.
 *
 * The framework owns the scope grammar, the dispatcher check and the intersection invariant
 * (Api_Scopes, framework-tested). What this template owns is the mint form's contract, and
 * these are the properties that make it safe rather than merely convenient:
 *
 *   - the access mode is answered EXPLICITLY - an unrecognised value is refused, because
 *     treating it as unrestricted would silently mint the widest key there is;
 *   - the stored scopes are re-derived from CONFIG BY NAME, so a browser that edits the
 *     scope text it was shown cannot widen the key it mints;
 *   - an unknown preset name is refused, not skipped - skipping would mint a key narrower
 *     than the operator ticked, discovered weeks later as a 403;
 *   - a malformed scope is a per-field form error carrying the framework validator's own
 *     message, never a minted key;
 *   - every configured preset satisfies the grammar, so a ticked preset can never be the
 *     thing that fails the mint;
 *   - get_key_scopes answers for the CURRENT USER'S key only, and a key belonging to
 *     somebody else is indistinguishable from one that does not exist.
 *
 * Endpoints are invoked directly at the controller layer; the returned value is the
 * endpoint's own contract.
 */
class Api_Key_Scope_Ui_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;
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

    /**
     * The per-field errors of a refused endpoint call.
     *
     * A failing endpoint returns an Error_Response, and response_form_error() puts the field
     * map (plus '_message') in its metadata - so asserting on a field name here is asserting
     * on exactly what the form renders under that input.
     */
    private static function __form_errors($result): array
    {
        static::__assert_instance_of(Error_Response::class, $result, 'The call was refused');
        static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_error_code(), 'Refused as a validation failure');

        return $result->get_metadata();
    }

    // ============================================================================
    // MINTING
    // ============================================================================

    public static function test_unrestricted_mode_mints_a_key_with_null_scopes()
    {
        static::__enable_api_access();

        $result = static::__create(['name' => 'Unrestricted key', 'access_mode' => 'unrestricted']);

        static::__assert_array_has_key('key', $result, 'An unrestricted mint returns the plaintext key');
        static::__assert_null($result['scopes'], 'No scopes means NULL - the key keeps its holder\'s full authority');

        $model = Api_Key_Model::find($result['id']);
        static::__assert_true($model->is_unrestricted(), 'The stored key reads back as unrestricted');
    }

    public static function test_a_ticked_preset_is_expanded_from_config_by_name()
    {
        static::__enable_api_access();

        $result = static::__create([
            'name' => 'Everything key',
            'access_mode' => 'scoped',
            // The NAME only. The browser sends no scope text for a preset, and this is what
            // stops an edited payload from widening the key.
            'presets' => ['Everything in v1'],
            'scopes' => '',
        ]);

        static::__assert_equals('/api/v1/*', $result['scopes'], 'The preset expanded to its configured scopes');
    }

    public static function test_presets_and_custom_scopes_are_unioned_and_normalized()
    {
        static::__enable_api_access();

        $result = static::__create([
            'name' => 'Union key',
            'access_mode' => 'scoped',
            'presets' => ['Everything in v1'],
            // A duplicate of the preset's own scope, plus one carrying a trailing slash:
            // canonicalize() normalizes both and collapses the duplicate.
            'scopes' => "/api/v1/*\n/api/v1/clients/#/view/",
        ]);

        static::__assert_equals(
            "/api/v1/*\n/api/v1/clients/#/view",
            $result['scopes'],
            'The union is canonical, and an exact duplicate appears once'
        );
    }

    public static function test_an_unrecognised_access_mode_is_refused()
    {
        static::__enable_api_access();

        // A broken or truncated form must not silently mint the widest key there is.
        $result = static::__create(['name' => 'No mode', 'access_mode' => '']);

        static::__assert_array_has_key('access_mode', static::__form_errors($result), 'The mode is a per-field error');
    }

    public static function test_an_unknown_preset_name_is_refused_not_skipped()
    {
        static::__enable_api_access();

        $result = static::__create([
            'name' => 'Bad preset',
            'access_mode' => 'scoped',
            'presets' => ['Everything in v1', 'No Such Preset'],
            'scopes' => '',
        ]);

        static::__assert_array_has_key('presets', static::__form_errors($result), 'An unknown preset name fails the mint');
    }

    public static function test_a_scoped_key_with_no_rules_is_refused()
    {
        static::__enable_api_access();

        // Blank is a value. "Scoped, with nothing chosen" is a key that can call nothing,
        // which is never what the operator meant.
        $result = static::__create([
            'name' => 'Empty scope',
            'access_mode' => 'scoped',
            'presets' => [],
            'scopes' => '   ',
        ]);

        static::__assert_array_has_key('scopes', static::__form_errors($result), 'A scoped key needs at least one scope');
    }

    public static function test_a_malformed_custom_scope_reports_the_rule_and_mints_nothing()
    {
        static::__enable_api_access();

        $before = Api_Key_Model::where('user_id', self::USER_ID)->count();

        $result = static::__create([
            'name' => 'Broken scope',
            'access_mode' => 'scoped',
            'presets' => [],
            'scopes' => '/api/v1/contacts*',
        ]);

        $errors = static::__form_errors($result);
        static::__assert_array_has_key('scopes', $errors, 'The validator message lands under the scopes field');
        static::__assert_contains('a wildcard must be a whole segment', $errors['scopes'], 'It names the rule that was broken');
        static::__assert_contains('/api/v1/contacts*', $errors['scopes'], 'And quotes the offending scope verbatim');

        static::__assert_equals(
            $before,
            Api_Key_Model::where('user_id', self::USER_ID)->count(),
            'A scope set that cannot be read never becomes a credential'
        );
    }

    public static function test_the_old_rule_language_is_refused_as_a_form_error()
    {
        static::__enable_api_access();

        $result = static::__create([
            'name' => 'Old syntax',
            'access_mode' => 'scoped',
            'presets' => [],
            'scopes' => 'Grant GET /api/v1/contacts/**',
        ]);

        $errors = static::__form_errors($result);
        static::__assert_contains('/api/<version>/', $errors['scopes'], 'The old grammar is not a path');
    }

    // ============================================================================
    // PREVIEW
    // ============================================================================

    public static function test_preview_narrows_the_catalogue_to_the_scopes()
    {
        static::__enable_api_access();

        $result = Frontend_Settings_Api_Keys_Controller::preview_scopes(
            new Request(),
            ['scopes' => '/api/v1/contacts/*']
        );

        static::__assert_null($result['error'], 'A well-formed scope set previews without an error');
        static::__assert_false($result['unrestricted'], 'A scope set is not unrestricted');
        static::__assert_not_empty($result['groups'], 'The contacts endpoints are reachable');

        foreach ($result['groups'] as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                static::__assert_contains('/api/v1/contacts', $endpoint['pattern'], 'Only the named subtree survives');
            }
        }
    }

    public static function test_preview_reports_a_malformed_scope_as_a_normal_answer()
    {
        static::__enable_api_access();

        // The operator is mid-keystroke: half-written scopes are this panel's expected
        // state, not a failed request.
        $result = Frontend_Settings_Api_Keys_Controller::preview_scopes(
            new Request(),
            ['scopes' => 'contacts']
        );

        static::__assert_not_null($result['error'], 'The validator message is returned');
        static::__assert_contains('/api/<version>/', $result['error'], 'And it says what is wrong with the scope');
        static::__assert_empty($result['groups'], 'Nothing is claimed reachable while the scope is unreadable');
    }

    public static function test_blank_scopes_preview_as_unrestricted()
    {
        static::__enable_api_access();

        $result = Frontend_Settings_Api_Keys_Controller::preview_scopes(new Request(), ['scopes' => '']);

        static::__assert_true($result['unrestricted'], 'No scopes is the full surface, exactly as a NULL column is');
    }

    // ============================================================================
    // READING ONE KEY
    // ============================================================================

    public static function test_get_key_scopes_returns_the_owners_own_key()
    {
        static::__enable_api_access();

        $created = static::__create([
            'name' => 'Viewable key',
            'access_mode' => 'scoped',
            'presets' => ['Everything in v1'],
            'scopes' => '',
        ]);

        $result = Frontend_Settings_Api_Keys_Controller::get_key_scopes(new Request(), ['id' => $created['id']]);

        static::__assert_equals('Viewable key', $result['name']);
        static::__assert_equals('/api/v1/*', $result['scopes']);
        static::__assert_false($result['unrestricted']);
    }

    public static function test_another_users_key_is_indistinguishable_from_a_missing_one()
    {
        static::__enable_api_access();

        // Minted for a user that is not the acting one. The record-level rule lives in the
        // endpoint body, not in the #[Auth] gate, because it depends on the row.
        $other = new User_Model();
        $other->site_id = self::SITE_ID;
        $other->login_user_id = null;
        $other->first_name = 'Someone';
        $other->last_name = 'Else';
        $other->email = 'scope_other_' . uniqid() . '@example.com';
        $other->role_id = User_Model::ROLE_VIEWER;
        $other->is_enabled = true;
        $other->save();

        $foreign = Api_Key_Model::generate($other->id, 'Someone else\'s key', 'live');

        $result = Frontend_Settings_Api_Keys_Controller::get_key_scopes(
            new Request(),
            ['id' => $foreign['model']->id]
        );

        static::__assert_instance_of(Error_Response::class, $result, 'A foreign key is refused');
        static::__assert_equals(
            Ajax::ERROR_NOT_FOUND,
            $result->get_error_code(),
            'And it is refused as MISSING - the same answer a key that does not exist gets'
        );
    }

    // ============================================================================
    // PRESETS
    // ============================================================================

    public static function test_presets_ship_their_scopes_so_the_preview_can_union_them()
    {
        static::__enable_api_access();

        $result = Frontend_Settings_Api_Keys_Controller::get_scope_presets(new Request());

        static::__assert_not_empty($result['presets'], 'The template configures presets');

        foreach ($result['presets'] as $preset) {
            static::__assert_array_has_key('name', $preset);
            static::__assert_array_has_key('description', $preset);
            static::__assert_array_has_key('scopes', $preset);
        }
    }

    /**
     * EVERY CONFIGURED PRESET MUST SATISFY THE GRAMMAR.
     *
     * A preset is the one scope set an operator does not type, so a broken one fails the
     * mint at the moment somebody trusted the UI most - and it would look like their fault.
     * This is the test that fails when a scope is added to config by hand.
     */
    public static function test_every_configured_preset_validates()
    {
        $presets = config('rsx.api.scope_presets', []);

        static::__assert_not_empty($presets, 'The template configures presets');

        foreach ($presets as $preset) {
            $text = $preset['scopes'] ?? '';

            static::__assert_not_empty($text, $preset['name'] . ' carries scope text');

            // Throws Api_Scope_Validation_Exception on the first bad scope, which fails the
            // test with the validator's own message naming the preset's offending line.
            $canonical = Api_Scopes::canonicalize($text);

            static::__assert_not_null($canonical, $preset['name'] . ' canonicalizes to a scope set');
            static::__assert_empty(
                Api_Scopes::parse_all($canonical)['malformed'],
                $preset['name'] . ' has no malformed scope'
            );
        }
    }

    /**
     * The preset that was removed with the grammar, kept as a test so it cannot come back by
     * accident: a scope carries no HTTP method, so "every GET endpoint" is not expressible.
     * The replacement is the key's own read_only flag (the mint form's "Read-only key"
     * checkbox), enforced by the dispatcher and covered by Api_Key_Read_Only_Ui_Test.
     */
    public static function test_there_is_no_read_only_preset()
    {
        foreach (config('rsx.api.scope_presets', []) as $preset) {
            static::__assert_not_equals(
                'Read-only',
                $preset['name'],
                'A method-based preset cannot be expressed in the scope grammar'
            );
        }
    }
}
