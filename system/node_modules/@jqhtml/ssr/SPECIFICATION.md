# JQHTML Server-Side Rendering (SSR) Specification

> **Document Status:** Active specification for `@jqhtml/ssr` package development.

## Overview

The JQHTML SSR system provides server-side rendering of JQHTML components via a persistent socket server. Unlike React/Vue/Angular SSR which require separate data-fetching abstractions, JQHTML SSR runs the exact same component code on the server - including `on_load()` with real HTTP requests.

**Primary use case:** SEO - rendering meaningful HTML for search engine crawlers.

**Architecture:** Long-running Node.js process accepting requests via TCP or Unix socket.

---

## Design Principles

1. **Same code path** - Components run identically on server and client
2. **Real data fetching** - `on_load()` makes actual HTTP requests during SSR
3. **Cache export** - Server exports localStorage/sessionStorage state for client hydration
4. **Request isolation** - Each render request has completely fresh state
5. **Bundle caching** - Parsed bundles stay in memory; environment state is cloned per request
6. **No authentication** - SSR is for SEO; crawlers aren't authenticated

---

## Server Architecture

### Process Model

```
┌─────────────────────────────────────────────────────────────────┐
│  SSR Server Process (long-running)                              │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Bundle Cache                                            │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐      │   │
│  │  │ Bundle Set A│  │ Bundle Set B│  │ Bundle Set C│      │   │
│  │  │ (hash: abc) │  │ (hash: def) │  │ (hash: ghi) │      │   │
│  │  └─────────────┘  └─────────────┘  └─────────────┘      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Request Handler                                         │   │
│  │                                                          │   │
│  │  Request → Create Isolated Environment                   │   │
│  │         → Load Bundle Set (from cache or parse)          │   │
│  │         → Execute Component Lifecycle                    │   │
│  │         → Capture HTML + Cache State                     │   │
│  │         → Return Response                                │   │
│  │         → Destroy Environment                            │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### Bundle Caching Strategy

Bundles are identified by a hash of their contents (or explicit version string). When a request comes in:

1. Check if bundle set exists in cache by identifier
2. If cached: clone the parsed bundle environment
3. If not cached: parse bundles, store in cache, then clone

**Cache eviction:** LRU with configurable max entries (default: 10 bundle sets).

### Request Isolation

Each render request gets:
- Fresh jsdom Document
- Fresh fake localStorage/sessionStorage
- Fresh global state (window.jqhtml, etc.)
- Cloned bundle execution context

This ensures one request cannot affect another, even if component code modifies global state.

---

## Protocol Specification

### Transport

Support two transport modes:
- **TCP:** For network access (e.g., PHP/Laravel calling from different process)
- **Unix Socket:** For local IPC (lower latency, better security)

### Message Format

JSON-based protocol with newline-delimited messages.

#### Request Format

```json
{
  "id": "request-uuid-123",
  "type": "render",
  "payload": {
    "bundles": [
      {
        "id": "vendor-abc123",
        "content": "... javascript content ..."
      },
      {
        "id": "app-def456",
        "content": "... javascript content ..."
      }
    ],
    "component": "Dashboard_Index",
    "args": {
      "page": 1,
      "filter": "active"
    },
    "options": {
      "baseUrl": "https://example.com",
      "timeout": 30000
    }
  }
}
```

**Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | Yes | Unique request identifier for correlation |
| `type` | string | Yes | Request type: `"render"`, `"ping"`, `"flush_cache"` |
| `payload.bundles` | array | Yes | JavaScript bundles to load |
| `payload.bundles[].id` | string | Yes | Bundle identifier (for caching) |
| `payload.bundles[].content` | string | Yes | JavaScript source code |
| `payload.component` | string | Yes | Component name to render |
| `payload.args` | object | No | Arguments to pass to component |
| `payload.options.baseUrl` | string | Yes | Base URL for relative fetch/ajax requests |
| `payload.options.timeout` | number | No | Max render time in ms (default: 30000) |

#### Response Format

**Success Response:**

```json
{
  "id": "request-uuid-123",
  "status": "success",
  "payload": {
    "html": "<div class=\"Dashboard_Index Component\">...</div>",
    "cache": {
      "localStorage": {
        "jqhtml_cache:Dashboard_Index:{\"page\":1}": "{\"items\":[...],\"total\":42}"
      },
      "sessionStorage": {}
    },
    "timing": {
      "total_ms": 245,
      "bundle_load_ms": 12,
      "render_ms": 233
    }
  }
}
```

**Error Response:**

```json
{
  "id": "request-uuid-123",
  "status": "error",
  "error": {
    "code": "RENDER_TIMEOUT",
    "message": "Component render exceeded 30000ms timeout",
    "stack": "..."
  }
}
```

**Error Codes:**

| Code | HTTP Equivalent | Description |
|------|-----------------|-------------|
| `PARSE_ERROR` | 400 | Invalid request JSON |
| `BUNDLE_ERROR` | 400 | JavaScript bundle failed to parse/execute |
| `COMPONENT_NOT_FOUND` | 404 | Requested component not registered |
| `RENDER_ERROR` | 500 | Component threw during lifecycle |
| `RENDER_TIMEOUT` | 504 | Render exceeded timeout |
| `INTERNAL_ERROR` | 500 | Unexpected server error |

#### Other Request Types

**Ping (health check):**
```json
{"id": "ping-1", "type": "ping", "payload": {}}
```
Response:
```json
{"id": "ping-1", "status": "success", "payload": {"uptime_ms": 123456}}
```

**Flush cache:**
```json
{"id": "flush-1", "type": "flush_cache", "payload": {"bundle_id": "vendor-abc123"}}
```
Response:
```json
{"id": "flush-1", "status": "success", "payload": {"flushed": true}}
```

---

## URL Rewriting / HTTP Interception

Components make HTTP requests via `fetch()` or jQuery's `$.ajax()`. These need URL rewriting for server-side execution.

### Interception Points

1. **`global.fetch`** - Override with custom implementation
2. **`XMLHttpRequest`** - Override for jQuery compatibility

### URL Rewriting Rules

Given `baseUrl: "https://example.com"`:

| Original | Rewritten |
|----------|-----------|
| `/api/users` | `https://example.com/api/users` |
| `//cdn.example.com/data.json` | `https://cdn.example.com/data.json` |
| `https://other.com/api` | `https://other.com/api` (unchanged) |
| `api/users` (relative) | `https://example.com/api/users` |

