# rsx/companions/

The application's companion applications: long-running processes that are part of this code
tree, run by the framework's `rsx-companiond` and calling the application as a peer. Nothing here
runs until an administrator starts it.

```
hello/
    hello_companion_service.php    class Hello_Companion_Service extends Companion_Service_Abstract
    resource/main.js       the process
```

**One directory per companion**, named after it: `<name>/<name>_companion_service.php` declares it and
`<name>/resource/` holds its source. Node source lives under `resource/` because the manifest does
not scan that directory; anywhere else under `rsx/` it would be read as browser JavaScript.

**`hello`** is the worked example, and it is safe to delete: a `hello` endpoint that writes nothing,
called by a Node process that heartbeats, sets a status line, calls it every 30 seconds, honours
`pause` and `resume`, and exits cleanly on SIGTERM.

**Running them**: `php artisan rsx:companion_apps:start [name]` / `stop` / `restart` / `list`. Desired
state is a flag file per box under `storage/state/companions/`; output is in
`storage/logs/companions/<name>/`. A Node companion uses the framework SDK,
`require('rsx-companion-sdk')` (on `NODE_PATH` when the daemon starts it): `ready()`, `status()`,
`state()`, `progress()`, `error()`, the `pause`/`resume`/`stop` events, and `companion.client.<endpoint>()`
(which names no site; `companion.call(endpoint, params, {site_id})` does).
`hello/resource/main.js` is the shape to copy.

**Watching them**: `/_sys/companions` for a developer. Anyone else is denied until a handler in
`rsx/handlers/` answers `companion.view.authorize` / `companion.control.authorize`; this
application registers none, and none for `companion.error`, so a companion's reported errors go
only to its log.

**Adding an endpoint**: a `public static` method with the `(Request $request, array $params = [])`
signature, marked `#[Companion_Endpoint('<name>')]`, on the companion class or any class with no
class-level `#[Auth]`. No `#[Auth]`: the request's signature is the gate.

**Three traps**: the site is DECLARED by each request (on a multi tenant install a request naming no
site is refused, and a task the endpoint dispatches runs on no site, so pass `site_id` in its
params); endpoints are IDEMPOTENT and enqueue rather than work, because a companion killed mid-call
repeats the call; and there is NO VERSIONING: a companion ships in the same commit as its endpoints,
and a stale one is refused with `contract_mismatch` and replaced.

Contract: `php artisan rsx:man companion_apps`; skill `rspade:companion-apps`.
