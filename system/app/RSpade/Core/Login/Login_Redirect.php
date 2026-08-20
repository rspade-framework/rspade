<?php

namespace App\RSpade\Core\Login;

use Illuminate\Http\Request;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Portal\Portal_Dispatcher;
use App\RSpade\Core\Portal\Rsx_Portal;

/**
 * Login_Redirect - intended-URL ("return to the page I was trying to visit")
 * threading for a multi-hop login flow.
 *
 * A user who requests a protected deep URL while logged out is bounced to login.
 * This class threads the originally requested path through the login funnel as a
 * ?redirect= query parameter, so the terminal post-login destination can return
 * the user to it. The strategy is URL-param threading (NOT a server session stash
 * nor browser storage): the intended URL is per-NAVIGATION state, not per-BROWSER
 * state, so a shared session cookie across tabs would send one tab to the URL
 * another tab captured. See: php artisan rsx:man login_redirect.
 *
 * All validation is centralized here (nowhere else) so an application developer
 * cannot introduce an open redirect or a malformed value. Beyond the structural
 * open-redirect guards, the validator also drops a no-op bare landing root (the
 * default post-login destination, carried with no query) and any target not
 * handled by a registered GET route in the active context (a route-registration
 * check, not a 404 or permission probe). The four public calls form the wiring
 * idiom:
 *
 *   - capture(Request)  at the rejection point (main.php / dispatcher)
 *   - params()          merged into every route built inside the login flow
 *   - hidden_input()    dropped into every login-flow Blade form
 *   - consume($default) once, at the terminal post-login destination decision
 *
 * The PHP/JS parity convention applies: an identical-API JS mirror lives at
 * app/RSpade/Core/Js/Login_Redirect.js.
 *
 * PORTAL CONTEXT SENSITIVITY:
 * The same four calls serve the staff app and the client portal unchanged. The
 * class reads Rsx_Portal::is_portal_request() and adapts its page-path and
 * loop-prevention rules to the active context. In portal prefix mode (no
 * dedicated portal domain) a return target must live under the portal prefix
 * (config('rsx.portal.prefix')); the prefix is stripped before the base non-page
 * and exclusion rules apply. In portal domain mode portal paths are unprefixed
 * and the base rules apply directly. Staff and portal targets are isolated in
 * both directions: a staff redirect can never point under the portal prefix, and
 * a portal redirect can never point outside it. Portal exclusions come from
 * config('rsx.login_redirect.portal_excluded_prefixes'), expressed in
 * portal-namespace (unprefixed) terms.
 *
 * SILENT-DEGRADATION DIVERGENCE FROM FAIL-LOUD (CR 2026-07-22, deliberate):
 * The validator and every read path silently degrade an invalid/tampered/hostile
 * value to []/the default destination - they NEVER throw and NEVER flash an error.
 * This is a documented, CR-mandated exception to the framework's fail-loud rule:
 * a hostile ?redirect= must reproduce exactly the pre-feature behavior (the user
 * lands on the dashboard), not surface an error to a would-be attacker or a user
 * who merely followed a stale link. The silent degradation is scoped to this
 * class's read/validate paths only; everything else stays fail-loud.
 */
class Login_Redirect
{
    /**
     * The threaded query-parameter name. PRIVATE - nothing outside this class
     * ever types the string; the hidden-input name attribute derives from it too.
     */
    private const PARAM = 'redirect';

    /**
     * Maximum accepted length of a redirect value. Over-length is treated as
     * invalid (a redirect target this long is not a legitimate page URL).
     */
    private const MAX_LENGTH = 2000;

    /**
     * Capture the current request as a return-to target, used at the auth
     * rejection point.
     *
     * Returns ['redirect' => '/path?query'] only when the request is a plain GET
     * page request worth returning to; [] for POST, XHR/Ajax requests, framework
     * internal / asset / Ajax-endpoint routes (/_...), external API routes
     * (/api/...), and any excluded login-flow route (loop prevention). The
     * page-path and exclusion rules are context-aware: in a portal request the
     * portal prefix is a page namespace (prefix mode) and the portal exclusion
     * list applies - see _is_non_page_path_in_context() / _excluded_prefixes().
     *
     * Usage in an app's main.php:
     *     return redirect(Rsx::Route('Login_Controller', Login_Redirect::capture($request)));
     *
     * Usage in a portal_main.php pre_dispatch (identical idiom):
     *     return redirect(Rsx_Portal::Route('Portal_Login_Controller', Login_Redirect::capture($request)));
     */
    public static function capture(Request $request): array
    {
        // Only plain GET page navigations are worth returning to.
        if (!$request->isMethod('get')) {
            return [];
        }

        // XHR / fetch requests are not page navigations.
        if ($request->ajax()) {
            return [];
        }

        // Normalize the requested path to a single leading slash. request()->path()
        // strips the leading slash and returns '/' for root.
        $path = '/' . ltrim($request->path(), '/');

        // Framework-internal, asset, Ajax-endpoint (/_...) and external API
        // (/api/...) routes are never legitimate return targets. Context-aware:
        // in portal prefix mode the portal prefix is a page namespace, so
        // <prefix>/... is a legitimate target while the portal's own non-page
        // sub-paths (<prefix>/_..., <prefix>/api/...) stay rejected.
        if (static::_is_non_page_path_in_context($path)) {
            return [];
        }

        // Preserve the original (already URL-encoded) query string verbatim.
        $query = $request->getQueryString();
        $target = $path . (($query !== null && $query !== '') ? '?' . $query : '');

        $valid = static::_validate($target);

        return $valid === null ? [] : [self::PARAM => $valid];
    }

