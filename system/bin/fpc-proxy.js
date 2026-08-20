#!/usr/bin/env node

/**
 * RSpade Full Page Cache (FPC) Proxy
 *
 * Reverse proxy that caches HTML responses marked with X-RSpade-FPC header.
 * Sits between nginx and the PHP backend. Serves cached responses from Redis
 * with ETag/304 validation so cached pages never hit PHP.
 *
 * Usage: node system/bin/fpc-proxy.js
 *
 * Requires .env: REDIS_HOST, REDIS_PORT, REDIS_PASSWORD
 * Optional: FPC_PROXY_PORT (default 3200), FPC_BACKEND_PORT (default 3201),
 *           FPC_TTL_MINS (default 0 = never expire; >0 sets a per-entry TTL)
 */

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

// Load .env from project root
const env_path = path.resolve(__dirname, '../../.env');
if (fs.existsSync(env_path)) {
    const env_content = fs.readFileSync(env_path, 'utf8');
    for (const line of env_content.split('\n')) {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) continue;
        const eq = trimmed.indexOf('=');
        if (eq === -1) continue;
        const key = trimmed.substring(0, eq);
        let value = trimmed.substring(eq + 1);
        // Strip surrounding quotes
        if ((value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))) {
            value = value.slice(1, -1);
        }
        if (!process.env[key]) {
            process.env[key] = value;
        }
    }
}

const FPC_PORT = parseInt(process.env.FPC_PROXY_PORT || '3200', 10);
const BACKEND_PORT = parseInt(process.env.FPC_BACKEND_PORT || '3201', 10);
const BACKEND_HOST = '127.0.0.1';
const REDIS_HOST = process.env.REDIS_HOST || '127.0.0.1';
const REDIS_PORT = parseInt(process.env.REDIS_PORT || '6379', 10);
const REDIS_PASSWORD = process.env.REDIS_PASSWORD === 'null' ? undefined : process.env.REDIS_PASSWORD;
// Entry TTL in minutes. 0 (or 'null'/unset) = never expire. Prevents orphaned
// keys from accumulating forever (Redis defaults to noeviction).
const FPC_TTL_MINS = (process.env.FPC_TTL_MINS === 'null' || process.env.FPC_TTL_MINS === undefined) ? 0 : parseInt(process.env.FPC_TTL_MINS, 10) || 0;

// Volatile storage root: <project>/storage once the relocation marker exists (written by
// system/bin/environment_updates/030_relocate_storage.sh), historic system/storage before
// that. Mirrors the PHP bootstrap bridge; TRANSITIONAL fallback.
const PROJECT_ROOT = path.resolve(__dirname, '..', '..');
const STORAGE_ROOT = fs.existsSync(path.resolve(PROJECT_ROOT, 'storage/.rspade_storage_relocated'))
    ? path.resolve(PROJECT_ROOT, 'storage')
    : path.resolve(__dirname, '../storage');

const BUILD_KEY_PATH = path.join(STORAGE_ROOT, 'rsx-build/build_key');
const SESSION_COOKIE_NAME = 'rsx';

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

let redis_client = null;
let redis_available = false;
let build_key = 'none';

// ---------------------------------------------------------------------------
// Build key management
// ---------------------------------------------------------------------------

function load_build_key() {
    try {
        const content = fs.readFileSync(BUILD_KEY_PATH, 'utf8').trim();
        if (content) {
            const old_key = build_key;
            build_key = content;
            if (old_key !== 'none' && old_key !== build_key) {
                console.log(`[fpc] Build key changed: ${old_key.substring(0, 8)}... -> ${build_key.substring(0, 8)}...`);
            }
        }
    } catch (err) {
        // File doesn't exist yet — use fallback
    }
}

function watch_build_key() {
    const dir = path.dirname(BUILD_KEY_PATH);

    try {
        // Watch the directory for changes to the build_key file
        fs.watch(dir, (event_type, filename) => {
            if (filename === 'build_key') {
                // Small delay to ensure file write is complete
                setTimeout(() => load_build_key(), 50);
            }
        });
    } catch (err) {
        console.error('[fpc] Cannot watch build key directory:', err.message);
        // Fall back to polling
        setInterval(() => load_build_key(), 5000);
    }
}

