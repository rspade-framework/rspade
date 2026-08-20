<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Settings;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Date;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Rsx_Settings - application custom value (settings) store.
 *
 * A database-backed key/value store for application behavior values: credentials
 * (e.g. a QuickBooks API key) and operational preferences (e.g. the day of week a
 * billing cycle ends). Like config-file values, but editable at runtime and
 * stored in the database, with a per-key definition (label, type, scope, default).
 *
 * PHP-only by design. These values never auto-populate into JavaScript and are not
 * exposed through any generated API; to surface a value to the client, return it
 * explicitly from an #[Ajax_Endpoint] method. This is guaranteed by intent: the
 * subsystem deliberately has NO Eloquent model. The two backing tables are
 * underscore-prefixed system tables (`_settings`, `_setting_values`) accessed here
 * directly through the query builder, exactly like `_migrations` and `_type_refs`.
 * (PHP-DB-01 skips underscore-prefixed tables, so these calls are not flagged.)
 *
 * Definitions are seeded with define() from application migrations; values are set
 * with set() (typically through an admin UI) and read with get() from program code.
 */
class Rsx_Settings
{
    // Scope: a setting is either global (one value) or site-scoped (per tenant).
    const SCOPE_GLOBAL = 1;
    const SCOPE_SITE = 2;

    // Value types. Stored serialized in a single column and cast on read.
    const TYPE_TEXT = 1;
    const TYPE_INTEGER = 2;
    const TYPE_DECIMAL = 3;   // fixed precision; returned as a string to preserve it
    const TYPE_FLOAT = 4;     // double
    const TYPE_BOOLEAN = 5;
    const TYPE_DATE = 6;      // "YYYY-MM-DD"
    const TYPE_DATETIME = 7;  // ISO 8601
    const TYPE_JSON = 8;

    /**
     * Per-request memo of definitions keyed by setting_key. Definitions do not
     * change during a normal request; cleared when define() mutates them.
     * @var array<string, object|null>
     */
    private static array $_definition_cache = [];

    /**
     * Define (register) a setting, or update its definition. Idempotent - safe to
     * call repeatedly, which is how application migrations register their settings.
     *
     * @param array $def Keys:
     *   - key (string, required)          machine identifier
     *   - label (string, required)        human-readable name
     *   - type (int, required)            one of the TYPE_* constants
     *   - scope (int, optional)           SCOPE_GLOBAL (default) or SCOPE_SITE
     *   - default (mixed, optional)       default value (type-cast and stored)
     *   - description (string, optional)
     *   - meta (array, optional)          type-specific config (e.g. ['scale' => 2])
     *   - is_secret (bool, optional)      credential; UI should mask it
     *   - group (string, optional)        UI grouping
     *   - sort_order (int, optional)
     * @return void
     */
    public static function define(array $def): void
    {
        $key = $def['key'] ?? null;
        if (empty($key) || !is_string($key)) {
            shouldnt_happen('Rsx_Settings::define() requires a non-empty string "key"');
        }
        if (empty($def['label']) || !is_string($def['label'])) {
            shouldnt_happen("Rsx_Settings::define('{$key}') requires a string \"label\"");
        }

        $type_id = $def['type'] ?? null;
        if (!in_array($type_id, self::_type_ids(), true)) {
            shouldnt_happen("Rsx_Settings::define('{$key}') requires a valid \"type\" (Rsx_Settings::TYPE_*)");
        }

        $scope_id = $def['scope'] ?? self::SCOPE_GLOBAL;
        if (!in_array($scope_id, [self::SCOPE_GLOBAL, self::SCOPE_SITE], true)) {
            shouldnt_happen("Rsx_Settings::define('{$key}') has an invalid \"scope\"");
        }

        $default_stored = array_key_exists('default', $def) && $def['default'] !== null
            ? self::_to_storage($def['default'], $type_id, $key)
            : null;

        $row = [
            'label' => $def['label'],
            'description' => $def['description'] ?? null,
            'scope_id' => $scope_id,
            'type_id' => $type_id,
            'default_value' => $default_stored,
            'meta' => isset($def['meta']) ? json_encode($def['meta']) : null,
            'is_secret' => !empty($def['is_secret']) ? 1 : 0,
            'setting_group' => $def['group'] ?? null,
            'sort_order' => (int)($def['sort_order'] ?? 0),
            'is_active' => 1,
            'updated_at' => now(),
        ];

        if (DB::table('_settings')->where('setting_key', $key)->exists()) {
            DB::table('_settings')->where('setting_key', $key)->update($row);
        } else {
            $row['setting_key'] = $key;
            $row['created_at'] = now();
            DB::table('_settings')->insert($row);
        }

        unset(self::$_definition_cache[$key]);
    }

