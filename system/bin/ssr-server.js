#!/usr/bin/env node
/**
 * RSpade SSR Server
 *
 * Thin wrapper around @jqhtml/ssr server.
 * Starts a long-running Node process that renders jqhtml components to HTML.
 * Managed by Rsx_SSR.php — spawned on-demand, communicates via Unix socket.
 *
 * Usage:
 *   node system/bin/ssr-server.js --socket=/path/to/ssr-server.sock
 */

const path = require('path');
const fs = require('fs');
const { SSRServer } = require(path.join(__dirname, '..', 'node_modules', '@jqhtml', 'ssr', 'src', 'server.js'));

// Volatile storage root: <project>/storage once the relocation marker exists (written by
// system/bin/environment_updates/030_relocate_storage.sh), historic system/storage before
// that. Mirrors the PHP bootstrap bridge; TRANSITIONAL fallback.
const PROJECT_ROOT = path.resolve(__dirname, '..', '..');
const SYSTEM_DIR = path.resolve(__dirname, '..');
const STORAGE_ROOT = fs.existsSync(path.join(PROJECT_ROOT, 'storage', '.rspade_storage_relocated'))
    ? path.join(PROJECT_ROOT, 'storage')
    : path.join(SYSTEM_DIR, 'storage');

// Parse --socket argument
let socketPath = null;
for (let i = 2; i < process.argv.length; i++) {
    if (process.argv[i].startsWith('--socket=')) {
        socketPath = process.argv[i].split('=')[1];
    }
}

if (!socketPath) {
    socketPath = path.join(STORAGE_ROOT, 'rsx-tmp', 'ssr-server.sock');
}

const server = new SSRServer({
    maxBundles: 10,
    defaultTimeout: 30000
});

(async () => {
    try {
        await server.listenUnix(socketPath);
    } catch (err) {
        console.error('[SSR] Failed to start:', err.message);
        process.exit(1);
    }
})();

// Ignore SIGHUP so server survives terminal disconnect
process.on('SIGHUP', () => {});

process.on('SIGINT', async () => {
    await server.stop();
    process.exit(0);
});

process.on('SIGTERM', async () => {
    await server.stop();
    process.exit(0);
});

process.on('unhandledRejection', (err) => {
    console.error('[SSR] Unhandled rejection:', err);
});

process.on('uncaughtException', (err) => {
    console.error('[SSR] Uncaught exception:', err);
});