    /**
     * Read + validate the threaded redirect value from the current request (GET
     * query or POST body), for merging into a login-flow route.
     *
     * Returns ['redirect' => $value] when valid, [] otherwise. Because Rsx::Route()
     * URL-encodes extra params, a nested query string in the value survives hops:
     *     Rsx::Route('Login_2fa_Controller::index', ['token' => $t] + Login_Redirect::params())
     */
    public static function params(): array
    {
        $value = static::_read_request_value();
        $valid = static::_validate($value);

        return $valid === null ? [] : [self::PARAM => $valid];
    }

    /**
     * Emit the escaped hidden form input carrying the threaded redirect value, for
     * Blade login-flow forms. Returns '' when no valid value is present. The form
     * POST hop is the one most likely to be dropped when wired by hand; this call
     * kills that failure mode.
     */
    public static function hidden_input(): string
    {
        $params = static::params();
        if (empty($params)) {
            return '';
        }

        return '<input type="hidden" name="' . e(self::PARAM) . '" value="' . e($params[self::PARAM]) . '">';
    }

    /**
     * Terminal call: the validated redirect target, or $default when absent or
     * invalid. Used exactly once, at the final-destination decision after
     * successful authentication (welcome/onboarding funnels still win).
     */
    public static function consume(string $default): string
    {
        $params = static::params();

        return $params[self::PARAM] ?? $default;
    }

    /**
     * Context-aware page-path predicate. Wraps the base rules with the portal
     * namespace rule when the current request is a portal request:
     *
     *   - staff context: the base rules apply to $path directly.
     *   - portal domain mode: portal paths are unprefixed; base rules apply
     *     directly.
     *   - portal prefix mode: $path MUST be exactly the portal prefix or start
     *     with prefix + '/' (anything else is not a portal page - reject). The
     *     prefix is stripped and the base rules apply to the remainder (empty
     *     remainder counts as '/'), so <prefix>/_... and <prefix>/api/... reject
     *     while <prefix>/page passes.
     */
    private static function _is_non_page_path_in_context(string $path): bool
    {
        if (!Rsx_Portal::is_portal_request() || Rsx_Portal::has_dedicated_domain()) {
            return static::_is_non_page_path($path);
        }

        // Portal prefix mode: the target must live under the portal prefix.
        $prefix = Rsx_Portal::get_prefix();
        if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
            return true;
        }

        $remainder = substr($path, strlen($prefix));
        if ($remainder === '') {
            $remainder = '/';
        }

