# JavaScript Core Systems - RPC Server Architecture

## Overview
JavaScript parsing and transformation use long-running Node.js RPC servers via Unix sockets to avoid spawning 1000+ Node processes during builds.

## Rpc_Client_Abstract - THE way to build an RPC helper client

`Rpc_Client_Abstract` (this directory) is the ONE lifecycle. Every helper client extends it:
`Js_Parser`, `Js_Transformer`, `Minifier` (`Core/Bundle/`), `JqhtmlWebpackCompiler`
(`Integrations/Jqhtml/`), `FileSanitizer` and `Js_CodeQuality_Rpc` (`CodeQuality/Support/`).

A child declares three constants and nothing else about lifecycle:

```php
class Js_Parser extends Rpc_Client_Abstract
{
    protected const RPC_SERVER_SCRIPT = 'app/RSpade/Core/JsParsers/resource/js-parser-server.js';
    protected const RPC_SOCKET        = 'storage/rsx-tmp/js-parser-server.sock';  // project-relative
    protected const RPC_LABEL         = 'JS Parser';                              // diagnostics only
}
```

The base owns spawn, ping, ready-wait, stop, self-heal and provenance. **The child keeps its
own request marshaling, its own protocol quirks and its own caches** - the base does not touch
them. A missing constant throws at the point of use, naming the class and the constant.

### Base API
```php
static::ensure_rpc_server();       // call before you talk to the daemon (idempotent per process)
static::ping_rpc_server(): bool;   // is it answering
static::stop_rpc_server($force);   // graceful shutdown message, THEN reap by pid ($force skips the message)
static::get_rpc_process(): ?Process;             // the handle THIS process published, or null
Rpc_Client_Abstract::quiesce_all(): int;         // kill every rsx-tmp daemon of any family; returns the count
```

Spawn always goes through `RsxLocks::command_without_inherited_locks()` - a daemon that
inherits an flock descriptor holds that lock for its whole idle life, and `Manifest::init()`
locks at boot, so the whole CLI wedges. It is applied in the base precisely so no future
helper can be written without it. The process handle is published **only after** the daemon
answers; publishing before the ready-wait left a non-null handle after a failed start, so
every later caller skipped the spawn and died at "socket not found" instead (field report,
2026-08-11: one slow boot, six failed bundles).

### Freshness: the `.meta` provenance contract
At spawn the client writes `<socket>.meta` containing the **sha1 of the server script it
launched**. A running daemon is reused only when: the socket file exists AND that hash still
matches the script on disk AND a ping is answered. Anything else is self-heal-killed and
respawned.

This is what makes an edited server script (dev) or a freshly-pulled one (framework update)
invalidate its daemon AT SOURCE, on every box, without anyone remembering to reap.
`.meta` lives inside `rsx-tmp` deliberately: `rsx:clean` wipes it, and a MISSING meta reads as
"unknown provenance", which forces a fresh spawn.

### Self-heal, and why a socket message is not teardown
`stop_rpc_server()` and the reuse path both end in the same reaper:
`pgrep -f -- '--socket=<abs path>'`, SIGTERM, one settle pass, SIGKILL, then remove the socket
and its `.meta`. A daemon whose socket file was unlinked under it (by `rsx:clean`, by a newer
daemon rebinding the path, by a storage relocation) keeps a listener on the dead inode forever
and can NEVER receive another socket message - but its argv still names the path, so pgrep
still finds it. The match is family-narrow (this socket only), so other families are untouched.

`quiesce_all()` is the same reaper widened to the whole `storage/rsx-tmp/` socket directory:
it matches on argv, not on family, so it takes down all six helpers, `ssr-server`, duplicates,
and any family added later without knowing they exist. `rsx:clean` calls it immediately before
wiping `rsx-tmp`; `bin/maintenance-mode.sh enable` runs the bash twin of it. See
`bin/CLAUDE.md` for the normative rule.

