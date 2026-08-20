# JQHTML Server-Side Rendering (SSR)

> **See [SPECIFICATION.md](./SPECIFICATION.md) for the complete technical specification.**

## Overview

The JQHTML SSR system renders components to HTML on the server for SEO purposes. Unlike React/Vue/Angular which require separate data-fetching abstractions, JQHTML SSR runs the exact same component code - including `on_load()` with real HTTP requests.

**Primary use case:** SEO - rendering meaningful HTML for search engine crawlers.

## Installation

```bash
npm install @jqhtml/ssr
```

Or install globally for CLI access:

```bash
npm install -g @jqhtml/ssr
```

---

## Quick Start

### Starting the Server

```bash
# After installing (see Installation above)

# Start on TCP port
npx jqhtml-ssr --tcp 9876

# Or with Unix socket (better performance, local only)
npx jqhtml-ssr --socket /tmp/jqhtml-ssr.sock
```

### Server Options

```
--tcp <port>        Listen on TCP port
--socket <path>     Listen on Unix socket
--max-bundles <n>   Max cached bundle sets (default: 10)
--timeout <ms>      Default render timeout (default: 30000)
--help              Show help
```

---

## Reference CLI Example

The package includes `jqhtml-ssr-example`, a complete reference implementation that demonstrates the full SSR workflow. **Use this as the canonical source of truth for building integrations in any language.**

### Basic Usage

```bash
# Start the SSR server in one terminal
jqhtml-ssr --tcp 9876

# In another terminal, run the example
jqhtml-ssr-example \
  --vendor ./bundles/vendor.js \
  --app ./bundles/app.js \
  --component Dashboard_Index_Action \
  --base-url http://localhost:3000
```

### Example Options

```
REQUIRED:
  --vendor, -v <path>      Path to vendor bundle (contains @jqhtml/core)
  --app, -a <path>         Path to app bundle (contains components)
  --component, -c <name>   Component name to render

OPTIONS:
  --args <json>            Component arguments as JSON (default: {})
  --base-url, -b <url>     Base URL for fetch requests (default: http://localhost:3000)
  --timeout, -t <ms>       Render timeout in milliseconds (default: 30000)
  --port, -p <port>        SSR server port (default: 9876)
  --socket, -s <path>      Use Unix socket instead of TCP
  --format, -f <format>    Output format: pretty, json, html-only (default: pretty)
  --help, -h               Show help message
```

### Output Formats

**Pretty (default)** - Human-readable output showing:
- Rendered HTML (formatted)
- localStorage cache entries
- sessionStorage cache entries
- Timing information

```bash
jqhtml-ssr-example -v vendor.js -a app.js -c My_Component
```

**JSON** - Raw server response for programmatic use:

```bash
jqhtml-ssr-example -v vendor.js -a app.js -c My_Component --format json > result.json
```

**HTML-only** - Just the rendered HTML:

```bash
jqhtml-ssr-example -v vendor.js -a app.js -c My_Component --format html-only > output.html
```

### Example with Component Arguments

```bash
jqhtml-ssr-example \
  -v ./bundles/vendor.js \
  -a ./bundles/app.js \
  -c User_Profile \
  --args '{"user_id": 123, "show_avatar": true}'
```

### Integration Reference

The `jqhtml-ssr-example` source code (`bin/jqhtml-ssr-example.js`) is the **canonical reference** for building integrations. Key implementation details:

1. **Connection** - TCP socket to `localhost:PORT` or Unix socket
2. **Request format** - Newline-delimited JSON
3. **Bundle loading** - Read JS files, send as `content` strings
4. **Request structure** - See the commented request object in the source
5. **Response parsing** - JSON with `status`, `payload`, and `error` fields

When building integrations in PHP, Python, Go, etc., refer to this example for the exact protocol and data structures.

---

## Integration

### Protocol

The server uses a simple newline-delimited JSON protocol over TCP or Unix socket.

**Request format:**
```json
{
  "id": "unique-request-id",
  "type": "render",
  "payload": {
    "bundles": [
      { "id": "vendor", "content": "..." },
      { "id": "app", "content": "..." }
    ],
    "component": "Dashboard_Index_Action",
    "args": { "user_id": 123 },
    "options": {
      "baseUrl": "http://localhost:3000",
      "timeout": 30000
    }
  }
}
```

**Response format:**
```json
{
  "id": "unique-request-id",
  "status": "success",
  "payload": {
    "html": "<div class=\"Dashboard_Index_Action Component\">...</div>",
    "cache": {
      "localStorage": {},
      "sessionStorage": {}
    },
    "timing": {
      "total_ms": 231,
      "bundle_load_ms": 49,
      "render_ms": 99
    }
  }
}
```