### Implementation

```javascript
// Pseudocode for fetch override
const originalFetch = globalThis.fetch;

globalThis.fetch = function(url, options) {
  const resolvedUrl = resolveUrl(url, baseUrl);
  return originalFetch(resolvedUrl, {
    ...options,
    // Strip any auth headers for SEO safety
    headers: filterHeaders(options?.headers)
  });
};
```

jQuery uses XMLHttpRequest internally, so overriding XHR covers `$.ajax()`, `$.get()`, `$.post()`.

---

## JQHTML Core Modifications

### SSR Mode Flag

Add `ssr_mode` configuration to jqhtml initialization:

```javascript
// In SSR environment, before loading bundles:
window.__JQHTML_SSR_MODE__ = true;

// Or via jqhtml config:
jqhtml.configure({ ssr_mode: true });
```

### Fake Storage Implementation

When `ssr_mode` is true, replace `localStorage` and `sessionStorage` with capturing implementations:

```javascript
class SSR_Storage {
  constructor() {
    this._data = new Map();
  }

  getItem(key) {
    return this._data.get(key) ?? null;
  }

  setItem(key, value) {
    this._data.set(key, String(value));
  }

  removeItem(key) {
    this._data.delete(key);
  }

  clear() {
    this._data.clear();
  }

  get length() {
    return this._data.size;
  }

  key(index) {
    return Array.from(this._data.keys())[index] ?? null;
  }

  // SSR-specific: export all data
  _export() {
    return Object.fromEntries(this._data);
  }
}
```

### Cache Export

At end of SSR render, export storage state:

```javascript
function exportCacheState() {
  return {
    localStorage: window.localStorage._export(),
    sessionStorage: window.sessionStorage._export()
  };
}
```

### Behaviors Disabled in SSR Mode

When `ssr_mode` is true:

| Feature | Behavior |
|---------|----------|
| Event handlers (`@click`, etc.) | Rendered as attributes but not bound |
| `setInterval`/`setTimeout` | Allowed but auto-cleared after render |
| `requestAnimationFrame` | Stubbed (no-op) |
| CSS animations | Ignored |
| `window.location` mutations | Blocked with warning |

---

## Client-Side Hydration

The SSR server returns HTML and cache state. Client-side integration is the responsibility of the external tooling, but the expected pattern is:

### 1. Server Renders Page with SSR HTML

```html
<div id="app">
  <!-- SSR-rendered component HTML -->
  <div class="Dashboard_Index Component" data-cid="abc123">
    <h1>Dashboard</h1>
    <div class="User_List Component" data-cid="def456">
      <ul>
        <li>User 1</li>
        <li>User 2</li>
      </ul>
    </div>
  </div>
</div>

<script type="application/json" id="jqhtml-ssr-cache">
{
  "localStorage": {
    "jqhtml_cache:Dashboard_Index:{...}": "...",
    "jqhtml_cache:User_List:{...}": "..."
  },
  "sessionStorage": {}
}
</script>
```

