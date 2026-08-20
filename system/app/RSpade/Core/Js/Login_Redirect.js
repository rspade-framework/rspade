/**
 * Login_Redirect - intended-URL ("return to the page I was trying to visit")
 * threading for a multi-hop login flow (JavaScript mirror).
 *
 * Identical-API twin of the PHP App\RSpade\Core\Login\Login_Redirect. The PHP side
 * captures the intended URL at the server-side auth-rejection 302; this JS side is
 * the client-side entry point (a future expired-session Ajax handler): it captures
 * the current browser location and threads/reads the same ?redirect= param through
 * the login flow. See: php artisan rsx:man login_redirect.
 *
 * All validation is centralized here (nowhere else) so an open redirect or a
 * malformed value cannot be introduced. The four public calls mirror the PHP API:
 *
 *   - capture()          at a client-side rejection point (captures window.location)
 *   - params()           merged into login-flow routes / read on arrival
 *   - hidden_input()     dropped into a login-flow form
 *   - consume(default)   once, at the terminal post-login destination decision
 *
 * SILENT-DEGRADATION DIVERGENCE FROM FAIL-LOUD (deliberate, CR 2026-07-22):
 * the validator and every read path silently degrade an invalid/tampered/hostile
 * value to {}/the default - never throwing. A hostile ?redirect= must reproduce the
 * pre-feature behavior (land on the dashboard), not surface an error.
 *
 * PORTAL CONTEXT SENSITIVITY (mirrors the PHP twin): the validator reads
 * Rsx_Portal.is_portal() and adapts its page-path and loop-prevention rules. In
 * portal prefix mode (no dedicated portal domain) a return target must live under
 * the portal prefix (Rsx_Portal.get_prefix()); the prefix is stripped before the
 * base non-page and exclusion rules apply. In portal domain mode portal paths are
 * unprefixed and the base rules apply directly. Staff and portal targets are
 * isolated in both directions. Portal exclusions read
 * window.rsxapp.login_redirect.portal_excluded_prefixes (namespace-relative).
 */
class Login_Redirect {

    /**
     * The threaded query-parameter name. Private - nothing outside this class ever
     * types the string; the hidden-input name attribute derives from it too.
     * @type {string}
     */
    static #PARAM = 'redirect';

    /**
     * Maximum accepted length of a redirect value.
     * @type {number}
     */
    static #MAX_LENGTH = 2000;

