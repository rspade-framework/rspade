---
name: external-resources
description: Loading an external CDN library, vendor widget or third-party script into an RSX page - declaring it in a *.externals.php file, loading it by identifier with Rsx.load_external(), the readiness contract, sealed-build mirroring, and how the Content-Security-Policy derives from the declaration. Use when adding a CDN library or vendor script, wiring Google Analytics or a tag manager, diagnosing a "Refused to load ... Content Security Policy" or "[RSX CSP]" console warning, hitting "Unknown external resource", triaging storage/logs/csp_violations.log, or deciding whether to enforce the policy.
---

# External Resources and CSP

**Every external URL a page loads is DECLARED.** Nothing else may inject a `<script>` or `<link>` at an external host - the Content-Security-Policy whitelist is COMPUTED from the declarations, so an undeclared resource is a blocked resource and policy can never drift from code.

Two files, no wiring:

```php
// rsx/app/frontend/reports/charts.externals.php  (NOT under resource/ or public/ - those are manifest-invisible)
<?php

return [
    'chartjs' => [
        'js'    => ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'],
        'realm' => 'staff',
    ],
];
```

```javascript
async on_load() {
    await Rsx.load_external('chartjs');     // memoized: tags appended once per page
    this.state.chart = new Chart(this.$sid('canvas')[0], { ... });
}
```

A declaration is **inert** - it costs one CSP source and nothing else. Nothing is fetched until something calls `load_external()`.

---

## Spec keys

| Key | Meaning |
|---|---|
| `js` / `css` | Lists of absolute `https://` URLs. At least one must be non-empty. |
| `integrity` | `url => sha384-...`, keys must be this entry's own URLs. Applies only while still fetched externally. |
| `mirror` | Default `true`. `false` for a vendor endpoint serving DYNAMIC per-client bytes (Turnstile's `api.js`, Google's `gtag.js`) - a local copy would be stale and wrong. |
| `realm` | `staff` / `portal` / `both` (default). A portal-only widget never widens the staff policy. |
| `readiness` | `'onload'` (default) or `['callback_param' => 'onload']` - see below. |
| `csp` | Extra directives describing what the script DOES at runtime (frames it opens, hosts it calls). Applies in every mode. Asset origins derive from `js`/`css` and are never listed here. |

Identifiers are one **flat namespace**, `lowercase_with_underscores`. A duplicate is a manifest-build FATAL naming both files.

**`readiness` callback_param**: the declared URL carries `?<param>=<global_name>`; the loader reads the name out of the URL, defines `window[name]` before appending the script, and resolves when the vendor calls it. **The URL is the single source of truth for the name** - consuming code never mints one.

---

## Decision: registry vs bundle `cdn_assets`

- **`cdn_assets`** (Asset Bundle) = a HEAD asset fetched on **every** page the bundle serves. For a global library or CSS framework.
- **The registry** = an **on-demand** load, fetched only when code asks. For a feature-specific library or widget. Realm split, readiness handshake and per-entry CSP extras exist only here.

---

## Modes

- **Development**: raw URL; the vendor host is in the CSP whitelist.
- **Sealed (debug/production)**: `mirror:true` entries are mirrored at build time (`[2/5] Mirroring external assets`) into `rsx/resource/.cdn-cache/` and served from `/_vendor/` - same-origin, so the host drops out of the policy. `mirror:false` keeps its raw URL and its whitelist entry.
- **A sealed build NEVER downloads at request time.** A missing mirror file throws; re-run `rsx:prod:refresh`.

---

## CSP

Ships **report-only** by default (`csp.report_only => true`): violations POST to `/_csp-report` and land in `storage/logs/csp_violations.log`, and nothing is blocked. Framework inline scripts carry a **nonce** (`'unsafe-inline'` never appears in script-src); style-src keeps `'unsafe-inline'` and deliberately has **no nonce** (a nonce would make browsers ignore it and break RSX's pervasive inline styles).

**Triage, then flip.** Every log line has exactly one correct fix:

| Violation | Fix |
|---|---|
| A resource the page loads itself | Declare it in `*.externals.php`, load by identifier |
| An origin a DECLARED script fetches on its own (analytics, tag manager) | `rsx.csp.additional_sources` (widen-only; `object-src` refused) |
| An inline `<script>` in app markup | Move the code into a bundled `.js` file - **never** reach for the nonce |
| `eval` / `Function` from framework code | **STOP and escalate** - `'unsafe-eval'` is a framework decision, not an app one |

Then set `csp.report_only => false`. Report-only is a silent state; the flip is a prelaunch task.

---

## Pitfalls

- **Declaration file under `resource/` or `public/`** - manifest-invisible, so the identifier throws as unknown. Put it beside the feature's code.
- **`Unknown external resource '<id>'`** - thrown SYNCHRONOUSLY (a typo is a programming error); the message lists every declared identifier.
- **Per-install configuration in a declared URL** - a declared URL is a CONSTANT (the CSP origin and the mirror filename derive from it). Supply ids/keys at runtime through the vendor's own bootstrap, in bundled code.
- **`mirror:true` on a dynamic vendor endpoint** - works in development, breaks in a sealed build.
- **Inventing a timeout** because a load "hangs" - the loader has none by design.

Full contract: `php artisan rsx:man external_resources` and `php artisan rsx:man csp`. Worked examples: `system/app/RSpade/Core/Js/turnstile.externals.php` (framework), `rsx/lib/analytics/` (template app).