### Process lifetime (MEASURED 2026-08-13, not assumed)
- **CLI**: Symfony's `Process::__destruct` calls `stop(0)`, so a daemon spawned by an artisan
  run dies with that process. Verified directly.
- **php-fpm**: the worker outlives the request, and a daemon spawned during one keeps serving
  later requests (three consecutive `rsx:debug` renders reused the same two pids). This is the
  case the reuse path exists for, and why a daemon must be trusted by PROVENANCE rather than
  because it answers.

Orphans come from the abrupt deaths neither path covers - a SIGKILLed worker, an interrupted
build - which is what the ten-deep live pile on this box turned out to be. Nothing registers a
shutdown function: on CLI the destructor already reaps what this process spawned, and a socket
left behind by a killed daemon is detected and removed by the next `ensure_rpc_server()`.

## JS Parser - RPC Server

### Components
- `Js_Parser.php` - PHP client (extends `Rpc_Client_Abstract`)
- `js-parser-server.js` - Node.js RPC server, processes batch parse requests

### Server Lifecycle
1. **Lazy start:** `ensure_rpc_server()` on first JS file parse during manifest build
2. **Startup:** reuse if socket + `.meta` + ping all agree; otherwise self-heal-kill and spawn fresh
3. **Wait:** Polls socket with ping (50ms intervals, budget = `rsx.javascript.rpc_server_ready_wait_ms`, default 10s). On expiry it kills what it spawned, leaves NO handle so the NEXT use is a clean retry, and throws a diagnostic built by `Rpc_Startup_Diagnostics` that reports what was OBSERVED (process exited with its stderr / no socket / stale socket / missing script) rather than guessing at a cause. Never suggests `npm install` inside `system/` - that is an owned zone.
4. **Usage:** All JS parsing during build goes through RPC (batched when possible)
5. **Shutdown:** `stop_rpc_server()`, the CLI process destructor, or a quiesce (`rsx:clean`, maintenance enable)

### Socket
- **Path:** `storage/rsx-tmp/js-parser-server.sock` (+ `.sock.meta` provenance sidecar)
- **Protocol:** Line-delimited JSON over Unix domain socket

### RPC Methods
- `ping` → `"pong"` - Health check
- `parse` → `{file: result, ...}` - Batch parse multiple files
- `shutdown` → Graceful server termination

### PHP API
```php
Js_Parser::ensure_rpc_server();       // Lazy init, auto-called
Js_Parser::stop_rpc_server($force);   // Shutdown message, then reap by pid
Js_Parser::parse($file_path);         // Cached parse (the class's own marshaling)
```

## JS Transformer (Babel) - RPC Server

### Components
- `Js_Transformer.php` - PHP client (extends `Rpc_Client_Abstract`)
- `js-transformer-server.js` - Node.js RPC server, processes batch Babel transformations

### Server Lifecycle
1. **Lazy start:** `ensure_rpc_server()` on first JS transformation during bundle compilation
2. **Startup:** reuse if socket + `.meta` + ping all agree; otherwise self-heal-kill and spawn fresh
3. **Wait:** Polls socket with ping (50ms intervals, budget = `rsx.javascript.rpc_server_ready_wait_ms`, default 10s). On expiry it kills what it spawned, leaves NO handle so the NEXT use is a clean retry, and throws a diagnostic built by `Rpc_Startup_Diagnostics` that reports what was OBSERVED (process exited with its stderr / no socket / stale socket / missing script) rather than guessing at a cause. Never suggests `npm install` inside `system/` - that is an owned zone.
4. **Usage:** All JS transformations during bundle builds go through RPC
5. **Shutdown:** `stop_rpc_server()`, the CLI process destructor, or a quiesce (`rsx:clean`, maintenance enable)

### Socket
- **Path:** `storage/rsx-tmp/js-transformer-server.sock` (+ `.sock.meta` provenance sidecar)
- **Protocol:** Line-delimited JSON over Unix domain socket

