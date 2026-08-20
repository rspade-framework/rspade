# JavaScript Parser - RPC Server Architecture

## Problem Statement

RSpade's manifest build system needs to parse ~1,200+ JavaScript files to extract metadata (classes, methods, decorators, etc). The original implementation spawned a new Node.js process for each file:

```php
foreach ($js_files as $file) {
    $process = new Process(['node', 'js-parser.js', $file]);
    $process->run();
    // ... parse output
}
```

**Performance Impact:**
- 1,200+ process spawns per clean build
- Each spawn: ~50-150ms overhead
- Total overhead: 60-180 seconds just for process management
- Plus Node.js interpreter startup time per file

## Solution: Long-Running RPC Server

Replace N process spawns with ONE long-running Node.js server that handles all parse requests via RPC over a Unix domain socket.

**Benefits:**
- Single Node.js interpreter startup
- No process spawn overhead per file
- Batch processing support
- ~25-50x speedup for clean builds

## Architecture

### Components

#### 1. PHP Client (`Js_Parser.php`)
- Manages RPC server lifecycle
- Provides static methods for server operations
- Integrates with existing parse/cache logic

#### 2. Node.js RPC Server (`js-parser-server.js`)
- Long-running process listening on Unix socket
- Handles JSON-RPC style requests
- Processes multiple files per request (batch support)

#### 3. Unix Domain Socket
- Path: `storage/rsx-tmp/js-parser-server.sock`
- Protocol: Line-delimited JSON
- No port conflicts, no network stack overhead

### Communication Protocol

**Request Format:**
```json
{"id": 1, "method": "parse", "files": ["path1.js", "path2.js"]}\n
```

**Response Format:**
```json
{"id": 1, "results": {"path1.js": {...}, "path2.js": {...}}}\n
```

**Methods:**
- `ping` → `{id: N, result: "pong"}` - Health check
- `parse` → `{id: N, results: {file: data, ...}}` - Parse files
- `shutdown` → `{id: N, result: "shutting down"}` - Graceful stop

Line-delimited JSON allows simple stream parsing without complex framing.

## Server Lifecycle

The lifecycle is NOT written in `Js_Parser` any more. It lives once, in
`Rpc_Client_Abstract` (same directory), which every helper client extends. `Js_Parser`
declares three constants (`RPC_SERVER_SCRIPT`, `RPC_SOCKET`, `RPC_LABEL`) and keeps only its
own parse marshaling and cache.

### 1. Lazy initialization

```php
public static function extract_metadata(string $file_path): array
{
    static::ensure_rpc_server();   // idempotent per process
    // ... continue parsing
}
```

### 2. What ensure_rpc_server() does

1. **Reuse, if provenance holds.** The socket file exists AND `<socket>.meta` (the sha1 of the
   server script recorded at spawn) still matches the script on disk AND a ping is answered.
   A missing `.meta` is unknown provenance and never a match.
2. **Otherwise self-heal-kill.** `pgrep -f -- '--socket=<abs path>'`, SIGTERM, one settle pass,
   SIGKILL, then remove the socket and its `.meta`. This is the only teardown that reaches a
   daemon whose socket file was already unlinked - a socket `shutdown` message never can.
3. **Spawn**, wrapped in `RsxLocks::command_without_inherited_locks()` so no flock descriptor
   is inherited by a process that may idle for hours.
4. **Ready-wait**: ping every 50ms up to `rsx.javascript.rpc_server_ready_wait_ms` (10s).
   The process handle is published ONLY after the daemon answers.
5. **On expiry**: build a `Rpc_Startup_Diagnostics::failure_message()` from what was OBSERVED,
   stop what was spawned, leave no handle/socket/meta behind, and throw.

### 3. Normal operation

All JS parsing during the build goes through the RPC server, batched where possible. The cache
is checked first - only uncached files are sent.

### 4. Shutdown

`stop_rpc_server($force = false)` sends the socket `shutdown` message and then reaps by pid;
`$force` skips the message. There is no registered shutdown function: on CLI, Symfony's
`Process` destructor already stops what this process spawned, and a socket left behind by a
killed daemon is collected by the next `ensure_rpc_server()`.