// ---------------------------------------------------------------------------
// Cache key generation
// ---------------------------------------------------------------------------

function make_cache_key(url) {
    // Parse URL to extract path and sorted query params
    const parsed = new URL(url, 'http://localhost');
    const path_str = parsed.pathname;

    // Sort query parameters for consistent cache keys
    const params = new URLSearchParams(parsed.searchParams);
    const sorted = new URLSearchParams([...params.entries()].sort());
    const full_url = sorted.toString()
        ? path_str + '?' + sorted.toString()
        : path_str;

    const hash = crypto.createHash('sha1').update(full_url).digest('hex');
    return `fpc:${build_key}:${hash}`;
}

// ---------------------------------------------------------------------------
// Cookie parsing
// ---------------------------------------------------------------------------

function has_session_cookie(req) {
    const cookie_header = req.headers.cookie;
    if (!cookie_header) return false;

    // Parse cookies to check for the session cookie
    const cookies = cookie_header.split(';');
    for (const cookie of cookies) {
        const [name] = cookie.trim().split('=');
        if (name === SESSION_COOKIE_NAME) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Proxy logic
// ---------------------------------------------------------------------------

function proxy_to_backend(client_req, client_res, cache_key) {
    const options = {
        hostname: BACKEND_HOST,
        port: BACKEND_PORT,
        path: client_req.url,
        method: client_req.method,
        headers: { ...client_req.headers },
    };

    const proxy_req = http.request(options, (proxy_res) => {
        const fpc_header = proxy_res.headers['x-rspade-fpc'];

        // No FPC header or no cache_key — pass through as-is
        if (!fpc_header || !cache_key) {
            // Strip internal header before sending to client
            const headers = { ...proxy_res.headers };
            delete headers['x-rspade-fpc'];
            client_res.writeHead(proxy_res.statusCode, headers);
            proxy_res.pipe(client_res);
            return;
        }

        // FPC-marked response — collect body and cache it
        const chunks = [];
        proxy_res.on('data', (chunk) => chunks.push(chunk));
        proxy_res.on('end', () => {
            const body = Buffer.concat(chunks);
            const body_str = body.toString('utf8');
            const etag = crypto.createHash('sha1')
                .update(body_str)
                .digest('hex')
                .substring(0, 30);

            // Build cache entry
            const is_redirect = proxy_res.statusCode >= 300 && proxy_res.statusCode < 400;
            const entry = {
                url: new URL(client_req.url, 'http://localhost').pathname,
                status_code: proxy_res.statusCode,
                content_type: proxy_res.headers['content-type'] || 'text/html; charset=UTF-8',
                etag: etag,
                cached_at: new Date().toISOString(),
            };

            if (is_redirect) {
                entry.location = proxy_res.headers['location'] || '';
                entry.html = '';
            } else {
                entry.html = body_str;
            }

            // Store in Redis (fire-and-forget). Apply a TTL when FPC_TTL_MINS > 0;
            // otherwise the entry never expires (invalidated by build-key rotation
            // or an explicit clear).
            if (redis_available) {
                const write = FPC_TTL_MINS > 0
                    ? redis_client.set(cache_key, JSON.stringify(entry), { EX: FPC_TTL_MINS * 60 })
                    : redis_client.set(cache_key, JSON.stringify(entry));
                write.catch((err) => {
                    console.error('[fpc] Redis write error:', err.message);
                });
            }

            // Build response headers — strip internals, add cache headers
            const response_headers = { ...proxy_res.headers };
            delete response_headers['x-rspade-fpc'];
            delete response_headers['set-cookie'];
            response_headers['etag'] = etag;
            response_headers['x-fpc-cache'] = 'MISS';

            client_res.writeHead(proxy_res.statusCode, response_headers);
            client_res.end(body);
        });
    });

    proxy_req.on('error', (err) => {
        console.error('[fpc] Backend error:', err.message);
        if (!client_res.headersSent) {
            client_res.writeHead(502, { 'Content-Type': 'text/plain' });
            client_res.end('Bad Gateway');
        }
    });

    // Pipe request body to backend (for POST, etc.)
    client_req.pipe(proxy_req);
}

// ---------------------------------------------------------------------------
// HTTP server
// ---------------------------------------------------------------------------

const server = http.createServer(async (req, res) => {
    // 1. Non-GET requests: proxy straight through, no caching
    if (req.method !== 'GET' && req.method !== 'HEAD') {
        return proxy_to_backend(req, res, null);
    }

    // 2. Session cookie present: user has an active session, always pass through
    if (has_session_cookie(req)) {
        return proxy_to_backend(req, res, null);
    }

    // 3. Redis unavailable: proxy straight through (fail-open)
    if (!redis_available) {
        return proxy_to_backend(req, res, null);
    }

    // 4. Compute cache key and check Redis
    const cache_key = make_cache_key(req.url);

    try {
        const cached = await redis_client.get(cache_key);

        if (cached) {
            const entry = JSON.parse(cached);

            // Check If-None-Match for 304 Not Modified
            const if_none_match = req.headers['if-none-match'];
            if (if_none_match && if_none_match === entry.etag) {
                res.writeHead(304, {
                    'ETag': entry.etag,
                    'X-FPC-Cache': 'HIT',
                });
                return res.end();
            }

            // Cached redirect
            if (entry.status_code >= 300 && entry.status_code < 400 && entry.location) {
                res.writeHead(entry.status_code, {
                    'Location': entry.location,
                    'ETag': entry.etag,
                    'X-FPC-Cache': 'HIT',
                });
                return res.end();
            }

            // Serve cached HTML
            res.writeHead(entry.status_code || 200, {
                'Content-Type': entry.content_type || 'text/html; charset=UTF-8',
                'ETag': entry.etag,
                'X-FPC-Cache': 'HIT',
            });
            return res.end(entry.html);
        }
    } catch (err) {
        console.error('[fpc] Redis read error:', err.message);
    }

    // 5. Cache MISS — proxy to backend and potentially cache response
    //    Only GET requests can populate the cache (HEAD has no body to cache)
    return proxy_to_backend(req, res, req.method === 'GET' ? cache_key : null);
});

// ---------------------------------------------------------------------------
// Startup
// ---------------------------------------------------------------------------

async function start() {
    const { createClient } = require('redis');

    // Connect to Redis (DB 0 — shared cache database; FPC entries carry a TTL
    // only when FPC_TTL_MINS > 0)
    redis_client = createClient({
        socket: { host: REDIS_HOST, port: REDIS_PORT },
        password: REDIS_PASSWORD,
    });

    redis_client.on('error', (err) => {
        if (redis_available) {
            console.error('[fpc] Redis error:', err.message);
        }
        redis_available = false;
    });

    redis_client.on('ready', () => {
        redis_available = true;
        console.log(`[fpc] Connected to Redis at ${REDIS_HOST}:${REDIS_PORT}`);
    });

    try {
        await redis_client.connect();
    } catch (err) {
        console.error('[fpc] Redis connection failed:', err.message);
        console.log('[fpc] Running in pass-through mode (no caching)');
        redis_available = false;
    }

    // Load build key and watch for changes
    load_build_key();
    watch_build_key();

    // Start HTTP server
    server.listen(FPC_PORT, () => {
        console.log(`[fpc] FPC proxy listening on port ${FPC_PORT}`);
        console.log(`[fpc] Backend: ${BACKEND_HOST}:${BACKEND_PORT}`);
        console.log(`[fpc] Build key: ${build_key}`);
    });
}

// ---------------------------------------------------------------------------
// Graceful shutdown
// ---------------------------------------------------------------------------

async function shutdown(signal) {
    console.log(`[fpc] Received ${signal}, shutting down...`);

    server.close();

    if (redis_client) {
        try {
            await redis_client.quit();
        } catch (err) {
            // Ignore
        }
    }

    process.exit(0);
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

start();