### 2. Client JavaScript Hydrates

```javascript
// 1. Load cache data into storage BEFORE any components initialize
const cacheData = JSON.parse(document.getElementById('jqhtml-ssr-cache').textContent);

for (const [key, value] of Object.entries(cacheData.localStorage)) {
  localStorage.setItem(key, value);
}
for (const [key, value] of Object.entries(cacheData.sessionStorage)) {
  sessionStorage.setItem(key, value);
}

// 2. Replace SSR HTML with live components
// Option A: Clear and re-render (simple, guaranteed correct)
$('#app').empty();
$('#app').component('Dashboard_Index', { page: 1, filter: 'active' });

// Option B: Use boot() if SSR output used placeholder format
// jqhtml.boot($('#app'));
```

### Why Replace Instead of Attach?

JQHTML's model is simpler than React/Vue hydration:

1. SSR provides meaningful HTML for SEO crawlers
2. Client replaces with live components using cached data
3. Cache ensures `on_load()` returns instantly (cache hit)
4. No complex DOM diffing or event attachment needed
5. Single code path - same render logic everywhere

The "flicker" is avoided because:
- Cache is pre-populated before components initialize
- Components render synchronously from cache
- Browser paints once with final result

---

## Testing Strategy

### Unit Tests (Node.js)

Test SSR server components in isolation:

```
tests/ssr/
├── test-bundle-cache.js      # Bundle caching logic
├── test-url-rewriting.js     # Fetch/XHR URL rewriting
├── test-storage-capture.js   # Fake localStorage/sessionStorage
├── test-isolation.js         # Request isolation
├── test-protocol.js          # Message parsing/formatting
└── test-timeout.js           # Render timeout handling
```

### Integration Tests

Test full SSR render cycle:

```
tests/ssr_integration/
├── basic_render/
│   ├── test_component.jqhtml
│   ├── test_component.js
│   └── run-test.sh           # Starts SSR server, sends request, verifies output
├── fetch_data/
│   ├── fetching_component.jqhtml
│   ├── fetching_component.js
│   ├── mock_server.js        # Mock API server for fetch testing
│   └── run-test.sh
├── nested_components/
│   └── ...
├── cache_export/
│   └── ...
└── error_handling/
    └── ...
```

### Test Runner Integration

Tests should work with existing `run-all-tests.sh`:

```bash
#!/bin/bash
# tests/ssr_integration/basic_render/run-test.sh

# Start SSR server in background
node /var/www/html/jqhtml/aux/ssr/server.js --port 9999 &
SSR_PID=$!
sleep 1

# Run test
node test-runner.js

# Capture result
RESULT=$?

# Cleanup
kill $SSR_PID

exit $RESULT
```

---

## File Structure

```
aux/ssr/
├── SPECIFICATION.md          # This document
├── README.md                 # Quick start and API reference
├── package.json
├── src/
│   ├── server.js             # TCP/Unix socket server
│   ├── renderer.js           # SSR render orchestration
│   ├── environment.js        # jsdom environment setup
│   ├── bundle-cache.js       # Bundle caching with LRU
│   ├── http-intercept.js     # fetch/XHR URL rewriting
│   ├── storage.js            # Fake localStorage/sessionStorage
│   └── protocol.js           # Message parsing/formatting
├── bin/
│   └── jqhtml-ssr            # CLI entry point
└── test/
    └── ...
```

---

## CLI Interface

```bash
# Start SSR server on TCP port
jqhtml-ssr --tcp 9876

# Start SSR server on Unix socket
jqhtml-ssr --socket /tmp/jqhtml-ssr.sock

# With options
jqhtml-ssr --tcp 9876 --max-bundles 20 --timeout 60000

# One-shot render (for testing)
jqhtml-ssr --render \
  --bundle vendor.js \
  --bundle app.js \
  --component Dashboard_Index \
  --args '{"page":1}' \
  --base-url https://example.com
```

---

## Performance Considerations

### Bundle Caching

- Bundles are parsed once and cached by ID
- Environment state is cloned per request (not re-parsed)
- LRU eviction prevents unbounded memory growth

### Request Handling

- Requests processed concurrently (Node.js async I/O)
- Each request gets isolated environment
- Heavy renders don't block other requests

### Memory Management

- jsdom environments are destroyed after each request
- Weak references where possible for cache entries
- Configurable max concurrent renders

### Timeout Handling

- Each render has configurable timeout (default 30s)
- Timeout triggers environment destruction
- Partial renders are not returned

---

## Security Considerations

1. **No cookies/auth** - SSR requests don't forward authentication
2. **URL whitelist** - Only allow fetch to configured baseUrl domain
3. **Code execution** - Only execute provided bundles, no eval of request data
4. **Resource limits** - Timeout, memory limits per request
5. **Socket permissions** - Unix socket should have restricted permissions

