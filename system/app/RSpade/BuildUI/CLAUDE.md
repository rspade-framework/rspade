# RSpade BuildUI Component

## Overview

The BuildUI component provides real-time visibility into RSpade's manifest and bundle compilation processes during development. When the framework detects that a rebuild is needed, developers see a live terminal interface showing compilation progress via WebSocket streaming.

## Architecture

### Node.js Service (`resource/build-service/`)

A standalone Express + WebSocket server that:
- Runs on ports 3100 (HTTP) and 3101 (WebSocket)
- Managed by supervisord in development environment
- Auto-reloads on code changes via webpack watch + nodemon
- Streams build process output to connected clients
- Shares single build process across multiple browser connections

### Integration Points

1. **ManifestKernel** - Detects rebuild needs, redirects to build UI
2. **BundleCompiler** - Triggers compilation, streams output
3. **Nginx** - Routes `/_build/` to Node.js service
4. **Supervisord** - Keeps service running, auto-restarts

## Development Setup

The service is designed for zero-setup in the Docker development environment:

1. Supervisord automatically starts the service
2. Webpack watch rebuilds frontend on changes
3. Nodemon restarts server on backend changes
4. Changes to any file in `/system/app/RSpade/BuildUI/` trigger reload

## Ports

- **3100** - HTTP server (UI and API endpoints)
- **3101** - WebSocket server (real-time communication)

## Service Commands

```bash
# Start development mode (auto-reload)
cd /var/www/html/system/app/RSpade/BuildUI/resource/build-service
npm start

# Manual build
npm run build

# Server only
npm run server
```

## Future Features

See `/docs.dev/LIVE_COMPILE_UI_FEATURE_PROJECT_PLAN.md` for complete roadmap.

Phase 1 (Current): Hello world WebSocket service
Phase 2: Integration with manifest/bundle rebuild detection
Phase 3: Terminal UI with xterm.js
Phase 4: Build process streaming and shared execution

## Security

Development-only feature:
- Only runs when `APP_ENV=local` or `APP_DEBUG=true`
- Not deployed to production
- No privileged command execution
- Read-only build process output