A daemon spawned inside a php-fpm request SURVIVES the request and is reused by later ones -
which is what the provenance check exists to make safe. `rsx:clean`
(`Rpc_Client_Abstract::quiesce_all()`) and `bin/maintenance-mode.sh enable` (its bash twin)
take down every daemon under `storage/rsx-tmp/` regardless of family.

## Node.js Server Implementation

### Key Design Decisions

**1. Line-Delimited JSON**

Simple, no complex framing needed:

```javascript
socket.on('data', (data) => {
    buffer += data.toString();
    let newlineIndex;
    while ((newlineIndex = buffer.indexOf('\n')) !== -1) {
        const line = buffer.substring(0, newlineIndex);
        buffer = buffer.substring(newlineIndex + 1);
        handleRequest(line);
    }
});
```

**2. Synchronous File Processing**

Parse files sequentially in single request handler - simpler than async complexity for this use case.

**3. Graceful Shutdown Handling**

```javascript
if (request.method === 'shutdown') {
    socket.end();
    server.close(() => {
        fs.unlinkSync(socketPath);
        process.exit(0);
    });
}
```

**4. Signal Handlers**

Ensure socket cleanup on unexpected termination:

```javascript
process.on('SIGTERM', () => {
    server.close(() => {
        fs.unlinkSync(socketPath);
        process.exit(0);
    });
});
```

## Cache Integration

The RPC server integrates with existing cache system:

```php
public static function parse($file_path)
{
    // Check cache first
    if (file_exists($cache_file)) {
        return json_decode(file_get_contents($cache_file), true);
    }

    // Uncached: guarantee the daemon, then go over RPC. There is no single-file path.
    static::ensure_rpc_server();

    return static::parse_via_rpc([$file_path])[$file_path];
}
```

**Batch Optimization (Future):**

Collect uncached files and send in batches to RPC:

```php
$uncached_files = array_filter($files, fn($f) => !cache_exists($f));
$results = static::parse_via_rpc($uncached_files);
foreach ($results as $file => $data) {
    cache_result($file, $data);
}
```

## Error Handling

### Fatal Error Philosophy

RPC server failure is a **fatal error** - no fallback to single-file mode. This is intentional:

**Why fatal?**
1. Server startup failure indicates serious system issue (Node.js missing, permissions, etc)
2. Failing loudly during development catches problems immediately
3. Simpler code - no complex fallback logic
4. Performance: fallback would still be slow, defeating the purpose

**When does it fail?**
- Node.js not installed
- Socket permission issues
- Server crashes during startup
- Timeout waiting for ping (10 seconds)

**How to debug:**
```bash
# Manual server test
node js-parser-server.js --socket=/tmp/test.sock

# Socket connection test
echo '{"id":1,"method":"ping"}' | nc -U /tmp/test.sock
```

## Applying This Pattern Elsewhere

This RPC architecture can be reused for other expensive Node.js operations:

### Example: SCSS Compilation

```php
class Scss_Compiler extends Rpc_Client_Abstract
{
    protected const RPC_SERVER_SCRIPT = 'app/RSpade/Core/Scss/resource/scss-compiler-server.js';
    protected const RPC_SOCKET        = 'storage/rsx-tmp/scss-compiler.sock';
    protected const RPC_LABEL         = 'SCSS Compiler';

    public static function compile_batch(array $files): array
    {
        static::ensure_rpc_server();

        $sock = stream_socket_client('unix://' . base_path(self::RPC_SOCKET));
        fwrite($sock, json_encode([
            'id' => 1,
            'method' => 'compile',
            'files' => $files
        ]) . "\n");

        $response = fgets($sock);
        fclose($sock);

        return json_decode($response, true)['results'];
    }
}
```

### Pattern Checklist

When implementing RPC server for another operation:

1. **Extend `Rpc_Client_Abstract`** - it owns spawn, ping, ready-wait, stop, self-heal and provenance. Do not hand-roll any of them.
2. **Declare the three constants** - `RPC_SERVER_SCRIPT`, `RPC_SOCKET`, `RPC_LABEL`.
3. **Socket path:** `storage/rsx-tmp/{name}.sock`, so the quiesce sweeps collect it for free.
4. **Protocol:** line-delimited JSON, with `ping` and `shutdown` implemented on the node side.
5. **Call `ensure_rpc_server()`** before the first request of an operation.
6. **Signal handlers on the node side:** clean up the socket on SIGTERM/SIGINT.
7. **Error handling:** fatal, with no silent alternative path.
8. **Batch support:** process multiple items per request.

