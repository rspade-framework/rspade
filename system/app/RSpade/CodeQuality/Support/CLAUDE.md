# Code Quality Support Classes

## FileSanitizer - RPC Server Architecture

### Overview
JavaScript sanitization uses long-running Node.js RPC server via Unix socket to avoid spawning 1000+ Node processes during manifest build.

### Components
- `FileSanitizer.php` - PHP client, manages server lifecycle
- `js-sanitizer-server.js` - Node.js RPC server, processes batch sanitization requests
- `js-sanitizer.js` - Legacy single-file sanitizer (kept in `/bin/` for compatibility)

### Server Lifecycle
1. **Lazy start:** Server spawns on first JS file sanitization during code quality checks
2. **Startup:** Checks for stale socket, force-kills if found, starts fresh server
3. **Wait:** Polls socket with ping (50ms intervals, budget = `rsx.javascript.rpc_server_ready_wait_ms`, default 10s). On expiry it kills what it spawned, clears its handle so the NEXT use is a clean retry, and throws a diagnostic built by `Rpc_Startup_Diagnostics` that reports what was OBSERVED (process exited with its stderr / no socket / stale socket / missing script) rather than guessing at a cause. Never suggests `npm install` inside `system/` - that is an owned zone.
4. **Usage:** All JS sanitization during checks goes through RPC
5. **Shutdown:** Graceful shutdown when code quality checks complete (registered shutdown handler)

### Socket
- **Path:** `storage/rsx-tmp/js-sanitizer-server.sock`
- **Protocol:** Line-delimited JSON over Unix domain socket

### RPC Methods
- `ping` → `"pong"` - Health check
- `sanitize` → `{file: {status, sanitized, original_lines}, ...}` - Batch sanitize multiple files
- `shutdown` → Graceful server termination

### PHP API
```php
FileSanitizer::sanitize_javascript($file_path);  // Auto-starts RPC server, uses cache
FileSanitizer::ensure_rpc_server();              // Lazy init, auto-called (Rpc_Client_Abstract)
FileSanitizer::stop_rpc_server($force);          // Shutdown message, then reap by pid
```

### Force Parameter
`stop_rpc_server($force = false)`:
- `false` (default): Send the socket `shutdown` message first, then reap by pid
- `true`: Skip the message and go straight to the reap (a socket message can never reach a daemon whose socket file was already unlinked)

### Cache Integration
Cache checked before RPC call - only files with stale cache sent to server for sanitization. Cache directory: `storage/rsx-tmp/cache/js-sanitized/`

### Error Handling
Server failure → fatal error (no fallback). Server must start or code quality check fails.

### Sanitization Process
1. **Remove comments:** Uses `decomment` npm package to strip comments while preserving line numbers
2. **Replace string contents:** Parses with Acorn AST parser, replaces string literal contents with spaces
3. **Preserve structure:** Maintains line/column positions for accurate violation reporting

### Performance Impact
Before RPC: 900+ Node.js process spawns during manifest build (~30-60s overhead)
After RPC: Single Node.js process, reused across all sanitizations (~1-2s startup overhead)

### Parallel to JS Parser
This architecture mirrors the JS parser RPC server pattern. See `/app/RSpade/Core/JsParsers/CLAUDE.md` for detailed RPC pattern documentation.

## Js_CodeQuality_Rpc - RPC Server Architecture

### Overview
JavaScript linting and this-usage analysis use a long-running Node.js RPC server via Unix socket to avoid spawning thousands of Node processes during code quality checks.

### Components
- `Js_CodeQuality_Rpc.php` - PHP client, manages server lifecycle
- `js-code-quality-server.js` - Node.js RPC server, processes lint and this-usage analysis requests

### Server Lifecycle
1. **Lazy start:** Server spawns on first lint or analyze_this call during code quality checks
2. **Startup:** Checks for stale socket, force-kills if found, starts fresh server
3. **Wait:** Polls socket with ping (50ms intervals, budget = `rsx.javascript.rpc_server_ready_wait_ms`, default 10s). On expiry it kills what it spawned, clears its handle so the NEXT use is a clean retry, and throws a diagnostic built by `Rpc_Startup_Diagnostics` that reports what was OBSERVED (process exited with its stderr / no socket / stale socket / missing script) rather than guessing at a cause. Never suggests `npm install` inside `system/` - that is an owned zone.
4. **Usage:** All JS linting and this-usage analysis goes through RPC
5. **Shutdown:** Graceful shutdown when code quality checks complete (registered shutdown handler)

### Socket
- **Path:** `storage/rsx-tmp/js-code-quality-server.sock`
- **Protocol:** Line-delimited JSON over Unix domain socket

### RPC Methods
- `ping` → `"pong"` - Health check
- `lint` → `{file: {status, error}, ...}` - Check JavaScript syntax using Babel parser
- `analyze_this` → `{file: {status, violations}, ...}` - Analyze 'this' usage patterns using Acorn
- `shutdown` → Graceful server termination

### PHP API
```php
Js_CodeQuality_Rpc::lint($file_path);         // Returns error array or null
Js_CodeQuality_Rpc::analyze_this($file_path); // Returns violations array
Js_CodeQuality_Rpc::ensure_rpc_server();      // Lazy init, auto-called (Rpc_Client_Abstract)
Js_CodeQuality_Rpc::stop_rpc_server($force);  // Shutdown message, then reap by pid
```

### Force Parameter
`stop_rpc_server($force = false)`:
- `false` (default): Send the socket `shutdown` message first, then reap by pid
- `true`: Skip the message and go straight to the reap (a socket message can never reach a daemon whose socket file was already unlinked)

### Cache Integration
Both lint and analyze_this have their own caching layers:
- **Lint cache:** Flag files in `storage/rsx-tmp/cache/js-lint-passed/` (mtime-based)
- **This-usage cache:** JSON files in `storage/rsx-tmp/cache/code-quality/js-this/` (mtime-based)

Cache is checked before RPC call - only files with stale cache are sent to the server.

### Error Handling
Server failure → fatal error for lint, silent failure for analyze_this.

### Performance Impact
Before RPC: Thousands of Node.js process spawns during rsx:check (~20+ seconds on first run)
After RPC: Single Node.js process, reused across all operations (~1-2s startup overhead)
