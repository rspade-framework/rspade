/**
 * Spa - Single Page Application orchestrator for RSpade
 *
 * This class manages the Spa lifecycle:
 * - Auto-discovers action classes extending Spa_Action
 * - Extracts route information from decorator metadata
 * - Registers routes with the router
 * - Dispatches to the current URL
 *
 * Initialization happens automatically during the on_app_init phase when
 * window.rsxapp.is_spa === true.
 *
 * Unlike JQHTML, this is a static class (not a Component) following the RS3 pattern.
 */
class Spa {
    // Registered routes: { pattern: action_class }
    static routes = {};

    // Current layout instance
    static layout = null;

    // Current action instance (use Spa.action() to access)
    static _action = null;

    // Current route instance
    static route = null;

    // Current route 'params'
    static params = null;

    // Flag to prevent re-entrant dispatch
    static is_dispatching = false;

    // Pending redirect that occurred during dispatch (e.g., in action on_load)
    static pending_redirect = null;

    // Flag to track if SPA is enabled (can be disabled on errors or dirty forms)
    static _spa_enabled = true;

    // Timer ID for 30-minute auto-disable
    static _spa_timeout_timer = null;

    // Flag to track if initial load is complete (for session validation)
    static _initial_load_complete = false;

    // Timestamp of last navigation start - used for error suppression grace period
    static _navigation_timestamp = 0;

    // Grace period in milliseconds for suppressing errors after navigation
    static NAVIGATION_GRACE_PERIOD_MS = 10000;

    // The registered leave-guard callback: () => string|null|false. Returns a
    // confirmation message to block/prompt navigation, or a falsy value to allow it.
    static _leave_guard = null;

    // URL of the page currently showing, captured once a navigation actually
    // completes. Used to restore the address bar if a Back/Forward navigation is
    // vetoed (the browser has already changed window.location by the time popstate
    // fires, so cancelling requires explicitly pushing the prior URL back).
    static _last_committed_url = null;

    // Whether the window.beforeunload guard listener has been installed (once per page load)
    static _beforeunload_installed = false;

    /**
     * Get the current action component instance
     *
     * Returns the cached action if available, otherwise finds it from the DOM.
     * This method should be used instead of direct property access to ensure
     * the action is always found even if the cache was cleared.
     *
     * @returns {Spa_Action|null} The current action instance, or null if none
     */
    static action() {
        if (Spa._action) {
            return Spa._action;
        }

        const $spa_action = $('.Spa_Action').first();
        if (!$spa_action.exists()) {
            return null;
        }

        Spa._action = $spa_action.component();
        return Spa._action;
    }

    /**
     * Check if we're within the navigation grace period
     *
     * During the 10 seconds after navigation starts, pending AJAX requests from the
     * previous page may error out (because their context is gone). These errors should
     * not be shown to the user as they're not relevant to the new page.
     *
     * @returns {boolean} True if within grace period, false otherwise
     */
    static is_within_navigation_grace_period() {
        if (Spa._navigation_timestamp === 0) {
            return false;
        }
        return (Date.now() - Spa._navigation_timestamp) < Spa.NAVIGATION_GRACE_PERIOD_MS;
    }

    /**
     * Register a leave-guard: a function returning a confirmation message string
     * to block navigation (prompting the user), or a falsy value to allow it.
     * Consulted at the single choke point every navigation passes through -
     * Spa.dispatch() - so link clicks, popstate (Back/Forward), and programmatic
     * dispatch()/redirect() calls are all covered by one registration.
     *
     * Also installs (once, lazily, idempotently) a window.beforeunload handler so
     * native browser navigation (refresh, tab close, typed URL, external link) gets
     * the same coverage automatically - no separate addEventListener needed by the
     * caller.
     *
     * Auto-cleared once a navigation it approved completes (see clear_leave_guard),
     * so a stale guard can never outlive the editor that registered it - a fresh
     * action must re-register if it wants a guard.
     *
     * @param {function(): (string|null|false)} fn
     */
    static set_leave_guard(fn) {
        Spa._leave_guard = fn;
        Spa._install_beforeunload_guard();
    }

    /**
     * Clear the registered leave-guard, if any.
     */
    static clear_leave_guard() {
        Spa._leave_guard = null;
    }

