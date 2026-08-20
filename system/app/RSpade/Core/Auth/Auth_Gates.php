<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Portal\Rsx_Portal;

/**
 * Framework-internal evaluation engine for declarative #[Auth] gates.
 *
 * Reads the auth index built by Auth_ManifestSupport and executes named checks for
 * a realm. Application code does not call this class directly - it spells checks as
 * Permission::can_x() and Permission::can_access('Target') (Portal_Permission for
 * the portal realm); the dispatch seams call gates_pass().
 *
 * SEMANTICS
 * - A check's result is MEMOIZED per request, per realm: a marked check body runs
 *   at most once no matter how many surfaces, map-building passes, or inline calls
 *   consult it.
 * - STRICT: only an exact `true` grants. Anything else (null, 0, '', a forgotten
 *   return) denies - fail-closed.
 * - An UNKNOWN check name THROWS, listing the realm's defined names. A typo must
 *   never silently deny (which would read as a permission problem) or silently
 *   grant.
 *
 * See: php artisan rsx:man auth_gates
 */
class Auth_Gates
{
    public const REALM_STAFF = Auth_ManifestSupport::REALM_STAFF;
    public const REALM_PORTAL = Auth_ManifestSupport::REALM_PORTAL;
    public const REALM_ANY = Auth_ManifestSupport::REALM_ANY;

    /** @var array<string, bool> Memoized check results keyed "realm|name". */
    protected static array $_memo = [];

    /** @var array|null Test seam: replaces the manifest check registry when set. */
    protected static ?array $_checks_for_testing = null;

    /** @var array|null Test seam: replaces the manifest surface index when set. */
    protected static ?array $_surfaces_for_testing = null;

    // =========================================================================
    // REALM
    // =========================================================================

    /**
     * The realm this request belongs to.
     *
     * Portal requests (dedicated domain or the portal prefix) resolve check names
     * against Portal_Permission; everything else - including CLI - resolves against
     * Permission. This is the same detection the ORM endpoint uses to choose
     * between fetch() and portal_fetch().
     */
    public static function active_realm(): string
    {
        return Rsx_Portal::is_portal_request() ? self::REALM_PORTAL : self::REALM_STAFF;
    }

    // =========================================================================
    // EVALUATION
    // =========================================================================

    /**
     * Execute one named check for a realm.
     *
     * @param string $check_name Name as spelled in #[Auth(...)] / @auth(...)
     * @param string $realm self::REALM_STAFF or self::REALM_PORTAL
     * @return bool True only when the check body returned exactly true
     * @throws \RuntimeException When the name is not defined in the realm
     */
    public static function evaluate(string $check_name, string $realm): bool
    {
        $memo_key = $realm . '|' . $check_name;

        if (array_key_exists($memo_key, static::$_memo)) {
            return static::$_memo[$memo_key];
        }

        $checks = static::get_checks($realm);

        if (!isset($checks[$check_name])) {
            throw new \RuntimeException(
                "Unknown auth check '{$check_name}' in the {$realm} realm.\n" .
                "  Defined {$realm} checks: " . static::_format_check_names($checks) . "\n" .
                "  Define it as a public static parameterless ': bool' method marked\n" .
                "  #[Auth_Check] on the realm's Permission class.\n" .
                "  See: php artisan rsx:man auth_gates"
            );
        }

        $class = $checks[$check_name]['class'];
        $method = $checks[$check_name]['method'];

        $result = $class::$method();

        // Strict: anything that is not exactly true denies.
        $granted = ($result === true);

        static::$_memo[$memo_key] = $granted;

        return $granted;
    }

