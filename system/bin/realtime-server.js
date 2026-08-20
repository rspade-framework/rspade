#!/usr/bin/env node

/**
 * RSpade Realtime WebSocket Server
 *
 * Dumb relay: validates HMAC tokens, routes messages by site_id + topic + filter.
 * Zero business logic, zero database access. PHP is the authority.
 *
 * Usage: node system/bin/realtime-server.js
 *
 * Requires .env: APP_KEY, REDIS_HOST, REDIS_PORT, REDIS_PASSWORD,
 *                REALTIME_WS_PORT (default 6200), APP_URL,
 *                REALTIME_PHP_ORIGIN (default http://127.0.0.1)
 */

const { WebSocketServer } = require('ws');
const { createClient } = require('redis');
const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const https = require('https');
const os = require('os');
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

const WS_PORT = parseInt(process.env.REALTIME_WS_PORT || '6200', 10);
const APP_KEY = process.env.APP_KEY || '';
const REDIS_HOST = process.env.REDIS_HOST || '127.0.0.1';
const REDIS_PORT = parseInt(process.env.REDIS_PORT || '6379', 10);
const REDIS_PASSWORD = process.env.REDIS_PASSWORD === 'null' ? undefined : process.env.REDIS_PASSWORD;
const REDIS_PREFIX = 'rsx_rt';
// Control-plane channel: targeted refresh frames routed by connection stamps (realm +
// session_id, or realm + site_id + user_id) with NO topic/subscription. See route_control().
const CONTROL_CHANNEL = `${REDIS_PREFIX}:control`;

const HEARTBEAT_INTERVAL = 30000;  // 30 seconds
const PONG_TIMEOUT = 10000;        // 10 seconds to respond
const AUTH_TIMEOUT = 5000;         // 5 seconds to authenticate after connect

// Subscriber-change notify channel (relay -> PHP). Where PHP listens is a DEPLOYMENT
// fact about this box (the precedent is fpc-proxy's BACKEND_HOST), never invocation
// intent, so it is an .env value. A relay host with no co-located HTTP simply gets no
// baseline seeding; the PHP engine's absent-baseline publish covers those identities on
// their first write instead. Never a lost change either way.
const PHP_ORIGIN = process.env.REALTIME_PHP_ORIGIN || 'http://127.0.0.1';
const NOTIFY_PATH = '/_realtime/subs_changed';
const NOTIFY_COALESCE_MS = 200;    // one-shot burst coalescer (event-armed, NOT a poll)
const NOTIFY_TIMEOUT_MS = 2000;

/**
 * The Host header the notify POST presents: the APP_URL host, so PHP's dev-mode hostname
 * guard sees the host it expects even though the connection goes to loopback. Mirrors
 * Rsx_App_Url's $HOSTNAME substitution (this loader does no phpdotenv interpolation, so
 * an APP_URL=https://$HOSTNAME line arrives here literally). Empty when APP_URL is
 * unparseable - the request then presents the origin host, which loopback exempts.
 */
function resolve_app_host() {
    const os_hostname = os.hostname();
    const raw = (process.env.APP_URL || '')
        .split('${HOSTNAME}').join(os_hostname)
        .split('$HOSTNAME').join(os_hostname);

    try {
        return new URL(raw).host;
    } catch {
        return '';
    }
}

const APP_HOST = resolve_app_host();

if (!APP_KEY) {
    console.error('[realtime] APP_KEY not set in .env');
    process.exit(1);
}

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

// ws → { realm, user_id, site_id, session_id, authenticated, subscriptions: Map<sub_id, {topic, filter}>, alive }
const connections = new Map();

// Redis SET key holding the active subscriber registry. PHP reads this to decide
// whether to run emitters (only topics with a live subscriber are recomputed).
const REGISTRY_KEY = `${REDIS_PREFIX}:subs`;
// TTL (seconds) on the registry key; refreshed on every rewrite and periodically, so
// if this Node process dies the key expires and PHP stops running emitters.
const REGISTRY_TTL = 90;

// node-redis v5: the pSubscribe client is locked into subscriber mode and cannot run
// ordinary commands, so registry writes go through a SEPARATE command connection
// (subscriber.duplicate()), created and connected in start_redis().
let command_client = null;

// ---------------------------------------------------------------------------
// Subscriber registry (Redis SET rsx_rt:subs)
// ---------------------------------------------------------------------------

