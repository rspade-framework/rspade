# RSpade Build UI Service

A Node.js service that provides real-time visibility into RSpade manifest and bundle compilation processes via WebSocket streaming to an xterm.js terminal interface.

## Architecture

- **HTTP Server**: Express on port 3100
- **WebSocket Server**: ws library on port 3101
- **Terminal UI**: xterm.js for build output display
- **Frontend**: Vanilla JavaScript bundled with Webpack
- **Auto-reload**: Webpack watch + nodemon for development

## Directory Structure

```
build-service/
├── rspade-build-ui-server.js  # Express + WebSocket server
├── src/
│   └── app.js                 # Frontend application with xterm.js
├── public/
│   ├── index.html             # Terminal UI page
│   ├── hello_world.html       # Test page
│   └── dist/                  # Webpack output (generated)
│       └── bundle.js
├── package.json
├── webpack.config.js
├── nodemon.json
├── start-with-backoff.sh      # Startup script with exponential backoff
└── rspade-build-ui.conf       # Supervisord configuration
```

## Development

### Install Dependencies
```bash
npm install
```

### Development Mode (Auto-reload)
```bash
npm start
# or
npm run dev
```

This runs:
- Webpack in watch mode (rebuilds on src/ changes)
- Nodemon (restarts server on rspade-build-ui-server.js changes)

### Production Build
```bash
npm run build
npm run server
```

## Ports

- **3100** - HTTP server (serves UI and API)
- **3101** - WebSocket server (real-time communication)

## Endpoints

### HTTP
- `GET /_build/` - Terminal UI showing build output
- `GET /_hello_world` - WebSocket test page
- `GET /api/hello` - Hello world endpoint
- `GET /api/health` - Service status

### WebSocket
- `ws://localhost:3101` - WebSocket connection for build streaming

## Testing

### Test HTTP Server
```bash
curl http://localhost:3100/api/hello
```

### Test Terminal UI
1. Visit `http://localhost:3100/_build/`
2. WebSocket connects automatically
3. Build process starts automatically when clients connect
4. Watch real-time build output in xterm terminal

## Supervisord Setup

Copy configuration to supervisord:
```bash
cp rspade-build-ui.conf /etc/supervisor/conf.d/
supervisorctl reread
supervisorctl update
supervisorctl start rspade-build-ui
```

### Crash Recovery with Exponential Backoff

The service uses a wrapper script (`start-with-backoff.sh`) that implements exponential backoff:

**Retry Schedule:**
- Attempt 1: Immediate
- Attempt 2: 1 second delay
- Attempt 3: 2 second delay
- Attempt 4: 4 second delay
- Attempt 5: 8 second delay
- Attempt 6: 16 second delay
- Attempt 7: 32 second delay
- Attempt 8: 64 second delay
- Attempt 9: 128 second delay
- Attempt 10: 256 second delay
- Attempt 11: 512 second delay
- Attempt 12+: 600 seconds (10 minutes) - caps here

**Additional Safety:**
- Kills processes on ports 3100/3101 before starting
- Kills any orphaned rspade-build-ui-server.js processes
- Ensures clean startup even after crashes

**Behavior:**
- Retries infinitely (never gives up)
- Short delays for transient issues
- Long delays for persistent problems
- Supervisord manages the wrapper script
- All retry logic is in the wrapper, not supervisord

## WebSocket Protocol

### Server → Client Messages

**Connection Established**
```json
{
  "type": "connected",
  "message": "Connected to RSpade Build UI WebSocket",
  "timestamp": "2025-10-31T05:40:00.000Z"
}
```

**Build Output**
```json
{
  "type": "output",
  "content": "[BUILD] Parsing JS file: app/RSpade/Core/Js/Ajax.js\n"
}
```

**Build Complete**
```json
{
  "type": "build_complete",
  "exit_code": 0,
  "timestamp": "2025-10-31T05:40:15.123Z"
}
```

**Build Error**
```json
{
  "type": "build_error",
  "error": "Error message",
  "timestamp": "2025-10-31T05:40:15.123Z"
}
```

## Build Process Integration

### --build-debug Flag

Both `rsx:manifest:build` and `rsx:bundle:compile` commands support the `--build-debug` flag:

- Enables verbose build output via `console_debug('BUILD', ...)` messages
- Filters console_debug to ONLY show BUILD channel messages
- Forces CLI output even if console_debug is disabled in config
- Implemented via `$_SERVER['argv']` detection in Debugger class

### Current Implementation

The WebSocket server automatically:
1. Spawns `php artisan rsx:bundle:compile --build-debug` on client connection
2. Captures both stdout and stderr
3. Buffers output for late-joining clients
4. Broadcasts output to all connected clients in real-time
5. Only runs one build process at a time (shared across clients)
6. Notifies clients when build completes with exit code

### Build Output Streaming

When a client connects:
- If no build is running: starts new build process
- If build is running: sends buffered output + continues streaming
- All clients see the same shared build output
- Build completion triggers visual notification in terminal

## Features

- ✅ HTTP server with static file serving
- ✅ WebSocket server for real-time communication
- ✅ xterm.js terminal emulation
- ✅ Build process spawning with --build-debug flag
- ✅ Real-time stdout/stderr streaming
- ✅ Shared build process across multiple clients
- ✅ Buffered output for late joiners
- ✅ Build completion notifications
- ✅ Webpack bundling with watch mode
- ✅ Auto-restart on code changes (nodemon)
- ✅ Visual connection status indicator
- ✅ ANSI color support in terminal

## Next Steps

### Phase 1: Build Redirection Integration (Planned)

Integrate with RSpade's manifest and bundle rebuild detection:

1. **Detect Build Need**
   - When Manifest detects rebuild needed (file changes)
   - When BundleCompiler detects compilation needed

2. **Redirect to Build UI**
   - Set cookie with build hash: `rsx_build_${hash}=1`
   - Redirect user to `/_build/` with build UI
   - Cookie prevents multiple redirects for same build

3. **Execute Build**
   - WebSocket server spawns appropriate build command
   - Streams output to terminal in real-time
   - User watches build progress live

4. **Forward Back**
   - On build completion, automatically redirect to original URL
   - Clear build hash cookie
   - User continues to intended page with fresh build

### Additional Planned Features

- Handle both manifest AND bundle builds
- Queue multiple build requests if needed
- Better error handling and recovery
- Build status persistence across page reloads
- Ability to cancel/restart builds

**Note:** This is the general direction - implementation will require additional tweaks to handle edge cases, error conditions, and ensure smooth UX.