    /**
     * Lazily install a single beforeunload listener (once per page load) that
     * consults Spa._leave_guard fresh on every unload attempt - a no-op when no
     * guard is registered. Private.
     */
    static _install_beforeunload_guard() {
        if (Spa._beforeunload_installed) {
            return;
        }
        Spa._beforeunload_installed = true;
        window.addEventListener('beforeunload', (e) => {
            if (!Spa._leave_guard) {
                return;
            }
            const message = Spa._leave_guard();
            if (message) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    /**
     * Disable SPA navigation - all navigation becomes full page loads
     * Call this when errors occur or forms are dirty
     */
    static disable() {
        console.warn('[Spa] SPA navigation disabled - browser navigation mode active');
        Spa._spa_enabled = false;
    }

    /**
     * Re-enable SPA navigation after it was disabled
     * Call this after forms are saved or errors are resolved
     */
    static enable() {
        console.log('[Spa] SPA navigation enabled');
        Spa._spa_enabled = true;
    }

    /**
     * Start 30-minute timeout to auto-disable SPA
     * Prevents users from working with stale code for more than 30 minutes
     */
    static _start_spa_timeout() {
        // 30-minute timeout to auto-disable SPA navigation
        //
        // WHY: When the application is deployed with updated code, users who have the
        // SPA already loaded in their browser will continue using the old JavaScript
        // bundle indefinitely. This can cause:
        // - API mismatches (stale client code calling updated server endpoints)
        // - Missing features or UI changes
        // - Bugs from stale client-side logic
        //
        // FUTURE: A future version of RSpade will use WebSockets to trigger all clients
        // to automatically reload their pages on deploy. However, this timeout serves as
        // a secondary line of defense against:
        // - Failures in the WebSocket notification system
        // - Memory leaks in long-running SPA sessions
        // - Other unforeseen issues that may arise
        // This ensures that users will eventually and periodically get a fresh state,
        // regardless of any other system failures.
        //
        // SOLUTION: After 30 minutes, automatically disable SPA navigation. The next
        // forward navigation (link click, manual dispatch) will do a full page reload,
        // fetching the new bundle. Back/forward buttons continue to work via SPA
        // (force: true) to preserve form state and scroll position.
        //
        // 30 MINUTES: Chosen as a balance between:
        // - Short enough that users don't work with stale code for too long
        // - Long enough that users aren't interrupted during active work sessions
        //
        // TODO: Make this timeout value configurable by developers via:
        // - window.rsxapp.spa_timeout_minutes (set in PHP)
        // - Default to 30 if not specified
        // - Allow 0 to disable timeout entirely (for dev/testing)
        const timeout_ms = 30 * 60 * 1000;

        Spa._spa_timeout_timer = setTimeout(() => {
            console.warn('[Spa] 30-minute timeout reached - disabling SPA navigation');
            Spa.disable();
        }, timeout_ms);

        console_debug('Spa', '30-minute auto-disable timer started');
    }

    /**
     * Framework module initialization hook called during framework boot
     * Only runs when window.rsxapp.is_spa === true
     */
    static _on_framework_modules_init() {
        // Only initialize Spa if we're in a Spa route
        if (!window.rsxapp || !window.rsxapp.is_spa) {
            return;
        }

        console_debug('Spa', 'Initializing Spa system');

        // Start 30-minute auto-disable timer
        Spa._start_spa_timeout();

        // Discover and register all action classes
        Spa.discover_actions();

        // Setup browser integration using History API
        // Note: Navigation API evaluated but not mature enough for production use
        // See: /docs.dev/SPA_BROWSER_INTEGRATION.md for details
        console.log('[Spa] Using History API for browser integration');
        Spa.setup_browser_integration();
    }

    /**
     * SPA init phase - dispatches initial route after all app code has initialized
     *
     * Runs after on_app_init and before on_app_ready. Awaiting ensures the
     * initial action's full lifecycle (on_load, on_ready) completes before
     * on_app_ready fires, so app code in on_app_ready can reference
     * Spa.action(), layout state, etc.
     */
    static async _on_spa_init() {
        if (!window.rsxapp || !window.rsxapp.is_spa) {
            return;
        }

        const initial_url = window.location.pathname + window.location.search + window.location.hash;
        console_debug('Spa', 'Dispatching to initial URL: ' + initial_url);
        await Spa.dispatch(initial_url, { history: 'none' });
    }

    /**
     * Discover all classes extending Spa_Action and register their routes
     */
    static discover_actions() {
        const all_classes = Manifest.get_all_classes();
        let action_count = 0;

        // Per-class try/catch exists ONLY for attribution - every failure is rethrown below
        const failures = [];

        for (const class_info of all_classes) {
            const class_object = class_info.class_object;
            const class_name = class_info.class_name;

            try {
                // Check if this class extends Spa_Action
                if (class_object.prototype instanceof Spa_Action || class_object === Spa_Action) {
                    // Skip the base class itself
                    if (class_object === Spa_Action) {
                        continue;
                    }

                    // Extract route patterns from decorator metadata
                    const routes = class_object._spa_routes || [];

                    if (routes.length === 0) {
                        console.warn(`Spa: Action ${class_name} has no routes defined`);
                        continue;
                    }

                    // Register each route pattern
                    for (const pattern of routes) {
                        Spa.register_route(pattern, class_object);
                        console_debug('Spa', `Registered route: ${pattern} -> ${class_name}`);
                    }

                    action_count++;
                }
            } catch (error) {
                failures.push({ class_name: class_name, error: error });
            }
        }

        Manifest._throw_boot_failures(failures, 'Spa.discover_actions');

        console_debug('Spa', `Discovered ${action_count} action classes`);
    }

    /**
     * Register a route pattern to an action class
     */
    static register_route(pattern, action_class) {
        // Normalize pattern - remove trailing /index
        if (pattern.endsWith('/index')) {
            pattern = pattern.slice(0, -6) || '/';
        }

        // Check for duplicates in dev mode
        if (Rsx.is_dev() && Spa.routes[pattern]) {
            console.error(`Spa: Duplicate route '${pattern}' - ${action_class.name} conflicts with ${Spa.routes[pattern].name}`);
        }

        Spa.routes[pattern] = action_class;
    }

    /**
     * Match URL to a route and extract parameters
     * Returns: { action_class, args, layouts } or null
     *
     * layouts is an array of layout class names for sublayout chain support.
     * First element = outermost layout, last = innermost (closest to action).
     */
    static match_url_to_route(url) {
        // Parse URL to get path and query params
        const parsed = Spa.parse_url(url);
        let path = parsed.path;

        // Strip portal prefix if in portal context
        if (Rsx_Portal.is_portal() && !Rsx_Portal.has_dedicated_domain()) {
            const prefix = Rsx_Portal.get_prefix();
            if (path.startsWith(prefix)) {
                path = path.substring(prefix.length) || '/';
            }
        }

        // Normalize path - remove leading/trailing slashes for matching
        path = path.substring(1); // Remove leading /

        // Remove /index suffix
        if (path === 'index' || path.endsWith('/index')) {
            path = path.slice(0, -5) || '';
        }

        // Try exact match first
        const exact_pattern = '/' + path;
        if (Spa.routes[exact_pattern]) {
            const action_class = Spa.routes[exact_pattern];
            return {
                action_class: action_class,
                args: parsed.query_params,
                layouts: action_class._spa_layouts || ['Default_Layout'],
            };
        }

        // Try pattern matching with :param segments
        for (const pattern in Spa.routes) {
            const match = Spa.match_pattern(path, pattern);
            if (match) {
                // Merge parameters with correct priority order:
                // 1. GET parameters (from query string, lowest priority)
                // 2. URL route parameters (extracted from route pattern like :id, highest priority)
                // This matches the PHP Dispatcher behavior where route params override GET params
                const args = { ...parsed.query_params, ...match };
                const action_class = Spa.routes[pattern];

                return {
                    action_class: action_class,
                    args: args,
                    layouts: action_class._spa_layouts || ['Default_Layout'],
                };
            }
        }

        // No match found
        return null;
    }

    /**
     * Match a path against a pattern with :param segments
     * Returns object with extracted params or null if no match
     */
    static match_pattern(path, pattern) {
        // Remove leading / from both
        path = path.replace(/^\//, '');
        pattern = pattern.replace(/^\//, '');

        // Split into segments
        const path_segments = path.split('/');
        const pattern_segments = pattern.split('/');

        // Must have same number of segments
        if (path_segments.length !== pattern_segments.length) {
            return null;
        }

        const params = {};

        for (let i = 0; i < pattern_segments.length; i++) {
            const pattern_seg = pattern_segments[i];
            const path_seg = path_segments[i];

            if (pattern_seg.startsWith(':')) {
                // This is a parameter - extract it
                const param_name = pattern_seg.substring(1);
                params[param_name] = decodeURIComponent(path_seg);
            } else {
                // This is a literal - must match exactly
                if (pattern_seg !== path_seg) {
                    return null;
                }
            }
        }

        return params;
    }

    /**
     * Parse URL into components
     */
    static parse_url(url) {
        let parsed_url;

        try {
            if (url.startsWith('http://') || url.startsWith('https://')) {
                parsed_url = new URL(url);
            } else {
                parsed_url = new URL(url, window.location.href);
            }
        } catch (e) {
            parsed_url = new URL(window.location.href);
        }

        const path = parsed_url.pathname;
        const search = parsed_url.search;

        // Parse query string
        const query_params = {};
        if (search && search !== '?') {
            const query = search.startsWith('?') ? search.substring(1) : search;
            const pairs = query.split('&');

            for (const pair of pairs) {
                const [key, value] = pair.split('=');
                if (key) {
                    query_params[decodeURIComponent(key)] = value ? decodeURIComponent(value) : '';
                }
            }
        }

        return { host: parsed_url.host, path, search, query_params, hash: parsed_url.hash };
    }

    /**
     * Generate URL from pattern and parameters
     */
    static generate_url_from_pattern(pattern, params = {}) {
        let url = pattern;
        const used_params = new Set();

        // Replace :param placeholders
        url = url.replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, (match, param_name) => {
            if (params.hasOwnProperty(param_name)) {
                used_params.add(param_name);
                return encodeURIComponent(params[param_name]);
            }
            return '';
        });

        // Collect unused parameters for query string
        const query_params = {};
        for (const key in params) {
            if (!used_params.has(key)) {
                query_params[key] = params[key];
            }
        }

        // Add query string if needed
        if (Object.keys(query_params).length > 0) {
            const query_string = Object.entries(query_params)
                .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
                .join('&');
            url += '?' + query_string;
        }

        return url;
    }

    /**
     * Setup browser integration for back/forward and link interception
     *
     * This implements Phase 1 of browser integration using the History API.
     * See: /docs.dev/SPA_BROWSER_INTEGRATION.md for complete documentation.
     *
     * Key Behaviors:
     * - Intercepts clicks on <a> tags for same-domain SPA routes
     * - Preserves standard browser behaviors (Ctrl+click, target="_blank", etc.)
     * - Handles back/forward navigation with scroll restoration
     * - Hash-only changes don't create history entries
     * - Defers to server for edge cases (external links, non-SPA routes, etc.)
     */
    static setup_browser_integration() {
        console_debug('Spa', 'Setting up browser integration (History API mode)');

        // Handle browser back/forward buttons
        window.addEventListener('popstate', (e) => {
            console_debug('Spa', 'popstate event fired (back/forward navigation)');
            console.warn('[Spa.dispatch] Handling history popstate event', {
                url: window.location.pathname + window.location.search + window.location.hash,
                state: e.state
            });

            // Get target URL (browser has already updated location)
            const url = window.location.pathname + window.location.search + window.location.hash;

            // Retrieve scroll position from history state
            const scroll = e.state?.scroll || null;

            // TODO: Form Data Restoration
            // Retrieve form data from history state and restore after action renders
            // Implementation notes:
            // - Get form_data from e.state?.form_data
            // - After action on_ready(), find all form inputs
            // - Restore values from form_data object
            // - Trigger change events for restored fields
            // - Handle edge cases:
            //   * Dynamic forms (loaded via Ajax) - need to wait for form to exist
            //   * File inputs (cannot be programmatically set for security)
            //   * Custom components (need vals() method for restoration)
            //   * Timing (must restore after form renders, possibly in on_ready)
            // const form_data = e.state?.form_data || {};

            // Dispatch without modifying history (we're already at the target URL)
            // Force SPA dispatch even if disabled - popstate navigates to cached history state
            Spa.dispatch(url, {
                history: 'none',
                scroll: scroll,
                force: true,
                _is_popstate: true
            });
        });

        // Intercept link clicks using event delegation
        document.addEventListener('click', (e) => {
            // Find <a> tag in event path (handles clicks on child elements)
            let link = e.target.closest('a');

            if (!link) {
                return;
            }

            const href = link.getAttribute('href');

            // Ignore if:
            // - No href
            // - Ctrl/Cmd/Meta is pressed (open in new tab)
            // - Shift is pressed (open in new window)
            // - Has target attribute
            // - Not left click (button 0)
            // - Is empty or hash-only (#)
            if (!href ||
                e.ctrlKey ||
                e.metaKey ||
                e.shiftKey ||
                link.getAttribute('target') ||
                e.button !== 0 ||
                href === '' ||
                href === '#') {
                return;
            }

            // Parse URLs for comparison
            const current_parsed = Spa.parse_url(window.location.href);
            const target_parsed = Spa.parse_url(href);

            // If same page (same path + search), let browser handle (causes reload)
            // This mimics non-SPA behavior where clicking current page refreshes
            if (current_parsed.path === target_parsed.path &&
                current_parsed.search === target_parsed.search) {
                console_debug('Spa', 'Same page click, letting browser reload');
                return;
            }

            // Only intercept same-domain links
            if (current_parsed.host !== target_parsed.host) {
                console_debug('Spa', 'External domain link, letting browser handle: ' + href);
                return;
            }

            // Check if target URL matches a Spa route
            if (Spa.match_url_to_route(href)) {
                // Check if SPA is enabled
                if (!Spa._spa_enabled) {
                    console_debug('Spa', 'SPA disabled, letting browser handle: ' + href);
                    return; // Don't preventDefault - browser navigates normally
                }

                console_debug('Spa', 'Intercepting link click: ' + href);
                e.preventDefault();

                // Check for loader title hint - provides immediate title feedback while page loads
                const loader_title_hint = link.getAttribute('data-loader-title-hint');
                const dispatch_options = { history: 'auto' };

                if (loader_title_hint) {
                    // Set document title immediately for instant feedback
                    document.title = loader_title_hint;
                    dispatch_options.loader_title_hint = loader_title_hint;
                    console_debug('Spa', 'Loader title hint: ' + loader_title_hint);
                }

                Spa.dispatch(href, dispatch_options);
            } else {
                console_debug('Spa', 'No SPA route match, letting server handle: ' + href);
            }
        });
    }


    /**
     * Main dispatch method - navigate to a URL
     *
     * This is the single entry point for all navigation within the SPA.
     * Handles route matching, history management, layout/action lifecycle, and scroll restoration.
     *
     * @param {string} url - Target URL (relative or absolute)
     * @param {object} options - Navigation options
     * @param {string} options.history - 'auto'|'push'|'replace'|'none' (default: 'auto')
     *   - 'auto': Push for new URLs, replace for same URL
     *   - 'push': Always create new history entry
     *   - 'replace': Replace current history entry
     *   - 'none': Don't modify history (used for back/forward)
     * @param {object|null} options.scroll - Scroll position {x, y} to restore (default: null = scroll to top)
     * @param {boolean} options.triggers - Fire before/after dispatch events (default: true)
     * @param {boolean} options.force - Force SPA dispatch even if disabled (used by popstate) (default: false)
     * @param {boolean} options.skip_leave_guard - Bypass the registered leave-guard for an intentional
     *   programmatic navigation (default: false). Note: popstate does NOT set this - Back/Forward must
     *   always be subject to the guard.
     */
    /**
     * Navigate to a URL, replacing the current history entry instead of pushing.
     * Use for programmatic redirects (e.g., in on_load() when a record should
     * display at a different URL) so Back button skips the redirecting page.
     */
    static async redirect(url, options = {}) {
        return Spa.dispatch(url, { ...options, history: 'replace' });
    }

    static async dispatch(url, options = {}) {
        if (Spa._leave_guard && !options.skip_leave_guard) {
            const message = Spa._leave_guard();
            if (message) {
                const confirmed = (typeof Modal !== 'undefined' && Modal.confirm)
                    ? await Modal.confirm('Leave Page?', message, 'Leave', 'Stay')
                    : window.confirm(message);
                if (!confirmed) {
                    if (options._is_popstate && Spa._last_committed_url) {
                        // Browser already changed window.location by the time popstate
                        // fires; restore the address bar without re-triggering another
                        // popstate (pushState does not fire it).
                        history.pushState(history.state, '', Spa._last_committed_url);
                    }
                    return;
                }
            }
        }
        Spa.clear_leave_guard();

        // Check if SPA is disabled - do full page load
        // Exception: popstate events always attempt SPA dispatch (force: true)
        if (!Spa._spa_enabled && !options.force) {
            console.warn('[Spa.dispatch] SPA disabled, forcing full page load');
            Spa._navigate_away(url, options);
            return;
        }

        if (Spa.is_dispatching) {
            // Already dispatching - queue this as a pending redirect
            // This commonly happens when an action redirects in on_load()
            console_debug('Spa', 'Nested dispatch detected, queuing pending redirect: ' + url);
            Spa.pending_redirect = { url, options };
            return;
        }

        Spa.is_dispatching = true;

        // Clear cached action - will be set when new action is created
        Spa._action = null;

        // Record navigation timestamp for error suppression grace period
        // Errors from previous page's pending requests should be ignored for 10 seconds
        Spa._navigation_timestamp = Date.now();

        // Reset any pending scroll restoration from previous navigation
        // (Browser refresh scroll is handled separately by Rsx._restore_scroll_on_refresh)
        Rsx.reset_pending_scroll();

        // Reset ORM fetch cache - cached data is per-page, not cross-navigation
        Rsx_Js_Model.orm_cache_reset();

        // Trigger spa_dispatch_start event - allows cleanup before navigation
        // Use case: close modals, cancel pending operations, etc.
        Rsx.trigger('spa_dispatch_start', { url });

        try {
            const opts = {
                history: options.history || 'auto',
                scroll: 'scroll' in options ? options.scroll : undefined,
                triggers: options.triggers !== false,
                loader_title_hint: options.loader_title_hint || null,
            };

            console_debug('Spa', 'Dispatching to: ' + url + ' (history: ' + opts.history + ')');

            // Handle fully qualified URLs
            const current_domain = window.location.hostname;

            if (url.startsWith('http://') || url.startsWith('https://')) {
                try {
                    const parsed_url = new URL(url);

                    // Check if different domain
                    if (parsed_url.hostname !== current_domain) {
                        // External domain - navigate away
                        console_debug('Spa', 'External domain, navigating: ' + url);
                        console.warn('[Spa.dispatch] Navigating away (external domain)', {
                            url: url,
                            reason: 'External domain'
                        });
                        Spa._navigate_away(url, options);
                        Spa.is_dispatching = false;
                        return;
                    }

                    // Same domain - strip to relative URL
                    url = parsed_url.pathname + parsed_url.search + parsed_url.hash;
                    console_debug('Spa', 'Same domain, stripped to relative: ' + url);
                } catch (e) {
                    console.error('Spa: Invalid URL format:', url);
                    Spa.is_dispatching = false;
                    return;
                }
            }

            // Parse the (now relative) URL
            const parsed = Spa.parse_url(url);

            // CRITICAL: Strip hash from URL before route matching
            // Hash represents page state (e.g., DataGrid page number), not routing state
            // Hash is preserved in browser URL bar but not used for route matching
            const url_without_hash = parsed.path + parsed.search;

            console_debug('Spa', 'URL for route matching (hash stripped): ' + url_without_hash);

            // Try to match URL to a route (without hash)
            const route_match = Spa.match_url_to_route(url_without_hash);

            // Check if this is the same URL we're currently on (without hash)
            const current_url = window.location.pathname + window.location.search;
            const is_same_url = url_without_hash === current_url;

            // Same URL navigation with history: 'auto' should reload via server
            // This mimics non-SPA behavior where clicking current page refreshes
            // Exception: history: 'replace' means a redirect landed here — proceed with SPA dispatch
            if (is_same_url && opts.history === 'auto') {
                console_debug('Spa', 'Same URL with auto history, letting browser reload');
                console.warn('[Spa.dispatch] Navigating away (same URL reload)', {
                    url: url,
                    reason: 'Same URL with auto history - mimics browser reload behavior'
                });
                Spa._navigate_away(url, options);
                Spa.is_dispatching = false;
                return;
            }

            if (is_same_url && !route_match) {
                // We're being asked to navigate to the current URL, but it doesn't match
                // any known route. This shouldn't happen - prevents infinite redirect loop.
                Spa.spa_unknown_route_fatal(parsed.path);
                Spa.is_dispatching = false;
                return;
            }

            // If no route match and we're not on this URL, let server handle it
            if (!route_match) {
                console_debug('Spa', 'No route matched, letting server handle: ' + url);
                console.warn('[Spa.dispatch] Navigating away (no route match)', {
                    url: url,
                    reason: 'URL does not match any registered SPA routes'
                });
                Spa._navigate_away(url, options);
                Spa.is_dispatching = false;
                return;
            }

            console_debug('Spa', 'Route match:', {
                action_class: route_match?.action_class?.name,
                args: route_match?.args,
                layouts: route_match?.layouts,
            });

            // Check if action's @spa() attribute matches current SPA bootstrap
            const action_spa_controller = route_match.action_class._spa_controller_method;
            const current_spa_controller = window.rsxapp.current_controller + '::' + window.rsxapp.current_action;

            if (action_spa_controller && action_spa_controller !== current_spa_controller) {
                // Different SPA module - let server bootstrap it
                console_debug('Spa', 'Different SPA module, letting server handle: ' + url);
                console_debug('Spa', `  Action uses: ${action_spa_controller}`);
                console_debug('Spa', `  Current SPA: ${current_spa_controller}`);
                console.warn('[Spa.dispatch] Navigating away (different SPA module)', {
                    url: url,
                    reason: 'Action belongs to different SPA module/bundle',
                    action_spa_controller: action_spa_controller,
                    current_spa_controller: current_spa_controller
                });
                Spa._navigate_away(url, options);
                Spa.is_dispatching = false;
                return;
            }

            // Update browser history with scroll position storage
            if (opts.history !== 'none') {
                // Store current scroll position before navigation
                const current_scroll = {
                    x: window.scrollX || window.pageXOffset,
                    y: window.scrollY || window.pageYOffset
                };

                // Build history state object
                const state = {
                    scroll: current_scroll,
                    form_data: {}  // Reserved for future form state restoration
                };

                // Construct full URL with hash (hash is preserved in browser URL bar)
                const new_url = parsed.path + parsed.search + (parsed.hash || '');

                if (opts.history === 'push' || (opts.history === 'auto' && !is_same_url)) {
                    console_debug('Spa', 'Pushing history state');
                    history.pushState(state, '', new_url);
                } else if (opts.history === 'replace' || (opts.history === 'auto' && is_same_url)) {
                    console_debug('Spa', 'Replacing history state');
                    history.replaceState(state, '', new_url);
                }
            }

            // AUTH GATE. Every @auth check the target action declares must be present
            // in the render-time grant map. Consulted here - route committed, history
            // moved, nothing instantiated yet - so a denied action NEVER constructs.
            //
            // THE URL BECOMES THE DENIED PAGE, mirroring the server: a 403 is served
            // AT the requested URL, not by bouncing the browser somewhere else. So a
            // refresh of a denied deep link behaves identically (the server renders
            // its own denial for that same URL) and the address bar never lies about
            // what was asked for.
            //
            // DOCTRINE: client gates are UX, not security. This exists so the interface
            // is honest (no dead links, no flash-then-deny); the server runs the same
            // named gates on every Ajax/ORM/API call the action would have made.
            const denied_checks = Spa._denied_auth_checks(route_match.action_class);
            if (denied_checks.length) {
                console.warn('[Spa.dispatch] Auth gate denied', {
                    url: url,
                    action: route_match.action_class.name,
                    missing_checks: denied_checks
                });

                Error_Screens.unauthorized();

                // The denied URL is what the address bar shows, so it is also what a
                // vetoed Back/Forward navigation has to restore.
                Spa._last_committed_url = parsed.path + parsed.search + (parsed.hash || '');
                Spa.is_dispatching = false;

                return;
            }

            // Set global Spa state
            Spa.route = route_match;
            Spa.path = parsed.path;
            Spa.params = route_match.args;

            // Get layout chain and action info
            const target_layouts = route_match.layouts;
            const action_class = route_match.action_class;
            const action_name = action_class.name;

            // Merge loader title hint into action args if provided
            // This allows the action to show a placeholder title while loading
            let action_args = route_match.args;
            if (opts.loader_title_hint) {
                action_args = { ...route_match.args, _loader_title_hint: opts.loader_title_hint };
            }

            // Log successful SPA navigation
            console.warn('[Spa.dispatch] Executing SPA navigation', {
                url: url,
                path: parsed.path,
                params: action_args,
                action: action_name,
                layouts: target_layouts,
                history_mode: opts.history
            });

            // Scroll handling: snap to position BEFORE layout chain resolves
            // so the scroll happens on first render of the new action
            if (opts.scroll) {
                console_debug('Spa', 'Restoring scroll position: ' + opts.scroll.x + ', ' + opts.scroll.y);
                window.scrollTo({ left: opts.scroll.x, top: opts.scroll.y, behavior: 'instant' });
            } else if (opts.scroll === undefined) {
                // Default: scroll to top for new navigation (only if scroll not explicitly set)
                // BUT skip on page reload - let Rsx._restore_scroll_on_refresh handle it
                const nav_entries = performance.getEntriesByType('navigation');
                const is_reload = nav_entries.length > 0 && nav_entries[0].type === 'reload';
                if (is_reload) {
                    console_debug('Spa', 'Skipping scroll-to-top on page reload (Rsx handles refresh scroll restore)');
                } else {
                    console_debug('Spa', 'Scrolling to top (new navigation)');
                    window.scrollTo({ left: 0, top: 0, behavior: 'instant' });
                }
            }
            // If opts.scroll === null, don't scroll (let Navigation API or browser handle it)

            // Resolve layout chain - find divergence point and reuse matching layouts
            await Spa._resolve_layout_chain(target_layouts, action_name, action_args, url);

            // TODO: Scroll Restoration #2 - After action on_ready() completes
            // This requires an action lifecycle event system to detect when on_ready() finishes.
            // Implementation notes:
            // - Listen for action on_ready completion event
            // - Only retry scroll if restoration #1 failed (page wasn't tall enough)
            // - Check if page height has increased since first attempt
            // - Avoid infinite retry loops (track attempts)
            // - This ensures scroll restoration works even when content loads asynchronously
            //
            // Additional context: The action may load data in on_load() that increases page height,
            // making the target scroll position accessible. The first restoration happens before
            // this content renders, so we need a second attempt after the page is fully ready.

            console_debug('Spa', `Rendered ${action_name} with ${target_layouts.length} layout(s)`);

            // Session validation after navigation
            // Validates that client state (window.rsxapp) matches server state.
            // Detects:
            // 1. Codebase updates (build_key changed) - new deployment
            // 2. User changes (name, role, permissions updated)
            // 3. Session changes (logged out, ACLs modified)
            //
            // Only runs on subsequent navigations, not:
            // - Initial page load (window.rsxapp is fresh from server)
            // - Back/forward navigation (force: true) - restoring cached state
            //
            // On mismatch, triggers location.replace() for transparent refresh
            // that doesn't pollute browser history.
            if (Spa._initial_load_complete && !options.force) {
                // Fire and forget - don't block navigation on validation
                Rsx.validate_session();
            }

            // Mark initial load complete for subsequent navigations
            if (!Spa._initial_load_complete) {
                Spa._initial_load_complete = true;
            }

            // Record the URL now showing. Used to restore the address bar if a
            // later Back/Forward navigation is vetoed by a leave-guard. Same URL
            // construction as the history push/replace above.
            Spa._last_committed_url = parsed.path + parsed.search + (parsed.hash || '');

        } catch (error) {
            console.error('[Spa] Dispatch error:', error);

            // The target never mounted, so the content area would otherwise keep
            // showing whatever body is still on screen under a URL that has
            // already changed. Render the fatal
            // screen there instead - except when Exception_Handler's in-layout debug
            // box is going to take this exception (debug builds, action mid-load),
            // which shows the stack in place and is strictly more useful. The error
            // still propagates: this decides what the USER sees, nothing else.
            if (!Exception_Handler._should_show_in_layout()) {
                Error_Screens.fatal(error);
            }

            throw error;
        } finally {
            Spa.is_dispatching = false;

            // Check if a redirect was queued during this dispatch
            if (Spa.pending_redirect) {
                const redirect = Spa.pending_redirect;
                Spa.pending_redirect = null;

                console_debug('Spa', 'Processing pending redirect: ' + redirect.url);

                // Execute the queued redirect immediately
                // This will destroy the action that triggered the redirect
                await Spa.dispatch(redirect.url, redirect.options);
            }
        }
    }

    /**
     * Navigate away from the SPA to a full page load.
     * Uses location.replace() for redirect (history: 'replace') so the
     * current history entry is overwritten, preserving correct Back behavior.
     */
    static _navigate_away(url, options = {}) {
        if (options.history === 'replace') {
            document.location.replace(url);
        } else {
            document.location.href = url;
        }
    }

    /**
     * Resolve layout chain - find divergence point and reuse matching layouts
     *
     * Walks down from the top-level layout comparing current DOM chain to target chain.
     * Reuses layouts that match, destroys from the first mismatch point down,
     * then creates new layouts/action from that point.
     *
     * @param {string[]} target_layouts - Array of layout class names (outermost first)
     * @param {string} action_name - The action class name to render at the bottom
     * @param {object} args - URL parameters for the action
     * @param {string} url - The full URL being navigated to
     */
    static async _resolve_layout_chain(target_layouts, action_name, args, url) {
        // Build target chain: layouts + action at the end
        const target_chain = [...target_layouts, action_name];

        console_debug('Spa', 'Resolving layout chain:', target_chain);

        // Find the divergence point by walking the current DOM
        let $current_container = $('#spa-root');
        if (!$current_container.length) {
            throw new Error('[Spa] #spa-root element not found - check Spa_App.blade.php');
        }

        let divergence_index = 0;
        let reusable_layouts = [];

        // Walk down current chain, checking each level against target
        for (let i = 0; i < target_chain.length; i++) {
            const target_name = target_chain[i];
            const is_last = (i === target_chain.length - 1);

            // Check if current container has a component with target class name
            // jqhtml adds class names to component root elements automatically
            //
            // Special case for first iteration (i=0):
            // The top-level layout is rendered ON #spa-root itself, not as a child.
            // $.component() converts the container element into the component.
            // So we check if the container itself has the target class.
            //
            // For subsequent iterations:
            // Sublayouts and actions are rendered into the parent's $content area.
            // The $content element becomes the component (same pattern).
            // So we still check if the container itself has the target class.
            const $existing = $current_container;

            if ($existing.hasClass(target_name)) {
                // Match found - can potentially reuse this level
                const existing_component = $existing.component();

                if (is_last) {
                    // This is the action level - actions are never reused, always replaced
                    divergence_index = i;
                    break;
                }

                // This is a layout level - reuse it
                reusable_layouts.push(existing_component);
                divergence_index = i + 1;

                // Move to next level - look in this layout's $content
                const $content = existing_component.$sid ? existing_component.$sid('content') : null;
                if (!$content || !$content.length) {
                    // Layout doesn't have content area - can't go deeper
                    break;
                }
                $current_container = $content;
            } else {
                // No match - divergence point found
                divergence_index = i;
                break;
            }
        }

        console_debug('Spa', `Divergence at index ${divergence_index}, reusing ${reusable_layouts.length} layouts`);

        // Destroy everything from divergence point down
        if (divergence_index === 0) {
            // Complete replacement - destroy top-level layout
            if (Spa.layout) {
                await Spa.layout.trigger('unload');
                Spa.layout.stop();
                Spa.layout = null;
            }
            $current_container = $('#spa-root');
            $current_container.empty();
            Spa._clear_container_attributes($current_container);
        } else {
            // Partial replacement - clear from the reusable layout's $content
            const last_reusable = reusable_layouts[reusable_layouts.length - 1];
            $current_container = last_reusable.$sid('content');
            $current_container.empty();
            Spa._clear_container_attributes($current_container);
        }

        // Create new layouts/action from divergence point
        for (let i = divergence_index; i < target_chain.length; i++) {
            const component_name = target_chain[i];
            const is_last = (i === target_chain.length - 1);

            console_debug('Spa', `Creating ${is_last ? 'action' : 'layout'}: ${component_name}`);

            // Create component
            const component = $current_container.component(component_name, is_last ? args : {}).component();

            if (i === 0) {
                // Top-level layout - set reference immediately
                Spa.layout = component;
            }

            if (is_last) {
                // This is the action - set reference but don't wait
                Spa._action = component;

                // Exception_Handler routes an unhandled exception into the layout's
                // debug error box only while the action is mid-load. ready() never
                // rejects - a boot failure simply leaves it pending - so the flag
                // correctly stays raised for an action that failed to load.
                const loading_layout = Spa.layout;
                if (loading_layout) {
                    loading_layout._action_is_loading = true;
                }

                // After action is fully ready (on_load + on_ready complete)
                component.ready().then(() => {
                    if (loading_layout) {
                        loading_layout._action_is_loading = false;
                    }

                    // Retry scroll restoration - handles cases where on_load fetches data that increases page height
                    Rsx.try_restore_scroll();

                    // Anchor deep-link / scroll-to-top. Deliberately here and not at
                    // dispatch time: dispatch does not await the action (see the
                    // fire-and-forget ready() above), so scrolling any earlier races
                    // a page whose content has not been rendered yet.
                    Spa._apply_scroll_position();

                    // Trigger spa_dispatch_ready event - action is fully loaded and rendered
                    Rsx.trigger('spa_dispatch_ready', { url, action: component });
                });
            } else {
                // This is a layout
                // Wait for render to complete (not full ready - we don't need child data to load)
                // This allows layout navigation to update immediately while action loads
                await component.rendered();

                // Move container to this layout's $content for next iteration
                $current_container = component.$sid('content');
                if (!$current_container || !$current_container.length) {
                    throw new Error(`[Spa] Layout ${component_name} must have an element with $sid="content"`);
                }
            }
        }

        // Propagate on_action to all layouts in the chain
        // All layouts receive the same action info (final action's url, name, args)
        const layouts_for_on_action = Spa._collect_all_layouts();

        for (const layout of layouts_for_on_action) {
            // Set action reference before calling on_action so layouts can access it
            layout.action = Spa.action();
            if (layout.on_action) {
                layout.on_action(url, action_name, args);
            }
            layout.trigger('action');
        }

        console_debug('Spa', `Rendered ${action_name} with ${target_layouts.length} layout(s)`);
    }

    // =========================================================================
    // ANCHOR SCROLLING
    //
    // One opinionated contract for "where does the page sit after a dispatch",
    // so applications stop hand-rolling scrollTop(0) in every layout and
    // in-page anchors work at all. See: php artisan rsx:man anchors
    // =========================================================================

    /**
     * Resolve the scroll target for an anchor slug.
     *
     * [data-anchor] is the primary selector and a plain #id is the secondary one.
     * id is NOT primary because jqhtml mangles ids on scoped elements
     * ($sid="content" renders id="content:aa"), so an authored id is not
     * reliably addressable.
     *
     * @param {string} slug
     * @returns {jQuery} The target, or an empty set
     */
    static _find_anchor_target(slug) {
        const attr_value = String(slug).replace(/\\/g, '\\\\').replace(/"/g, '\\"');

        const $by_anchor = $('[data-anchor="' + attr_value + '"]').first();
        if ($by_anchor.length) {
            return $by_anchor;
        }

        // getElementById sidesteps CSS selector escaping entirely, which matters
        // because heading slugs are author-controlled.
        const el = document.getElementById(slug);
        return el ? $(el) : $();
    }

    /**
     * Scroll an anchor into view, waiting briefly for it to exist.
     *
     * The retry window covers targets that render after the action is ready -
     * a child component finishing its own on_load, or a cached stub being
     * reconciled.
     *
     * @param {string} slug - Anchor slug (the value of the reserved `at` hash key)
     * @param {Object} [options]
     * @param {number} [options.timeout_ms=500] - How long to keep looking
     * @param {string} [options.behavior='smooth'] - 'smooth', 'instant' or 'auto'
     * @returns {boolean} True if the target was found and scrolled on the first try
     */
    static scroll_to_anchor(slug, options = {}) {
        if (!slug) {
            return false;
        }

        const timeout_ms = options.timeout_ms ?? 500;
        const deadline = Date.now() + timeout_ms;

        const attempt = () => {
            const $target = Spa._find_anchor_target(slug);

            if ($target.length) {
                scroll_anchor_into_view($target, { behavior: options.behavior || 'smooth' });

                // Move focus so keyboard and screen-reader users land on the
                // section too, not just sighted users. preventScroll because
                // scroll_anchor_into_view already positioned it correctly.
                if (!$target.is('a, button, input, select, textarea, [tabindex]')) {
                    $target.attr('tabindex', '-1');
                }
                $target[0].focus({ preventScroll: true });

                console_debug('Spa', `Scrolled to anchor: ${slug}`);
                return true;
            }

            if (Date.now() < deadline) {
                requestAnimationFrame(attempt);
            } else {
                console_debug('Spa', `Anchor target not found: ${slug}`);
            }

            return false;
        };

        return attempt();
    }

    /**
     * Decide where the page sits after a dispatch.
     *
     * Precedence: explicit anchor > pending back/forward restoration > top.
     * An anchor is an explicit request from the URL, so it outranks restoration.
     */
    static _apply_scroll_position() {
        // Opt-out: a layout that manages its own scroll position entirely.
        const layout = Spa.layout;
        if (layout && layout.$ && layout.$.attr('data-anchor-scroll') === 'manual') {
            return;
        }

        const anchor = Rsx.url_hash_get(Rsx.HASH_ANCHOR_KEY);

        if (anchor) {
            // A deep link overrides restoring the previous scroll position.
            Rsx._pending_scroll = null;
            Spa.scroll_to_anchor(anchor);
            return;
        }

        // A pending back/forward restoration owns the scroll position; leave it.
        if (Rsx._pending_scroll) {
            return;
        }

        Spa._scroll_action_container_to_top();
    }

    /**
     * Scroll whichever element actually scrolls back to the top.
     *
     * Checks the action root itself before walking ancestors: a layout's
     * $sid="content" element is frequently BOTH the action's root and the
     * scrolling box, and an ancestor-only walk would skip straight past it.
     */
    static _scroll_action_container_to_top() {
        const action = Spa.action();
        if (!action || !action.$ || !action.$.length) {
            return;
        }

        const $self = action.$;
        const overflow_y = $self.css('overflow-y');

        if ((overflow_y === 'auto' || overflow_y === 'scroll') && $self[0].scrollHeight > $self[0].clientHeight) {
            $self[0].scrollTo({ top: 0, left: 0, behavior: 'instant' });
            return;
        }

        nearest_scrollable_ancestor($self)[0].scrollTo({ top: 0, left: 0, behavior: 'instant' });
    }

    /**
     * Collect all layouts from Spa.layout down through nested $content elements
     * @returns {Array} Array of layout instances from top to bottom
     */
    static _collect_all_layouts() {
        const layouts = [];
        let current = Spa.layout;

        while (current && current instanceof Spa_Layout) {
            layouts.push(current);

            // Look for nested layout in $content
            // Note: Sublayouts are placed directly ON the $content element (container becomes component)
            const $content = current.$sid ? current.$sid('content') : null;
            if (!$content || !$content.length) break;

            const child_component = $content.component();
            if (child_component && child_component !== current && child_component instanceof Spa_Layout) {
                current = child_component;
            } else {
                break;
            }
        }

        return layouts;
    }

    /**
     * Clear all attributes except id from a container element
     * Called before loading new content to ensure clean state
     * @param {jQuery} $container - The container element to clear
     */
    static _clear_container_attributes($container) {
        if (!$container || !$container.length) return;

        const el = $container[0];
        const attrs_to_remove = [];

        for (const attr of el.attributes) {
            if (attr.name !== 'id' && attr.name !== 'data-id') {
                attrs_to_remove.push(attr.name);
            }
        }

        for (const attr_name of attrs_to_remove) {
            el.removeAttribute(attr_name);
        }
    }

    /**
     * The @auth checks an action declares that the current user was NOT granted
     *
     * An action with no @auth passes (empty result): closed-by-default enforcement
     * arrives with the annotation pass, and until every action is annotated an
     * un-gated action must still dispatch.
     *
     * Reads window.rsxapp.auth - the ACTIVE realm's grants-only map, so the same
     * code serves staff and portal pages. A denied or nonexistent check name is
     * indistinguishable here, by design (see rsx:man auth_gates).
     *
     * @param {Function} action_class - The action class being dispatched to
     * @returns {string[]} Ungranted check names (empty = the gate passes)
     */
    static _denied_auth_checks(action_class) {
        const checks = action_class._auth_checks || [];
        if (!checks.length) {
            return [];
        }

        const grants = window.rsxapp.auth || {};
        return checks.filter((check_name) => grants[check_name] !== 1);
    }

    /**
     * No action matches the URL the browser is already showing
     *
     * Reached when a dispatch targets the CURRENT URL and nothing claims it -
     * navigating away would reload the same unroutable URL forever, so the SPA
     * renders its own 404 in the layout instead. The address bar already shows the
     * unmatched URL, which is exactly where a 404 belongs.
     */
    static spa_unknown_route_fatal(path) {
        console.error(`[Spa] No action matches ${path} - rendering the not-found screen`);

        Error_Screens.not_found();
    }

    /**
     * Load an action in detached mode without affecting the live SPA state
     *
     * This method resolves a URL to an action, instantiates it on a detached DOM element
     * (not in the actual document), runs its full lifecycle including on_load(), and
     * returns the fully-initialized component instance.
     *
     * Use cases:
     * - Getting action metadata (title, breadcrumbs) for navigation UI
     * - Pre-fetching action data before navigation
     * - Inspecting action state without displaying it
     *
     * IMPORTANT: The caller is responsible for calling action.stop() when done
     * to prevent memory leaks. The detached action holds references and may have
     * event listeners that need cleanup.
     *
     * @param {string} url - The URL to resolve and load
     * @param {object} extra_args - Optional extra parameters to pass to the action component.
     *   These are merged with URL-extracted args (extra_args take precedence).
     *   Pass {use_cached_data: true} to have the action load with cached data
     *   without revalidation if cached data is available.
     * @param {object} options - Optional behavior options
     * @param {boolean} options.load_children - If true, renders the full component tree
     *   so all children (datagrids, views, etc.) also run their on_load(). Uses
     *   _load_render_only instead of _load_only. Useful for preloading/warming cache.
     * @returns {Promise<Spa_Action|null>} The fully-loaded action instance, or null if route not found
     *
     * @example
     *   // Basic usage
     *   const action = await Spa.load_detached_action('/contacts/123');
     *   if (action) {
     *       const title = action.get_title?.() ?? action.constructor.name;
     *       console.log('Page title:', title);
     *       action.stop(); // Clean up when done
     *   }
     *
     * @example
     *   // With cached data (faster, no network request if cached)
     *   const action = await Spa.load_detached_action('/contacts/123', {use_cached_data: true});
     *
     * @example
     *   // Preload with children (warms cache for entire page tree)
     *   const action = await Spa.load_detached_action('/contacts/123', {}, {load_children: true});
     *   action.stop();
     */
    static async load_detached_action(url, extra_args = {}, options = {}) {
        // Parse URL and match to route
        const parsed = Spa.parse_url(url);
        const url_without_hash = parsed.path + parsed.search;
        const route_match = Spa.match_url_to_route(url_without_hash);

        if (!route_match) {
            console_debug('Spa', 'load_detached_action: No route match for ' + url);
            return null;
        }

        const action_class = route_match.action_class;
        const action_name = action_class.name;

        // Same auth gate dispatch() applies: a denied action must never construct,
        // not even detached (its on_load() would call the endpoints the gate covers).
        // Unreachable resolves to null, exactly like an unmatched route.
        const denied_checks = Spa._denied_auth_checks(action_class);
        if (denied_checks.length) {
            console_debug('Spa', `load_detached_action: auth gate denied ${action_name}, missing: ${denied_checks.join(', ')}`);
            return null;
        }

        // _load_only: action on_load() only, no children
        // _load_render_only: renders full tree, all children run on_load() too
        const lifecycle_flag = options.load_children ? '_load_render_only' : '_load_only';

        const args = {
            ...route_match.args,
            ...extra_args,
            [lifecycle_flag]: true
        };

        console_debug('Spa', `load_detached_action: Loading ${action_name} with args:`, args);

        // Create a detached container (not in DOM)
        const $detached = $('<div>');

        // Instantiate the action on the detached element
        $detached.component(action_name, args);
        const action = $detached.component();

        // Wait for on_load to complete (data fetching)
        await action.ready();

        console_debug('Spa', `load_detached_action: ${action_name} ready`);

        return action;
    }
}