/**
 * Canonical registry member for one active subscription. Filter keys are SORTED so
 * two connections watching the same site+topic+filter collapse to ONE set member
 * (and so the string is stable). Paired with PHP's
 * Realtime_Emitter_Service::_canonical_filter_json(), which ksorts the filter the
 * same way when deriving an emitter's hash identity.
 */
function build_registry_member(site_id, topic, filter) {
    const sorted_filter = {};
    for (const key of Object.keys(filter || {}).sort()) {
        sorted_filter[key] = filter[key];
    }
    return JSON.stringify({ site_id, topic, filter: sorted_filter });
}

/**
 * Recompute the full active subscription set from the in-memory connections map and
 * atomically rewrite the Redis registry key (MULTI: DEL, SADD members, EXPIRE). When
 * there are no active subscriptions the key is simply deleted (absent = nobody
 * watching). Also serves as the periodic EXPIRE refresh.
 */
async function rewrite_registry() {
    if (!command_client) return;

    const members = new Set();
    for (const [, conn] of connections) {
        if (!conn.authenticated) continue;
        for (const [, sub] of conn.subscriptions) {
            members.add(build_registry_member(conn.site_id, sub.topic, sub.filter));
        }
    }

    const multi = command_client.multi();
    multi.del(REGISTRY_KEY);
    if (members.size > 0) {
        multi.sAdd(REGISTRY_KEY, [...members]);
        multi.expire(REGISTRY_KEY, REGISTRY_TTL);
    }
    await multi.exec();

    // Diff only AFTER a successful write. The baseline may advance only when redis really
    // holds the new set, or the two diverge and a new member is never reported again.
    // (A SADD-returns-1 detection cannot be built here: this is a full-state DEL+SADD-all
    // rewrite, so SADD reports every member as new on every rewrite.)
    const added = diff_new_members(last_members, members);
    last_members = members;
    if (added.length > 0) {
        queue_seed_notify(added);
    }
}

// ---------------------------------------------------------------------------
// Subscriber-change notify (relay -> PHP)
//
// PHP needs to know the moment a subscription identity becomes live, so it can seed an
// emitter baseline while "the subscriber has current state" is actually true (the
// subscribe ack IS the resync signal). The relay stays dumb: it reports ALL new registry
// members and knows nothing about emitters - PHP filters to emitter-served entries.
//
// Recurrences are DESIRABLE, not waste: a relay restart empties the baseline, so every
// member is new and everything reseeds; an unsubscribe/resubscribe re-seeds and refreshes
// the baseline TTL.
// ---------------------------------------------------------------------------

// Members present at the last successful registry write.
let last_members = new Set();

// New members awaiting the coalesced POST, and its one-shot timer.
const pending_notify = new Set();
let notify_timer = null;

/**
 * Pure: the members of `current` absent from `prev`. Accepts Sets or arrays. Exported
 * for unit tests (the relay itself always passes Sets).
 */
function diff_new_members(prev, current) {
    const prev_set = prev instanceof Set ? prev : new Set(prev || []);
    const added = [];
    for (const member of (current || [])) {
        if (!prev_set.has(member)) {
            added.push(member);
        }
    }
    return added;
}

/**
 * Pure: the notify signature PHP verifies (hex HMAC-SHA256 of the RAW body under APP_KEY -
 * the same shared secret the websocket tokens are signed with). Exported so a test can
 * prove node/php parity.
 */
function sign_notify_body(body, app_key) {
    return crypto.createHmac('sha256', app_key).update(body).digest('hex');
}

/**
 * Accumulate new members and arm the one-shot coalescer. Event-armed: nothing is scheduled
 * while nothing subscribes, so this is not a polling tick.
 */
function queue_seed_notify(added) {
    for (const member of added) {
        pending_notify.add(member);
    }

    if (pending_notify.size === 0 || notify_timer) return;

    notify_timer = setTimeout(() => {
        notify_timer = null;
        flush_notify();
    }, NOTIFY_COALESCE_MS);
}

/**
 * POST the accumulated members to PHP. Fire-and-forget: the response is drained and
 * discarded, and EVERY failure path is log-only. A dropped notify costs a baseline seed,
 * which the PHP engine's absent-baseline publish covers on the next write.
 *
 * The 'error' handler is MANDATORY, not defensive: an unhandled 'error' event on a node
 * request object is an uncaught exception, and this relay is a SINGLE shared process -
 * one uncaught throw kills realtime for every connected user (the pre-auth DoS lesson).
 */
