// @ROUTE-EXISTS-01-EXCEPTION - This file contains documentation examples with fictional route names

/**
 * Rsx_Portal - Client Portal JavaScript Runtime Utilities
 *
 * Provides static utility methods for client portal JavaScript code including
 * route generation and portal context detection.
 *
 * Key differences from Rsx:
 * - Route() prepends portal domain/prefix
 * - Portal-specific context detection
 * - Simpler (no event system, fewer utilities)
 *
 * Usage Examples:
 * ```javascript
 * // Check if in portal context
 * if (Rsx_Portal.is_portal()) { ... }
 *
 * // Route generation (applies portal prefix/domain)
 * const url = Rsx_Portal.Route('Portal_Dashboard_Action');
 * // Development: /_portal/dashboard
 * // Production: /dashboard (on portal domain)
 *
 * // Route with parameters
 * const url = Rsx_Portal.Route('Portal_Project_View_Action', 123);
 * // Development: /_portal/projects/123
 * ```
 *
 * @static
 * @global
 */
class Rsx_Portal {
    /**
     * URL prefix for portal when no dedicated domain configured
     * Must match PHP Rsx_Portal::URL_PREFIX
     */
    static URL_PREFIX = '/_portal';

    /**
     * Storage for portal route definitions loaded from bundles
     */
    static _routes = {};

    /**
     * Cached portal context detection result
     */
    static _is_portal = null;

    // =========================================================================
    // Configuration Methods
    // =========================================================================

    /**
     * Get the configured portal domain
     *
     * @returns {string|null} Domain if configured, null otherwise
     */
    static get_domain() {
        return window.rsxapp?.portal?.domain || null;
    }

    /**
     * Get the portal URL prefix (used when no domain configured)
     *
     * @returns {string} The prefix, defaults to '/_portal'
     */
    static get_prefix() {
        return window.rsxapp?.portal?.prefix || Rsx_Portal.URL_PREFIX;
    }

    /**
     * Check if portal is using a dedicated domain (vs URL prefix)
     *
     * @returns {boolean} True if dedicated domain is configured
     */
    static has_dedicated_domain() {
        return !!Rsx_Portal.get_domain();
    }

    // =========================================================================
    // Portal Context Detection
    // =========================================================================

    /**
     * Check if currently in portal context
     *
     * @returns {boolean} True if this is a portal page
     */
    static is_portal() {
        if (Rsx_Portal._is_portal !== null) {
            return Rsx_Portal._is_portal;
        }

        // Check rsxapp flag (set by portal bootstrap)
        Rsx_Portal._is_portal = !!window.rsxapp?.is_portal;

        return Rsx_Portal._is_portal;
    }

    /**
     * Get the current portal user
     *
     * @returns {Object|null} Portal user data or null if not logged in
     */
    static user() {
        if (!Rsx_Portal.is_portal()) {
            return null;
        }
        return window.rsxapp?.user || null;
    }

    /**
     * Whether the current portal session is a staff impersonation session.
     * UI affordance only - the server (Portal_Session::is_impersonating()) is the
     * enforcement boundary, and the APPLICATION is responsible for the read-only
     * experience. See: php artisan rsx:man portal.
     *
     * @returns {boolean}
     */
    static is_impersonating() {
        return !!window.rsxapp?.portal?.is_impersonating;
    }

    /**
     * The staff user id impersonating in the current session, or null.
     *
     * @returns {number|null}
     */
    static impersonator_user_id() {
        return window.rsxapp?.portal?.impersonator_user_id || null;
    }

    // =========================================================================
    // Internal Endpoints
    // =========================================================================

    /**
     * Rebase a framework INTERNAL endpoint path (/_ajax/..., /_ajax/_batch,
     * /_upload) onto the base the current page is served under.
     *
     * The framework serves each internal endpoint on BOTH channels: the staff
     * dispatcher answers the bare path, and Portal_Dispatcher answers it under the
     * portal's own base. Which one a page must call is decided entirely by the page
     * itself, using the same two facts Rsx_Portal.Route() uses:
     *
     *   staff page                -> unchanged  (/_ajax/Foo/bar)
     *   portal page, domain mode  -> unchanged  (the portal HOST already routes to
     *                                            Portal_Dispatcher; adding the prefix
     *                                            would produce a doubled portal path)
     *   portal page, prefix mode  -> prefixed   (/_portal/_ajax/Foo/bar)
     *
     * Calling the bare path from a portal page is what made portal Ajax a STAFF
     * request: the portal session's realm, CSRF token and portal_fetch() contract
     * were all resolved wrongly. Every internal-endpoint URL the client builds goes
     * through here.
     *
     * @param {string} path An internal endpoint path beginning with '/'
     * @returns {string} The path to request from this page
     */
    static internal_url(path) {
        if (!Rsx_Portal.is_portal()) {
            return path;
        }

        return Rsx_Portal._apply_portal_base(path);
    }

