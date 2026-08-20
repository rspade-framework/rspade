# JQHTML Integration - RPC Server Architecture

## Overview
JQHTML template compilation uses long-running Node.js RPC server via Unix socket to avoid spawning Node.js processes for each `.jqhtml` file during bundle builds.

## Components
- `JqhtmlWebpackCompiler.php` - PHP client, manages compiler server lifecycle
- `jqhtml-compile-server.js` - Node.js RPC server (ES module), uses @jqhtml/parser API directly
- `jqhtml-compile` (legacy) - CLI from @jqhtml/parser npm package (no longer called)

## Server Lifecycle
1. **Lazy start:** Server spawns on first JQHTML compilation during bundle build
2. **Startup:** Checks for stale socket, force-kills if found, starts fresh server
3. **Wait:** Polls socket with ping (50ms intervals, budget = `rsx.javascript.rpc_server_ready_wait_ms`, default 10s). On expiry it kills what it spawned, clears its handle so the NEXT use is a clean retry, and throws a diagnostic built by `Rpc_Startup_Diagnostics` that reports what was OBSERVED (process exited with its stderr / no socket / stale socket / missing script) rather than guessing at a cause. Never suggests `npm install` inside `system/` - that is an owned zone.
4. **Usage:** All JQHTML compilations during bundle builds go through RPC
5. **Shutdown:** Graceful shutdown when bundle compilation completes (registered shutdown handler)

## Socket
- **Path:** `storage/rsx-tmp/jqhtml-compile-server.sock`
- **Protocol:** Line-delimited JSON over Unix domain socket

## RPC Methods
- `ping` → `"pong"` - Health check
- `compile` → `{file: {status, result}, ...}` - Batch compile multiple templates
- `shutdown` → Graceful server termination

## PHP API
```php
JqhtmlWebpackCompiler::ensure_rpc_server();       // Lazy init, auto-called (Rpc_Client_Abstract)
JqhtmlWebpackCompiler::stop_rpc_server($force);   // Shutdown message, then reap by pid
JqhtmlWebpackCompiler::_compile_via_rpc(...);     // Internal RPC compilation
```

## Compilation Details
- Uses `compileTemplate` from `@jqhtml/parser` directly (not CLI)
- Always compiles in IIFE format with sourcemap support
- Maintains existing cache strategy (mtime-based)
- Throws `Jqhtml_Exception_ViewException` for template errors with line/column info

## Cache Integration
Cache checked before RPC call - only uncached or stale templates sent to server for compilation.
Cache directory: `storage/rsx-tmp/jqhtml-cache/`

## Error Handling
Server failure → fatal error (no fallback). Server must start or bundle compilation fails.
Template errors include line/column information for precise error reporting.

## Performance Impact
**Before RPC:** N Node.js process spawns (one per .jqhtml file needing compilation)
**After RPC:** Single persistent Node.js process, reused across all compilations (~1-2s startup overhead)

## ES Module Support
The RPC server is an ES module (uses `import` syntax) to directly use @jqhtml/parser's ES module exports.
This provides direct API access without CLI wrapper overhead.
