/**
 * JQHTML Integration - Component Registration and Hydration Bootstrap
 *
 * This module bridges RSpade's manifest system with jqhtml's component runtime.
 *
 * == TWO-PHASE INITIALIZATION ==
 *
 * Phase 1: _on_framework_modules_define() - Component Registration
 *   - Runs early in framework boot, before DOM is processed
 *   - Registers all ES6 classes extending Component with jqhtml runtime
 *   - Tags static methods with cache IDs for jqhtml's caching system
 *   - After this phase, jqhtml knows: "User_Card" → UserCardClass
 *
 * Phase 2: _on_framework_modules_init() - DOM Hydration
 *   - Calls jqhtml.boot() to hydrate all ._Component_Init placeholders
 *   - Triggers 'jqhtml_ready' when all components are initialized
 *
 * == KEY PARTICIPANTS ==
 *
 * JqhtmlBladeCompiler.php  - Transforms <Component /> tags into ._Component_Init divs
 * jqhtml runtime           - Maintains registry of component names → classes
 * jqhtml.boot()            - Finds and hydrates all ._Component_Init placeholders
 * This module              - Orchestrates registration and triggers hydration
 */
class Jqhtml_Integration {
    /**
     * Phase 1: Register Component Classes
     *
     * Compiled .jqhtml templates self-register their render methods with jqhtml.
     * But the framework must separately register ES6 component classes (the ones
     * extending Component with lifecycle methods like on_create, on_load, etc).
     *
     * This runs during framework_modules_define, before any DOM processing.
     */
    static _on_framework_modules_define() {
        // ─────────────────────────────────────────────────────────────────────
        // SSR Preload Data Injection
        //
        // If the page was SSR-rendered, window.rsxapp.ssr_preload contains
        // captured component data from the SSR server. Seed jqhtml's preload
        // cache so on_load() is skipped for components with matching data.
        // Must happen before component registration / DOM hydration.
        // ─────────────────────────────────────────────────────────────────────
        if (window.rsxapp && window.rsxapp.page_data && window.rsxapp.page_data.ssr_preload && typeof jqhtml.set_preload_data === 'function') {
            jqhtml.set_preload_data(window.rsxapp.page_data.ssr_preload);
            console_debug('JQHTML_INIT', 'SSR preload data seeded: ' + window.rsxapp.page_data.ssr_preload.length + ' entries');
        }

        // ─────────────────────────────────────────────────────────────────────
        // Register Component Classes with jqhtml Runtime
        //
        // The Manifest knows all classes extending Component. We register each
        // with jqhtml so it can instantiate them by name during hydration.
        // ─────────────────────────────────────────────────────────────────────
        let jqhtml_components = Manifest.get_extending('Component');
        console_debug('JQHTML_INIT', 'Registering ' + jqhtml_components.length + ' Components');

        for (let component of jqhtml_components) {
            jqhtml.register_component(component.class_name, component.class_object);
        }

        // ─────────────────────────────────────────────────────────────────────
        // Tag Static Methods with Cache IDs
        //
        // jqhtml caches component renders based on a hash of their args.
        // Problem: Functions can't be serialized, so passing one (e.g., a
        // DataGrid's data_source callback) would defeat caching entirely.
        //
        // Solution: Tag every static method with a stable string identifier.
        // When jqhtml hashes component args, it uses _jqhtml_cache_id instead
        // of the function reference, making the cache key deterministic.
        //
        // Example:
        //   <My_DataGrid $data_source=Controller.fetch />
        //
        //   Without tagging: args hash includes [Function] → uncacheable
        //   With tagging:    args hash includes "Controller.fetch" → cacheable
        //
        // This enables Ajax endpoints and other callbacks to be passed to
        // components without breaking the automatic caching system.
        // ─────────────────────────────────────────────────────────────────────
        const all_classes = Manifest.get_all_classes();
        let methods_tagged = 0;

        for (const class_info of all_classes) {
            const class_object = class_info.class_object;
            const class_name = class_info.class_name;
            const property_names = Object.getOwnPropertyNames(class_object);

            for (const property_name of property_names) {
                if (property_name === 'length' || property_name === 'name' || property_name === 'prototype') {
                    continue;
                }

                const property_value = class_object[property_name];
                if (typeof property_value === 'function') {
                    property_value._jqhtml_cache_id = `${class_name}.${property_name}`;
                    methods_tagged++;
                }
            }
        }

        console_debug('JQHTML_INIT', `Tagged ${methods_tagged} static methods with _jqhtml_cache_id`);

        // ─────────────────────────────────────────────────────────────────────
        // Configure jqhtml Caching
        //
        // scope_key() changes when: app code changes, user logs out, site changes.
        // This automatically invalidates cached component renders.
        //
        // Data Cache Mode (jqhtml 2.x):
        // - Enforces hot/cold cache parity - fresh data and cached data behave identically
        // - Any ES6 class instance stored in this.data must be registered with jqhtml
        // - Without registration, class instances become plain objects on cache restore
        //   (properties preserved but prototype methods lost)
        // ─────────────────────────────────────────────────────────────────────
        jqhtml.set_cache_key(Rsx.scope_key(), 'data');
        window.jqhtml.debug.verbose = false;

        // ─────────────────────────────────────────────────────────────────────
        // Register All Classes with jqhtml for Cache Serialization
        //
        // Loop through all classes already registered with Manifest and register
        // them with jqhtml. This enables proper serialization of class instances
        // stored in this.data during data caching.
        // ─────────────────────────────────────────────────────────────────────
        if (typeof jqhtml.register_cache_class === 'function') {
            const all_classes = Manifest.get_all_classes();
            for (const class_info of all_classes) {
                jqhtml.register_cache_class(class_info.class_object);
            }
            console_debug('JQHTML_INIT', `Registered ${all_classes.length} classes for cache serialization`);
        }
    }

    /**
     * Phase 2: DOM Hydration
     *
     * Delegates to jqhtml.boot() which finds all ._Component_Init placeholders
     * and converts them into live components.
     *
     * jqhtml.boot() handles:
     * - Finding ._Component_Init elements
     * - Parsing data-component-init-name / data-component-args
     * - Calling $element.component(name, args)
     * - Recursive nested component handling
     * - Promise tracking for async components
     */
    static async _on_framework_modules_init() {
        await jqhtml.boot();

        // Clear any remaining SSR preload data after hydration completes
        if (typeof jqhtml.clear_preload_data === 'function') {
            jqhtml.clear_preload_data();
        }

        Rsx.trigger('jqhtml_ready');
    }
}

// Class is automatically made global by RSX manifest - no window assignment needed