    // =========================================================================
    // Route Generation
    // =========================================================================

    /**
     * Generate URL for a portal route
     *
     * Similar to Rsx.Route() but:
     * - Returns URLs with portal domain or prefix
     * - Only works with portal routes
     *
     * Usage examples:
     * ```javascript
     * // Portal action route
     * const url = Rsx_Portal.Route('Portal_Dashboard_Action');
     * // Development: /_portal/dashboard
     *
     * // Route with integer parameter (sets 'id')
     * const url = Rsx_Portal.Route('Portal_Project_View_Action', 123);
     * // Development: /_portal/projects/123
     *
     * // Route with named parameters
     * const url = Rsx_Portal.Route('Portal_Project_View_Action', {id: 123, tab: 'files'});
     * // Development: /_portal/projects/123?tab=files
     *
     * // Placeholder route
     * const url = Rsx_Portal.Route('Future_Portal_Feature::#index');
     * // Returns: #
     * ```
     *
     * @param {string} action Controller class, SPA action, or "Class::method"
     * @param {number|Object} [params=null] Route parameters
     * @returns {string} The generated URL (includes portal prefix in dev mode)
     */
    static Route(action, params = null) {
        // Parse action into class_name and action_name
        let class_name, action_name;
        if (action.includes('::')) {
            [class_name, action_name] = action.split('::', 2);
        } else {
            class_name = action;
            action_name = 'index';
        }

        // Normalize params to object
        let params_obj = {};
        if (typeof params === 'number') {
            params_obj = { id: params };
        } else if (typeof params === 'string' && /^\d+$/.test(params)) {
            params_obj = { id: parseInt(params, 10) };
        } else if (params && typeof params === 'object') {
            params_obj = params;
        } else if (params !== null && params !== undefined) {
            throw new Error('Params must be number, object, or null');
        }

        // Placeholder route: action starts with # means unimplemented/scaffolding
        if (action_name.startsWith('#')) {
            return '#';
        }

        // Check if route exists in portal route definitions
        let pattern = null;

        if (Rsx_Portal._routes[class_name] && Rsx_Portal._routes[class_name][action_name]) {
            const route_patterns = Rsx_Portal._routes[class_name][action_name];
            pattern = Rsx_Portal._select_best_route_pattern(route_patterns, params_obj);

            if (!pattern) {
                const route_list = route_patterns.join(', ');
                throw new Error(
                    `No suitable portal route found for ${class_name}::${action_name} with provided parameters. ` +
                    `Available routes: ${route_list}`
                );
            }
        } else {
            // Not found in portal routes - try SPA action route
            pattern = Rsx_Portal._try_spa_action_route(class_name, params_obj);

            if (!pattern) {
                throw new Error(
                    `Portal route not found for ${action}. ` +
                    `Ensure the class has a @portal_route decorator.`
                );
            }
        }

        // Generate base URL from pattern
        const path = Rsx_Portal._generate_url_from_pattern(pattern, params_obj);

        // Apply portal prefix (in dev mode) or return as-is (domain mode)
        return Rsx_Portal._apply_portal_base(path);
    }

    /**
     * Apply portal domain or prefix to a path
     *
     * @param {string} path The route path (e.g., '/dashboard')
     * @returns {string} Path with portal prefix (dev) or plain path (domain mode)
     * @private
     */
    static _apply_portal_base(path) {
        // If using dedicated domain, path is relative to that domain
        if (Rsx_Portal.has_dedicated_domain()) {
            return path;
        }

        // Development mode: prepend prefix
        const prefix = Rsx_Portal.get_prefix();
        return prefix + path;
    }