    /**
     * Whether EVERY named check passes (AND semantics; an empty list passes).
     *
     * @param array<int, string> $check_names
     * @param string $realm
     * @return bool
     */
    public static function gates_pass(array $check_names, string $realm): bool
    {
        foreach ($check_names as $check_name) {
            if (!static::evaluate($check_name, $realm)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The DISPATCH-SEAM form of gates_pass(): identical semantics, except that a
     * check name unknown in the active realm DENIES (and logs) instead of throwing.
     *
     * Rationale: at a seam an unsatisfiable gate must refuse the request, not 500.
     * The gate says "only users passing X may enter"; if X cannot be evaluated in
     * this realm, nobody passes. The warning keeps the misconfiguration loud in the
     * logs, and the manifest validation pass rejects a name unknown in BOTH realms
     * at build time, so this path only fires on a realm mismatch (a staff check
     * name on a surface reached in the portal realm, or vice versa).
     *
     * Programmatic callers (Permission::can_access(), evaluate(), gates_pass())
     * keep throwing - a typo in a link-visibility check must never read as a
     * permission problem.
     *
     * @param array<int, string> $check_names
     * @param string $realm Realm to resolve names in (usually active_realm())
     * @param string $surface Human-readable surface being gated, for the log line
     * @return bool
     */
    public static function gates_pass_at_seam(array $check_names, string $realm, string $surface): bool
    {
        foreach ($check_names as $check_name) {
            try {
                $granted = static::evaluate($check_name, $realm);
            } catch (\RuntimeException $e) {
                Log::warning(
                    "Auth gate on {$surface}: check '{$check_name}' is not defined in realm "
                    . "'{$realm}' - denying"
                );

                return false;
            }

            if (!$granted) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a surface may be reached from the REQUEST's realm at all - the check
     * that runs BEFORE any gate name is evaluated, at the seams whose transport is
     * reachable from both realms (Ajax, ORM).
     *
     * A surface indexed 'staff' or 'portal' serves exactly that realm; a request from
     * the other realm is refused outright, because evaluating the gate names in the
     * WRONG registry is what admits the wrong caller: 'is_logged_in' is defined in
     * both realms, so a staff session would satisfy a portal endpoint's gate (and a
     * portal session a staff endpoint's) and the body would then run with the wrong -
     * or a null - identity. 'any' surfaces (relationships, the framework services
     * that deliberately serve both realms) always permit.
     *
     * A surface the index does not know permits: only indexed surfaces can declare a
     * realm, and the gate layer above still applies.
     *
     * @param string $target 'Simple_Class::method' as spelled in the surface index
     * @param string $realm The request's realm (usually active_realm())
     * @return bool
     */
    public static function surface_realm_permits(string $target, string $realm): bool
    {
        $declared = static::get_surfaces()[$target]['realm'] ?? null;

        if ($declared === null || $declared === self::REALM_ANY || $declared === $realm) {
            return true;
        }

        Log::warning(
            "Auth realm mismatch on {$target}: the surface serves the '{$declared}' realm, "
            . "but the request is in the '{$realm}' realm - denying"
        );

        return false;
    }

    /**
     * The gate list a dispatchable surface declares, or [] when the surface carries
     * none (or is not indexed). The seams that resolve gates from the surface index
     * rather than from a route row (Ajax, ORM) go through here.
     *
     * @param string $target 'Simple_Class::method' as spelled in the surface index
     * @return array<int, string>
     */
    public static function surface_gates(string $target): array
    {
        return static::get_surfaces()[$target]['auth'] ?? [];
    }

    // =========================================================================
    // CLIENT EXPORT (window.rsxapp.auth / auth_routes)
    // =========================================================================

    /**
     * The GRANTS-ONLY check map shipped as window.rsxapp.auth.
     *
     * Every #[Auth_Check] registered for the realm is evaluated (through the
     * memoized evaluate(), so a check the page render already consulted at a
     * dispatch seam does not execute a second time), and only the names that
     * returned true appear:
     *
     *     ['public' => 1, 'is_logged_in' => 1, 'can_view_billing' => 1]
     *
     * Denied checks are OMITTED, never listed as false: page source must not
     * enumerate the capabilities a user lacks, nor the admin checks that exist
     * beyond those granted. Absence, denial, and nonexistence are one thing on
     * the client, by design.
     *
     * 'public' always passes, so an anonymous render still ships ['public' => 1] -
     * the map is never empty and is never absent.
     *
     * @param string $realm self::REALM_STAFF or self::REALM_PORTAL
     * @return array<string, int>
     */
    public static function export_grants(string $realm): array
    {
        $grants = [];

        foreach (array_keys(static::get_checks($realm)) as $check_name) {
            if (static::evaluate($check_name, $realm)) {
                $grants[$check_name] = 1;
            }
        }

        ksort($grants);

        return $grants;
    }

    /**
     * The GRANTS-ONLY reachable-page map shipped as window.rsxapp.auth_routes,
     * built ONLY when config('rsx.auth.export_php_route_grants') is true (the
     * assembler owns that decision; this method just computes the map).
     *
     * Covers the PHP PAGE surfaces of the realm - #[Route] and #[SPA] for staff,
     * #[Portal_Route] for the portal. Ajax/API/model surfaces are not link targets
     * and never appear.
     *
     * GATELESS SURFACES ARE EXCLUDED. During the transition to closed-by-default a
     * surface may carry no #[Auth] at all; "no gate" is an un-migrated declaration,
     * not a statement that everyone may reach the page. Listing it would let the
     * map assert reachability the code never declared, so absence keeps the map
     * honest - an un-annotated page simply cannot be link-gated client-side until
     * it declares its gates (a deliberately public page declares #[Auth('public')]
     * and does appear).
     *
     * @param string $realm self::REALM_STAFF or self::REALM_PORTAL
     * @return array<string, int>
     */
    public static function export_route_grants(string $realm): array
    {
        $page_kinds = [self::REALM_STAFF => ['route', 'spa'], self::REALM_PORTAL => ['portal_route']];
        $kinds = $page_kinds[$realm] ?? [];

        $reachable = [];

        foreach (static::get_surfaces() as $target => $surface) {
            if (empty(array_intersect($surface['kinds'] ?? [], $kinds))) {
                continue;
            }

            if (($surface['realm'] ?? null) !== $realm && ($surface['realm'] ?? null) !== self::REALM_ANY) {
                continue;
            }

            // See GATELESS SURFACES ARE EXCLUDED above.
            if (empty($surface['auth'])) {
                continue;
            }

            // Seam semantics: a name unknown in this realm denies (and logs) rather
            // than throwing - a misconfigured surface must not 500 a page render.
            if (!static::gates_pass_at_seam($surface['auth'], $realm, "auth_routes export of {$target}")) {
                continue;
            }

            $reachable[$target] = 1;
        }

        ksort($reachable);

        return $reachable;
    }

    // =========================================================================
    // TARGET RESOLUTION (can_access)
    // =========================================================================

    /**
     * Whether every gate on a TARGET surface passes for the current user.
     *
     * Targets use the spellings Rsx::Route() takes: 'Controller::method' (a bare
     * 'Controller' implies '::index') or a bare SPA action class name. No route
     * params, ever - gates are user-scoped.
     *
     * @param string $target
     * @param string $realm Realm the caller belongs to
     * @return bool
     * @throws \RuntimeException Unknown target, or a target belonging to the other realm
     */
    public static function can_access(string $target, string $realm): bool
    {
        $surface = static::resolve_surface($target, $realm);

        return static::gates_pass($surface['auth'], $realm);
    }

    /**
     * The URL of a target the current viewer may actually reach, or null.
     *
     * The link-visibility primitive in one call: null when the surface does not exist
     * in this install, when it belongs to the other realm, or when its gates deny.
     * A caller renders a link on a string and plain text on null.
     *
     * Deliberately NON-throwing where can_access() throws. can_access() is used by page
     * code naming a surface it knows is there, so an unknown target is a typo worth a
     * loud error. This one is used by code that must tolerate the surface being ABSENT -
     * framework core naming a screen the template app ships, which a downstream app is
     * free to rename or delete. "No such page" and "you may not see it" are the same
     * answer to the caller (render plain text), so both collapse to null. Introduced for
     * the actor layer's get_view_profile_url(); use it for any conditional link
     * whose destination may legitimately not exist.
     *
     * @param string $target Same spellings as Rsx::Route(): 'Controller::method',
     *                       'Controller' ('::index' implied), or a SPA action class name
     * @param string $realm self::REALM_STAFF or self::REALM_PORTAL
     * @param mixed $params Route params, passed through to the realm's Route() builder
     * @return string|null
     */
    public static function accessible_route(string $target, string $realm, $params = null): ?string
    {
        try {
            $surface = static::resolve_surface($target, $realm);
        } catch (\RuntimeException $e) {
            // Absent in this install, or another realm's surface. Both mean "no link".
            return null;
        }

        if (!static::gates_pass($surface['auth'], $realm)) {
            return null;
        }

        if ($realm === self::REALM_PORTAL) {
            return Rsx_Portal::Route($target, $params);
        }

        return \App\RSpade\Core\Rsx::Route($target, $params);
    }

    /**
     * Resolve a can_access() target to its surface entry.
     *
     * @throws \RuntimeException Unknown target, or a target outside the given realm
     */
    public static function resolve_surface(string $target, string $realm): array
    {
        $target = trim($target);
        $surfaces = static::get_surfaces();

        $resolved = null;

        if (isset($surfaces[$target])) {
            $resolved = $target;
        } elseif (!str_contains($target, '::') && isset($surfaces[$target . '::index'])) {
            // 'Controller' implies 'Controller::index', exactly like Rsx::Route().
            $resolved = $target . '::index';
        }

        if ($resolved === null) {
            throw new \RuntimeException(
                "Unknown auth target '{$target}'.\n" .
                "  can_access() takes the same spellings as Rsx::Route(): 'Controller::method'\n" .
                "  ('::index' implied), or a SPA action class name. The target must be a\n" .
                "  dispatchable surface indexed by the manifest.\n" .
                "  See: php artisan rsx:man auth_gates"
            );
        }

        $surface = $surfaces[$resolved];

        if ($surface['realm'] !== $realm && $surface['realm'] !== self::REALM_ANY) {
            throw new \RuntimeException(
                "Auth target '{$resolved}' belongs to the {$surface['realm']} realm, "
                . "but was resolved from the {$realm} realm.\n" .
                "  Staff and portal registries never blur: use Permission::can_access() for\n" .
                "  staff surfaces and Portal_Permission::can_access() for portal surfaces.\n" .
                "  See: php artisan rsx:man auth_gates"
            );
        }

        return $surface;
    }

    // =========================================================================
    // INDEX ACCESS
    // =========================================================================

    /**
     * The check registry for a realm: name => ['class' => FQCN, 'method', 'file'].
     */
    public static function get_checks(string $realm): array
    {
        if (static::$_checks_for_testing !== null) {
            return static::$_checks_for_testing[$realm] ?? [];
        }

        return static::get_index()['checks'][$realm] ?? [];
    }

    /**
     * The full surface index: target => ['kinds', 'realm', 'auth', 'file', 'member'].
     */
    public static function get_surfaces(): array
    {
        if (static::$_surfaces_for_testing !== null) {
            return static::$_surfaces_for_testing;
        }

        return static::get_index()['surfaces'] ?? [];
    }

    /**
     * The raw auth index as built by Auth_ManifestSupport.
     */
    public static function get_index(): array
    {
        $manifest = Manifest::get_full_manifest();

        return $manifest['data']['auth'] ?? ['checks' => [], 'surfaces' => []];
    }

    // =========================================================================
    // TEST SEAMS
    // =========================================================================

    /**
     * Install a synthetic registry (both maps optional) so evaluation can be tested
     * against fixture classes without those fixtures entering the real registry.
     * Passing null for both restores manifest-backed resolution.
     *
     * @param array|null $checks   realm => [name => ['class' => FQCN, 'method' => name]]
     * @param array|null $surfaces target => ['realm' => ..., 'auth' => [...], ...]
     */
    public static function _set_index_for_testing(?array $checks, ?array $surfaces): void
    {
        static::$_checks_for_testing = $checks;
        static::$_surfaces_for_testing = $surfaces;
        static::$_memo = [];
    }

    /**
     * Drop memoized results and any installed test index.
     */
    public static function _reset_for_testing(): void
    {
        static::$_memo = [];
        static::$_checks_for_testing = null;
        static::$_surfaces_for_testing = null;
    }

    /**
     * Drop memoized results only. The rsxapp assembler and the dispatch seams share
     * one snapshot per request; this exists for the request-boundary reset.
     */
    public static function reset_memo(): void
    {
        static::$_memo = [];
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    /**
     * Render a realm's defined check names for an error message.
     */
    private static function _format_check_names(array $checks): string
    {
        if (empty($checks)) {
            return '(none defined)';
        }

        $names = array_keys($checks);
        sort($names);

        return implode(', ', $names);
    }
}
