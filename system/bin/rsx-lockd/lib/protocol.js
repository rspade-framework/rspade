/**
 * rsx-lockd wire protocol.
 *
 * Newline-delimited JSON, strict request/response. The server NEVER sends an
 * unsolicited frame: every frame it writes is the answer to exactly one request the
 * client sent. That is what lets a client park on a blocking acquire and treat the next
 * inbound byte as "my answer arrived".
 *
 * Everything in this file is PURE - no sockets, no timers, no process state. It is
 * require()-able from a test without binding a port.
 */

const crypto = require('crypto');

// A frame is one line. This cap exists so a peer that never sends a newline (hostile or
// broken) cannot grow the per-connection buffer without bound. Real frames are tens of
// bytes; a megabyte is four orders of magnitude of headroom.
const MAX_FRAME_BYTES = 1024 * 1024;

// How far a hello timestamp may be from the server's clock, in seconds. Bounds replay of
// a captured hello to a one-minute window without demanding tight clock sync.
const HELLO_WINDOW_SECONDS = 60;

const PROTOCOL_VERSION = 1;

// Every op the server answers. Exported so the CLI and the tests share one list.
const OPS = [
    'hello',
    'acquire',
    'release',
    'release_all',
    'upgrade',
    'sem_acquire',
    'sem_release',
    'stats',
    'dump',
    'force_clear',
    'ping',
];

// Response statuses. `granted` is the success of an acquire; `ok` the success of
// everything else.
const STATUS_OK = 'ok';
const STATUS_GRANTED = 'granted';
const STATUS_TIMEOUT = 'timeout';
const STATUS_DEADLOCK = 'deadlock';
const STATUS_ERROR = 'error';

const MODE_READ = 'read';
const MODE_WRITE = 'write';

/**
 * Serialize one frame, newline-terminated. JSON.stringify cannot produce a bare newline
 * inside a string (it escapes them), so the newline is an unambiguous frame boundary.
 */
function encode_frame(obj) {
    return JSON.stringify(obj) + '\n';
}

/**
 * Parse one line into a frame. NEVER throws - hostile input arrives here and this daemon
 * is a single shared process, so one uncaught throw would drop every other holder's
 * locks. Returns { ok: true, value } or { ok: false, error }.
 *
 * A JSON literal `null` parses fine but has no property surface, so a later `frame.op`
 * read would throw TypeError. It is rejected here, as are all non-object frames.
 */
function decode_frame(line) {
    let value;
    try {
        value = JSON.parse(line);
    } catch (err) {
        return { ok: false, error: 'Malformed JSON frame: ' + err.message };
    }
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return { ok: false, error: 'Frame must be a JSON object' };
    }
    return { ok: true, value };
}

/**
 * Stateful newline splitter for a byte stream. One instance per connection.
 *
 * push(chunk) returns { lines, error }. Once `error` is non-null the connection is
 * unrecoverable (the frame cap was blown) and the caller must respond and destroy;
 * further pushes keep reporting the same error.
 */
class Frame_Reader {
    constructor(max_bytes = MAX_FRAME_BYTES) {
        this.max_bytes = max_bytes;
        this.buffer = '';
        this.error = null;
    }

    push(chunk) {
        if (this.error) {
            return { lines: [], error: this.error };
        }

        this.buffer += chunk.toString('utf8');

        const lines = [];
        let index = this.buffer.indexOf('\n');
        while (index !== -1) {
            const line = this.buffer.slice(0, index).trim();
            this.buffer = this.buffer.slice(index + 1);
            if (line.length > 0) {
                lines.push(line);
            }
            index = this.buffer.indexOf('\n');
        }

        if (this.buffer.length > this.max_bytes) {
            this.error = 'Frame exceeds ' + this.max_bytes + ' bytes without a newline';
            this.buffer = '';
            return { lines, error: this.error };
        }

        return { lines, error: null };
    }
}

/**
 * The exact bytes a hello signature covers: host, pid and unix timestamp joined by
 * colons. Kept as its own function so the PHP client and any test can prove parity
 * against one definition.
 */
function hello_payload(host, pid, ts) {
    return String(host) + ':' + String(pid) + ':' + String(ts);
}

/** Hex HMAC-SHA256 of the hello payload under the shared key (APP_KEY by default). */
function sign_hello(host, pid, ts, key) {
    return crypto.createHmac('sha256', key).update(hello_payload(host, pid, ts)).digest('hex');
}

/**
 * A lock GROUP id: the unit of ownership when a process spawns a synchronous subprocess
 * that must inherit its locks. Deliberately NOT part of the signed payload - the client
 * signs its own claim either way, so covering it would add ceremony and no security. The
 * HMAC key is the whole trust boundary: anything that can say hello is already trusted to
 * name a group. The charset bound exists so a group id stays safe to print in dump output
 * and log lines, not as a security control.
 */
