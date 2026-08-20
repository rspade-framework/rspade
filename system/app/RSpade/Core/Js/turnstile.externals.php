<?php

/**
 * External resource declaration: Cloudflare Turnstile.
 *
 * This is the framework's own worked example of the `*.externals.php` format. A file like
 * this sits beside the feature that needs an external URL, returns a bare array of
 * identifier => spec, and is consolidated into the manifest at build time. The CSP
 * whitelist derives from these declarations, so declaring the resource here is what makes
 * the browser allowed to fetch it.
 *
 * mirror => false. Cloudflare serves api.js dynamically - the bytes are challenge- and
 * client-specific and the script coordinates with challenges.cloudflare.com at runtime.
 * A locally mirrored copy would be stale and functionally wrong, so this resource is
 * fetched from its origin in EVERY mode, and its origin stays in the CSP whitelist in
 * sealed builds as well.
 *
 * readiness. The URL carries render=explicit (nothing auto-renders) plus
 * onload=__rsx_turnstile_onload: Cloudflare calls that named global when the script is
 * usable, which is the readiness signal instead of the element's own load event. The
 * callback_param declaration is what documents that handshake.
 *
 * csp. The extra directives describe what the script DOES once running - it opens the
 * challenge in an iframe, calls home, and injects its own styles - none of which is
 * implied by the script URL itself.
 *
 * See: php artisan rsx:man external_resources, php artisan rsx:man turnstile
 */

return [
    'turnstile' => [
        'js' => [
            'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=__rsx_turnstile_onload',
        ],
        'mirror' => false,
        'realm' => 'both',
        'readiness' => ['callback_param' => 'onload'],
        'csp' => [
            'frame-src' => ['https://challenges.cloudflare.com'],
            'connect-src' => ['https://challenges.cloudflare.com'],
            'style-src' => ['https://challenges.cloudflare.com'],
        ],
    ],
];
