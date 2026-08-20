/**
 * Rsx_External_Resources - the client half of the declarative external-resources registry.
 *
 * Every external URL a page loads (a CDN library, a vendor widget) is DECLARED in a
 * `*.externals.php` file beside the feature that needs it. The bundle compiler bakes the
 * realm-appropriate resolved map into the bundle by calling _define() below, and page code
 * reaches a resource by IDENTIFIER only:
 *
 *     await Rsx.load_external('turnstile');
 *
 * Nothing else may inject a <script> or <link> at an external host: the Content-Security-Policy
 * whitelist DERIVES from the same declarations, so an undeclared resource is a blocked resource
 * by construction and policy can never drift from code.
 *
 * ONE LOAD PER IDENTIFIER. load() memoizes its promise per identifier (the pdf.js precedent),
 * so however many components ask for a resource, the tags are appended exactly once and every
 * caller awaits the same settlement.
 *
 * THE READINESS CONTRACT. A declaration says how the resource announces that it is USABLE, and
 * there are exactly two spellings:
 *
 * - `'onload'` - the elements' own load events. The promise resolves once every css link and
 *   every js script has loaded.
 * - `{callback_param: 'name'}` - the script's URL carries `?name=<global>` (Turnstile's
 *   `onload=__rsx_turnstile_onload`), and the script calls that named global when it is ready.
 *   THE LOADER OWNS THE GLOBAL: it reads the function name out of the URL's query string,
 *   defines window[name] BEFORE appending the script, and resolves when the script calls it.
 *   The declared URL is the single source of truth for the name - consuming code never mints it.
 *
 * FAIL LOUD. An unknown identifier throws SYNCHRONOUSLY (a typo is a programming error, not a
 * load failure). A failed fetch rejects the promise naming the URL. There is no timeout, no
 * polling and no retry anywhere in this class: a resource that never arrives leaves its promise
 * pending, exactly as the browser leaves the request.
 *
 * See: php artisan rsx:man external_resources
 */
class Rsx_External_Resources {
    /** identifier => {js, css, integrity, readiness}, baked by BundleCompiler. */
    static _map = {};

    /** identifier => in-flight or settled load promise. The one-load guarantee. */
    static _promises = {};

    /**
     * Receive the baked map. Called once per bundle, at the bundle tail.
     *
     * @param {Object} map identifier => {js: [], css: [], integrity: {}, readiness}
     */
    static _define(map) {
        Rsx_External_Resources._map = map;
    }

    /**
     * Framework core init: in development, explain every CSP violation the browser reports.
     */
    static _on_framework_core_init() {
        if (Rsx.is_ssr()) return;
        if (!Rsx.is_dev()) return;

        document.addEventListener('securitypolicyviolation', Rsx_External_Resources._on_violation);
    }

    /**
     * The declared spec for one identifier.
     *
     * @param {string} identifier
     * @returns {Object}
     * @throws {Error} when nothing declares the identifier.
     */
    static get(identifier) {
        const entry = Rsx_External_Resources._map[identifier];

        if (!entry) {
            const declared = Object.keys(Rsx_External_Resources._map);
            const known = declared.length === 0 ? '(none declared)' : declared.join(', ');

            throw new Error(
                `Unknown external resource '${identifier}'\n`
                + `  Declared identifiers: ${known}\n`
                + '  Declare it in a *.externals.php file beside the feature that needs it.\n'
                + '  See: php artisan rsx:man external_resources'
            );
        }

        return entry;
    }

    /**
     * Load a declared external resource, once per page.
     *
     * @param {string} identifier
     * @returns {Promise} resolves when the resource is USABLE per its readiness contract.
     * @throws {Error} SYNCHRONOUSLY when the identifier is not declared.
     */
    static load(identifier) {
        const entry = Rsx_External_Resources.get(identifier);

        if (Rsx_External_Resources._promises[identifier]) {
            return Rsx_External_Resources._promises[identifier];
        }

        const promise = Rsx_External_Resources._load_entry(identifier, entry).catch((error) => {
            console_debug('EXTERNALS', `Failed to load external resource '${identifier}': ${error.message}`);
            throw error;
        });

        Rsx_External_Resources._promises[identifier] = promise;

        return promise;
    }