### PHP Integration Example

```php
<?php
class JqhtmlSSR {
    private $socket;

    public function __construct(string $socketPath = '/tmp/jqhtml-ssr.sock') {
        $this->socket = stream_socket_client("unix://$socketPath", $errno, $errstr, 5);
        if (!$this->socket) {
            throw new Exception("SSR connection failed: $errstr");
        }
    }

    public function render(string $component, array $args = [], array $bundles = []): array {
        $request = json_encode([
            'id' => uniqid('ssr-'),
            'type' => 'render',
            'payload' => [
                'bundles' => $bundles,
                'component' => $component,
                'args' => $args,
                'options' => [
                    'baseUrl' => 'http://localhost',
                    'timeout' => 30000
                ]
            ]
        ]) . "\n";

        fwrite($this->socket, $request);
        $response = fgets($this->socket);

        return json_decode($response, true);
    }

    public function ping(): bool {
        $request = json_encode([
            'id' => 'ping',
            'type' => 'ping',
            'payload' => []
        ]) . "\n";

        fwrite($this->socket, $request);
        $response = json_decode(fgets($this->socket), true);

        return $response['status'] === 'success';
    }
}

// Usage
$ssr = new JqhtmlSSR();
$result = $ssr->render('Dashboard_Index_Action', ['user_id' => 123], $bundles);

if ($result['status'] === 'success') {
    echo $result['payload']['html'];
}
```

### Node.js Client Example

```javascript
const net = require('net');

function sendSSRRequest(port, request) {
  return new Promise((resolve, reject) => {
    const client = net.createConnection({ port }, () => {
      client.write(JSON.stringify(request) + '\n');
    });

    let data = '';
    client.on('data', (chunk) => {
      data += chunk.toString();
      if (data.includes('\n')) {
        client.end();
        resolve(JSON.parse(data.trim()));
      }
    });

    client.on('error', reject);
    setTimeout(() => {
      client.end();
      reject(new Error('Request timeout'));
    }, 30000);
  });
}

// Usage
const response = await sendSSRRequest(9876, {
  id: 'render-1',
  type: 'render',
  payload: {
    bundles: [
      { id: 'vendor', content: vendorBundleContent },
      { id: 'app', content: appBundleContent }
    ],
    component: 'Dashboard_Index_Action',
    args: { user_id: 123 },
    options: { baseUrl: 'http://localhost:3000' }
  }
});

console.log(response.payload.html);
```

---

## Request Types

### ping

Health check.

```json
{ "id": "1", "type": "ping", "payload": {} }
```

Response includes `uptime_ms`.

### render

Render a component to HTML.

Required payload fields:
- `bundles` - Array of `{ id, content }` objects
- `component` - Component name to render
- `options.baseUrl` - Base URL for relative fetch/XHR requests

Optional:
- `args` - Component arguments (default: `{}`)
- `options.timeout` - Render timeout in ms (default: 30000)

### render_spa

Boot a full SPA bundle in a jsdom environment, dispatch a route, and render the resulting page to HTML (for SPA frameworks built on JQHTML, e.g. RSpade-style apps) rather than rendering a single named component.

```json
{
  "id": "1",
  "type": "render_spa",
  "payload": {
    "bundles": [
      { "id": "vendor", "content": "..." },
      { "id": "app", "content": "..." }
    ],
    "url": "/products/123",
    "rsxapp": {},
    "options": {
      "baseUrl": "http://localhost:3000",
      "timeout": 30000,
      "ssr_token": "optional-auth-token",
      "extract_meta": true,
      "ready_selector": "#spa-root > *:first-child"
    }
  }
}
```

Required payload fields:
- `bundles` - Array of `{ id, content }` or `{ id, path }` objects
- `url` - Route path to render (resolved against `options.baseUrl`)
- `rsxapp` - Object passed through to the SPA environment (empty object `{}` if unused)
- `options.baseUrl` - Base URL for relative fetch/XHR requests and route resolution

Optional:
- `options.timeout` - Render timeout in ms (default: 30000)
- `options.ssr_token` - Auth token exposed to the SPA environment
- `options.extract_meta` - Extract `<title>`/meta tags into the response's `meta` field (default: false)
- `options.ready_selector` - CSS selector polled to detect the SPA has finished mounting (default: `#spa-root > *:first-child`)

Response payload includes `html`, `meta`, `cache`, and `timing` (same shape as `render`, plus `meta`).

### flush_cache

Clear bundle cache.

```json
{ "id": "1", "type": "flush_cache", "payload": {} }
```

Or flush specific bundle:
```json
{ "id": "1", "type": "flush_cache", "payload": { "bundle_id": "app" } }
```

