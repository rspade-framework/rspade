# JQHTML SSR Quickstart

## 1. Install

```bash
npm install @jqhtml/ssr
```

## 2. Start the Server

```bash
# TCP (for remote/container access)
npx jqhtml-ssr --tcp 9876

# Or Unix socket (better performance, local only)
npx jqhtml-ssr --socket /tmp/jqhtml-ssr.sock
```

## 3. Test with Reference CLI

```bash
npx jqhtml-ssr-example \
  --vendor ./path/to/vendor-bundle.js \
  --app ./path/to/app-bundle.js \
  --component Dashboard_Index_Action \
  --base-url http://localhost:8000
```

## 4. Integration

### Protocol

Send newline-delimited JSON to the server:

```json
{
  "id": "req-1",
  "type": "render",
  "payload": {
    "bundles": [
      { "id": "vendor", "content": "<vendor bundle JS>" },
      { "id": "app", "content": "<app bundle JS>" }
    ],
    "component": "Component_Name",
    "args": {},
    "options": {
      "baseUrl": "http://localhost:8000",
      "timeout": 30000
    }
  }
}
```

Response:

```json
{
  "id": "req-1",
  "status": "success",
  "payload": {
    "html": "<div class=\"Component_Name Component\">...</div>",
    "cache": {
      "localStorage": {},
      "sessionStorage": {}
    },
    "timing": {
      "total_ms": 230,
      "bundle_load_ms": 50,
      "render_ms": 100
    }
  }
}
```

### PHP Example

```php
<?php
$socket = stream_socket_client('unix:///tmp/jqhtml-ssr.sock');

$request = json_encode([
    'id' => uniqid(),
    'type' => 'render',
    'payload' => [
        'bundles' => [
            ['id' => 'vendor', 'content' => file_get_contents('vendor.js')],
            ['id' => 'app', 'content' => file_get_contents('app.js')]
        ],
        'component' => 'Dashboard_Index_Action',
        'args' => [],
        'options' => ['baseUrl' => 'http://localhost', 'timeout' => 30000]
    ]
]) . "\n";

fwrite($socket, $request);
$response = json_decode(fgets($socket), true);

echo $response['payload']['html'];
```

## Documentation

- `README.md` - Full integration guide
- `SPECIFICATION.md` - Protocol specification
- `bin/jqhtml-ssr-example.js` - Reference implementation (source of truth)