        return static::_is_non_page_path($remainder);
    }

    /**
     * The portal-namespace-relative path used for exclusion matching. In portal
     * prefix mode the portal prefix is stripped (empty remainder counts as '/');
     * in every other context the path is returned unchanged. Callers reach this
     * only after _is_non_page_path_in_context() has confirmed the path is a valid
     * page path, so in prefix mode the prefix is guaranteed present.
     */
    private static function _namespace_path(string $path): string
    {
        if (!Rsx_Portal::is_portal_request() || Rsx_Portal::has_dedicated_domain()) {
            return $path;
        }

        $prefix = Rsx_Portal::get_prefix();
        if (!str_starts_with($path, $prefix)) {
            return $path;
        }

        $remainder = substr($path, strlen($prefix));

        return $remainder === '' ? '/' : $remainder;
    }

    /**
     * Whether a path is a framework-internal, asset, Ajax-endpoint or external API
     * route - never a legitimate page return target. Base rules only; portal
     * context is layered on by _is_non_page_path_in_context().
     */
    private static function _is_non_page_path(string $path): bool
    {
        // All framework internal / asset / Ajax-endpoint routes are underscore-led:
        // /_ajax, /_vendor, /_compiled, /_preview, /_ide.
        if (str_starts_with($path, '/_')) {
            return true;
        }

        // External REST API routes (/api/vN/...).
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return true;
        }

        return false;
    }

    /**
     * Read the raw redirect value from the current request (GET query or POST body).
     */
    private static function _read_request_value()
    {
        return request()->input(self::PARAM);
    }

    /**
     * The single redirect sanitizer in the codebase. Accepts only a local path
     * (single leading '/') with an optional query string; returns the value
     * verbatim when valid, or null. NEVER throws (silent-degradation mandate).
     *
     * Rejects: non-strings, empty, over-length, protocol-relative ('//...'),
     * any scheme (':' before the first '/'), backslashes, control characters /
     * newlines, fragments ('#' anywhere - the whole value is rejected, not
     * truncated), excluded login-flow prefixes (loop prevention), the no-op bare
     * landing root (namespace path '/' with no query string - a just-authenticated
     * user lands there anyway; a bare root WITH a query is kept), and targets that
     * are not routable in the active context (no REGISTERED GET route handles the
     * URL - staff Dispatcher or portal Portal_Dispatcher). The routability gate
     * confirms only that a route pattern exists; it is NOT a record-existence (404)
     * probe and NOT an authorization check, and matches on GET only.
     */
    private static function _validate($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        // Control characters and newlines (anything below 0x20, plus DEL 0x7f).
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        // Backslashes (browsers may normalize '\' to '/', enabling '/\evil.com').
        if (str_contains($value, '\\')) {
            return null;
        }

        // Fragments are unsupported: reject the whole value (do not truncate).
        if (str_contains($value, '#')) {
            return null;
        }

        // Must be a local absolute path: exactly one leading slash.
        if ($value[0] !== '/' || str_starts_with($value, '//')) {
            return null;
        }

        // No scheme: reject any ':' appearing before the first '/'. The value
        // starts with '/', so the segment before the first slash is empty and this
        // holds structurally; the explicit guard documents the invariant and
        // catches any future relaxation of the leading-slash rule.
        $first_slash = strpos($value, '/');
        if ($first_slash > 0 && str_contains(substr($value, 0, $first_slash), ':')) {
            return null;
        }

        $path_only = strtok($value, '?');

        // Framework-internal / asset / Ajax-endpoint / external API paths are
        // never valid return targets - applied here too (not only in capture())
        // so a hand-threaded ?redirect= meets the same bar as a captured one.
        // Context-aware: closes cross-context isolation (a staff target may not
        // point under the portal prefix; a portal target may not point outside).
        if (static::_is_non_page_path_in_context($path_only)) {
            return null;
        }

        // Loop prevention: reject targets that are themselves login-flow routes.
        // The exclusion list is namespace-relative, so in portal prefix mode the
        // portal prefix is stripped before the comparison.
        $namespace_path = static::_namespace_path($path_only);
        foreach (static::_excluded_prefixes() as $prefix) {
            if ($namespace_path === $prefix || str_starts_with($namespace_path, $prefix . '/')) {
                return null;
            }
        }

        // No-op redirect: the bare landing root (staff '/' or the portal prefix
        // root, both reduced to namespace path '/') is where a just-authenticated
        // user lands anyway, so threading it back adds nothing but URL noise.
        // A bare root WITH a query string still carries intent and is kept.
        if ($namespace_path === '/' && !str_contains($value, '?')) {
            return null;
        }

        // Routability: the target must be handled by a REGISTERED route in the
        // active context - staff PHP/SPA routes (Dispatcher) or portal routes
        // (Portal_Dispatcher). A structurally-valid path that no route pattern
        // handles would dead-end post-login, so degrade to the default instead.
        // This only confirms a route is registered for the URL - NOT that the
        // requested record exists (no 404 probe) and NOT that this user is
        // authorized (no permission probe). GET only (capture already rejects
        // non-GET), so a POST-only endpoint reads as "not a page to return to".
        $routable = Rsx_Portal::is_portal_request()
            ? Portal_Dispatcher::resolve_url_to_route($path_only, 'GET')
            : Dispatcher::resolve_url_to_route($path_only, 'GET');
        if ($routable === null) {
            return null;
        }

        return $value;
    }

    /**
     * The login-flow route prefixes a redirect target may never point at, matched
     * against the namespace-relative path. Staff default ['/login', '/logout']
     * (config rsx.login_redirect.excluded_prefixes). Portal default covers the
     * framework's shipped portal auth routes, expressed in portal-namespace
     * (unprefixed) terms (config rsx.login_redirect.portal_excluded_prefixes).
     */
    private static function _excluded_prefixes(): array
    {
        if (Rsx_Portal::is_portal_request()) {
            return config('rsx.login_redirect.portal_excluded_prefixes', [
                '/login', '/logout', '/register', '/password/reset', '/request-access', '/impersonate',
            ]);
        }

        return config('rsx.login_redirect.excluded_prefixes', ['/login', '/logout']);
    }
}