### RPC Methods
- `ping` → `"pong"` - Health check
- `transform` → `{file: {status, result, hash}, ...}` - Batch transform multiple files
- `shutdown` → Graceful server termination

### PHP API
```php
Js_Transformer::ensure_rpc_server();       // Lazy init, auto-called
Js_Transformer::stop_rpc_server($force);   // Shutdown message, then reap by pid
Js_Transformer::_transform_via_rpc(...);   // Internal RPC transformation
```

### Transformation Details
- Preprocesses `@decorator` on standalone functions
- Applies Babel transformations: decorators, class properties, optional chaining, nullish coalescing
- Prefixes generated helper functions with file hash to prevent namespace collisions
- Uses target presets: `modern`, `es6`, `es5`
- Generates inline source maps for debugging

### Decorator class-binding (vendored fork)
Output is concatenated into a shared, non-module bundle scope, so every decorated class
must keep its module-scope bare-name binding. Upstream Babel drops that binding for a
decorated class DECLARATION with static members. The decorator transform is therefore
vendored and patched at `resource/babel-plugin-decorators/` (a self-contained esbuild
bundle, `@babel/core` externalized) which emits `var <Name> = <uid>;` at the producer.
See that dir's `README.md`. This replaced a fragile output-shape matcher (retired) that
broke when `@babel/compat-data` 7.29.3 changed the destructuring baseline.

`js-transformer-server.js` no longer rewrites decorator output; its prefix plugin now
only (1) prefixes generated helpers and (2) carries a **fail-closed contract assertion** -
it records decorated class declarations pre-transform and throws (naming class + file) if
any lost its program-scope binding post-transform. The server also `module.exports` its
internals (`transformFileContent`, `createPrefixPlugin`) when required as a module, guarded
by `require.main === module`, so the `js_transform` test concern can drive it directly.

`Js_Transformer.php` folds a toolchain fingerprint (fork bundle + server script +
`@babel/core` version) into every cache key, so swapping the toolchain invalidates cached
transforms automatically. The daemon itself is invalidated by the `.meta` provenance check
(see the base class above), so a changed server script is picked up on the next use with no
manual restart.

**CORRECTION (2026-08-13).** This section used to claim the RPC server is "spawned on demand
per build process and torn down at process end (NOT a persistent daemon)". Half of that is
true and the half that is not produced a ten-deep orphan pile. A CLI-spawned daemon IS torn
down at process end (Symfony's `Process` destructor calls `stop(0)`), but a daemon spawned
inside a php-fpm request SURVIVES the request and is reused by later ones - it is a persistent
daemon in that context - and neither path covers an abrupt death. Freshness is therefore
guaranteed by the `.meta` provenance check, not by the process boundary. See PROCESS LIFETIME
above.

## Common RPC Pattern

### Force Parameter
`stop_rpc_server($force = false)`:
- `false` (default): Send the socket `shutdown` message first, then reap by pid
- `true`: Skip the message and go straight to the reap (the message cannot reach an orphan whose socket was already unlinked, which is why the reap always runs)

### Cache Integration
Cache checked before RPC call - only uncached files sent to server for processing.

### Error Handling
Server failure → fatal error, no alternative path. The server must start or build/compilation fails.

### Performance Impact
**Before RPC:** N Node.js process spawns (where N = number of files needing processing)
**After RPC:** One Node.js daemon per server type (~1-2s startup overhead), reused for as long as its provenance holds

## Adding another RPC server
Extend `Rpc_Client_Abstract`, declare the three constants, call `ensure_rpc_server()` before
your first request, and write your marshaling. Put the socket under `storage/rsx-tmp/` so
`quiesce_all()` and the bash twin in `bin/maintenance-mode.sh` collect it without being taught
about it. Worked pattern for the node side (protocol, argv, signal handling):
`docs.dev/reference/rpc_server_integration_patterns.md`.
