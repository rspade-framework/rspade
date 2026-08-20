<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module that builds the declarative authorization index (#[Auth] gates).
 *
 * Registered LAST in config('rsx.manifest_support'): it is self-contained (it walks
 * the file metadata itself rather than the route rows), but running last keeps the
 * route-row 'auth' keys written by the route support modules and this consolidated
 * index consistent within one build.
 *
 * INDEX SHAPE - $manifest_data['data']['auth']:
 *
 *   [
 *     'checks' => [
 *       'staff'  => [ '<check_name>' => ['class' => FQCN, 'method' => name, 'file' => rel] ],
 *       'portal' => [ ... same shape ... ],
 *     ],
 *     'surfaces' => [
 *       '<target>' => [
 *          'kinds'  => ['route'|'spa'|'portal_route'|'js_action'|'portal_js_action'
 *                       |'ajax'|'api'|'model_fetch'|'model_relationship', ...],
 *          'realm'  => 'staff'|'portal'|'any',
 *          'auth'   => ['check_name', ...],   // class-level gates then method-level
 *          'file'   => relative source path,
 *          'member' => 'Simple_Class::method' or the JS action class name,
 *       ],
 *     ],
 *   ]
 *
 * TARGET SPELLING mirrors Rsx::Route(): 'Simple_Class::method' for PHP members and
 * the bare action class name for JS actions. Permission::can_access() resolves the
 * same strings (with '::index' implied when a bare controller name is given).
 *
 * REALM. 'staff' surfaces resolve check names against the Permission lineage,
 * 'portal' surfaces against the Portal_Permission lineage. 'any' means the surface
 * is reachable from BOTH realms and its gates are evaluated in whichever realm the
 * request is in: model relationship methods (reached through fetch() OR
 * portal_fetch()) and the framework services that deliberately serve both. Model
 * fetch entry points are realm-explicit: fetch() is staff, portal_fetch() is portal.
 *
 * AJAX REALM. Each realm now has its OWN internal-endpoint channel (/_ajax/... for
 * staff, <portal-prefix>/_ajax/... for the portal, served by Portal_Dispatcher), so
 * an #[Ajax_Endpoint] surface is NOT realm-agnostic: it declares the realm it serves
 * and the Ajax seam DENIES a request from the other realm before evaluating any gate
 * name (Auth_Gates::surface_realm_permits). Resolution, in order:
 *
 *   1. a class-level #[Auth_Realm('staff'|'portal'|'any')] declaration, else
 *   2. 'portal' when the file lives under a portal root (self::PORTAL_ROOTS), else
 *   3. 'staff' - the fail-closed default.
 *
 * A portal controller placed outside the portal roots therefore denies portal
 * callers loudly until it declares #[Auth_Realm('portal')]; the failure mode of
 * forgetting the declaration is always a denial, never an admission.
 *
 * GATE MERGE. A surface's gate list is the declaring CLASS's #[Auth] arguments
 * followed by the MEMBER's own #[Auth] arguments, order preserved, duplicates
 * removed. Method-level gates are additive - they can only narrow.
 *
 * FATAL at build time (RuntimeException, aborts the manifest build):
 *   - an #[Auth_Check] method that declares parameters
 *   - an #[Auth_Check] method without a declared ': bool' return type
 *   - #[Auth_Check] on a public instance (non-static) method
 *   - two unrelated classes in one realm declaring the same check name
 *   - an unmarked override shadowing a marked ancestor check (it would silently
 *     never run - the registry resolves the most derived declaration)
 *   - a non-string #[Auth] argument
 *
 * CLOSED BY DEFAULT (the validation pass, _validate()). After the index is built,
 * every surface is validated and ALL findings are raised as ONE RuntimeException -
 * the error list is the worklist. Four findings:
 *
 *   - MISSING GATE: a surface with no #[Auth] / @auth at all, class-level or
 *     member-level. There is no attribute-free spelling of "open": a public
 *     surface declares #[Auth('public')].
 *   - UNKNOWN CHECK: a referenced name that resolves in no registry the surface
 *     can be evaluated against. A 'staff' surface's names must exist in the staff
 *     registry, a 'portal' surface's in the portal registry, and an 'any' surface's
 *     in at least ONE of them (an 'any' surface is evaluated in the REQUEST's realm,
 *     so a name defined in only one realm denies in the other - that runtime
 *     deny-with-a-warning is the correct outcome, not a build failure).
 *   - CONTRADICTION: a class-level gate that actually restricts (any check other
 *     than 'public') combined with a member-level 'public'. Gates are AND-ed and
 *     method-level gates are additive, so the member's 'public' opens nothing -
 *     the declaration reads as open while the class keeps it shut.
 *   - MISSING GATE on a JS @route action (same finding, action-class spelling).
 *
 * This pass runs pre-save, so a violation aborts the build and NOTHING is written.
 * That bricks artisan by design; the always-runnable escape hatch (rsx:man,
 * rsx:clean, list/help, --version) still works, and the message names every file
 * and member to fix.
 */
class Auth_ManifestSupport extends ManifestSupport_Abstract
{
    // Realm identifiers. 'any' is a surface-only value; a check always belongs to
    // exactly one of the two real realms.
    public const REALM_STAFF = 'staff';
    public const REALM_PORTAL = 'portal';
    public const REALM_ANY = 'any';

    // Roots of the two check registries.
    private const STAFF_PERMISSION_BASE = 'App\\RSpade\\Core\\Permission\\Permission_Abstract';
    private const PORTAL_PERMISSION_BASE = 'App\\RSpade\\Core\\Portal\\Portal_Permission_Abstract';

    /**
     * Manifest-relative directories whose code belongs to the portal realm: the app's
     * portal tree and the framework's portal core. An #[Ajax_Endpoint] declared here
     * defaults to the portal realm (see AJAX REALM in the class docblock).
     */
    private const PORTAL_ROOTS = [
        'rsx/portal/',
        'app/RSpade/Core/Portal/',
    ];

    public static function get_name(): string
    {
        return 'Auth Gates';
    }

    /**
     * Build $manifest_data['data']['auth'], then enforce closed-by-default.
     */
    public static function process(array &$manifest_data): void
    {
        $index = static::build_index($manifest_data['data']['files'] ?? []);

        $manifest_data['data']['auth'] = [
            'checks' => $index['checks'],
            'surfaces' => $index['surfaces'],
        ];

        static::validate($index['surfaces'], $index['checks'], $index['violations']);
    }

    /**
     * Build the auth index WITHOUT enforcing it.
     *
     * Separated from process() so the index construction can be exercised over
     * synthetic file metadata (which describes a fixture vocabulary, not the
     * application's, and would therefore fail every closed-by-default assertion).
     *
     * @param array $files $manifest_data['data']['files']
     * @return array{checks: array, surfaces: array, violations: array}
     */
    public static function build_index(array $files): array
    {
        // Index every class's metadata by FQCN so the extends chain can be walked
        // here without calling Manifest:: query APIs (the manifest is not finalized
        // mid-build; same constraint Api_Endpoint_ManifestSupport documents).
        $fqcn_index = [];
        foreach ($files as $file => $metadata) {
            if (isset($metadata['fqcn'])) {
                $fqcn_index[$metadata['fqcn']] = $metadata + ['__file' => $file];
            }
        }

        $checks = [
            self::REALM_STAFF => static::_build_check_registry($fqcn_index, self::STAFF_PERMISSION_BASE, self::REALM_STAFF),
            self::REALM_PORTAL => static::_build_check_registry($fqcn_index, self::PORTAL_PERMISSION_BASE, self::REALM_PORTAL),
        ];

        $surfaces = [];
        $violations = [];
        static::_collect_php_surfaces($files, $surfaces, $violations);
        static::_collect_js_action_surfaces($surfaces);

        ksort($surfaces);

        return [
            'checks' => $checks,
            'surfaces' => $surfaces,
            'violations' => $violations,
        ];
    }

    // =========================================================================
    // CLOSED-BY-DEFAULT VALIDATION
    // =========================================================================

    /**
     * Validate the built index and raise every finding in ONE exception.
     *
     * Public so the validation matrix can be driven directly with synthetic index
     * data (the manifest build calls it with what it just built).
     *
     * @param array $surfaces   target => ['kinds', 'realm', 'auth', 'file', 'member']
     * @param array $checks     realm => [name => declaration]
     * @param array $violations Findings already collected during surface collection
     *                          (the class-gate / member-'public' contradictions)
     * @throws \RuntimeException When any surface fails to declare a usable gate
     */
    public static function validate(array $surfaces, array $checks, array $violations = []): void
    {
        foreach ($surfaces as $target => $surface) {
            $realm = $surface['realm'];
            $is_js_action = static::_is_js_action_surface($surface);

            if (empty($surface['auth'])) {
                $violations[] = [
                    'type' => 'MISSING GATE',
                    'target' => $target,
                    'file' => $surface['file'],
                    'kinds' => $surface['kinds'],
                    'realm' => $realm,
                    'is_js_action' => $is_js_action,
                    'detail' => 'declares no #[Auth] / @auth gate, directly or on its class',
                ];

                continue;
            }

            foreach ($surface['auth'] as $name) {
                if (static::_check_name_resolves($name, $realm, $checks)) {
                    continue;
                }

                $violations[] = [
                    'type' => 'UNKNOWN CHECK',
                    'target' => $target,
                    'file' => $surface['file'],
                    'kinds' => $surface['kinds'],
                    'realm' => $realm,
                    'is_js_action' => $is_js_action,
                    'detail' => "references the check name '{$name}', which is declared by no"
                        . " #[Auth_Check] method in the {$realm} realm",
                ];
            }
        }

        if (empty($violations)) {
            return;
        }

        throw new \RuntimeException(static::_format_validation_failure($violations, $checks));
    }

    /**
     * Whether a check name resolves for a surface in the given realm.
     *
     * A realm-exact surface resolves against its own registry. An 'any' surface is
     * evaluated in the REQUEST's realm, so the build only requires the name to exist
     * somewhere; the seam denies (with a logged warning) in a realm that lacks it.
     */
    private static function _check_name_resolves(string $name, string $realm, array $checks): bool
    {
        if ($realm === self::REALM_ANY) {
            return isset($checks[self::REALM_STAFF][$name]) || isset($checks[self::REALM_PORTAL][$name]);
        }

        return isset($checks[$realm][$name]);
    }

    /**
     * Whether a surface entry is a JS action (its gate is spelled @auth on the class).
     */
    private static function _is_js_action_surface(array $surface): bool
    {
        foreach ($surface['kinds'] as $kind) {
            if ($kind === 'js_action' || $kind === 'portal_js_action') {
                return true;
            }
        }

        return false;
    }

    /**
     * Render the batched validation failure.
     *
     * Every violation states the file, the class::method (or action class), what is
     * wrong, and the exact syntax that fixes it. The footer names where check names
     * are declared and lists the names currently defined in each realm, so resolving
     * a violation is a copy-paste rather than a hunt.
     */
    private static function _format_validation_failure(array $violations, array $checks): string
    {
        $count = count($violations);
        $plural = $count === 1 ? '' : 's';

        $lines = [];
        $lines[] = "Auth gate validation failed: {$count} violation{$plural}.";
        $lines[] = '';
        $lines[] = 'Every dispatchable surface is CLOSED BY DEFAULT: it dispatches';
        $lines[] = 'only if it declares a gate and every named check resolves. A';
        $lines[] = 'surface with no gate does not deploy - there is no attribute-free';
        $lines[] = "spelling of open. A public surface declares #[Auth('public')].";
        $lines[] = '';

        $index = 0;
        foreach ($violations as $violation) {
            $index++;

            $kinds = implode(', ', $violation['kinds']);

            $lines[] = "  [{$index}] {$violation['type']}: {$violation['target']}";
            $lines[] = "      file:  {$violation['file']}";
            $lines[] = "      kind:  {$kinds} ({$violation['realm']} realm)";

            foreach (static::_wrap($violation['detail'], 56) as $offset => $chunk) {
                $lines[] = ($offset === 0 ? '      what:  ' : '             ') . $chunk;
            }

            if ($violation['type'] === 'MISSING GATE') {
                $suggested = static::_suggested_check($violation['realm'], $checks);
                $lines[] = $violation['is_js_action']
                    ? "      add:   @auth('{$suggested}')"
                    : "      add:   #[Auth('{$suggested}')]";
                $lines[] = $violation['is_js_action']
                    ? '             on the action class, beside @route'
                    : '             directly above the method';
            } elseif ($violation['type'] === 'UNKNOWN CHECK') {
                $lines[] = '      fix:   use a name from AVAILABLE CHECK NAMES below,';
                $lines[] = '             or declare the check with #[Auth_Check] on';
                $lines[] = "             the realm's Permission class";
            } else {
                $lines[] = "      fix:   remove the member's 'public' (the class gate";
                $lines[] = '             already applies), or drop the class-level';
                $lines[] = '             #[Auth] and gate each member individually';
            }

            $lines[] = '';
        }

        $lines[] = 'AVAILABLE CHECK NAMES - declared with #[Auth_Check] on a public';
        $lines[] = "static method of the realm's Permission class:";
        $lines[] = '';

        foreach ([self::REALM_STAFF => 'rsx/permission.php', self::REALM_PORTAL => 'rsx/portal_permission.php'] as $realm => $home) {
            $lines[] = "  {$realm} - {$home}";
            foreach (static::_wrap(static::_format_check_names($checks[$realm] ?? []), 56) as $chunk) {
                $lines[] = '      ' . $chunk;
            }
        }

        $lines[] = '';
        $lines[] = 'A staff surface resolves names in the staff realm, a portal';
        $lines[] = "surface in the portal realm, and an 'any' surface in either.";
        $lines[] = 'Multiple checks ride in ONE attribute and are AND-ed:';
        $lines[] = "#[Auth('is_logged_in', 'can_manage_users')].";
        $lines[] = '';
        $lines[] = 'See: php artisan rsx:man auth_gates';

        return implode("\n", $lines);
    }

    /**
     * Break a run of text into lines no wider than $width, so the console error
     * renderer does not re-wrap (and thereby mangle) the message.
     *
     * @return array<int, string>
     */
    private static function _wrap(string $text, int $width): array
    {
        return explode("\n", wordwrap($text, $width, "\n", true));
    }

    /**
     * The check name used in a violation's suggested syntax. 'is_logged_in' is the
     * realm's ordinary floor; anything is better than a placeholder the developer
     * has to look up.
     */
    private static function _suggested_check(string $realm, array $checks): string
    {
        $registry = $realm === self::REALM_PORTAL
            ? ($checks[self::REALM_PORTAL] ?? [])
            : ($checks[self::REALM_STAFF] ?? []);

        return isset($registry['is_logged_in']) ? 'is_logged_in' : 'public';
    }

    /**
     * Render a realm's defined check names for an error message.
     */
    private static function _format_check_names(array $registry): string
    {
        if (empty($registry)) {
            return '(none defined)';
        }

        $names = array_keys($registry);
        sort($names);

        return implode(', ', $names);
    }

    // =========================================================================
    // SHARED GATE EXTRACTION (used by the route/spa/api support modules too)
    // =========================================================================

    /**
     * Merge a declaring class's #[Auth] arguments with a member's own, preserving
     * order and dropping duplicates. Either argument may be the raw attributes
     * bucket of the manifest metadata (['Auth' => [[...args...], ...]]) or null.
     *
     * @param array|null $class_attributes  $metadata['attributes']
     * @param array|null $member_attributes $method_data['attributes']
     * @param string $location Human-readable "Class::method in file" for error text
     * @return array<int, string> Ordered, de-duplicated check names
     */
    public static function merge_gate_lists(?array $class_attributes, ?array $member_attributes, string $location): array
    {
        $names = array_merge(
            static::extract_auth_arguments($class_attributes, $location),
            static::extract_auth_arguments($member_attributes, $location)
        );

        $merged = [];
        foreach ($names as $name) {
            if (!in_array($name, $merged, true)) {
                $merged[] = $name;
            }
        }

        return $merged;
    }

    /**
     * Pull the check names out of an attributes bucket.
     *
     * The contract is ONE #[Auth] per declaration site carrying variadic string
     * arguments. The scanner stores attribute instances as a list, so a developer
     * who repeats the attribute anyway produces two instances; every instance's
     * arguments are merged rather than rejected here (tolerant read - a stricter
     * one-attribute rule may arrive with the closed-by-default validation pass).
     *
     * @param array|null $attributes Attributes bucket keyed by simple attribute name
     * @param string $location Human-readable location for error text
     * @return array<int, string>
     */
    public static function extract_auth_arguments(?array $attributes, string $location): array
    {
        if (empty($attributes)) {
            return [];
        }

        $names = [];

        foreach ($attributes as $attr_name => $instances) {
            if ($attr_name !== 'Auth' && !str_ends_with($attr_name, '\\Auth')) {
                continue;
            }

            foreach ($instances as $args) {
                foreach ($args as $arg) {
                    if (!is_string($arg) || $arg === '') {
                        throw new \RuntimeException(
                            "Invalid #[Auth] argument: {$location}\n" .
                            "  Every #[Auth] argument must be a non-empty check-name string,\n" .
                            "  e.g. #[Auth('is_logged_in', 'can_manage_users')].\n" .
                            "  See: php artisan rsx:man auth_gates"
                        );
                    }
                    $names[] = $arg;
                }
            }
        }

        return $names;
    }

    // =========================================================================
    // CHECK REGISTRY
    // =========================================================================

    /**
     * Build one realm's check registry from the Permission lineage rooted at
     * $base_fqcn (the base class itself included).
     *
     * Resolution is MOST-DERIVED-WINS: when a check name is declared by more than
     * one class in the realm, the class that descends from all the others wins, so
     * an application override of an inherited check is the one that actually runs.
     * Two declarations on unrelated branches are a name collision and fail loud.
     *
     * @param array $fqcn_index Class metadata keyed by FQCN (each carrying __file)
     * @param string $base_fqcn Realm root
     * @param string $realm Realm identifier (for error text)
     * @return array<string, array>
     */
    private static function _build_check_registry(array $fqcn_index, string $base_fqcn, string $realm): array
    {
        // Every class in the realm lineage, base included.
        $realm_classes = [];
        foreach ($fqcn_index as $fqcn => $metadata) {
            if ($fqcn === $base_fqcn || static::_is_descendant_of($fqcn, $base_fqcn, $fqcn_index)) {
                $realm_classes[$fqcn] = $metadata;
            }
        }

        // name => list of candidate declarations
        $candidates = [];
        // name => list of unmarked declarations shadowing a marked one
        $unmarked_declarations = [];

        foreach ($realm_classes as $fqcn => $metadata) {
            $file = $metadata['__file'] ?? '(unknown file)';
            $instance_methods = $metadata['public_instance_methods'] ?? [];

            foreach (($metadata['public_static_methods'] ?? []) as $method_name => $method_data) {
                // The scanner's 'public_static_methods' map is filtered PUBLIC-or-STATIC,
                // so it also lists public instance methods. The instance pass below owns
                // those (a check must be static).
                if (isset($instance_methods[$method_name])) {
                    continue;
                }

                if (!static::_has_auth_check_marker($method_data)) {
                    $unmarked_declarations[$method_name][] = ['class' => $fqcn, 'file' => $file];

                    continue;
                }

                $location = "{$fqcn}::{$method_name}() in {$file}";

                if (!empty($method_data['parameters'])) {
                    throw new \RuntimeException(
                        "Invalid #[Auth_Check]: {$location}\n" .
                        "  An auth check takes NO PARAMETERS - it answers 'may THIS USER use this\n" .
                        "  kind of surface' from identity state alone. A predicate that needs the\n" .
                        "  specific record belongs inside the surface's function body.\n" .
                        "  See: php artisan rsx:man auth_gates"
                    );
                }

                $return_type = $method_data['return_type']['type'] ?? null;
                $nullable = !empty($method_data['return_type']['nullable']);
                if ($return_type !== 'bool' || $nullable) {
                    throw new \RuntimeException(
                        "Invalid #[Auth_Check]: {$location}\n" .
                        "  An auth check must declare a ': bool' return type.\n" .
                        "  See: php artisan rsx:man auth_gates"
                    );
                }

                $candidates[$method_name][] = [
                    'class' => $fqcn,
                    'method' => $method_name,
                    'file' => $file,
                ];
            }

            // #[Auth_Check] on a public instance method: the surfaces call checks
            // statically, so a non-static check can never run.
            foreach (($metadata['public_instance_methods'] ?? []) as $method_name => $method_data) {
                if (static::_has_auth_check_marker($method_data)) {
                    throw new \RuntimeException(
                        "Invalid #[Auth_Check]: {$fqcn}::{$method_name}() in {$file}\n" .
                        "  An auth check must be a PUBLIC STATIC method - checks are invoked\n" .
                        "  statically on the realm's Permission class.\n" .
                        "  See: php artisan rsx:man auth_gates"
                    );
                }
            }
        }

        $registry = [];

        foreach ($candidates as $name => $declarations) {
            $winner = static::_resolve_most_derived($declarations, $fqcn_index);

            if ($winner === null) {
                $lines = [];
                foreach ($declarations as $declaration) {
                    $lines[] = "    {$declaration['class']}::{$name}() in {$declaration['file']}";
                }

                throw new \RuntimeException(
                    "Duplicate auth check name '{$name}' in the {$realm} realm:\n" .
                    implode("\n", $lines) . "\n" .
                    "  A check name must be unique within its realm. Rename one of them, or\n" .
                    "  make one class extend the other so the override resolves.\n" .
                    "  See: php artisan rsx:man auth_gates"
                );
            }

            // An UNMARKED redeclaration below the winner would shadow it at runtime
            // (a static call resolves to the most derived body), so the marked check
            // would silently never run.
            foreach (($unmarked_declarations[$name] ?? []) as $shadow) {
                if (static::_is_descendant_of($shadow['class'], $winner['class'], $fqcn_index)) {
                    throw new \RuntimeException(
                        "Unmarked override of auth check '{$name}' in the {$realm} realm:\n" .
                        "    {$shadow['class']}::{$name}() in {$shadow['file']}\n" .
                        "  overrides the marked check\n" .
                        "    {$winner['class']}::{$name}() in {$winner['file']}\n" .
                        "  The override is the body that actually runs, so it must carry\n" .
                        "  #[Auth_Check] itself (or be removed).\n" .
                        "  See: php artisan rsx:man auth_gates"
                    );
                }
            }

            $registry[$name] = $winner;
        }

        ksort($registry);

        return $registry;
    }

    /**
     * Pick the declaration that descends from every other candidate. Returns null
     * when the candidates sit on unrelated branches (an unresolvable collision).
     */
    private static function _resolve_most_derived(array $declarations, array $fqcn_index): ?array
    {
        foreach ($declarations as $candidate) {
            $descends_from_all = true;

            foreach ($declarations as $other) {
                if ($other['class'] === $candidate['class']) {
                    continue;
                }
                if (!static::_is_descendant_of($candidate['class'], $other['class'], $fqcn_index)) {
                    $descends_from_all = false;
                    break;
                }
            }

            if ($descends_from_all) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Walk the extends chain to determine whether $child_fqcn descends from
     * $ancestor_fqcn (strictly - a class is not its own descendant).
     */
    private static function _is_descendant_of(string $child_fqcn, string $ancestor_fqcn, array $fqcn_index): bool
    {
        $current = $fqcn_index[$child_fqcn]['extends_fqcn'] ?? null;
        $guard = 0;

        while ($current && $guard++ < 50) {
            if ($current === $ancestor_fqcn) {
                return true;
            }
            $current = $fqcn_index[$current]['extends_fqcn'] ?? null;
        }

        return false;
    }

    /**
     * Whether a method's metadata carries the #[Auth_Check] marker attribute.
     */
    private static function _has_auth_check_marker(array $method_data): bool
    {
        foreach (($method_data['attributes'] ?? []) as $attr_name => $instances) {
            if ($attr_name === 'Auth_Check' || str_ends_with($attr_name, '\\Auth_Check')) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // SURFACE COLLECTION
    // =========================================================================

    /**
     * Attribute -> [surface kind, realm] for the PHP surfaces carried by public
     * static methods.
     *
     * #[Ajax_Endpoint] carries a null realm: each realm has its own internal-endpoint
     * channel, so the realm is resolved per declaring class by _resolve_ajax_realm().
     */
    private static function _php_surface_attributes(): array
    {
        return [
            'Route' => ['route', self::REALM_STAFF],
            'SPA' => ['spa', self::REALM_STAFF],
            'Portal_Route' => ['portal_route', self::REALM_PORTAL],
            'Api_Endpoint' => ['api', self::REALM_STAFF],
            'Ajax_Endpoint' => ['ajax', null],
        ];
    }

    /**
     * The realm an #[Ajax_Endpoint] surface serves: an explicit class-level
     * #[Auth_Realm(...)], else the portal realm for code under a portal root, else
     * staff. See AJAX REALM in the class docblock.
     *
     * @param array|null $class_attributes $metadata['attributes'] of the declaring class
     * @param string $file Manifest-relative path of the declaring file
     * @param string $location Human-readable location for error text
     * @return string
     */
    private static function _resolve_ajax_realm(?array $class_attributes, string $file, string $location): string
    {
        $declared = static::_extract_auth_realm($class_attributes, $location);

        if ($declared !== null) {
            return $declared;
        }

        foreach (self::PORTAL_ROOTS as $root) {
            if (str_starts_with($file, $root)) {
                return self::REALM_PORTAL;
            }
        }

        return self::REALM_STAFF;
    }

    /**
     * Read a class-level #[Auth_Realm('staff'|'portal'|'any')] declaration.
     *
     * @param array|null $attributes Attributes bucket keyed by simple attribute name
     * @param string $location Human-readable location for error text
     * @return string|null The declared realm, or null when the class declares none
     * @throws \RuntimeException On a malformed or unknown realm value
     */
    private static function _extract_auth_realm(?array $attributes, string $location): ?string
    {
        if (empty($attributes)) {
            return null;
        }

        $valid = [self::REALM_STAFF, self::REALM_PORTAL, self::REALM_ANY];
        $declared = null;

        foreach ($attributes as $attr_name => $instances) {
            if ($attr_name !== 'Auth_Realm' && !str_ends_with($attr_name, '\\Auth_Realm')) {
                continue;
            }

            foreach ($instances as $args) {
                $value = $args[0] ?? null;

                if (!is_string($value) || !in_array($value, $valid, true)) {
                    throw new \RuntimeException(
                        "Invalid #[Auth_Realm] argument: {$location}\n" .
                        "  #[Auth_Realm] takes exactly one of: '" . implode("', '", $valid) . "'.\n" .
                        "  It declares which request realm may reach the class's #[Ajax_Endpoint]\n" .
                        "  methods; omit it for the default (portal under a portal root, else staff).\n" .
                        "  See: php artisan rsx:man auth_gates"
                    );
                }

                if ($declared !== null && $declared !== $value) {
                    throw new \RuntimeException(
                        "Conflicting #[Auth_Realm] declarations: {$location}\n" .
                        "  A class declares its ajax realm exactly once.\n" .
                        "  See: php artisan rsx:man auth_gates"
                    );
                }

                $declared = $value;
            }
        }

        return $declared;
    }

    /**
     * Walk every scanned class for PHP surfaces and record their gates.
     *
     * @param array $violations Collects the class-gate / member-'public' contradictions
     *                          found here, for the batched validation failure.
     */
    private static function _collect_php_surfaces(array $files, array &$surfaces, array &$violations): void
    {
        $surface_attributes = static::_php_surface_attributes();

        foreach ($files as $file => $metadata) {
            $class = $metadata['class'] ?? null;
            if (!$class) {
                continue;
            }

            $class_attributes = $metadata['attributes'] ?? null;
            $instance_methods = $metadata['public_instance_methods'] ?? [];

            // Resolved once per class: every #[Ajax_Endpoint] it declares serves the
            // same realm (the declaration is class-level).
            $ajax_realm = static::_resolve_ajax_realm($class_attributes, $file, "{$class} in {$file}");

            // A class-level gate that actually RESTRICTS. 'public' always passes, so a
            // class declaring only 'public' restricts nothing and a member repeating it
            // is redundant, not contradictory.
            $class_gates = static::extract_auth_arguments($class_attributes, "{$class} in {$file}");
            $class_restricts = !empty(array_diff($class_gates, ['public']));

            foreach (($metadata['public_static_methods'] ?? []) as $method_name => $method_data) {
                // The scanner's 'public_static_methods' map is filtered PUBLIC-or-STATIC,
                // so it also lists public instance methods. Only genuinely static members
                // are dispatchable surfaces; the instance pass below owns the rest
                // (fetchable relationships).
                if (isset($instance_methods[$method_name])) {
                    continue;
                }

                $target = $class . '::' . $method_name;
                $location = "{$target} in {$file}";
                $method_attributes = $method_data['attributes'] ?? null;

                $is_surface = false;

                foreach ($surface_attributes as $attr_name => $spec) {
                    if (!static::_method_has_attribute($method_data, $attr_name)) {
                        continue;
                    }

                    $is_surface = true;

                    static::_record_surface(
                        $surfaces,
                        $target,
                        $spec[0],
                        $spec[1] ?? $ajax_realm,
                        static::merge_gate_lists($class_attributes, $method_attributes, $location),
                        $file,
                        $target
                    );
                }

                // Model fetch entry points. fetch() is the staff authorization
                // contract, portal_fetch() the portal one; any other marked static
                // is reachable from whichever realm calls it.
                if (static::_method_has_attribute($method_data, 'Ajax_Endpoint_Model_Fetch')) {
                    $realm = self::REALM_ANY;
                    if ($method_name === 'fetch') {
                        $realm = self::REALM_STAFF;
                    } elseif ($method_name === 'portal_fetch') {
                        $realm = self::REALM_PORTAL;
                    }

                    $is_surface = true;

                    static::_record_surface(
                        $surfaces,
                        $target,
                        'model_fetch',
                        $realm,
                        static::merge_gate_lists($class_attributes, $method_attributes, $location),
                        $file,
                        $target
                    );
                }

                if ($is_surface) {
                    static::_record_public_contradiction(
                        $violations,
                        $surfaces,
                        $target,
                        $class_restricts,
                        static::extract_auth_arguments($method_attributes, $location)
                    );
                }
            }

            // Fetchable relationships are public INSTANCE methods. They are reached
            // through fetch() or portal_fetch(), so their realm is the request's.
            foreach (($metadata['public_instance_methods'] ?? []) as $method_name => $method_data) {
                if (!static::_method_has_attribute($method_data, 'Ajax_Endpoint_Model_Fetch')) {
                    continue;
                }

                $target = $class . '::' . $method_name;
                $location = "{$target} in {$file}";

                static::_record_surface(
                    $surfaces,
                    $target,
                    'model_relationship',
                    self::REALM_ANY,
                    static::merge_gate_lists($class_attributes, $method_data['attributes'] ?? null, $location),
                    $file,
                    $target
                );

                static::_record_public_contradiction(
                    $violations,
                    $surfaces,
                    $target,
                    $class_restricts,
                    static::extract_auth_arguments($method_data['attributes'] ?? null, $location)
                );
            }
        }
    }

    /**
     * Record the class-gate / member-'public' contradiction for one surface member.
     *
     * Gates are AND-ed and method-level gates are ADDITIVE, so a member's 'public'
     * inside a restricting class opens nothing: the declaration reads as an open
     * surface while the class keeps it shut. Restructure the controller instead
     * (move the member out, or drop the class-level gate and gate each member).
     */
    private static function _record_public_contradiction(
        array &$violations,
        array $surfaces,
        string $target,
        bool $class_restricts,
        array $member_gates
    ): void {
        if (!$class_restricts || !in_array('public', $member_gates, true)) {
            return;
        }

        $surface = $surfaces[$target] ?? null;
        if ($surface === null) {
            return;
        }

        $violations[] = [
            'type' => 'CONTRADICTION',
            'target' => $target,
            'file' => $surface['file'],
            'kinds' => $surface['kinds'],
            'realm' => $surface['realm'],
            'is_js_action' => false,
            'detail' => "declares #[Auth('public')] while its class declares a restricting gate"
                . ' - gates AND, so the member opens nothing',
        ];
    }

    /**
     * Record the JS action surfaces: every Spa_Action subclass carrying @route,
     * with the check names from its @auth decorator.
     */
    private static function _collect_js_action_surfaces(array &$surfaces): void
    {
        foreach (Manifest::js_get_extending('Spa_Action') as $class_name => $action_metadata) {
            $decorators = $action_metadata['decorators'] ?? [];

            $has_route = false;
            $is_portal = false;
            $gates = [];

            foreach ($decorators as $decorator) {
                [$name, $args] = $decorator;

                if ($name === 'route') {
                    $has_route = true;
                } elseif ($name === 'portal_spa') {
                    $is_portal = true;
                } elseif ($name === 'auth') {
                    foreach ($args as $arg) {
                        if (!is_string($arg) || $arg === '') {
                            throw new \RuntimeException(
                                "Invalid @auth argument on Spa action '{$class_name}' in "
                                . ($action_metadata['file'] ?? '(unknown file)') . "\n" .
                                "  Every @auth argument must be a non-empty check-name string,\n" .
                                "  e.g. @auth('is_logged_in', 'can_manage_users').\n" .
                                "  See: php artisan rsx:man auth_gates"
                            );
                        }
                        if (!in_array($arg, $gates, true)) {
                            $gates[] = $arg;
                        }
                    }
                }
            }

            if (!$has_route) {
                continue;
            }

            static::_record_surface(
                $surfaces,
                $class_name,
                $is_portal ? 'portal_js_action' : 'js_action',
                $is_portal ? self::REALM_PORTAL : self::REALM_STAFF,
                $gates,
                $action_metadata['file'] ?? '(unknown file)',
                $class_name
            );
        }
    }

    /**
     * Add (or widen) one surface entry.
     *
     * A member can legitimately carry more than one surface attribute, so 'kinds'
     * is a list. When two kinds disagree on realm the entry widens to 'any': the
     * same declaration is reachable from both realms, and the gates are identical
     * because they come from the same attributes.
     */
    private static function _record_surface(
        array &$surfaces,
        string $target,
        string $kind,
        string $realm,
        array $gates,
        string $file,
        string $member
    ): void {
        if (!isset($surfaces[$target])) {
            $surfaces[$target] = [
                'kinds' => [$kind],
                'realm' => $realm,
                'auth' => $gates,
                'file' => $file,
                'member' => $member,
            ];

            return;
        }

        $entry = &$surfaces[$target];

        if (!in_array($kind, $entry['kinds'], true)) {
            $entry['kinds'][] = $kind;
        }

        if ($entry['realm'] !== $realm) {
            $entry['realm'] = self::REALM_ANY;
        }

        foreach ($gates as $gate) {
            if (!in_array($gate, $entry['auth'], true)) {
                $entry['auth'][] = $gate;
            }
        }
    }

    /**
     * Whether a method's metadata carries an attribute by simple name.
     */
    private static function _method_has_attribute(array $method_data, string $short_name): bool
    {
        foreach (($method_data['attributes'] ?? []) as $attr_name => $instances) {
            if ($attr_name === $short_name || str_ends_with($attr_name, '\\' . $short_name)) {
                return true;
            }
        }

        return false;
    }
}