---

## Error Codes

| Code | Description |
|------|-------------|
| `PARSE_ERROR` | Invalid JSON or malformed request |
| `BUNDLE_ERROR` | JavaScript syntax error in bundle |
| `COMPONENT_NOT_FOUND` | Component not registered after loading bundles |
| `RENDER_ERROR` | Error during component lifecycle |
| `RENDER_TIMEOUT` | Component did not reach ready state in time |
| `INTERNAL_ERROR` | Unexpected server error |

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│  SSR Server (long-running Node.js process)                      │
│                                                                 │
│  1. Receive request (component name, args, bundles)             │
│  2. Load bundles (cached) into isolated jsdom environment       │
│  3. Execute component lifecycle (including real fetch())        │
│  4. Wait for on_ready                                           │
│  5. Return HTML + localStorage/sessionStorage cache dump        │
└─────────────────────────────────────────────────────────────────┘
```

**Key features:**
- **Real data fetching** - Components make actual HTTP requests during SSR
- **Cache export** - Server exports storage state for instant client hydration
- **Bundle caching** - Prepared bundle code cached with LRU eviction
- **Request isolation** - Each render gets fresh jsdom environment
- **URL rewriting** - Relative URLs resolved against baseUrl

---

## SSR Preload API (Hydration Acceleration)

The SSR Preload API eliminates redundant `on_load()` calls during client hydration. When the SSR server already fetched data for a component, the client can skip the identical fetch and use the captured data directly.

**Without preload:** SSR fetches data → client boots → client fetches same data again (wasted round-trip).

**With preload:** SSR fetches data → captures it → client receives captured data → client boots with preloaded data → no redundant fetch.

### Server-Side: Capturing Data

During SSR rendering, enable data capture to record each component's loaded data:

```javascript
// 1. Enable capture before rendering
jqhtml.start_data_capture();

// 2. Render the component (on_load() runs, data is captured automatically)
// ... component renders normally ...

// 3. Retrieve captured data (one-shot: clears buffer after retrieval)
const captured = jqhtml.get_captured_data();
// Returns: [{ component: 'DashboardIndex', args: { user_id: 123 }, data: { items: [...] } }, ...]

// 4. Stop capture when done
jqhtml.stop_data_capture();

// 5. Include captured data in SSR response
return { html: renderedHtml, preload: captured };
```

**Capture rules:**
- Only components with `on_load()` are captured (static components excluded)
- Deduplicates by component name + args (Load Coordinator aware)
- `get_captured_data()` is one-shot: clears the buffer on retrieval
- `start_data_capture()` is idempotent (safe to call multiple times)

### Client-Side: Injecting Preloaded Data

Before components boot on the client, seed the preload cache with SSR-captured data:

```javascript
// 1. Inject preloaded data BEFORE components initialize
jqhtml.set_preload_data(ssrPreloadData);

// 2. Components boot → _load() finds preload cache hit → skips on_load() entirely
// ... components initialize normally, but with instant data ...

// 3. Clear unconsumed entries after hydration is complete
jqhtml.clear_preload_data();
```

**Preload rules:**
- `set_preload_data(entries)` must be called before components boot
- Each preload entry is consumed on first use (one-shot per key)
- `set_preload_data(null)` or `set_preload_data([])` is a no-op
- `clear_preload_data()` removes any unconsumed entries

### Complete Data Flow

```
SSR Server:
  1. jqhtml.start_data_capture()
  2. Render component (on_load() fetches data → CAPTURED)
  3. captured = jqhtml.get_captured_data()
  4. Return { html, preload: captured }

Client:
  1. jqhtml.set_preload_data(ssr_preload)
  2. Components boot → _load() hits preload cache → skips on_load()
  3. jqhtml.clear_preload_data()
```

### Updated PHP Integration Example (with Preload)

```php
// Usage with preload data
$ssr = new JqhtmlSSR();
$result = $ssr->render('Dashboard_Index_Action', ['user_id' => 123], $bundles);

if ($result['status'] === 'success') {
    $html = $result['payload']['html'];
    $preload = json_encode($result['payload']['preload'] ?? []);

    echo $html;
    echo "<script>jqhtml.set_preload_data({$preload});</script>";
    echo "<script src='/bundles/vendor.js'></script>";
    echo "<script src='/bundles/app.js'></script>";
    echo "<script>jqhtml.boot().then(() => jqhtml.clear_preload_data());</script>";
}
```

### Updated Node.js Client Example (with Preload)

```javascript
const response = await sendSSRRequest(9876, {
  id: 'render-1',
  type: 'render',
  payload: {
    bundles: [
      { id: 'vendor', content: vendorBundleContent },
      { id: 'app', content: appBundleContent }
    ],
    component: 'Dashboard_Index_Action',
    args: { user_id: 123 },
    options: { baseUrl: 'http://localhost:3000' }
  }
});