    /**
     * Capture the current browser location as a return-to target, used at a
     * client-side rejection point (e.g. an expired-session Ajax handler).
     *
     * Returns {redirect: '/path?query'} when the current location is a valid local
     * page target, or {} otherwise (login-flow routes are rejected - loop prevention).
     *
     * @returns {Object}
     */
    static capture() {
        const target = window.location.pathname + window.location.search;
        const valid = Login_Redirect.#validate(target);

        return valid === null ? {} : { [Login_Redirect.#PARAM]: valid };
    }

    /**
     * Read + validate the threaded redirect value from the current page URL's
     * ?redirect=, for merging into a login-flow route.
     *
     * @returns {Object} {redirect: value} when valid, {} otherwise.
     */
    static params() {
        const value = new URL(window.location.href).searchParams.get(Login_Redirect.#PARAM);
        const valid = Login_Redirect.#validate(value);

        return valid === null ? {} : { [Login_Redirect.#PARAM]: valid };
    }

    /**
     * Emit the escaped hidden form input carrying the threaded redirect value, or
     * '' when no valid value is present.
     *
     * @returns {string}
     */
    static hidden_input() {
        const params = Login_Redirect.params();
        const value = params[Login_Redirect.#PARAM];
        if (!value) {
            return '';
        }

        return '<input type="hidden" name="' + html(Login_Redirect.#PARAM) + '" value="' + html(value) + '">';
    }

    /**
     * Terminal call: the validated redirect target, or default_url when absent or
     * invalid.
     *
     * @param {string} default_url
     * @returns {string}
     */
    static consume(default_url) {
        const params = Login_Redirect.params();

        return params[Login_Redirect.#PARAM] || default_url;
    }

    /**
     * The single redirect sanitizer (JS side). Accepts only a local path (single
     * leading '/') with an optional query string; returns the value verbatim when
     * valid, or null. NEVER throws (silent-degradation mandate).
     *
     * @param {*} value
     * @returns {string|null}
     */
    static #validate(value) {
        if (typeof value !== 'string' || value === '') {
            return null;
        }

        if (value.length > Login_Redirect.#MAX_LENGTH) {
            return null;
        }

        // Control characters and newlines (below 0x20, plus DEL 0x7f).
        if (/[\x00-\x1f\x7f]/.test(value)) {
            return null;
        }

        // Backslashes (browsers may normalize '\' to '/').
        if (value.includes('\\')) {
            return null;
        }

        // Fragments are unsupported: reject the whole value (do not truncate).
        if (value.includes('#')) {
            return null;
        }

        // Must be a local absolute path: exactly one leading slash.
        if (value[0] !== '/' || value.startsWith('//')) {
            return null;
        }

        // No scheme: reject any ':' before the first '/'. Structurally holds given
        // the leading-slash rule; the explicit guard documents the invariant.
        const first_slash = value.indexOf('/');
        if (first_slash > 0 && value.slice(0, first_slash).includes(':')) {
            return null;
        }

        const path_only = value.split('?')[0];

        // Framework-internal / asset / Ajax-endpoint / external API paths are never
        // valid return targets. Context-aware: in portal prefix mode the portal
        // prefix is a page namespace, so <prefix>/page passes while <prefix>/_...
        // and <prefix>/api/... reject; a staff target may not point under the
        // portal prefix, and a portal target may not point outside it.
        if (Login_Redirect.#is_non_page_path_in_context(path_only)) {
            return null;
        }

        // Loop prevention: reject targets that are themselves login-flow routes.
        // The exclusion list is namespace-relative, so in portal prefix mode the
        // portal prefix is stripped before the comparison.
        const namespace_path = Login_Redirect.#namespace_path(path_only);
        for (const prefix of Login_Redirect.#excluded_prefixes()) {
            if (namespace_path === prefix || namespace_path.startsWith(prefix + '/')) {
                return null;
            }
        }

        // No-op redirect: the bare landing root (staff '/' or the portal prefix
        // root, both reduced to namespace path '/') is where a just-authenticated
        // user lands anyway, so threading it back adds nothing but URL noise.
        // A bare root WITH a query string still carries intent and is kept.
        // (The PHP mirror additionally applies a routability gate, which is
        // inherently server-side and has no client-side equivalent here.)
        if (namespace_path === '/' && !value.includes('?')) {
            return null;
        }

        return value;
    }

    /**
     * Context-aware page-path predicate. Wraps the base rules with the portal
     * namespace rule when the current page is a portal page (mirror of the PHP
     * _is_non_page_path_in_context()).
     *
     * @param {string} path
     * @returns {boolean}
     */
    static #is_non_page_path_in_context(path) {
        if (!Rsx_Portal.is_portal() || Rsx_Portal.has_dedicated_domain()) {
            return Login_Redirect.#is_non_page_path(path);
        }

        // Portal prefix mode: the target must live under the portal prefix.
        const prefix = Rsx_Portal.get_prefix();
        if (path !== prefix && !path.startsWith(prefix + '/')) {
            return true;
        }

        let remainder = path.slice(prefix.length);
        if (remainder === '') {
            remainder = '/';
        }

        return Login_Redirect.#is_non_page_path(remainder);
    }

    /**
     * The portal-namespace-relative path used for exclusion matching. In portal
     * prefix mode the portal prefix is stripped (empty remainder counts as '/');
     * in every other context the path is returned unchanged.
     *
     * @param {string} path
     * @returns {string}
     */
    static #namespace_path(path) {
        if (!Rsx_Portal.is_portal() || Rsx_Portal.has_dedicated_domain()) {
            return path;
        }

        const prefix = Rsx_Portal.get_prefix();
        if (!path.startsWith(prefix)) {
            return path;
        }

        const remainder = path.slice(prefix.length);

        return remainder === '' ? '/' : remainder;
    }

    /**
     * Whether a path is a framework-internal, asset, Ajax-endpoint or external API
     * route - never a legitimate page return target. Base rules only; portal
     * context is layered on by #is_non_page_path_in_context().
     *
     * @param {string} path
     * @returns {boolean}
     */
    static #is_non_page_path(path) {
        // All framework internal / asset / Ajax-endpoint routes are underscore-led.
        if (path.startsWith('/_')) {
            return true;
        }

        // External REST API routes (/api/vN/...).
        if (path === '/api' || path.startsWith('/api/')) {
            return true;
        }

        return false;
    }

    /**
     * The login-flow route prefixes a redirect target may never point at, matched
     * against the namespace-relative path. Surfaced from PHP config via
     * window.rsxapp when present. Staff default ['/login', '/logout']; portal
     * default covers the framework's shipped portal auth routes, expressed in
     * portal-namespace (unprefixed) terms.
     *
     * @returns {string[]}
     */
    static #excluded_prefixes() {
        if (Rsx_Portal.is_portal()) {
            const portal_configured = window.rsxapp?.login_redirect?.portal_excluded_prefixes;

            return Array.isArray(portal_configured) && portal_configured.length
                ? portal_configured
                : ['/login', '/logout', '/register', '/password/reset', '/request-access', '/impersonate'];
        }

        const configured = window.rsxapp?.login_redirect?.excluded_prefixes;

        return Array.isArray(configured) && configured.length ? configured : ['/login', '/logout'];
    }
}