    /**
     * Append the entry's elements: every stylesheet first, then each script in declared order.
     *
     * @param {string} identifier
     * @param {Object} entry
     * @returns {Promise}
     */
    static async _load_entry(identifier, entry) {
        const callback_param = (entry.readiness && entry.readiness.callback_param) || null;
        const callback = callback_param
            ? Rsx_External_Resources._prepare_callback(identifier, entry, callback_param)
            : null;

        await Promise.all(entry.css.map((url) => Rsx_External_Resources._append_link(entry, url)));

        for (const url of entry.js) {
            await Rsx_External_Resources._append_script(entry, url);
        }

        if (callback) {
            await callback;
        }
    }

    /**
     * Define the named global the script will call, and return the promise it settles.
     *
     * The name is read out of the declared URL's query string - the declaration is the
     * single source of truth for the handshake.
     *
     * @param {string} identifier
     * @param {Object} entry
     * @param {string} callback_param the query parameter naming the global (e.g. 'onload')
     * @returns {Promise}
     */
    static _prepare_callback(identifier, entry, callback_param) {
        let global_name = null;

        for (const url of entry.js) {
            const value = new URL(url, window.location.href).searchParams.get(callback_param);

            if (value) {
                global_name = value;
                break;
            }
        }

        if (!global_name) {
            throw new Error(
                `External resource '${identifier}' declares readiness callback_param `
                + `'${callback_param}', but no declared js URL carries that query parameter.\n`
                + '  The URL in the *.externals.php file must name the global the script calls.\n'
                + '  See: php artisan rsx:man external_resources'
            );
        }

        return new Promise((resolve) => {
            window[global_name] = function () {
                resolve();
            };
        });
    }

    /**
     * Append one <link rel=stylesheet>, resolving on load and rejecting on error.
     *
     * @param {Object} entry
     * @param {string} url
     * @returns {Promise}
     */
    static _append_link(entry, url) {
        return new Promise((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = url;

            Rsx_External_Resources._apply_attributes(link, entry, url);

            link.onload = function () {
                resolve();
            };

            link.onerror = function () {
                reject(new Error(`Failed to load external stylesheet ${url}`));
            };

            document.head.appendChild(link);
        });
    }

    /**
     * Append one <script async>, resolving on load and rejecting on error.
     *
     * @param {Object} entry
     * @param {string} url
     * @returns {Promise}
     */
    static _append_script(entry, url) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = url;
            script.async = true;

            Rsx_External_Resources._apply_attributes(script, entry, url);

            script.onload = function () {
                resolve();
            };

            script.onerror = function () {
                reject(new Error(`Failed to load external script ${url}`));
            };

            document.head.appendChild(script);
        });
    }

    /**
     * The CSP nonce (when the page exported one) and the declared subresource-integrity hash.
     *
     * A hash is declared per URL and only survives for a URL still fetched from its external
     * host, so integrity and crossorigin are set together or not at all.
     *
     * @param {HTMLElement} element
     * @param {Object} entry
     * @param {string} url
     */
    static _apply_attributes(element, entry, url) {
        const nonce = window.rsxapp.csp_nonce;

        if (nonce) {
            element.nonce = nonce;
        }

        const hash = entry.integrity ? entry.integrity[url] : null;

        if (hash) {
            element.integrity = hash;
            element.crossOrigin = 'anonymous';
        }
    }

    /**
     * Development-only remediation for a blocked resource.
     *
     * The browser has already printed its own error by the time this runs; this adds the part
     * the browser cannot know - that in RSpade the policy is DERIVED from declarations, so the
     * fix is a declaration rather than a hand-edited header.
     *
     * @param {SecurityPolicyViolationEvent} event
     */
    static _on_violation(event) {
        const directive = event.effectiveDirective || event.violatedDirective || '(unknown directive)';
        const blocked = event.blockedURI || '(inline)';

        // report_only means the browser REPORTED this and loaded the resource anyway; saying
        // "blocked" then would send a developer hunting a breakage that did not happen.
        const report_only = window.rsxapp.csp.report_only;
        const verb = report_only ? ' reported (not blocked, the policy is report-only) ' : ' blocked ';

        console.warn(
            '[RSX CSP] ' + directive + verb + blocked + '\n'
            + '  RSpade derives its Content-Security-Policy from DECLARED external resources.\n'
            + '  Declare the resource in a *.externals.php file beside the feature that needs it,\n'
            + "  then load it by identifier with Rsx.load_external('<identifier>').\n"
            + '  See: php artisan rsx:man external_resources\n'
            + '  This violation was also reported to /_csp-report.'
        );
    }
}