const { html, preload } = response.payload;

// Embed preload data in the page for client-side hydration
const pageHtml = `
  ${html}
  <script>
    jqhtml.set_preload_data(${JSON.stringify(preload || [])});
  </script>
  <script src="/bundles/vendor.js"></script>
  <script src="/bundles/app.js"></script>
  <script>
    jqhtml.boot().then(() => jqhtml.clear_preload_data());
  </script>
`;
```

### API Reference

| Method | Description |
|--------|-------------|
| `jqhtml.start_data_capture()` | Enable data capture mode (idempotent) |
| `jqhtml.get_captured_data()` | Returns captured entries and clears buffer (one-shot) |
| `jqhtml.stop_data_capture()` | Stops capture and clears all capture state |
| `jqhtml.set_preload_data(entries)` | Seeds preload cache with SSR-captured data |
| `jqhtml.clear_preload_data()` | Clears unconsumed preload entries |

---

## File Structure

```
packages/ssr/
├── SPECIFICATION.md         # Complete technical specification
├── README.md                # This file
├── package.json
├── bin/
│   └── jqhtml-ssr-example.js  # Reference CLI example (canonical integration source)
├── src/
│   ├── index.js             # Package exports
│   ├── server.js            # TCP/Unix socket server (jqhtml-ssr CLI)
│   ├── environment.js       # jsdom + jQuery environment setup
│   ├── bundle-cache.js      # Bundle caching with LRU eviction
│   ├── http-intercept.js    # fetch/XHR URL rewriting
│   ├── storage.js           # Fake localStorage/sessionStorage
│   └── protocol.js          # Message parsing/formatting
├── test/
│   ├── test-protocol.js       # Protocol unit tests (16 tests)
│   ├── test-storage.js        # Storage unit tests (13 tests)
│   ├── test-server.js         # Server integration tests (6 tests)
│   ├── test-ajax-transport.js # AJAX/XHR transport tests
│   └── test-render-spa.js     # render_spa E2E tests
├── test-manual.js           # Manual test with real bundles
└── test-debug.js            # Debug script for troubleshooting
```

---

## Running Tests

```bash
# Unit tests
node test/test-protocol.js
node test/test-storage.js
node test/test-server.js
node test/test-ajax-transport.js
node test/test-render-spa.js

# Or run the full suite (same as `npm test`)
npm test

# Manual test with real bundles (requires bundles_for_ssr_test/)
node test-manual.js
```

---

## Critical Technical Discoveries

### 1. jQuery Module Load Order (CRITICAL)

**jQuery MUST be `require()`'d BEFORE `global.window` is set.**

```javascript
// CORRECT - jQuery returns a factory function
const jqueryFactory = require('jquery');  // First!
global.window = jsdomWindow;               // Second
const $ = jqueryFactory(jsdomWindow);      // Returns working jQuery

// WRONG - jQuery auto-initializes and returns an object
global.window = jsdomWindow;               // First (BAD)
const jqueryFactory = require('jquery');   // jQuery sees window, auto-binds
const $ = jqueryFactory(jsdomWindow);      // Returns object, not function!
```

**Why:** jQuery checks for `global.window` at require-time. If it exists, jQuery auto-initializes against that window instead of returning a factory.

### 2. Use `vm.runInThisContext` Not `vm.createContext`

Using `vm.createContext` creates VM context boundary issues where function references don't work across contexts. jQuery wrapper functions fail because closures can't access variables across the boundary.

**Solution:** Use `vm.runInThisContext` which executes in Node's global context.

### 3. Bundle Loading Order

Load bundles in dependency order:
1. **Vendor bundle** - Contains `@jqhtml/core`, initializes `window.jqhtml`
2. **App bundle** - Contains templates and component classes

### 4. Global Cleanup

When destroying environments, set globals to `undefined` instead of using `delete`:
```javascript
global.window = undefined;  // Safe
// delete global.window;    // Can cause issues
```

---

## Dependencies

```json
{
  "jsdom": "^24.0.0",
  "jquery": "^3.7.1"
}
```

---

## References

- [SPECIFICATION.md](./SPECIFICATION.md) - Complete technical specification
- [/packages/core/src/component.ts](/packages/core/src/component.ts) - Component lifecycle
- [/docs/official/18_boot.md](/docs/official/18_boot.md) - Client-side boot/hydration
- [jsdom documentation](https://github.com/jsdom/jsdom)
- [jQuery in Node.js](https://www.npmjs.com/package/jquery)
