// @ROUTE-EXISTS-01-EXCEPTION - This file contains documentation examples with fictional route names

/**
 * Rsx - Core JavaScript Runtime System
 *
 * The Rsx class is the central hub for the RSX JavaScript runtime, providing essential
 * system-level utilities that all other framework components depend on. It serves as the
 * foundation for the client-side framework, handling core operations that must be globally
 * accessible and consistently available.
 *
 * Core Responsibilities:
 * - Event System: Application-wide event bus for framework lifecycle and custom events
 * - Environment Detection: Runtime environment identification (dev/production)
 * - Route Management: Type-safe route generation and URL building
 * - Unique ID Generation: Client-side unique identifier generation
 * - Framework Bootstrap: Multi-phase initialization orchestration
 * - Logging: Centralized logging interface (delegates to console_debug)
 *
 * The Rsx class deliberately keeps its scope limited to core utilities. Advanced features
 * are delegated to specialized classes:
 * - Manifest operations → Manifest class
 * - Caching → Rsx_Cache class
 * - AJAX/API calls → Ajax_* classes
 * - Route proxies → Rsx_Route_Proxy class
 * - Behaviors → Rsx_Behaviors class
 *
 * All methods are static - Rsx is never instantiated. It's available globally from the
 * moment bundles load and remains constant throughout the application lifecycle.
 *
 * Usage Examples:
 * ```javascript
 * // Event system
 * Rsx.on('app_ready', () => console.log('App initialized'));
 * Rsx.trigger('custom_event', {data: 'value'});
 *
 * // Environment detection
 * if (Rsx.is_dev()) { console.log('Development mode'); }
 *
 * // Route generation
 * const url = Rsx.Route('Controller::action').url();
 *
 * // Unique IDs
 * const uniqueId = Rsx.uid(); // e.g., "rsx_1234567890_1"
 * ```
 *
 * @static
 * @global
 */
class Rsx {
    // Gets set to true to interupt startup sequence
    static __stopped = false;

    /**
     * Framework-reserved hash key naming the element to scroll to.
     *
     * Reserved the same way DataGrid reserves `dg_*`: an application must not
     * use this key for its own state. A bare `#fragment` normalizes to it on
     * parse, so `/docs/06-lifecycle#at=install` and `#install` are equivalent.
     *
     * See: php artisan rsx:man anchors
     */
    static HASH_ANCHOR_KEY = 'at';

    // Initialize event handlers storage
    static _init_events() {
        if (typeof Rsx._event_handlers === 'undefined') {
            Rsx._event_handlers = {};
        }
        if (typeof Rsx._triggered_events === 'undefined') {
            Rsx._triggered_events = {};
        }
    }

    // Register an event handler
    static on(event, callback) {
        Rsx._init_events();

        if (typeof callback !== 'function') {
            throw new Error('Callback must be a function');
        }

        if (!Rsx._event_handlers[event]) {
            Rsx._event_handlers[event] = [];
        }

        Rsx._event_handlers[event].push(callback);

        // If this event was already triggered, call the callback immediately
        if (Rsx._triggered_events[event]) {
            console_debug('RSX_INIT', 'Triggering ' + event + ' for late registered callback');
            callback(Rsx._triggered_events[event]);
        }
    }

    // Trigger an event with optional data
    static trigger(event, data = {}) {
        Rsx._init_events();

        // Record that this event was triggered
        Rsx._triggered_events[event] = data;

        if (!Rsx._event_handlers[event]) {
            return;
        }

        console_debug('RSX_INIT', 'Triggering ' + event + ' for ' + Rsx._event_handlers[event].length + ' callbacks');

        // Call all registered handlers for this event in order
        for (const callback of Rsx._event_handlers[event]) {
            callback(data);
        }
    }

    // Alias for trigger.refresh(''), should be called after major UI updates to apply such effects as
    // underlining links to unimplemented # routes
    static trigger_refresh() {
        // Use Rsx.on('refresh', callback); to register a callback for refresh
        this.trigger('refresh');
    }