---

## Preload Data Capture and Injection Protocol

The preload system captures component data during SSR and replays it on the client to skip redundant `on_load()` calls during hydration.

### Entry Format

Each captured entry has the following structure:

```json
{
  "component": "DashboardIndex",
  "args": { "user_id": 123 },
  "data": { "items": [...], "total": 42 }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `component` | string | Component name |
| `args` | object | Component arguments (as passed at creation) |
| `data` | object | The component's `this.data` after `on_load()` completed |

### Key Generation

Preload entries are matched by a composite key of `component` + `args`. The key generation algorithm:

1. Component name is used as-is (string)
2. Args are serialized deterministically (stable JSON stringification)
3. Combined key: `component + ":" + serialized_args`

This means two components with the same name and identical args share a preload entry, which is consistent with Load Coordinator deduplication behavior.

### One-Shot Semantics

Both capture and preload use one-shot consumption:

- **`get_captured_data()`** — Returns all captured entries and clears the capture buffer. Calling again returns an empty array until new components are captured.
- **Preload entries** — Each entry is consumed on first use. When `_load()` finds a preload cache hit, it removes that entry. A second component with the same key will not find a preload hit and will call `on_load()` normally.

### Integration Points in `_load()`

The preload system integrates into the component `_load()` method with the following priority:

1. **Preload cache check** (highest priority) — If `set_preload_data()` was called and an entry matches this component's name + args, apply the data via `_apply_load_result()` and skip `on_load()` entirely.
2. **Load Coordinator check** — If another instance of the same component + args is already loading, wait for its result.
3. **Normal `on_load()`** — Call the component's `on_load()` method.

### Capture Integration in `_apply_load_result()`

When data capture is enabled (`start_data_capture()` was called), `_apply_load_result()` records the component's data if the component has an `on_load()` method. Static components (those without `on_load()`) are excluded from capture.

### API Summary

| Method | Side | Description |
|--------|------|-------------|
| `start_data_capture()` | Server | Enable capture mode (idempotent) |
| `get_captured_data()` | Server | Return and clear captured entries |
| `stop_data_capture()` | Server | Stop capture, clear all state |
| `set_preload_data(entries)` | Client | Seed preload cache; null/empty is no-op |
| `clear_preload_data()` | Client | Clear unconsumed preload entries |

---

## Future Considerations

### Streaming SSR

For large pages, stream HTML as it becomes available:
- Send opening tags immediately
- Stream component HTML as each completes
- Requires chunked transfer encoding

**Not in initial scope** - adds significant complexity.

### Partial Hydration

Only hydrate interactive components, leave static HTML as-is:
- Mark components as `static` or `interactive`
- Static components not replaced on client

**Not in initial scope** - current model is simpler.

### Worker Threads

For CPU-bound renders, use Node.js worker threads:
- Main thread handles I/O
- Workers execute renders
- Better CPU utilization

**Consider for v2** if single-threaded performance is insufficient.

---

## Implementation Phases

### Phase 1: Core SSR Server
- [ ] Protocol implementation (request/response parsing)
- [ ] Bundle loading and caching
- [ ] jsdom environment with SSR mode
- [ ] Basic render (create component, wait for ready, return HTML)
- [ ] Error handling and timeouts

### Phase 2: HTTP Interception
- [ ] fetch() override with URL rewriting
- [ ] XMLHttpRequest override for jQuery
- [ ] baseUrl configuration

### Phase 3: Cache Export
- [ ] Fake localStorage/sessionStorage implementation
- [ ] Cache state export in response
- [ ] Integration with jqhtml's cache system

### Phase 4: Testing
- [ ] Unit tests for each module
- [ ] Integration tests for full render cycle
- [ ] Performance benchmarks
- [ ] Test runner integration

### Phase 5: Documentation & Polish
- [ ] CLI help and examples
- [ ] Integration guide for Laravel/PHP
- [ ] Troubleshooting guide

---

## Appendix: Example Session

```
# Terminal 1: Start SSR server
$ jqhtml-ssr --tcp 9876
[SSR] Server listening on tcp://0.0.0.0:9876

# Terminal 2: Send render request
$ echo '{"id":"1","type":"render","payload":{"bundles":[{"id":"test","content":"..."}],"component":"Hello_World","args":{},"options":{"baseUrl":"http://localhost"}}}' | nc localhost 9876

{"id":"1","status":"success","payload":{"html":"<div class=\"Hello_World Component\">Hello, World!</div>","cache":{"localStorage":{},"sessionStorage":{}},"timing":{"total_ms":45}}}
```