function flush_notify() {
    const members = [...pending_notify];
    pending_notify.clear();
    if (members.length === 0) return;

    const body = JSON.stringify({ ts: Math.floor(Date.now() / 1000), members });

    let target;
    try {
        target = new URL(NOTIFY_PATH, PHP_ORIGIN);
    } catch {
        console.error('[realtime] Seed notify skipped: REALTIME_PHP_ORIGIN is not a URL: ' + PHP_ORIGIN);
        return;
    }

    const is_https = target.protocol === 'https:';
    const headers = {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(body),
        'X-Realtime-Signature': sign_notify_body(body, APP_KEY),
    };
    if (APP_HOST) {
        headers.Host = APP_HOST;
    }

    const req = (is_https ? https : http).request({
        protocol: target.protocol,
        hostname: target.hostname,
        port: target.port || (is_https ? 443 : 80),
        path: target.pathname,
        method: 'POST',
        headers,
    }, (res) => {
        res.resume();   // drain; the body carries nothing the relay acts on
        if (res.statusCode !== 200) {
            console.error('[realtime] Seed notify rejected: HTTP ' + res.statusCode);
        }
    });

    req.on('error', (err) => {
        console.error('[realtime] Seed notify failed:', err.message);
    });
    req.setTimeout(NOTIFY_TIMEOUT_MS, () => {
        req.destroy();
    });

    req.end(body);
}

function rewrite_registry_async() {
    rewrite_registry().catch((err) => {
        console.error('[realtime] Registry rewrite failed:', err.message);
    });
}

// ---------------------------------------------------------------------------
// Token validation
// ---------------------------------------------------------------------------

