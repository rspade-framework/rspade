/**
 * Turnstile - client-side readiness gate for Cloudflare's Turnstile script.
 *
 * Turnstile is the CAPTCHA replacement: a background browser challenge that mints a
 * single-use token the server exchanges for a verdict. Cloudflare's api.js is what
 * renders the widget; this class is the one place that asks for it.
 *
 * THE SCRIPT IS DECLARED, NOT HARDCODED. The URL lives in Core/Js/turnstile.externals.php
 * under the identifier 'turnstile', and it is fetched through Rsx.load_external('turnstile').
 * That declaration is also what puts challenges.cloudflare.com in the page's
 * Content-Security-Policy, so declaring and loading are the same act - see
 * php artisan rsx:man external_resources.
 *
 * THE ONE-SCRIPT GUARANTEE. However many Turnstile_Input components a page renders, the
 * script is requested exactly once (a static guard here, plus the loader's own memoized
 * promise). The declared URL carries render=explicit, so nothing auto-renders and every
 * widget is created deliberately by the component that owns its container.
 *
 * QUEUE SEMANTICS. init(callback) is the only entry point. Before the script has loaded,
 * callbacks queue; the loader resolves when Cloudflare calls the readiness global named in
 * the declared URL (readiness => callback_param), which flips the loaded flag and flushes
 * the queue in registration order. After it has loaded, init() calls back SYNCHRONOUSLY.
 * There is no polling, no setTimeout and no deadline anywhere in this class or in the
 * loader - the script's own readiness callback is the sole signal. If the script never
 * arrives (offline, blocked), the queued callbacks simply never fire and every widget stays
 * in its pre-active state: no token is produced, and the server rejects a tokenless submit
 * regardless, so the failure mode is closed rather than silent.
 *
 * NO-OP WHEN INACTIVE. Turnstile is active only when the server exported a site key into
 * window.rsxapp.turnstile (config('rsx.turnstile.enabled') with both keys set). When it is
 * absent, init() loads nothing and NEVER calls back - callers must treat "my callback did
 * not run" as the normal disabled case, which is why it also returns false.
 *
 * See: php artisan rsx:man turnstile
 */
class Turnstile {
    /** The declared external-resource identifier (Core/Js/turnstile.externals.php). */
    static EXTERNAL_IDENTIFIER = 'turnstile';

    /** Has the script been requested from the loader? The one-script guarantee. */
    static _requested = false;

    /** Has Cloudflare's readiness callback fired? window.turnstile is usable once this is true. */
    static _loaded = false;

    /** Callbacks registered before the script finished loading, in registration order. */
    static _queue = [];

    /**
     * Is Turnstile active on this page?
     *
     * @returns {boolean}
     */
    static is_active() {
        return !!window.rsxapp.turnstile;
    }

    /**
     * The PUBLIC site key the server exported, or null when the feature is disabled.
     *
     * @returns {string|null}
     */
    static site_key() {
        return window.rsxapp.turnstile || null;
    }

    /**
     * Run a callback once Cloudflare's script is loaded, loading it on the first call.
     *
     * @param {Function} callback Invoked with no arguments once window.turnstile exists.
     * @returns {boolean} false when Turnstile is inactive (the callback will never run).
     */
    static init(callback) {
        if (!Turnstile.is_active()) {
            return false;
        }

        if (Turnstile._loaded) {
            callback();
            return true;
        }

        Turnstile._queue.push(callback);

        if (!Turnstile._requested) {
            Turnstile._requested = true;

            // A failed fetch leaves the queue intact and is NOT retried: there is no widget
            // to show and nothing useful to do until the page is loaded again, which starts
            // from a clean slate anyway. Reported on the debug channel rather than as an
            // error, because a blocked third-party script is an environment fact.
            Rsx.load_external(Turnstile.EXTERNAL_IDENTIFIER).then(Turnstile._flush).catch(function (error) {
                console_debug('turnstile', 'Turnstile: ' + error.message
                    + ' - widgets will stay inactive until the page is reloaded.');
            });
        }

        return true;
    }

    /**
     * Readiness reached. Flips the loaded flag and drains the queue in order.
     */
    static _flush() {
        Turnstile._loaded = true;

        while (Turnstile._queue.length > 0) {
            const callback = Turnstile._queue.shift();
            callback();
        }
    }
}