    /**
     * Setup global unhandled exception handlers
     * Must be called before framework initialization begins
     *
     * Exception Event Contract:
     * -------------------------
     * When exceptions occur, they are broadcast via:
     * Rsx.trigger('unhandled_exception', { exception, meta })
     *
     * Event payload structure:
     * {
     *   exception: Error|string,  // The exception (Error object or string)
     *   meta: {
     *     source: 'window_error'|'unhandled_rejection'|undefined
     *     // source = undefined means manually triggered (already logged by catcher)
     *     // source = 'window_error' or 'unhandled_rejection' means needs logging
     *   }
     * }
     *
     * The exception can be:
     * 1. An Error object (preferred) - Has .message, .stack, .filename, .lineno properties
     * 2. A string - Plain error message text
     *
     * Consumers should handle both formats:
     * ```javascript
     * Rsx.on('unhandled_exception', function(payload) {
     *     const exception = payload.exception;
     *     const meta = payload.meta || {};
     *
     *     let message;
     *     if (exception instanceof Error) {
     *         message = exception.message;
     *         // Can also access: exception.stack, exception.filename, exception.lineno
     *     } else if (typeof exception === 'string') {
     *         message = exception;
     *     } else {
     *         message = String(exception);
     *     }
     *
     *     // Only log if from global handler (not already logged)
     *     if (meta.source === 'window_error' || meta.source === 'unhandled_rejection') {
     *         console.error('[Handler]', exception);
     *     }
     * });
     * ```
     *
     * Exception Flow:
     * - window error/unhandledrejection → _handle_unhandled_exception(exception, {source})
     * - Triggers 'unhandled_exception' event with exception and metadata
     * - Debugger.js: Logs to server
     * - Exception_Handler: Logs to console (if from global handler) and displays
     * - Disables SPA navigation
     */
    static _setup_exception_handlers() {
        // Handle uncaught JavaScript errors
        window.addEventListener('error', function (event) {
            // Pass the Error object directly if available, otherwise create one
            const exception = event.error || new Error(event.message);
            // Attach additional metadata if not already present
            if (!exception.filename) exception.filename = event.filename;
            if (!exception.lineno) exception.lineno = event.lineno;
            if (!exception.colno) exception.colno = event.colno;

            Rsx._handle_unhandled_exception(exception, { source: 'window_error' });
        });

        // Handle unhandled promise rejections
        window.addEventListener('unhandledrejection', function (event) {
            // View Transition API rejections are benign — the page rendered fine,
            // the transition animation just couldn't complete (e.g., DOM changed
            // before snapshot finished during SPA navigation). Suppress silently.
            const reason_message = event.reason instanceof Error
                ? event.reason.message
                : (event.reason ? String(event.reason) : '');
            if (reason_message.includes('Transition was aborted') || reason_message.includes('Transition was skipped')) {
                event.preventDefault();
                return;
            }

            // event.reason can be Error, string, or any value
            const exception = event.reason instanceof Error
                ? event.reason
                : new Error(event.reason ? String(event.reason) : 'Unhandled promise rejection');

            Rsx._handle_unhandled_exception(exception, { source: 'unhandled_rejection' });
        });
    }

    /**
     * Internal handler for unhandled exceptions
     * Triggers event and disables SPA
     * Display and logging handled by Exception_Handler listening to the event
     *
     * @param {Error|string|Object} exception - Exception object, string, or object with message
     * @param {Object} meta - Metadata about exception source
     * @param {string} meta.source - 'window_error', 'unhandled_rejection', or undefined for manual triggers
     */
    static _handle_unhandled_exception(exception, meta = {}) {
        // Trigger event for listeners:
        // - Debugger.js: Logs to server
        // - Exception_Handler: Logs to console and displays error (layout or flash alert)
        // Pass exception and metadata (source info for logging decisions)
        Rsx.trigger('unhandled_exception', { exception, meta });

        // Disable SPA navigation if in SPA mode
        // This allows user to navigate away from broken page using normal browser navigation
        if (typeof Spa !== 'undefined' && window.rsxapp && window.rsxapp.is_spa) {
            Spa.disable();
        }
    }

    // Log to server that an event happened
    static log(type, message = 'notice') {
        Core_Log.log(type, message);
    }

    // Returns true if the app is being run in dev mode
    // This should affect caching and some debug checks
    static is_dev() {
        return window.rsxapp.debug;
    }

    static is_prod() {
        return !window.rsxapp.debug;
    }

    // Returns true when running inside the SSR Node server
    static is_ssr() {
        return !!window.__SSR__;
    }

    // Returns true in the browser when hydrating an SSR-rendered page
    static is_ssr_hydrate() {
        return !!(window.rsxapp && window.rsxapp.page_data && window.rsxapp.page_data.ssr);
    }

    /**
     * Load a DECLARED external resource (a CDN library, a vendor widget) by identifier.
     *
     * The identifier comes from a *.externals.php declaration file; the CSP whitelist derives
     * from the same declarations, so this is the only sanctioned way to reach an external host.
     * Delegates to Rsx_External_Resources - see php artisan rsx:man external_resources.
     *
     * @param {string} identifier
     * @returns {Promise} resolves when the resource is usable.
     */
    static load_external(identifier) {
        return Rsx_External_Resources.load(identifier);
    }

    /**
     * Get the current logged-in user model instance
     * Returns the hydrated ORM model if available, or the raw data object
     * @returns {Rsx_Js_Model|Object|null} User model instance or null if not logged in
     */
    static user() {
        return window.rsxapp?.user || null;
    }

    /**
     * Get the current site model instance
     * Returns the hydrated ORM model if available, or the raw data object
     * @returns {Rsx_Js_Model|Object|null} Site model instance or null if not set
     */
    static site() {
        return window.rsxapp?.site || null;
    }

    // Generates a unique number for the application instance
    static uid() {
        if (typeof Rsx._uid == undef) {
            Rsx._uid = 0;
        }
        return Rsx._uid++;
    }