function validate_token(token_string) {
    // Untrusted client input: this runs on raw inbound websocket frames while the
    // connection is still unauthenticated, so it MUST NEVER throw - the relay is a single
    // process with no per-connection isolation, so one uncaught throw here terminates
    // realtime for EVERY connected user. It only ever returns a decoded payload or null;
    // the outer try/catch is the guarantee (scoped strictly to token parse/validate, the
    // correct boundary for hostile client input - it does not mask bugs elsewhere).
    try {
        const dot = token_string.indexOf('.');
        if (dot === -1) return null;

        const payload_b64 = token_string.substring(0, dot);
        const signature = token_string.substring(dot + 1);

        // Shape-guard the signature BEFORE the timing-safe compare. A sha256 HMAC hex
        // digest is always exactly 64 lowercase hex chars. This is a SHAPE check, not a
        // length check: a 64-char NON-hex signature makes Buffer.from(sig, 'hex') decode
        // to fewer bytes, and crypto.timingSafeEqual THROWS RangeError (it does not return
        // false) when the two buffers differ in byte length.
        if (!/^[0-9a-f]{64}$/.test(signature)) return null;

        const json = Buffer.from(payload_b64, 'base64').toString('utf8');

        // Verify HMAC
        const expected = crypto.createHmac('sha256', APP_KEY).update(json).digest('hex');
        if (!crypto.timingSafeEqual(Buffer.from(signature, 'hex'), Buffer.from(expected, 'hex'))) {
            return null;
        }

        const payload = JSON.parse(json);

        // Check expiry
        if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
            return null;
        }

        return payload;
    } catch {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Filter matching
// ---------------------------------------------------------------------------

function matches_filter(subscription_filter, message_data) {
    if (!subscription_filter || Object.keys(subscription_filter).length === 0) return true;
    for (const key in subscription_filter) {
        // Bulk model-change frames carry {model, ids:[...]} instead of a scalar id. An 'id'
        // watcher matches when the frame's ids array contains its id (string-compared, like
        // the scalar path). Only the 'id' key gets this treatment; every other key stays a
        // scalar equality, so {model}-only collection watchers still match via 'model'.
        if (key === 'id' && message_data.id === undefined && Array.isArray(message_data.ids)) {
            const want = String(subscription_filter.id);
            if (!message_data.ids.some((v) => String(v) === want)) return false;
            continue;
        }
        if (String(message_data[key]) !== String(subscription_filter[key])) return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Inbound frame parsing
// ---------------------------------------------------------------------------

// Parse one raw inbound websocket frame into a usable message object, or null if it is
// not one. Returns null on unparseable JSON AND on the JSON literal null: `null` parses
// successfully but has no property surface, so a later `msg.type` read would throw
// TypeError and - since the relay is a single shared process - kill realtime for every
// connected user. The caller drops any null return before dereferencing the frame.
// (Numbers/strings/booleans/arrays are harmless here - their `.type` is just undefined.)
function parse_json_frame(raw) {
    let value;
    try {
        value = JSON.parse(raw.toString());
    } catch {
        return null;
    }
    if (value === null) return null;
    return value;
}

// ---------------------------------------------------------------------------
// Bootstrap - runtime side effects (port bind, redis, timers, signal handlers).
// Everything from here down runs ONLY when this file is executed as a program.
// It is guarded by require.main === module (the export seam at the end of the
// file) so the pure validators (validate_token / parse_json_frame) can be
// require()'d in tests without binding a port or connecting to redis.
// ---------------------------------------------------------------------------

function main() {

const wss = new WebSocketServer({ port: WS_PORT });

wss.on('listening', () => {
    console.log(`[realtime] WebSocket server listening on port ${WS_PORT}`);
});

wss.on('connection', (ws) => {
    const conn = {
        realm: null,
        user_id: null,
        site_id: null,
        session_id: null,
        authenticated: false,
        subscriptions: new Map(),
        alive: true,
    };
    connections.set(ws, conn);

    // Require authentication within timeout
    const auth_timer = setTimeout(() => {
        if (!conn.authenticated) {
            ws.close(4001, 'Authentication timeout');
        }
    }, AUTH_TIMEOUT);

    ws.on('pong', () => {
        conn.alive = true;
    });

    ws.on('message', (raw) => {
        const msg = parse_json_frame(raw);
        if (msg === null) return;

        if (msg.type === 'auth') {
            handle_auth(ws, conn, msg, auth_timer);
        } else if (!conn.authenticated) {
            ws.close(4001, 'Not authenticated');
        } else if (msg.type === 'subscribe') {
            handle_subscribe(ws, conn, msg);
        } else if (msg.type === 'unsubscribe') {
            handle_unsubscribe(conn, msg);
        }
    });

    ws.on('close', () => {
        clearTimeout(auth_timer);
        connections.delete(ws);
        rewrite_registry_async();
    });

    ws.on('error', () => {
        clearTimeout(auth_timer);
        connections.delete(ws);
        rewrite_registry_async();
    });
});

function handle_auth(ws, conn, msg, auth_timer) {
    if (conn.authenticated) return;

    // A valid, unexpired, HMAC-signed token is the only requirement — user_id may be
    // null (an anonymous connection, e.g. a public topic with no login of any kind).
    // Per-topic authorization happens later, at subscribe time, in can_subscribe().
    const payload = validate_token(msg.token || '');
    if (!payload || payload.site_id === undefined) {
        ws.close(4003, 'Invalid token');
        return;
    }

    conn.realm = payload.realm || 'staff';
    conn.user_id = payload.user_id ?? null;
    conn.site_id = payload.site_id;
    conn.session_id = payload.session_id;
    conn.authenticated = true;
    clearTimeout(auth_timer);

    ws.send(JSON.stringify({ type: 'auth_ok' }));
}

function handle_subscribe(ws, conn, msg) {
    const payload = validate_token(msg.token || '');
    if (!payload || !payload.topic) {
        ws.send(JSON.stringify({ type: 'error', message: 'Invalid subscribe token' }));
        return;
    }

    // Site ID must match connection
    if (payload.site_id !== conn.site_id) {
        ws.send(JSON.stringify({ type: 'error', message: 'Site mismatch' }));
        return;
    }

    const sub_id = msg.sub_id;
    const topic = payload.topic;
    const filter = payload.filter || {};

    conn.subscriptions.set(sub_id, { topic, filter });
    rewrite_registry_async();

    // This ack IS the resync signal: the client treats every successful subscribe
    // (first time or post-reconnect) as "fetch current state now" rather than this
    // server trying to replay whatever was missed. No message log to maintain here.
    ws.send(JSON.stringify({ type: 'subscribed', sub_id }));
}

function handle_unsubscribe(conn, msg) {
    conn.subscriptions.delete(msg.sub_id);
    rewrite_registry_async();
}

// ---------------------------------------------------------------------------
// Control-plane routing (targeted refresh)
// ---------------------------------------------------------------------------

/**
 * Route a control frame to every authenticated connection whose stamps match, sending a
 * {type:'refresh'} frame (the client does a deduped full reload). No subscription check —
 * this is a control plane, not a topic. Matching is by realm + session_id (session_refresh)
 * or realm + site_id + user_id (user_refresh). String-compared, mirroring matches_filter().
 */
function route_control(msg) {
    for (const [ws, conn] of connections) {
        if (!conn.authenticated) continue;

        let match = false;
        if (msg.kind === 'session_refresh') {
            match = conn.realm === msg.realm
                && String(conn.session_id) === String(msg.session_id);
        } else if (msg.kind === 'user_refresh') {
            match = conn.realm === msg.realm
                && conn.site_id === msg.site_id
                && String(conn.user_id) === String(msg.user_id);
        }

        if (match) {
            ws.send(JSON.stringify({ type: 'refresh' }));
        }
    }
}

// ---------------------------------------------------------------------------
// Heartbeat - ping every 30s, kill unresponsive connections
// ---------------------------------------------------------------------------

const heartbeat = setInterval(() => {
    let terminated = false;
    for (const [ws, conn] of connections) {
        if (!conn.alive) {
            ws.terminate();
            connections.delete(ws);
            terminated = true;
            continue;
        }
        conn.alive = false;
        ws.ping();
    }
    if (terminated) {
        rewrite_registry_async();
    }
}, HEARTBEAT_INTERVAL);

wss.on('close', () => {
    clearInterval(heartbeat);
});

// ---------------------------------------------------------------------------
// Redis subscriber - receive messages from PHP
// ---------------------------------------------------------------------------

async function start_redis() {
    const subscriber = createClient({
        socket: {
            host: REDIS_HOST,
            port: REDIS_PORT,
        },
        password: REDIS_PASSWORD,
    });

    subscriber.on('error', (err) => {
        console.error('[realtime] Redis error:', err.message);
    });

    await subscriber.connect();
    console.log(`[realtime] Connected to Redis at ${REDIS_HOST}:${REDIS_PORT}`);

    // Separate command connection for registry writes (the subscriber above is locked
    // into pSubscribe mode and cannot run SET/DEL/EXPIRE).
    command_client = subscriber.duplicate();
    command_client.on('error', (err) => {
        console.error('[realtime] Redis command client error:', err.message);
    });
    await command_client.connect();

    // Boot DEL: clear any registry the previous (possibly crashed) instance left behind.
    // Current connections, if any, are re-registered by the next rewrite.
    await command_client.del(REGISTRY_KEY);

    // Subscribe to pattern rsx_rt:*
    await subscriber.pSubscribe(`${REDIS_PREFIX}:*`, (message, channel) => {
        let msg;
        try {
            msg = JSON.parse(message);
        } catch {
            return;
        }

        // Control-plane frames are routed by connection stamps, not by topic/subscription.
        if (channel === CONTROL_CHANNEL) {
            route_control(msg);
            return;
        }

        const topic = msg.topic;
        const data = msg.data || {};
        const site_id = msg.site_id;
        const ts = msg.ts || Math.floor(Date.now() / 1000);

        // Route to matching WebSocket connections
        for (const [ws, conn] of connections) {
            if (!conn.authenticated) continue;
            if (conn.site_id !== site_id) continue;

            for (const [sub_id, sub] of conn.subscriptions) {
                if (sub.topic !== topic) continue;
                if (!matches_filter(sub.filter, data)) continue;

                ws.send(JSON.stringify({
                    type: 'message',
                    topic,
                    data,
                    ts,
                }));
                break; // Only send once per connection even if multiple subs match
            }
        }
    });

    return subscriber;
}

// ---------------------------------------------------------------------------
// Startup
// ---------------------------------------------------------------------------

start_redis().catch((err) => {
    console.error('[realtime] Failed to connect to Redis:', err.message);
    process.exit(1);
});

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('[realtime] Shutting down...');
    wss.close();
    process.exit(0);
});

process.on('SIGINT', () => {
    console.log('[realtime] Shutting down...');
    wss.close();
    process.exit(0);
});

// Log stats periodically and refresh the registry EXPIRE (piggybacked here so the
// key stays alive while this process runs, even when nothing changes). Rewriting from
// the in-memory map is the refresh — it re-sets EXPIRE and self-corrects any drift.
setInterval(() => {
    const conn_count = connections.size;
    let sub_count = 0;
    for (const [, conn] of connections) {
        sub_count += conn.subscriptions.size;
    }
    if (conn_count > 0) {
        console.log(`[realtime] Connections: ${conn_count}, Subscriptions: ${sub_count}`);
    }
    rewrite_registry_async();
}, 60000);

} // end main()

// Export seam: the pure, side-effect-free validators are exposed for unit tests.
// require()'ing this file does NOT start the relay (main() only runs as a program).
module.exports = { validate_token, parse_json_frame, matches_filter, diff_new_members, sign_notify_body };

if (require.main === module) {
    main();
}
