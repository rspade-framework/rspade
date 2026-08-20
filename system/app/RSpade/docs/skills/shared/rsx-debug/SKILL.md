---
name: rsx-debug
description: Rendering and inspecting RSX routes headlessly with php artisan rsx:debug - Playwright-backed page capture, authenticated and portal sessions, screenshots at device widths, layout dimension dumps, and in-page JavaScript evaluation. Use when verifying a page change actually renders, capturing a screenshot, debugging a layout or component that "looks wrong", reading console_debug output from a real page load, testing a portal screen, or when a route returns 404 and you are tempted to blame "SPA can't be tested server-side".
---

# rsx:debug - headless route rendering

`php artisan rsx:debug /path` drives a real headless browser (Playwright), so what it reports is what a user's browser produced: full JS execution, component lifecycles, XHR, console output, final DOM.

Development only - it throws in a production environment by design.

## Two rulings that stop wasted investigation

**SPA routes ARE server routes.** If you get 404, the route doesn't exist - check route definitions. Never dismiss as "SPA can't be tested server-side".

Remediation flow for a 404: confirm the `@route` decorator (JS action) or `#[Route]`/`#[SPA]` attribute exists and spells the path you asked for -> confirm the action's `@spa(...)` names a real SPA controller -> confirm the module's bootstrap controller exists. Do not reach for a different tool.

**rsx:debug captures the fully-rendered final DOM state** after all async operations, component lifecycles, and data loading complete. If the DOM doesn't match expectations, it's not a timing issue - what you see is what the user sees. Investigate the actual code, not the capture timing.

Remediation flow for wrong DOM: read the component's `on_load()`/`on_render()`/`on_ready()`, check the endpoint it calls with `rsx:ajax`, check `--console` for a thrown error. Adding waits is not the fix; there is nothing left to wait for.

## Authentication

```bash
rsx:debug /dashboard --user=1                   # staff user by id
rsx:debug /dashboard --user=admin@example.com   # staff user by email
```

`--user` bypasses the login screen and browses as that identity. The sessions this creates are `TYPE_PLAYWRIGHT` and are purged at the end of the run (run-scoped, not request-scoped - the page's own XHRs ride the same session cookie, so per-request cleanup would break them).

## Portal routes

```bash
rsx:debug /dashboard --portal --portal-user=1
rsx:debug /workspace --portal --portal-user=client@example.com
```

`--portal` selects the portal channel (prefix + portal authentication); `--portal-user` requires it. A portal screen browsed without `--portal` is a staff request and will be gated as one.

## Screenshots

```bash
rsx:debug /page --screenshot-path=/tmp/page.png
rsx:debug /page --screenshot-width=mobile --screenshot-path=/tmp/mobile.png
rsx:debug /page --screenshot-width=1024 --screenshot-path=/tmp/custom.png
```

`--screenshot-path` triggers capture (max height 5000px). `--screenshot-width` takes a pixel number or one of these presets - **this is the authoritative list, verified against `Route_Debug_Command.php` and `bin/route-debug.js`**:

| Preset | Width | Modelled on |
|---|---:|---|
| `mobile` | 412 | Pixel 7 |
| `iphone-mobile` | 390 | iPhone 12/13/14 |
| `tablet` | 768 | iPad Mini |
| `desktop-small` | 1366 | common laptop |
| `desktop-medium` | 1920 | Full HD |
| `desktop-large` | 2560 | 2K/WQHD |

Default width is 1920.

**TRAP: these preset names are NOT the SCSS breakpoint names.** RSX SCSS uses `mobile`/`desktop` (tier 1) and `phone`/`phone-sm`/`phone-lg`/`tablet`/`desktop-sm`/`desktop-md`/`desktop-lg`/`desktop-xl` (tier 2). `--screenshot-width=desktop-small` is a 1366px viewport, which lands in the SCSS `desktop-md` band, not `desktop-sm`. When you are verifying a specific breakpoint, pass the pixel number you actually want to test rather than a preset whose name resembles the breakpoint.

## Layout debugging

```bash
rsx:debug /page --dump-dimensions=".Card_Widget"
```

Injects position/size/margin/padding onto every element matching the selector as a `data-dimensions` attribute, so overlap, collapse, and unexpected-width problems are readable in the captured DOM instead of guessed at from a screenshot.

## Evaluating JavaScript in the page

```bash
# result via return value
rsx:debug / --eval="return Rsx_Time.now_iso()"

# result via console (needs --console)
rsx:debug / --console --eval="console.log(Rsx_Date.today())"

# simulate an interaction, then capture
rsx:debug /contacts --eval="$('.btn-add').click(); await sleep(1000)"
```

- The eval body is async - `await` works, and `sleep(ms)` is available.
- **`return` gives you the value**; anything logged needs `--console` to be shown.
- The DOM is captured AFTER the eval completes, which is what makes this the way to test click/input behaviour headlessly.

## Other options worth knowing

| Option | Use |
|---|---|
| `--console` | all browser console output plus `console_debug()` |
| `--console-debug-filter=CHANNEL` | narrow `console_debug()` to one channel |
| `--xhr-list` / `--xhr-dump` | which endpoints the page called; full request/response detail |
| `--wait-for=".selector"` | wait for a selector before capture |
| `--expect-element=".selector"` | fail the command if the element is absent (assertion-style checks) |
| `--dump-element=".selector"` | print just that element's HTML |
| `--input-elements` | list form inputs with their values |
| `--storage` / `--cookies` | localStorage/sessionStorage contents; cookies |
| `--post='{"k":"v"}'` | POST instead of GET |
| `--log` / `--all-logs` | Laravel log (and nginx logs) after the run |
| `--full` | everything except `--no-body`/`--follow-redirects` |
| `--examples` | the command's own example set |

## See also

`rsx:man rsx_debug` for the full reference. For calling an endpoint directly without a page, use `rsx:ajax` (endpoints fragment).