    // Storage for route definitions loaded from bundles
    static _routes = {};

    /**
     * The storage scope key for the current environment.
     *
     * This is the single accessor Rsx_Storage (key scoping + scope-change
     * invalidation) and Jqhtml_Integration (data cache key) read. The value is
     * computed SERVER-side - an md5 of session_hash / user id / site id / build_key
     * (see Rsx_Bundle_Abstract::_compute_cache_key) - and shipped as
     * window.rsxapp.cache_key, so the client never derives scope from session_hash
     * itself. It changes when the user logs out/swaps, the site changes, or the
     * application source is updated / redeployed.
     *
     * @returns {string}
     * @private
     */
    static scope_key() {
        return window.rsxapp?.cache_key || '';
    }

    /**
     * Generate URL for a controller route or SPA action
     *
     * This method generates URLs by looking up route patterns and replacing parameters.
     * It handles controller routes, SPA action routes, and Ajax endpoints.
     *
     * If the route is not found in the route definitions, a default pattern is used:
     * `/_/{controller}/{action}` with all parameters appended as query strings.
     *
     * Usage examples:
     * ```javascript
     * // Controller route (defaults to 'index' method)
     * const url = Rsx.Route('Frontend_Index_Controller');
     * // Returns: /dashboard
     *
     * // Controller route with explicit method
     * const url = Rsx.Route('Frontend_Client_View_Controller::view', 123);
     * // Returns: /clients/view/123
     *
     * // SPA action route
     * const url = Rsx.Route('Contacts_Index_Action');
     * // Returns: /contacts
     *
     * // Route with integer parameter (sets 'id')
     * const url = Rsx.Route('Contacts_View_Action', 123);
     * // Returns: /contacts/123
     *
     * // Route with named parameters (object)
     * const url = Rsx.Route('Contacts_View_Action', {id: 'C001'});
     * // Returns: /contacts/C001
     *
     * // Route with required and query parameters
     * const url = Rsx.Route('Contacts_View_Action', {
     *     id: 'C001',
     *     tab: 'history'
     * });
     * // Returns: /contacts/C001?tab=history
     *
     * // Placeholder route
     * const url = Rsx.Route('Future_Controller::#index');
     * // Returns: #
     * ```
     *
     * @param {string} action Controller class, SPA action, or "Class::method". Defaults to 'index' method if not specified.
     * @param {number|Object} [params=null] Route parameters. Integer sets 'id', object provides named params.
     * @returns {string} The generated URL
     */
    static Route(action, params = null) {
        if (typeof action !== 'string') {
            throw new Error('Rsx.Route: action must be a string, got ' + typeof action);
        }

        // Parse action into class_name and action_name
        // Format: "Controller_Name" or "Controller_Name::method_name" or "Spa_Action_Name"
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
            // String that looks like an integer - convert to number
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

        // Check if route exists in PHP controller definitions
        let pattern;
        if (Rsx._routes[class_name] && Rsx._routes[class_name][action_name]) {
            const route_patterns = Rsx._routes[class_name][action_name];

            // Route patterns are always arrays (even for single routes)
            pattern = Rsx._select_best_route_pattern(route_patterns, params_obj);

            if (!pattern) {
                // Route exists but no pattern satisfies the provided parameters
                const route_list = route_patterns.join(', ');
                throw new Error(
                    `No suitable route found for ${class_name}::${action_name} with provided parameters. ` +
                    `Available routes: ${route_list}`
                );
            }
        } else {
            // Not found in PHP routes - check if it's a SPA action
            pattern = Rsx._try_spa_action_route(class_name, params_obj);

            if (!pattern) {
                // Route not found - use default pattern /_/{controller}/{action}
                // For SPA actions, action_name defaults to 'index'
                pattern = `/_/${class_name}/${action_name}`;
            }
        }

        // Generate URL from pattern
        return Rsx._generate_url_from_pattern(pattern, params_obj);
    }

