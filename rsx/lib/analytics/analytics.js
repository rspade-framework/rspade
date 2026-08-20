/**
 * Analytics - the template app's worked example of the external-resources pattern.
 *
 * Pair this file with analytics.externals.php beside it: the declaration says WHAT may be
 * fetched and from where (and so what the Content-Security-Policy permits), this class
 * decides WHEN - and the answer is "only when the feature is actually configured".
 *
 * The whole pattern in three lines:
 *
 *     await Rsx.load_external('analytics');   // appends the declared <script>, once per page
 *     window.dataLayer = window.dataLayer || [];
 *     gtag('config', measurement_id);         // vendor bootstrap, in BUNDLED code
 *
 * NO INLINE <script>. Google's copy-paste snippet is an inline script tag in the page head;
 * that is exactly what the CSP forbids, and it is unnecessary - the same three statements run
 * from this bundled file, where they need no nonce and break no policy.
 *
 * CONFIGURATION. rsx.analytics.measurement_id (rsx/resource/config/rsx.php, empty by default)
 * reaches the browser through Frontend_Bundle::load_rsxapp_data(), which exports it into
 * window.rsxapp.page_data only when it is set. No id configured means this class returns
 * immediately: no tag, no request, no console noise, and the declaration's origin is the only
 * trace the shipped app leaves.
 *
 * DEVELOPMENT NEVER REPORTS. A developer's page loads are not traffic, and polluting a
 * production property with them is the classic analytics mistake, so the loader is gated on
 * Rsx.is_prod() (true in the sealed debug and production builds, false in development). To
 * exercise the loader in development, call Analytics._start('G-XXXXXXX') from the console.
 *
 * SCOPE OF THE EXAMPLE. This sends the initial page_view GA4 produces on config() and nothing
 * more; a real integration would also send one per SPA navigation. The subject here is the
 * external-resources pattern, not GA4 coverage.
 *
 * See: php artisan rsx:man external_resources, php artisan rsx:man csp
 */
class Analytics {
    /**
     * Boot hook: load and configure analytics when this install has it turned on.
     */
    static async on_app_ready() {
        // The SSR renderer is not a browser session and must never report one.
        if (Rsx.is_ssr()) return;

        const measurement_id = window.rsxapp.page_data?.analytics_measurement_id;

        if (!measurement_id) return;
        if (!Rsx.is_prod()) return;

        await Analytics._start(measurement_id);
    }

    /**
     * Fetch gtag.js by identifier, then run Google's standard bootstrap against it.
     *
     * @param {string} measurement_id the GA4 property, e.g. 'G-XXXXXXXXXX'
     * @returns {Promise}
     */
    static async _start(measurement_id) {
        await Rsx.load_external('analytics');

        window.dataLayer = window.dataLayer || [];

        // gtag() forwards its raw arguments object - the shape gtag.js consumes.
        window.gtag = function () {
            window.dataLayer.push(arguments);
        };

        window.gtag('js', new Date());
        window.gtag('config', measurement_id);
    }
}
