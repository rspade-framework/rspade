<?php

namespace Rsx\App\Frontend\Settings\ApiKeys;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scope_Validation_Exception;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;
use Rsx\App\Frontend\Settings\ApiKeys\Api_Keys_DataGrid;

/**
 * Frontend_Settings_Api_Keys_Controller - the API key management endpoints.
 *
 * EVERY ENDPOINT RE-ASKS Session::has_api_access(). The #[Auth('is_logged_in')] gate says
 * who may reach this controller at all; whether this identity may deal in API keys is
 * users.is_api_access_enabled, the same predicate Api_Dispatcher applies to a Bearer key
 * and the page applies to its own chrome. The nav link and the buttons hide themselves for
 * a user without it, but a hidden link is not access control - the refusal has to live
 * here, where the write happens.
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Api_Keys_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data for API keys
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        if (!Session::has_api_access()) {
            return response_unauthorized('API access is not enabled for your account');
        }

        return Api_Keys_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: Create new API key
     *
     * Returns the plaintext key (only time it's visible).
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function create_key(Request $request, array $params = [])
    {
        if (!Session::has_api_access()) {
            return response_unauthorized('API access is not enabled for your account');
        }

        $errors = [];

        // Validate name
        $name = trim($params['name'] ?? '');
        if (empty($name)) {
            $errors['name'] = 'Key name is required';
        } elseif (strlen($name) > 255) {
            $errors['name'] = 'Key name must be 255 characters or less';
        }

        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        // THE SCOPES ARE BUILT HERE, FROM NAMES - never from scope text the browser
        // computed. The preview panel unions the same presets client-side so the operator
        // can see the result, but what is stored is re-derived from config on the server:
        // a ticked preset is a NAME, and the browser cannot widen it by editing the scopes
        // it was shown.
        $access_mode = $params['access_mode'] ?? '';
        $scopes = null;

        // The mode is answered EXPLICITLY. Treating an unrecognised value as unrestricted
        // would mean a broken form silently mints the widest key there is, which is the one
        // failure this endpoint must not have.
        if ($access_mode !== 'unrestricted' && $access_mode !== 'scoped') {
            return response_form_error(
                'Please correct the errors below.',
                ['access_mode' => 'Choose whether this key is unrestricted or scoped.']
            );
        }

        if ($access_mode === 'scoped') {
            $preset_names = is_array($params['presets'] ?? null) ? $params['presets'] : [];
            $preset_rules = static::_expand_scope_presets($preset_names);

            if ($preset_rules === null) {
                return response_form_error(
                    'Please correct the errors below.',
                    ['presets' => 'One of those presets no longer exists. Reload the page and choose again.']
                );
            }

            // Union: presets first, then whatever the operator typed. Every scope is a
            // grant and order carries no meaning, so this is only about producing one
            // readable canonical text.
            $text = trim($preset_rules . "\n" . trim((string) ($params['scopes'] ?? '')));

            if ($text === '') {
                return response_form_error(
                    'Please correct the errors below.',
                    ['scopes' => 'A scoped key needs at least one scope. Tick a preset, write a scope, or choose Unrestricted.']
                );
            }

            try {
                $scopes = Api_Scopes::canonicalize($text);
            } catch (Api_Scope_Validation_Exception $e) {
                // The framework validator's own message names the rule that was broken and
                // the offending scope verbatim, which is more use to whoever typed it than
                // anything this endpoint could reword.
                return response_form_error($e->getMessage(), ['scopes' => $e->getMessage()]);
            }
        }

        $user = Session::get_user();
        if (!$user) {
            return response_error(Ajax::ERROR_AUTH_REQUIRED);
        }

        // Generate the key. read_only is the OTHER axis and is nothing to do with the
        // scopes: it says which VERBS the key may use, where a scope says which PATHS it
        // may reach. The checkbox serializes '1' or '0' like every other checkbox, and
        // anything that is not '1' means read+write - a form that arrived garbled must
        // never accidentally mint the WIDER key, so the narrow value is the one that has
        // to be stated.
        $read_only = (string) ($params['read_only'] ?? '0') === '1';

        $result = Api_Key_Model::generate(
            user_id: $user->id,
            name: $name,
            environment: 'live',
            scopes: $scopes,
            read_only: $read_only
        );

        return [
            'id' => $result['model']->id,
            'name' => $result['model']->name,
            'key' => $result['key'], // Plaintext - only shown once!
            'key_prefix' => $result['model']->key_prefix,
            'created_at' => $result['model']->created_at,
            'scopes' => $result['model']->scopes,
            'read_only' => (bool) $result['model']->read_only,
        ];
    }

    /**
     * Ajax endpoint: the app's named scope presets, for the mint modal.
     *
     * Ships the SCOPES as well as the label, because the preview panel unions the ticked
     * presets with the operator's own text client-side before asking preview_scopes() what
     * that union reaches. Nothing here is a secret - it is a config array describing this
     * app's own public API surface - and create_key re-derives the scopes from the NAMES, so
     * a browser that edits them changes nothing.
     */
    #[Ajax_Endpoint]
    public static function get_scope_presets(Request $request, array $params = [])
    {
        if (!Session::has_api_access()) {
            return response_unauthorized('API access is not enabled for your account');
        }

        $presets = [];

        foreach (static::_scope_presets() as $preset) {
            $presets[] = [
                'name' => $preset['name'],
                'description' => $preset['description'] ?? '',
                'scopes' => $preset['scopes'] ?? '',
            ];
        }

        return ['presets' => $presets];
    }

    /**
     * Ajax endpoint: which endpoints would this scope text actually reach?
     *
     * The live effective-access preview behind the mint modal, and the read-only view of an
     * existing key's reach. 'read_only' narrows the answer by VERB the way the scopes narrow
     * it by path, so the panel describes the key that would actually be minted.
     * Api_Catalog::resolve_for_scopes() is pure - it takes the TEXT, not a key - so the same
     * call answers for a scope set that is still being typed.
     *
     * THIS IS WHERE THE BROWSER ASKS ABOUT MATCHING, AND THE ONLY WAY IT MAY. Api_Scopes is
     * the one implementation of the grammar; a JavaScript twin of it would drift from the
     * dispatcher's answer and the drift would show up as a key that previews one thing and
     * does another. The panel therefore renders whatever this endpoint says and computes
     * nothing itself.
     *
     * A MALFORMED SCOPE IS A NORMAL ANSWER HERE, not a failure: the operator is mid-keystroke
     * and the panel's job is to say what is wrong with what they have written so far. The
     * validator's message is returned as 'error' and the panel renders it.
     */
    #[Ajax_Endpoint]
    public static function preview_scopes(Request $request, array $params = [])
    {
        if (!Session::has_api_access()) {
            return response_unauthorized('API access is not enabled for your account');
        }

        $scopes = trim((string) ($params['scopes'] ?? ''));
        $scopes = $scopes === '' ? null : $scopes;

        // The verb axis. A read-only key may only GET, so the panel must not list a write
        // endpoint it would be refused on - Api_Catalog narrows the verbs for us.
        $read_only = (string) ($params['read_only'] ?? '0') === '1';

        // Reading never throws, so a half-written scope is reported rather than raised. The
        // FIRST malformed one is named: the operator fixes them one at a time anyway, and a
        // wall of messages under a textarea is read by nobody.
        $malformed = Api_Scopes::parse_all($scopes)['malformed'];

        return [
            'error' => empty($malformed) ? null : Arr::first($malformed),
            'unrestricted' => Api_Scopes::is_unrestricted($scopes),
            'groups' => static::_preview_groups(
                Api_Catalog::resolve_for_scopes(1, $scopes, false, $read_only)
            ),
        ];
    }

    /**
     * Ajax endpoint: one key's scopes and the endpoints they reach.
     *
     * KEYS ARE IMMUTABLE AFTER MINT - there is no edit endpoint, and narrowing a key means
     * revoking it and minting a replacement, so that a credential already in service can
     * never change meaning under the integration holding it. This is the read side only.
     *
     * EVERY WRITE GOES THROUGH THE FRAMEWORK. This controller never touches _api_keys itself:
     * it mints with Api_Key_Model::generate() and reads through the model, so the scope
     * grammar is validated in exactly one place.
     *
     * The ownership rule lives in this body rather than in the #[Auth] gate: a gate answers
     * "may this user manage API keys at all", and "is this key theirs" depends on the row.
     * A key belonging to somebody else answers exactly as a key that does not exist.
     */
    #[Ajax_Endpoint]
    public static function get_key_scopes(Request $request, array $params = [])
    {
        if (!Session::has_api_access()) {
            return response_unauthorized('API access is not enabled for your account');
        }

        $user = Session::get_user();
        if (!$user) {
            return response_error(Ajax::ERROR_AUTH_REQUIRED);
        }

        $key = Api_Key_Model::where('id', $params['id'] ?? null)
            ->where('user_id', $user->id)
            ->first();

        if (!$key) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'API key not found');
        }

        // The SCOPES only. The endpoint list the view modal shows beside them is resolved by
        // <Api_Scope_Preview>, through preview_scopes(), exactly as it is under the mint
        // form - one resolver, one rendering, whether the rule set exists yet or not.
        return [
            'id' => $key->id,
            'name' => $key->name,
            'scopes' => $key->scopes,
            'unrestricted' => $key->is_unrestricted(),
            'read_only' => (bool) $key->read_only,
        ];
    }

    /**
     * Ajax endpoint: Revoke an API key
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function revoke_key(Request $request, array $params = [])
    {
        if (!Session::has_api_access()) {
            return response_unauthorized('API access is not enabled for your account');
        }

        $key_id = $params['id'] ?? null;

        if (!$key_id) {
            return response_error(Ajax::ERROR_VALIDATION, ['id' => 'Key ID is required']);
        }

        $user = Session::get_user();
        if (!$user) {
            return response_error(Ajax::ERROR_AUTH_REQUIRED);
        }

        // Find the key and verify ownership
        $key = Api_Key_Model::where('id', $key_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$key) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'API key not found');
        }

        if ($key->is_revoked) {
            return response_error(Ajax::ERROR_VALIDATION, ['id' => 'Key is already revoked']);
        }

        $key->revoke();

        return [
            'id' => $key->id,
            'message' => 'API key revoked successfully',
        ];
    }

    /**
     * The configured presets, always as a list of arrays.
     */
    private static function _scope_presets(): array
    {
        $presets = config('rsx.api.scope_presets', []);

        return is_array($presets) ? $presets : [];
    }

    /**
     * The scope text for a set of preset NAMES, or null when one of them is not configured.
     *
     * An unknown name is refused rather than skipped: silently dropping it would mint a key
     * narrower than the operator ticked, and they would find out from a 403 weeks later.
     */
    private static function _expand_scope_presets(array $names): ?string
    {
        $by_name = [];
        foreach (static::_scope_presets() as $preset) {
            $by_name[$preset['name']] = $preset['scopes'] ?? '';
        }

        $lines = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $by_name)) {
                return null;
            }
            $lines[] = $by_name[$name];
        }

        return implode("\n", $lines);
    }

    /**
     * Flatten Api_Catalog's grouped catalogue to what the preview component renders:
     * a resource name and its METHOD + path lines, nothing else. A scope carries no method,
     * so every verb a reachable endpoint declares is listed.
     */
    private static function _preview_groups(array $groups): array
    {
        $out = [];

        foreach ($groups as $group) {
            $endpoints = [];

            foreach ($group['endpoints'] as $endpoint) {
                foreach ($endpoint['methods'] as $method) {
                    $endpoints[] = ['method' => $method, 'pattern' => $endpoint['pattern']];
                }
            }

            $out[] = ['name' => $group['name'], 'endpoints' => $endpoints];
        }

        return $out;
    }
}
