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
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Settings\ApiKeys\Frontend_Settings_Api_Keys_Controller;

/**
 * The APP-OWNED half of API key scoping: the Settings > API Keys endpoints.
 *
 * The framework owns the rule language, the dispatcher check and the intersection invariant
 * (Api_Scopes, framework-tested). What this template owns is the mint form's contract, and
 * these are the properties that make it safe rather than merely convenient:
 *
 *   - the access mode is answered EXPLICITLY - an unrecognised value is refused, because
 *     treating it as unrestricted would silently mint the widest key there is;
 *   - the stored rules are re-derived from CONFIG BY NAME, so a browser that edits the rule
 *     text it was shown cannot widen the key it mints;
 *   - an unknown preset name is refused, not skipped - skipping would mint a key narrower
 *     than the operator ticked, discovered weeks later as a 403;
 *   - a malformed rule is a per-field form error naming the line, never a minted key;
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
        static::__assert_null($result['scopes'], 'No rules means NULL - the key keeps its holder\'s full authority');

        $model = Api_Key_Model::find($result['id']);
        static::__assert_true($model->is_unrestricted(), 'The stored key reads back as unrestricted');
    }

    public static function test_a_ticked_preset_is_expanded_from_config_by_name()
    {
        static::__enable_api_access();

        $result = static::__create([
            'name' => 'Read-only key',
            'access_mode' => 'scoped',
            // The NAME only. The browser sends no rule text for a preset, and this is what
            // stops an edited payload from widening the key.
            'presets' => ['Read-only'],
            'scopes' => '',
        ]);

        static::__assert_equals('Grant GET /api/v1/**', $result['scopes'], 'The preset expanded to its configured rules');
    }

    public static function test_presets_and_custom_rules_are_unioned_and_canonicalised()
    {
        static::__enable_api_access();

        $result = static::__create([
            'name' => 'Union key',
            'access_mode' => 'scoped',
            'presets' => ['Read-only'],
            // Lower case keyword and method, and a duplicate of the preset's own rule:
            // canonicalise() capitalises both and collapses the duplicate.
            'scopes' => "grant get /api/v1/**\ndeny post /api/v1/contacts/*/delete",
        ]);

        static::__assert_equals(
            "Grant GET /api/v1/**\nDeny POST /api/v1/contacts/*/delete",
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
            'presets' => ['Read-only', 'No Such Preset'],
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

        static::__assert_array_has_key('scopes', static::__form_errors($result), 'A scoped key needs at least one rule');
    }

    public static function test_a_malformed_custom_rule_reports_the_line_and_mints_nothing()
    {
        static::__enable_api_access();

        $before = Api_Key_Model::where('user_id', self::USER_ID)->count();

        $result = static::__create([
            'name' => 'Broken rule',
            'access_mode' => 'scoped',
            'presets' => [],
            'scopes' => 'Grant SNARF /api/v1/contacts/**',
        ]);

        $errors = static::__form_errors($result);
        static::__assert_array_has_key('scopes', $errors, 'The parser message lands under the scopes field');
        static::__assert_contains('SNARF', $errors['scopes'], 'The message names the offending token verbatim');

        static::__assert_equals(
            $before,
            Api_Key_Model::where('user_id', self::USER_ID)->count(),
            'A rule set that cannot be read never becomes a credential'
        );
    }

    // ============================================================================
    // PREVIEW
    // ============================================================================

    public static function test_preview_narrows_the_catalogue_to_the_rules()
    {
        static::__enable_api_access();

        $result = Frontend_Settings_Api_Keys_Controller::preview_scopes(
            new Request(),
            ['scopes' => 'Grant GET /api/v1/contacts/**']
        );

        static::__assert_null($result['error'], 'A well-formed rule set previews without an error');
        static::__assert_false($result['unrestricted'], 'A rule set is not unrestricted');
        static::__assert_not_empty($result['groups'], 'The contacts endpoints are reachable');

        foreach ($result['groups'] as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                static::__assert_equals('GET', $endpoint['method'], 'Only the granted verb survives');
                static::__assert_contains('/api/v1/contacts', $endpoint['pattern'], 'Only the granted subtree survives');
            }
        }
    }

    public static function test_preview_reports_a_malformed_rule_as_a_normal_answer()
    {
        static::__enable_api_access();

        // The operator is mid-keystroke: half-written rules are this panel's expected state,
        // not a failed request.
        $result = Frontend_Settings_Api_Keys_Controller::preview_scopes(
            new Request(),
            ['scopes' => 'Grant GET contacts']
        );

        static::__assert_not_null($result['error'], 'The parser message is returned');
        static::__assert_contains('/api/vN/', $result['error'], 'And it says what is wrong with the pattern');
        static::__assert_empty($result['groups'], 'Nothing is claimed reachable while the rules do not parse');
    }

    public static function test_blank_scopes_preview_as_unrestricted()
    {
        static::__enable_api_access();

        $result = Frontend_Settings_Api_Keys_Controller::preview_scopes(new Request(), ['scopes' => '']);

        static::__assert_true($result['unrestricted'], 'No rules is the full surface, exactly as a NULL column is');
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
            'presets' => ['Read-only'],
            'scopes' => '',
        ]);

        $result = Frontend_Settings_Api_Keys_Controller::get_key_scopes(new Request(), ['id' => $created['id']]);

        static::__assert_equals('Viewable key', $result['name']);
        static::__assert_equals('Grant GET /api/v1/**', $result['scopes']);
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

    public static function test_presets_ship_their_rules_so_the_preview_can_union_them()
    {
        static::__enable_api_access();

        $result = Frontend_Settings_Api_Keys_Controller::get_scope_presets(new Request());

        static::__assert_not_empty($result['presets'], 'The template configures presets');

        foreach ($result['presets'] as $preset) {
            static::__assert_array_has_key('name', $preset);
            static::__assert_array_has_key('description', $preset);
            static::__assert_array_has_key('rules', $preset);
        }
    }
}
