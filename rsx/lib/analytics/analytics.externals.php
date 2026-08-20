<?php

/**
 * External resource declaration: Google Analytics (gtag.js).
 *
 * THE TEMPLATE APP'S WORKED EXAMPLE of "an app declares an external resource, then loads it
 * lazily only when the feature is actually turned on". Everything an RSX page fetches from an
 * external host is declared in a file like this one, beside the feature that needs it; the CSP
 * whitelist DERIVES from these declarations, so declaring the resource here is what makes the
 * browser allowed to fetch it. Nothing else may inject a <script> at an external host.
 *
 * The loader half is rsx/lib/analytics/Analytics.js.
 *
 * INERT BY DEFAULT. Declaring a resource costs one CSP origin and nothing else - no tag is
 * appended and no request is made until something calls Rsx.load_external('analytics'), which
 * this template does only when a measurement id is configured (rsx.analytics.measurement_id in
 * rsx/resource/config/rsx.php, empty by default).
 *
 * URL: BARE AND STATIC. gtag.js also accepts the measurement id as ?id=G-XXXXXXX, but a
 * declared URL is a constant - it is what the CSP and the sealed-build mirror are computed
 * from, so it can never carry per-install configuration. The bare loader is the supported
 * form: the id is supplied at runtime by the standard dataLayer/gtag('config', id) bootstrap
 * in Analytics.js.
 *
 * mirror => false. Google serves gtag.js dynamically - the bytes are account- and
 * client-specific and the script fetches further Google code at runtime. A locally mirrored
 * copy would be stale and functionally wrong, so this resource is fetched from its origin in
 * every mode and its origin stays in the CSP whitelist in sealed builds too.
 *
 * TRANSITIVE ORIGINS ARE NOT DECLARED HERE - and that is the point of the cross-reference.
 * This declaration can name only what OUR code loads. gtag.js then loads further scripts and
 * beacons to Google origins it picks at runtime (www.google-analytics.com, analytics.google.com
 * and friends), which no declaration in this tree can enumerate. Those are exactly what the
 * config `csp.additional_sources` block exists for; the ready-to-uncomment list sits beside the
 * measurement id in rsx/resource/config/rsx.php.
 *
 * realm => 'staff'. This declares the resource for the staff application only. A portal
 * analytics property is a different account and a different consent story, so it would be its
 * own declaration with realm 'portal' - never a widening of this one to 'both'.
 *
 * See: php artisan rsx:man external_resources, php artisan rsx:man csp
 */

return [
    'analytics' => [
        'js' => [
            'https://www.googletagmanager.com/gtag/js',
        ],
        'mirror' => false,
        'realm' => 'staff',
        'readiness' => 'onload',
    ],
];