### Key Implementation Files

Reference these when implementing pattern elsewhere:

- **PHP lifecycle (the one implementation):** `/system/app/RSpade/Core/JsParsers/Rpc_Client_Abstract.php`
  - Methods: `ensure_rpc_server()`, `stop_rpc_server()`, `ping_rpc_server()`, `quiesce_all()`
- **PHP client example (marshaling + cache only):** `/system/app/RSpade/Core/JsParsers/Js_Parser.php`

- **Node.js RPC Server:** `/system/app/RSpade/Core/JsParsers/resource/js-parser-server.js`
  - Full example server with line-delimited JSON handling
  - Socket cleanup, signal handlers, graceful shutdown

## Performance Characteristics

### Clean Build (No Cache)
- **Before:** 1,200 process spawns = 60-180s overhead
- **After:** 1 process spawn + RPC calls = 1-2s overhead
- **Speedup:** ~30-90x for process management alone

### Incremental Build (With Cache)
- Most files cached, few parse needed
- RPC overhead minimal (single server already running)
- Similar performance to single-file mode

### Memory Usage
- Node.js server: ~50-100MB RAM
- CLI: dies with the artisan process (Symfony's Process destructor)
- php-fpm: outlives the request and is reused, until its provenance stops matching or a quiesce reaps it

## Future Enhancements

1. **Batch Processing:** Send 50 files per RPC call instead of 1
2. **Parallel Parsing:** Node.js worker threads for CPU-bound parsing
3. **Protocol Upgrade:** msgpack or protobuf if JSON parsing becomes bottleneck

Two former entries here are DONE: the server does persist between builds under php-fpm (with
`.meta` provenance as the staleness detector), and the shared RPC lifecycle is
`Rpc_Client_Abstract`.

## Debugging

### Check Server Status
```bash
# Is server running?
ps aux | grep js-parser-server

# Does socket exist?
ls -lh storage/rsx-tmp/js-parser-server.sock

# Can we connect?
echo '{"id":1,"method":"ping"}' | nc -U storage/rsx-tmp/js-parser-server.sock
```

### Manual Server Test
```bash
# Start server manually
node system/app/RSpade/Core/JsParsers/resource/js-parser-server.js \
    --socket=/tmp/test.sock

# In another terminal:
echo '{"id":1,"method":"ping"}' | nc -U /tmp/test.sock
# Should return: {"id":1,"result":"pong"}
```

### Common Issues

**Server won't start:**
- Check Node.js installed: `node --version`
- Check socket directory writable: `ls -ld storage/rsx-tmp`
- Check for port/socket conflicts: `lsof -U | grep js-parser`

**Timeout waiting for ping:**
- Server crashed during startup (check stderr)
- Socket permissions issue
- Node.js interpreter not in PATH

**Stale socket after crash:**
- Handled automatically (force-stop on next start)
- Manual cleanup: `rm storage/rsx-tmp/js-parser-server.sock`

## Security Considerations

**Socket Permissions:**
- Unix socket in `storage/rsx-tmp` owned by PHP process user
- No external access (not network socket)
- Cleaned up automatically

**Input Validation:**
- Server validates JSON requests
- File paths should be validated before sending to RPC
- No arbitrary code execution risk (parsing pre-validated JS files)

**Resource Limits:**
- Node.js process limited by OS
- No explicit memory limits (processes ~1-2 files per second)
- Terminates after manifest build completes

## Conclusion

The RPC server architecture provides massive performance improvements for operations requiring many Node.js process invocations. The pattern is clean, maintainable, and reusable across different parts of the framework.

Key benefits:
- **Performance:** 30-90x faster for clean builds
- **Reliability:** Fatal errors catch problems immediately
- **Maintainability:** Clear lifecycle, graceful shutdown
- **Reusability:** Pattern applicable to SCSS, TypeScript, etc.

This README serves as the reference implementation for future RPC servers in RSpade.