    /**
     * Read a setting's resolved, type-cast value. Returns the stored override for
     * the relevant scope, or the definition's default if none is set.
     *
     * @param string $key
     * @param int|null $site_id Override the site for a site-scoped setting (default: current session site)
     * @return mixed
     */
    public static function get(string $key, ?int $site_id = null): mixed
    {
        $def = self::_definition($key);
        if (!$def) {
            shouldnt_happen("Rsx_Settings::get(): setting '{$key}' is not defined");
        }

        $resolved_site_id = self::_resolve_site_id($def, $site_id);

        $value_row = DB::table('_setting_values')
            ->where('setting_id', $def->id)
            ->where('site_id', $resolved_site_id)
            ->first();

        $raw = $value_row ? $value_row->value : $def->default_value;

        return self::_from_storage($raw, (int)$def->type_id);
    }

    /**
     * Set (and persist) a setting's value for the relevant scope.
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $site_id Override the site for a site-scoped setting
     * @return void
     */
    public static function set(string $key, mixed $value, ?int $site_id = null): void
    {
        $def = self::_definition($key);
        if (!$def) {
            shouldnt_happen("Rsx_Settings::set(): setting '{$key}' is not defined");
        }

        $resolved_site_id = self::_resolve_site_id($def, $site_id);
        $stored = $value === null ? null : self::_to_storage($value, (int)$def->type_id, $key);

        $match = ['setting_id' => $def->id, 'site_id' => $resolved_site_id];

        if (DB::table('_setting_values')->where($match)->exists()) {
            DB::table('_setting_values')->where($match)->update([
                'value' => $stored,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('_setting_values')->insert($match + [
                'value' => $stored,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Clear a stored value, reverting the setting to its definition default.
     *
     * @param string $key
     * @param int|null $site_id
     * @return void
     */
    public static function forget(string $key, ?int $site_id = null): void
    {
        $def = self::_definition($key);
        if (!$def) {
            shouldnt_happen("Rsx_Settings::forget(): setting '{$key}' is not defined");
        }

        $resolved_site_id = self::_resolve_site_id($def, $site_id);

        DB::table('_setting_values')
            ->where('setting_id', $def->id)
            ->where('site_id', $resolved_site_id)
            ->delete();
    }

    /**
     * Whether a setting key is defined.
     *
     * @param string $key
     * @return bool
     */
    public static function is_defined(string $key): bool
    {
        return self::_definition($key) !== null;
    }

    /**
     * List all active settings with their resolved values, for an admin UI.
     *
     * @param int|null $site_id Site context for site-scoped settings
     * @return array<int, array> Each: key, label, description, type, scope, group, is_secret, value
     */
    public static function all(?int $site_id = null): array
    {
        $defs = DB::table('_settings')
            ->where('is_active', 1)
            ->orderBy('setting_group')
            ->orderBy('sort_order')
            ->orderBy('setting_key')
            ->get();

        $out = [];
        foreach ($defs as $def) {
            $out[] = [
                'key' => $def->setting_key,
                'label' => $def->label,
                'description' => $def->description,
                'type' => (int)$def->type_id,
                'scope' => (int)$def->scope_id,
                'group' => $def->setting_group,
                'is_secret' => (bool)$def->is_secret,
                'value' => self::get($def->setting_key, $site_id),
            ];
        }

        return $out;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Resolve which site_id a value lives under for this definition.
     *
     * When the caller names a site explicitly that answer wins. Otherwise the tenant is
     * the CURRENT EXPERIENCE's (Rsx_Portal::is_portal_request()), not the staff session's:
     * a portal request reading or writing a site-scoped setting must land on the site the
     * app declared for the portal. There is no portal caller today - Rsx_Settings has no
     * consumer outside its own directory yet - which is exactly why the fork goes in now,
     * before the first adopter inherits a silently wrong tenant.
     */
    private static function _resolve_site_id(object $def, ?int $site_id): int
    {
        if ((int)$def->scope_id !== self::SCOPE_SITE) {
            return 0; // global settings always store under site_id 0
        }

        if ($site_id !== null) {
            return $site_id;
        }

        if (Rsx_Portal::is_portal_request()) {
            return (int) Portal_Session::get_site_id();
        }

        return (int) Session::get_site_id();
    }

    /**
     * Look up (and memo) a setting definition by key.
     */
    private static function _definition(string $key): ?object
    {
        if (array_key_exists($key, self::$_definition_cache)) {
            return self::$_definition_cache[$key];
        }

        $def = DB::table('_settings')
            ->where('setting_key', $key)
            ->where('is_active', 1)
            ->first();

        self::$_definition_cache[$key] = $def ?: null;

        return self::$_definition_cache[$key];
    }

    /**
     * Validate and serialize a value to its stored string form. Throws on a value
     * that does not match the declared type.
     */
    private static function _to_storage(mixed $value, int $type_id, string $key): ?string
    {
        switch ($type_id) {
            case self::TYPE_TEXT:
                return (string)$value;

            case self::TYPE_INTEGER:
                if (!is_int($value) && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    shouldnt_happen("Rsx_Settings: '{$key}' expects an integer, got: " . var_export($value, true));
                }
                return (string)(int)$value;

            case self::TYPE_DECIMAL:
                if (!is_numeric($value)) {
                    shouldnt_happen("Rsx_Settings: '{$key}' expects a decimal, got: " . var_export($value, true));
                }
                // Stored verbatim as a numeric string so fixed precision survives.
                return (string)$value;

            case self::TYPE_FLOAT:
                if (!is_numeric($value)) {
                    shouldnt_happen("Rsx_Settings: '{$key}' expects a float, got: " . var_export($value, true));
                }
                return (string)(float)$value;

            case self::TYPE_BOOLEAN:
                return $value ? '1' : '0';

            case self::TYPE_DATE:
                return Rsx_Date::parse($value); // throws on a non-date / datetime

            case self::TYPE_DATETIME:
                return Rsx_Time::to_iso($value); // normalizes to ISO 8601

            case self::TYPE_JSON:
                return json_encode($value);
        }

        shouldnt_happen("Rsx_Settings: unknown type id {$type_id} for '{$key}'");
    }

    /**
     * Cast a stored string back to its typed PHP value.
     */
    private static function _from_storage(?string $raw, int $type_id): mixed
    {
        if ($raw === null) {
            return null;
        }

        switch ($type_id) {
            case self::TYPE_TEXT:
                return $raw;
            case self::TYPE_INTEGER:
                return (int)$raw;
            case self::TYPE_DECIMAL:
                return $raw; // string, precision preserved
            case self::TYPE_FLOAT:
                return (float)$raw;
            case self::TYPE_BOOLEAN:
                return $raw === '1';
            case self::TYPE_DATE:
            case self::TYPE_DATETIME:
                return $raw; // already an ISO string
            case self::TYPE_JSON:
                return json_decode($raw, true);
        }

        shouldnt_happen("Rsx_Settings: unknown type id {$type_id}");
    }

    /**
     * Valid type ids.
     * @return int[]
     */
    private static function _type_ids(): array
    {
        return [
            self::TYPE_TEXT, self::TYPE_INTEGER, self::TYPE_DECIMAL, self::TYPE_FLOAT,
            self::TYPE_BOOLEAN, self::TYPE_DATE, self::TYPE_DATETIME, self::TYPE_JSON,
        ];
    }
}