    /**
     * Select the best matching route pattern from available patterns
     *
     * @param {Array<string>} patterns Array of route patterns
     * @param {Object} params_obj Provided parameters
     * @returns {string|null} Selected pattern or null if none match
     * @private
     */
    static _select_best_route_pattern(patterns, params_obj) {
        const satisfiable = [];

        for (const pattern of patterns) {
            // Extract required parameters from pattern
            const required_params = [];
            const matches = pattern.match(/:([a-zA-Z_][a-zA-Z0-9_]*)/g);
            if (matches) {
                for (const match of matches) {
                    required_params.push(match.substring(1));
                }
            }

            // Check if all required parameters are provided
            let can_satisfy = true;
            for (const required of required_params) {
                if (!(required in params_obj)) {
                    can_satisfy = false;
                    break;
                }
            }

            if (can_satisfy) {
                satisfiable.push({
                    pattern: pattern,
                    param_count: required_params.length
                });
            }
        }

        if (satisfiable.length === 0) {
            return null;
        }

        // Sort by parameter count descending (most parameters first)
        satisfiable.sort((a, b) => b.param_count - a.param_count);

        return satisfiable[0].pattern;
    }

    /**
     * Generate URL from route pattern by replacing parameters
     *
     * @param {string} pattern The route pattern (e.g., '/projects/:id')
     * @param {Object} params Parameters to fill into the route
     * @returns {string} The generated URL
     * @private
     */
    static _generate_url_from_pattern(pattern, params) {
        // Extract required parameters from the pattern
        const required_params = [];
        const matches = pattern.match(/:([a-zA-Z_][a-zA-Z0-9_]*)/g);
        if (matches) {
            for (const match of matches) {
                required_params.push(match.substring(1));
            }
        }

        // Check for required parameters
        const missing = [];
        for (const required of required_params) {
            if (!(required in params)) {
                missing.push(required);
            }
        }

        if (missing.length > 0) {
            throw new Error(`Required parameters [${missing.join(', ')}] are missing for portal route ${pattern}`);
        }

        // Build the URL by replacing parameters
        let url = pattern;
        const used_params = {};

        for (const param_name of required_params) {
            const value = params[param_name];
            const encoded_value = encodeURIComponent(value);
            url = url.replace(':' + param_name, encoded_value);
            used_params[param_name] = true;
        }

        // Collect extra parameters for query string
        const internal_params = ['_loader_title_hint'];
        const query_params = {};
        for (const key in params) {
            if (!used_params[key] && !internal_params.includes(key)) {
                query_params[key] = params[key];
            }
        }

        // Append query string if there are extra parameters
        if (Object.keys(query_params).length > 0) {
            const query_string = Object.entries(query_params)
                .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
                .join('&');
            url += '?' + query_string;
        }

        return url;
    }

    /**
     * Try to find a route pattern for a portal SPA action class
     *
     * @param {string} class_name The action class name
     * @param {Object} params_obj The parameters for route selection
     * @returns {string|null} The route pattern or null
     * @private
     */
    static _try_spa_action_route(class_name, params_obj) {
        // Get all classes from manifest
        const all_classes = Manifest.get_all_classes();

        // Find the class by name
        for (const class_info of all_classes) {
            if (class_info.class_name === class_name) {
                const class_object = class_info.class_object;

                // Check if it's a SPA action with portal routes
                if (typeof Spa_Action !== 'undefined' &&
                    class_object.prototype instanceof Spa_Action) {

                    // Get route patterns from decorator metadata
                    // Portal SPA actions use @route() decorator (stores in _spa_routes)
                    // and @portal_spa() decorator (sets _is_portal_spa = true)
                    const routes = class_object._spa_routes || [];

                    // Only match if this is a portal SPA action
                    if (routes.length > 0 && class_object._is_portal_spa) {
                        const selected = Rsx_Portal._select_best_route_pattern(routes, params_obj);

                        if (!selected) {
                            throw new Error(
                                `No suitable portal route found for SPA action ${class_name} with provided parameters. ` +
                                `Available routes: ${routes.join(', ')}`
                            );
                        }

                        return selected;
                    }
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Define portal routes from bundled data
     * Called by generated JavaScript in portal bundles
     *
     * @param {Object} routes Route definitions object
     */
    static _define_routes(routes) {
        for (const class_name in routes) {
            if (!Rsx_Portal._routes[class_name]) {
                Rsx_Portal._routes[class_name] = {};
            }
            for (const method_name in routes[class_name]) {
                Rsx_Portal._routes[class_name][method_name] = routes[class_name][method_name];
            }
        }
    }

    /**
     * Clear cached state (for testing)
     */
    static _clear_cache() {
        Rsx_Portal._is_portal = null;
        Rsx_Portal._routes = {};
    }
}