function normalize_group_id(group_id) {
    if (group_id === undefined || group_id === null || group_id === '') {
        return { ok: true, value: null };
    }
    if (typeof group_id !== 'string') {
        return { ok: false, error: 'hello group_id must be a string' };
    }
    if (!/^[A-Za-z0-9_.:-]{1,128}$/.test(group_id)) {
        return {
            ok: false,
            error: 'hello group_id must be 1-128 chars of [A-Za-z0-9_.:-]',
        };
    }
    return { ok: true, value: group_id };
}

/**
 * Validate an inbound hello frame. Returns { ok: true, meta } or { ok: false, error }.
 *
 * NEVER throws: this runs on the first frame of an unauthenticated connection. The
 * signature is shape-guarded (64 lowercase hex) BEFORE timingSafeEqual, because that
 * function throws RangeError - it does not return false - when the two buffers differ in
 * byte length, and a 64-char non-hex string decodes short.
 */
function verify_hello(frame, key, now_seconds) {
    if (!key) {
        return { ok: false, error: 'Server has no HMAC key configured' };
    }

    const host = frame.host;
    const pid = frame.pid;
    const ts = frame.ts;
    const sig = frame.sig;

    if (typeof host !== 'string' || host.length === 0) {
        return { ok: false, error: 'hello requires a non-empty string host' };
    }
    if (!Number.isInteger(pid) || pid <= 0) {
        return { ok: false, error: 'hello requires a positive integer pid' };
    }
    if (!Number.isInteger(ts)) {
        return { ok: false, error: 'hello requires an integer ts (unix seconds)' };
    }
    if (typeof sig !== 'string' || !/^[0-9a-f]{64}$/.test(sig)) {
        return { ok: false, error: 'hello requires a 64-char lowercase hex sig' };
    }

    if (Math.abs(now_seconds - ts) > HELLO_WINDOW_SECONDS) {
        return {
            ok: false,
            error: 'hello timestamp outside the ' + HELLO_WINDOW_SECONDS + 's replay window',
        };
    }

    const group = normalize_group_id(frame.group_id);
    if (!group.ok) {
        return { ok: false, error: group.error };
    }

    const expected = sign_hello(host, pid, ts, key);
    if (!crypto.timingSafeEqual(Buffer.from(sig, 'hex'), Buffer.from(expected, 'hex'))) {
        return { ok: false, error: 'hello signature rejected' };
    }

    return { ok: true, meta: { host, pid, ts, group_id: group.value } };
}

/** Build a signed hello frame. Used by the CLI client; exported for parity tests. */
function build_hello(host, pid, ts, key, group_id) {
    const frame = {
        op: 'hello',
        host: host,
        pid: pid,
        ts: ts,
        sig: sign_hello(host, pid, ts, key),
        protocol: PROTOCOL_VERSION,
    };

    if (group_id !== undefined && group_id !== null && group_id !== '') {
        frame.group_id = group_id;
    }

    return frame;
}

/**
 * Normalize a requested lock mode. Returns 'read' | 'write' | null. Undefined means
 * write: an unqualified "lock" is exclusive, which is the safe reading of an ambiguous
 * request.
 */
function normalize_mode(mode) {
    if (mode === undefined || mode === null || mode === '') return MODE_WRITE;
    if (typeof mode !== 'string') return null;
    const lowered = mode.toLowerCase();
    if (lowered === MODE_READ || lowered === MODE_WRITE) return lowered;
    return null;
}

/**
 * Normalize a requested timeout. Returns { ok, value } where value is null (wait
 * forever) or a positive number of seconds. null/absent is the DEFAULT - waiting forever
 * is the model, and a timeout is the exception a caller opts into.
 */
function normalize_timeout(timeout) {
    if (timeout === undefined || timeout === null) {
        return { ok: true, value: null };
    }
    if (typeof timeout !== 'number' || !isFinite(timeout) || timeout < 0) {
        return { ok: false, value: null };
    }
    return { ok: true, value: timeout };
}

/**
 * THE timeout message, emitted verbatim. system/bin/framework-pull-upstream.sh greps
 * `Failed to acquire.*lock` to classify a retryable pull failure, and the PHP test suite
 * asserts the same shape. Do not reword.
 */
function timeout_message(name, mode, seconds) {
    const kind = mode === MODE_READ ? 'READ' : 'WRITE';
    return 'Failed to acquire ' + kind + ' lock for ' + name + ' after ' + seconds + ' seconds';
}

module.exports = {
    MAX_FRAME_BYTES,
    HELLO_WINDOW_SECONDS,
    PROTOCOL_VERSION,
    OPS,
    STATUS_OK,
    STATUS_GRANTED,
    STATUS_TIMEOUT,
    STATUS_DEADLOCK,
    STATUS_ERROR,
    MODE_READ,
    MODE_WRITE,
    encode_frame,
    decode_frame,
    Frame_Reader,
    hello_payload,
    sign_hello,
    verify_hello,
    build_hello,
    normalize_group_id,
    normalize_mode,
    normalize_timeout,
    timeout_message,
};