    /**
     * Select the best matching route pattern from available patterns based on provided parameters
     *
     * Selection algorithm:
     * 1. Filter patterns where all required parameters can be satisfied by provided params
     * 2. Among satisfiable patterns, prioritize those with MORE parameters (more specific)
     * 3. If tie, any pattern works (deterministic by using first match)
     *
     * @param {Array<string>} patterns Array of route patterns
     * @param {Object} params_obj Provided parameters
     * @returns {string|null} Selected pattern or null if none match
     */
    static _select_best_route_pattern(patterns, params_obj) {
        const satisfiable = [];

        for (const pattern of patterns) {
            // Extract required parameters from pattern
            const required_params = [];
            const matches = pattern.match(/:([a-zA-Z_][a-zA-Z0-9_]*)/g);
            if (matches) {
                // Remove the : prefix from each match
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

        // Return the pattern with the most parameters
        return satisfiable[0].pattern;
    }

    /**
     * Generate URL from route pattern by replacing parameters
     *
     * @param {string} pattern The route pattern (e.g., '/users/:id/view')
     * @param {Object} params Parameters to fill into the route
     * @returns {string} The generated URL
     */
    static _generate_url_from_pattern(pattern, params) {
        // Extract required parameters from the pattern
        const required_params = [];
        const matches = pattern.match(/:([a-zA-Z_][a-zA-Z0-9_]*)/g);
        if (matches) {
            // Remove the : prefix from each match
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
            throw new Error(`Required parameters [${missing.join(', ')}] are missing for route ${pattern}`);
        }

        // Build the URL by replacing parameters
        let url = pattern;
        const used_params = {};

        for (const param_name of required_params) {
            const value = params[param_name];
            // URL encode the value
            const encoded_value = encodeURIComponent(value);
            url = url.replace(':' + param_name, encoded_value);
            used_params[param_name] = true;
        }

        // Collect any extra parameters for query string
        // Filter out internal parameters that should not appear in URLs
        const internal_params = ['_loader_title_hint'];
        const query_params = {};
        let anchor = null;
        for (const key in params) {
            if (used_params[key] || internal_params.includes(key)) {
                continue;
            }

            // The reserved anchor key rides the hash, not the query string, so
            // Rsx.Route('Action', {at: 'install'}) deep-links to a section.
            // Mirrors Rsx::HASH_ANCHOR_KEY handling in Core/Rsx.php.
            // See: php artisan rsx:man anchors
            if (key === Rsx.HASH_ANCHOR_KEY) {
                anchor = params[key];
                continue;
            }

            query_params[key] = params[key];
        }

        // Append query string if there are extra parameters
        if (Object.keys(query_params).length > 0) {
            const query_string = Object.entries(query_params)
                .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
                .join('&');
            url += '?' + query_string;
        }

        // Anchor last - a fragment always terminates the URL
        if (anchor !== null && anchor !== undefined && anchor !== '') {
            url += '#' + Rsx.HASH_ANCHOR_KEY + '=' + encodeURIComponent(anchor);
        }

        return url;
    }

    /**
     * Try to find a route pattern for a SPA action class
     * Returns the route pattern or null if not found
     *
     * @param {string} class_name The action class name
     * @param {Object} params_obj The parameters for route selection
     * @returns {string|null} The route pattern or null
     */
    static _try_spa_action_route(class_name, params_obj) {
        // Get all classes from manifest
        const all_classes = Manifest.get_all_classes();

        // Find the class by name
        for (const class_info of all_classes) {
            if (class_info.class_name === class_name) {
                const class_object = class_info.class_object;

                // Check if it's a SPA action (has Spa_Action in prototype chain)
                if (typeof Spa_Action !== 'undefined' &&
                    class_object.prototype instanceof Spa_Action) {

                    // Get route patterns from decorator metadata
                    const routes = class_object._spa_routes || [];

                    if (routes.length > 0) {
                        // Select best matching route based on parameters
                        const selected = Rsx._select_best_route_pattern(routes, params_obj);

                        if (!selected) {
                            // Routes exist but none are satisfiable
                            throw new Error(
                                `No suitable route found for SPA action ${class_name} with provided parameters. ` +
                                `Available routes: ${routes.join(', ')}`
                            );
                        }

                        return selected;
                    }
                }

                // Found the class but it's not a SPA action or has no routes
                return null;
            }
        }

        // Class not found
        return null;
    }

    /**
     * Define routes from bundled data
     * Called by generated JavaScript in bundles
     */
    static _define_routes(routes) {
        // Merge routes into the global route storage
        for (const class_name in routes) {
            if (!Rsx._routes[class_name]) {
                Rsx._routes[class_name] = {};
            }
            for (const method_name in routes[class_name]) {
                Rsx._routes[class_name][method_name] = routes[class_name][method_name];
            }
        }
    }

    /**
     * Internal: Call a specific method on all classes that have it
     * Collects promises from return values and waits for all to resolve
     * @param {string} method_name The method name to call on all classes
     * @returns {Promise} Promise that resolves when all method calls complete
     */
    static async _rsx_call_all_classes(method_name) {
        const all_classes = Manifest.get_all_classes();
        const classes_with_method = [];
        const promise_pile = [];

        // Per-class try/catch exists ONLY for attribution - every failure is rethrown below
        const failures = [];

        for (const class_info of all_classes) {
            const class_object = class_info.class_object;
            const class_name = class_info.class_name;

            // Check if this class has the method (static methods are on the class itself)
            if (typeof class_object[method_name] === 'function') {
                classes_with_method.push(class_name);

                try {
                    const return_value = await class_object[method_name]();

                    // Collect promises from return value
                    if (return_value instanceof Promise) {
                        promise_pile.push(return_value);
                    } else if (Array.isArray(return_value)) {
                        for (const item of return_value) {
                            if (item instanceof Promise) {
                                promise_pile.push(item);
                            }
                        }
                    }
                } catch (error) {
                    failures.push({ class_name: class_name, error: error });
                }

                if (Rsx.__stopped) {
                    Manifest._throw_boot_failures(failures, method_name);
                    return;
                }
            }
        }

        Manifest._throw_boot_failures(failures, method_name);

        if (classes_with_method.length > 0) {
            console_debug('RSX_INIT', `${method_name}: ${classes_with_method.length} classes`);
        }

        // Await all promises before returning
        if (promise_pile.length > 0) {
            console_debug('RSX_INIT', `${method_name}: Awaiting ${promise_pile.length} promises`);
            await Promise.all(promise_pile);
        }
    }

    /**
     * Hydrate rsxapp.user and rsxapp.site into ORM model instances
     *
     * Checks if window.rsxapp.user and window.rsxapp.site contain raw data objects
     * with __MODEL markers, and if the corresponding model classes are available,
     * replaces them with proper ORM instances.
     *
     * This enables code like:
     *   const user = Rsx.user();
     *   await user.some_relationship();  // Works because user is a proper model instance
     */
    static _hydrate_rsxapp_models() {
        if (!window.rsxapp) {
            return;
        }

        // Hydrate user if present and has __MODEL marker
        if (window.rsxapp.user && window.rsxapp.user.__MODEL) {
            const UserClass = Manifest.get_class_by_name(window.rsxapp.user.__MODEL);
            // Check class exists and extends Rsx_Js_Model - @JS-DEFENSIVE-01-EXCEPTION - dynamic model resolution
            if (UserClass && Manifest.js_is_subclass_of(UserClass, Rsx_Js_Model)) {
                window.rsxapp.user = new UserClass(window.rsxapp.user);
                console_debug('RSX_INIT', `Hydrated rsxapp.user as ${window.rsxapp.user.__MODEL}`);
            }
        }

        // Hydrate site if present and has __MODEL marker
        if (window.rsxapp.site && window.rsxapp.site.__MODEL) {
            const SiteClass = Manifest.get_class_by_name(window.rsxapp.site.__MODEL);
            // Check class exists and extends Rsx_Js_Model - @JS-DEFENSIVE-01-EXCEPTION - dynamic model resolution
            if (SiteClass && Manifest.js_is_subclass_of(SiteClass, Rsx_Js_Model)) {
                window.rsxapp.site = new SiteClass(window.rsxapp.site);
                console_debug('RSX_INIT', `Hydrated rsxapp.site as ${window.rsxapp.site.__MODEL}`);
            }
        }
    }

    /**
     * Internal: Execute multi-phase initialization for all registered classes
     * This runs various initialization phases in order to properly set up the application
     * @returns {Promise} Promise that resolves when all initialization phases complete
     */
    static async _rsx_core_boot() {
        if (Rsx.__booted) {
            console.error('Rsx._rsx_core_boot called more than once');
            return;
        }

        Rsx.__booted = true;

        // Setup exception handlers first, before any initialization phases
        Rsx._setup_exception_handlers();

        // Hydrate rsxapp.user and rsxapp.site into ORM model instances
        // This must happen early, before any code tries to use these objects
        Rsx._hydrate_rsxapp_models();

        // Get all registered classes from the manifest
        const all_classes = Manifest.get_all_classes();

        console_debug('RSX_INIT', `Starting _rsx_core_boot with ${all_classes.length} classes`);

        if (!all_classes || all_classes.length === 0) {
            // No classes to initialize
            shouldnt_happen('No classes registered in js - there should be at least the core framework classes');
            return;
        }

        // Define initialization phases in order
        const phases = [
            { event: 'framework_core_define', method: '_on_framework_core_define' },
            { event: 'framework_modules_define', method: '_on_framework_modules_define' },
            { event: 'framework_core_init', method: '_on_framework_core_init' },
            { event: 'app_modules_define', method: 'on_app_modules_define' },
            { event: 'app_define', method: 'on_app_define' },
            { event: 'framework_modules_init', method: '_on_framework_modules_init' },
            { event: 'app_modules_init', method: 'on_app_modules_init' },
            { event: 'app_init', method: 'on_app_init' },
            { event: '_spa_init', method: '_on_spa_init' },
            { event: 'app_ready', method: 'on_app_ready' },
        ];

        // Execute each phase in order
        for (const phase of phases) {
            await Rsx._rsx_call_all_classes(phase.method);

            if (Rsx.__stopped) {
                return;
            }

            Rsx.trigger(phase.event);
        }

        // All phases complete
        console_debug('RSX_INIT', 'Initialization complete');

        // Skip browser-only post-boot steps in SSR
        if (!Rsx.is_ssr()) {
            // Ui refresh callbacks
            Rsx.trigger_refresh();

            // TODO: Find a good wait to wait for all jqhtml components to load, then trigger on_ready and on('ready') emulating the top level last syntax that jqhtml components operateas, but as a standard js class (such as a page class).  The biggest question is, how do we efficiently choose only the top level jqhtml components.  do we only consider components cretaed directly on blade templates? that seams reasonable...

            // Trigger _debug_ready event - this is ONLY for tooling like rsx:debug
            // DO NOT use this in application code - use on_app_ready() phase instead
            // This event exists solely for debugging tools that need to run after full initialization
            Rsx.trigger('_debug_ready');

            // Restore scroll position on page refresh
            // Use requestAnimationFrame to ensure DOM is fully rendered after SPA action completes
            requestAnimationFrame(() => {
                Rsx._restore_scroll_on_refresh();
            });
        }
    }

    /**
     * Storage key for scroll position in sessionStorage
     * @private
     */
    static _SCROLL_STORAGE_KEY = 'rsx_scroll_pos';

    /**
     * Pending scroll restoration state
     * Set when a refresh is detected, cleared when restoration succeeds
     * @private
     */
    static _pending_scroll = null;

    /**
     * Save scroll position to sessionStorage on scroll (debounced)
     * Called from scroll event listener set up in _restore_scroll_on_refresh
     * @private
     */
    static _scroll_save_timeout = null;
    static _save_scroll_position() {
        // Debounce: cancel pending save and schedule fresh one
        if (Rsx._scroll_save_timeout) {
            clearTimeout(Rsx._scroll_save_timeout);
        }

        Rsx._scroll_save_timeout = setTimeout(() => {
            const scroll_data = {
                url: window.location.pathname + window.location.search,
                x: window.scrollX,
                y: window.scrollY
            };
            sessionStorage.setItem(Rsx._SCROLL_STORAGE_KEY, JSON.stringify(scroll_data));
        }, 100); // 100ms debounce
    }

    /**
     * Initialize scroll restoration on page refresh
     * Sets up scroll saving and queues restoration if this is a refresh
     * @private
     */
    static _restore_scroll_on_refresh() {
        // Set up scroll listener to continuously save position
        window.addEventListener('scroll', Rsx._save_scroll_position, { passive: true });

        // Check if this is a page refresh using Performance API
        const nav_entries = performance.getEntriesByType('navigation');
        if (nav_entries.length === 0) {
            return;
        }

        const nav_type = nav_entries[0].type;
        if (nav_type !== 'reload') {
            return;
        }

        // This is a refresh - load stored scroll position
        const stored = sessionStorage.getItem(Rsx._SCROLL_STORAGE_KEY);
        if (!stored) {
            return;
        }

        try {
            const scroll_data = JSON.parse(stored);
            const current_url = window.location.pathname + window.location.search;

            // Only restore if URL matches
            if (scroll_data.url !== current_url) {
                sessionStorage.removeItem(Rsx._SCROLL_STORAGE_KEY);
                return;
            }

            // Queue the scroll restoration
            Rsx._pending_scroll = { x: scroll_data.x, y: scroll_data.y };

            // Clear stored position (we've loaded it into memory)
            sessionStorage.removeItem(Rsx._SCROLL_STORAGE_KEY);

            // Try first restoration attempt
            Rsx.try_restore_scroll();
        } catch (e) {
            // Invalid JSON or other error - ignore
            sessionStorage.removeItem(Rsx._SCROLL_STORAGE_KEY);
        }
    }

    /**
     * Attempt to restore scroll position if pending
     *
     * Can be called multiple times during page load. Checks if the page is tall
     * enough to scroll to the target position. Once successful, subsequent calls
     * are no-ops until the next page refresh.
     *
     * Call this after content that may increase page height has loaded
     * (e.g., after SPA action on_ready completes).
     *
     * @returns {boolean} True if scroll was restored, false if pending/skipped
     */
    static try_restore_scroll() {
        // No pending restoration
        if (!Rsx._pending_scroll) {
            return false;
        }

        const target = Rsx._pending_scroll;

        // Check if page is tall enough to scroll to target position
        // Page needs to be at least (target.y + viewport height) tall
        const page_height = document.documentElement.scrollHeight;
        const viewport_height = window.innerHeight;
        const min_height_needed = target.y + viewport_height;

        if (page_height < min_height_needed) {
            // Page not tall enough yet - keep pending for retry
            console_debug('Spa', `Scroll restore waiting: page ${page_height}px < needed ${min_height_needed}px`);
            return false;
        }

        // Page is tall enough - restore scroll position
        window.scrollTo({
            left: target.x,
            top: target.y,
            behavior: 'instant'
        });

        // Mark as complete - clear pending state
        Rsx._pending_scroll = null;

        console_debug('Spa', `Scroll restored to (${target.x}, ${target.y})`);
        return true;
    }

    /**
     * Reset pending scroll state
     * Called by SPA dispatch to clear any pending restoration from previous navigation
     */
    static reset_pending_scroll() {
        Rsx._pending_scroll = null;
    }

    /* Calling this stops the boot process. */
    static async _rsx_core_boot_stop(reason) {
        console.error(reason);
        Rsx.__stopped = true;
    }

    /**
     * Parse URL hash into key-value object
     * Handles format: #key=value&key2=value2
     *
     * BARE FRAGMENTS normalize to the reserved anchor key (Rsx.HASH_ANCHOR_KEY).
     * A web-standard `#some-heading` has no `=`, so it used to parse as
     * {'some-heading': ''} - a value _serialize_hash() then DROPPED, silently
     * destroying the anchor the moment any component wrote hash state (a
     * DataGrid page, a tab). Normalizing it to {at: 'some-heading'} both fixes
     * that round-trip and lets a pasted standard URL drive Spa anchor scrolling.
     *
     * See: php artisan rsx:man anchors
     *
     * @returns {Object} Parsed hash parameters
     */
    static _parse_hash() {
        const hash = window.location.hash;
        if (!hash || hash === '#') {
            return {};
        }

        // Remove leading # and parse as query string
        const hash_string = hash.substring(1);
        const params = {};

        const pairs = hash_string.split('&');
        for (const pair of pairs) {
            if (!pair) {
                continue;
            }

            // No '=' at all: a bare fragment. Treat it as the anchor key so it
            // survives serialization instead of being filtered out as empty.
            if (!pair.includes('=')) {
                params[Rsx.HASH_ANCHOR_KEY] = decodeURIComponent(pair);
                continue;
            }

            const [key, value] = pair.split('=');
            if (key) {
                params[decodeURIComponent(key)] = value ? decodeURIComponent(value) : '';
            }
        }

        return params;
    }

    /**
     * Serialize object into URL hash format
     * Produces format: #key=value&key2=value2
     *
     * @param {Object} params Key-value pairs to encode
     * @returns {string} Encoded hash string (with leading #, or empty string)
     */
    static _serialize_hash(params) {
        const pairs = [];
        for (const key in params) {
            const value = params[key];
            if (value !== null && value !== undefined && value !== '') {
                pairs.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
            }
        }

        return pairs.length > 0 ? '#' + pairs.join('&') : '';
    }

    /**
     * Get all hash state from URL hash
     *
     * Usage:
     * ```javascript
     * const state = Rsx.url_hash_get_all();
     * // Returns: {dg_page: '2', dg_sort: 'name'}
     * ```
     *
     * @returns {Object} All hash parameters as key-value pairs
     */
    static url_hash_get_all() {
        return Rsx._parse_hash();
    }

    /**
     * Get single value from URL hash state
     *
     * Usage:
     * ```javascript
     * const page = Rsx.url_hash_get('dg_page');
     * // Returns: '2' or null if not set
     * ```
     *
     * @param {string} key The key to retrieve
     * @returns {string|null} The value or null if not found
     */
    static url_hash_get(key) {
        const state = Rsx._parse_hash();
        return state[key] ?? null;
    }

    /**
     * Set single value in URL hash state (replaces history, doesn't add)
     *
     * Usage:
     * ```javascript
     * Rsx.url_hash_set_single('dg_page', 2);
     * // URL becomes: http://example.com/page#dg_page=2
     *
     * Rsx.url_hash_set_single('dg_page', null); // Remove key
     * ```
     *
     * @param {string} key The key to set
     * @param {string|number|null} value The value (null/empty removes the key)
     */
    static url_hash_set_single(key, value) {
        const state = Rsx._parse_hash();

        // Update or remove the key
        if (value === null || value === undefined || value === '') {
            delete state[key];
        } else {
            state[key] = String(value);
        }

        // Update URL without adding history
        const new_hash = Rsx._serialize_hash(state);
        const url = window.location.pathname + window.location.search + new_hash;
        history.replaceState(null, '', url);
    }

    /**
     * Set multiple values in URL hash state at once
     *
     * Usage:
     * ```javascript
     * Rsx.url_hash_set({dg_page: 2, dg_sort: 'name'});
     * // URL becomes: http://example.com/page#dg_page=2&dg_sort=name
     *
     * Rsx.url_hash_set({dg_page: null}); // Remove key from hash
     * ```
     *
     * @param {Object} new_state Object with key-value pairs to set (null removes key)
     */
    static url_hash_set(new_state) {
        const state = Rsx._parse_hash();

        // Merge new state
        for (const key in new_state) {
            const value = new_state[key];
            if (value === null || value === undefined || value === '') {
                delete state[key];
            } else {
                state[key] = String(value);
            }
        }

        // Update URL without adding history
        const new_hash = Rsx._serialize_hash(state);
        const url = window.location.pathname + window.location.search + new_hash;
        history.replaceState(null, '', url);
    }

    /**
     * Render an error in a DOM element
     *
     * Displays errors from Ajax calls in a standardized format. Handles different
     * error types (fatal, validation, auth, generic) with appropriate formatting.
     *
     * Usage:
     * ```javascript
     * try {
     *     const result = await Controller.method();
     * } catch (error) {
     *     Rsx.render_error(error, '#error_container');
     * }
     * ```
     *
     * @param {Error|Object} error - Error object from Ajax call
     * @param {jQuery|string} container - jQuery element or selector for error display
     */
    static render_error(error, container) {
        const $container = $(container);

        if (!$container.exists()) {
            console.error('Rsx.render_error: Container not found', container);
            return;
        }

        // Clear existing content
        $container.empty();

        let html = '';

        // Handle different error types
        if (error.type === 'fatal' && error.details) {
            // Fatal PHP error with file/line/error
            const details = error.details;
            const file = details.file || 'Unknown file';
            const line = details.line || '?';
            const message = details.error || error.message || 'Fatal error occurred';

            html = `
                <div class="alert alert-danger" role="alert">
                    <h5>Uncaught Fatal Error in ${file}:${line}:</h5>
                    <p class="mb-0">${Rsx._escape_html(message)}</p>
                </div>
            `;
        } else if (error.code === Ajax.ERROR_VALIDATION && error.metadata) {
            // Validation errors - show unmatched errors only
            // (matched errors should be handled by Form_Utils.apply_form_errors)
            const errors = error.metadata;
            const error_list = [];

            for (const field in errors) {
                error_list.push(errors[field]);
            }

            if (error_list.length > 0) {
                html = `
                    <div class="alert alert-warning" role="alert">
                        <h5>Validation Errors:</h5>
                        <ul class="mb-0">
                            ${error_list.map((err) => `<li>${Rsx._escape_html(err)}</li>`).join('')}
                        </ul>
                    </div>
                `;
            }
        } else if (error.type === 'auth_required' || error.type === 'unauthorized') {
            // Authentication/authorization errors
            const message = error.message || 'Authentication required';
            html = `
                <div class="alert alert-warning" role="alert">
                    <p class="mb-0">${Rsx._escape_html(message)}</p>
                </div>
            `;
        } else if (error.type === 'network') {
            // Network errors
            const message = error.message || 'Unable to reach server. Please check your connection.';
            html = `
                <div class="alert alert-danger" role="alert">
                    <p class="mb-0">${Rsx._escape_html(message)}</p>
                </div>
            `;
        } else {
            // Generic/unknown error
            const message = error.message || error.toString() || 'An unknown error occurred';
            html = `
                <div class="alert alert-danger" role="alert">
                    <p class="mb-0">${Rsx._escape_html(message)}</p>
                </div>
            `;
        }

        $container.html(html);
    }

    /**
     * Escape HTML to prevent XSS in error messages
     * @private
     */
    static _escape_html(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Validate current session state against server
     *
     * Called after SPA navigation to detect stale state requiring page refresh:
     * 1. Codebase updates - build_key changed means new deployment
     * 2. User changes - name, role, permissions updated by admin
     * 3. Session changes - logged out, ACLs modified, session invalidated
     *
     * If any values differ from window.rsxapp, triggers a transparent page refresh
     * using location.replace() to avoid polluting browser history.
     *
     * @returns {Promise<boolean>} True if session is valid, false if refresh triggered
     */
    static async validate_session() {
        try {
            // Call the session validation endpoint. Pass is_portal so the server
            // validates against the portal session (Portal_Session) on portal pages
            // rather than the staff session (which is empty in portal context and
            // would otherwise force a refresh on every navigation).
            const server_state = await Spa_Session_Controller.get_state({ is_portal: Rsx_Portal.is_portal() });

            // Compare with current client state
            const client_state = window.rsxapp;
            let needs_refresh = false;
            let refresh_reason = null;

            // Check build_key - codebase was updated
            if (server_state.build_key !== client_state.build_key) {
                needs_refresh = true;
                refresh_reason = 'codebase_updated';
                console_debug('Rsx', 'Session validation: build_key changed, triggering refresh');
            }

            // Check session_hash - session was invalidated or changed
            if (!needs_refresh && server_state.session_hash !== client_state.session_hash) {
                needs_refresh = true;
                refresh_reason = 'session_changed';
                console_debug('Rsx', 'Session validation: session_hash changed, triggering refresh');
            }

            // Check user - user account details changed (null check for logout detection)
            if (!needs_refresh) {
                const server_user_id = server_state.user?.id ?? null;
                const client_user_id = client_state.user?.id ?? null;

                if (server_user_id !== client_user_id) {
                    needs_refresh = true;
                    refresh_reason = 'user_changed';
                    console_debug('Rsx', 'Session validation: user changed, triggering refresh');
                }
            }

            // Check site - site context changed
            if (!needs_refresh) {
                const server_site_id = server_state.site?.id ?? null;
                const client_site_id = client_state.site?.id ?? null;

                if (server_site_id !== client_site_id) {
                    needs_refresh = true;
                    refresh_reason = 'site_changed';
                    console_debug('Rsx', 'Session validation: site changed, triggering refresh');
                }
            }

            if (needs_refresh) {
                // Use location.replace() to refresh without adding history entry
                // This ensures back/forward navigation isn't polluted by the refresh
                console.warn('[Rsx] Session validation failed (' + refresh_reason + '), refreshing page');
                window.location.replace(window.location.href);
                return false;
            }

            return true;

        } catch (e) {
            // On network error or endpoint failure, don't block navigation
            // The user will eventually hit a stale state naturally
            console.warn('[Rsx] Session validation failed (network error), continuing', e);
            return true;
        }
    }
}
