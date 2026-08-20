/**
 * rsx-lockd network layer.
 *
 * Owns the sockets and the per-connection state; the lock semantics live entirely in
 * locktable.js. This file's whole job is: accept a connection, authenticate it, turn bytes
 * into frames, hand each frame to the table, write the answer back, and - the important
 * part - tell the table the moment a connection dies.
 *
 * DOCTRINE: this is a SINGLE shared process serving every lock holder on the box. One
 * uncaught throw here would drop every lock in the cluster. Nothing in a frame handling
 * path is allowed to throw: hostile or malformed input is answered with a status:error
 * frame, and the outer try/catch around dispatch is a guarantee, not decoration.
 */

const fs = require('fs');
const net = require('net');
const path = require('path');

const protocol = require('./protocol');
const { Lock_Table } = require('./locktable');

// A connection that has not said hello inside this window is dropped. Bounds the number
// of unauthenticated sockets a stranger can park on the daemon.
const HELLO_TIMEOUT_MS = 10000;

// Applied to every accepted socket. Keepalive is what turns a partitioned peer (power
// loss, cable pull - the cases where no FIN ever arrives) into a close event, which is
// what releases its locks. Without it those locks would be held by a ghost forever, since
// there is no lease to expire.
const KEEPALIVE_DELAY_MS = 30000;

class Lockd_Server {
    constructor(options) {
        this.config = options.config;
        this.hmac_key = options.hmac_key || '';
        this.log = options.log || function () {};

        this.conn_seq = 0;
        this.connections = new Map();   // conn_id -> { id, socket, authenticated, reader, hello_timer }
        this.servers = [];
        this.unix_path = null;

        this.table = new Lock_Table({
            deliver: (conn_id, frame) => {
                this._send_to(conn_id, frame);
            },
        });
    }

    // ---- Lifecycle ------------------------------------------------------------------

    async start() {
        if (this.config.unix.enabled) {
            await this._listen_unix();
        }
        if (this.config.tcp.enabled) {
            await this._listen_tcp();
        }
    }

    async stop() {
        for (const [, conn] of this.connections) {
            this._destroy(conn);
        }
        this.connections.clear();

        for (const server of this.servers) {
            await new Promise((resolve) => server.close(() => resolve()));
        }
        this.servers = [];

        // Best-effort: a leftover socket file blocks the next bind, and the file is
        // meaningless once this process is gone.
        if (this.unix_path) {
            try {
                fs.unlinkSync(this.unix_path);
            } catch (err) {
                if (err.code !== 'ENOENT') {
                    this.log('[WARNING] Could not remove socket ' + this.unix_path + ': ' + err.message);
                }
            }
        }
    }

    _listen_unix() {
        const socket_path = this.config.unix.path;

        // A stale socket file from a crashed instance makes bind fail with EADDRINUSE even
        // though nobody is listening. Probe it: if a connect succeeds, another daemon is
        // live and we must NOT steal its path.
        return this._probe_unix(socket_path).then((in_use) => {
            if (in_use) {
                throw new Error('Another process is already listening on ' + socket_path);
            }
            try {
                fs.unlinkSync(socket_path);
            } catch (err) {
                if (err.code !== 'ENOENT') {
                    throw new Error('Cannot remove stale socket ' + socket_path + ': ' + err.message);
                }
            }

            const dir = path.dirname(socket_path);
            if (!fs.existsSync(dir)) {
                fs.mkdirSync(dir, { recursive: true });
            }

            return new Promise((resolve, reject) => {
                const server = net.createServer((socket) => this._accept(socket, 'unix'));
                server.on('error', (err) => reject(err));
                server.listen(socket_path, () => {
                    try {
                        fs.chmodSync(socket_path, parseInt(this.config.unix.mode, 8));
                    } catch (err) {
                        this.log('[WARNING] Could not chmod ' + socket_path + ': ' + err.message);
                    }
                    this.unix_path = socket_path;
                    this.servers.push(server);
                    this.log('[OK] Listening on unix socket ' + socket_path);
                    resolve();
                });
            });
        });
    }

    _probe_unix(socket_path) {
        return new Promise((resolve) => {
            if (!fs.existsSync(socket_path)) {
                resolve(false);
                return;
            }
            const probe = net.connect(socket_path);
            probe.on('connect', () => {
                probe.destroy();
                resolve(true);
            });
            probe.on('error', () => {
                probe.destroy();
                resolve(false);
            });
        });
    }

    _listen_tcp() {
        return new Promise((resolve, reject) => {
            const server = net.createServer((socket) => this._accept(socket, 'tcp'));
            server.on('error', (err) => reject(err));
            server.listen(this.config.tcp.port, this.config.tcp.bind, () => {
                this.servers.push(server);
                this.log('[OK] Listening on tcp ' + this.config.tcp.bind + ':' + this.config.tcp.port);
                resolve();
            });
        });
    }

    // ---- Connections ----------------------------------------------------------------

    _accept(socket, kind) {
        this.conn_seq++;
        const id = 'c' + this.conn_seq;

        if (kind === 'tcp') {
            // Tiny request/response frames: Nagle would add 40ms to a lock acquisition for
            // no benefit. Keepalive is the liveness detector described at the top.
            socket.setNoDelay(true);
            socket.setKeepAlive(true, KEEPALIVE_DELAY_MS);
        }
        const remote = kind === 'tcp'
            ? (socket.remoteAddress || '?') + ':' + (socket.remotePort || '?')
            : 'unix';

        const conn = {
            id: id,
            socket: socket,
            kind: kind,
            authenticated: false,
            reader: new protocol.Frame_Reader(),
            hello_timer: null,
        };
        this.connections.set(id, conn);
        this.table.register_connection(id, { remote: remote });

        conn.hello_timer = setTimeout(() => {
            if (!conn.authenticated) {
                this._send(conn, { status: protocol.STATUS_ERROR, message: 'hello timeout' });
                this._destroy(conn);
            }
        }, HELLO_TIMEOUT_MS);

        socket.on('data', (chunk) => this._on_data(conn, chunk));
        socket.on('close', () => this._on_close(conn));
        socket.on('error', (err) => {
            // Not exceptional: a client that exits mid-write produces ECONNRESET. The
            // close handler that follows is what releases the locks.
            if (err && err.code !== 'ECONNRESET' && err.code !== 'EPIPE') {
                this.log('[WARNING] Connection ' + conn.id + ' error: ' + err.message);
            }
        });
    }

    _on_data(conn, chunk) {
        const result = conn.reader.push(chunk);

        for (const line of result.lines) {
            const decoded = protocol.decode_frame(line);
            if (!decoded.ok) {
                this._send(conn, { status: protocol.STATUS_ERROR, message: decoded.error });
                continue;
            }
            this._dispatch(conn, decoded.value);
        }

        if (result.error) {
            this._send(conn, { status: protocol.STATUS_ERROR, message: result.error });
            this._destroy(conn);
        }
    }

    _on_close(conn) {
        if (conn.hello_timer) {
            clearTimeout(conn.hello_timer);
            conn.hello_timer = null;
        }
        if (!this.connections.has(conn.id)) return;
        this.connections.delete(conn.id);

        // THE correctness model: the connection is the lock, so its disappearance - clean
        // exit, crash, kill -9, or a dead TCP peer - releases everything it held and
        // unparks whoever was waiting on it.
        const result = this.table.drop_connection(conn.id);
        if (result.released > 0 || result.cancelled > 0) {
            this.log('[lockd] ' + conn.id + ' closed: released ' + result.released
                + ' hold(s), cancelled ' + result.cancelled + ' wait(s)');
        }
    }

    _destroy(conn) {
        try {
            conn.socket.destroy();
        } catch (err) {
            // Already gone. The close handler still runs and still releases.
        }
    }

    // ---- Dispatch -------------------------------------------------------------------

    _dispatch(conn, frame) {
        // The guarantee. Any bug or hostile shape below produces an error frame on ONE
        // connection instead of terminating the daemon for every lock holder on the box.
        try {
            const op = frame.op;

            if (typeof op !== 'string' || op.length === 0) {
                this._send(conn, { id: frame.id, status: protocol.STATUS_ERROR, message: "Frame requires an 'op'" });
                return;
            }

            // Liveness probe, deliberately open pre-hello: it discloses nothing and lets a
            // health check confirm the daemon is answering without holding a key.
            if (op === 'ping') {
                this._send(conn, { id: frame.id, status: protocol.STATUS_OK, pong: true });
                return;
            }

            if (op === 'hello') {
                this._handle_hello(conn, frame);
                return;
            }

            if (!conn.authenticated) {
                this._send(conn, {
                    id: frame.id,
                    status: protocol.STATUS_ERROR,
                    message: 'Not authenticated - send hello first',
                });
                return;
            }

            const response = this._handle_authenticated(conn, op, frame);
            if (response !== null && response !== undefined) {
                this._send(conn, response);
            }
        } catch (err) {
            this.log('[ERROR] Dispatch failure on ' + conn.id + ' (' + (frame && frame.op) + '): ' + err.stack);
            this._send(conn, {
                id: frame ? frame.id : undefined,
                status: protocol.STATUS_ERROR,
                message: 'Internal error: ' + err.message,
            });
        }
    }

    _handle_authenticated(conn, op, frame) {
        switch (op) {
            case 'acquire':      return this.table.acquire(conn.id, frame);
            case 'release':      return this.table.release(conn.id, frame);
            case 'release_all':  return this.table.release_all(conn.id, frame);
            case 'upgrade':      return this.table.upgrade(conn.id, frame);
            case 'sem_acquire':  return this.table.sem_acquire(conn.id, frame);
            case 'sem_release':  return this.table.sem_release(conn.id, frame);
            case 'stats':        return this.table.stats(frame);
            case 'dump':         return this.table.dump(frame);
            case 'force_clear':  return this.table.force_clear(frame);
            default:
                return {
                    id: frame.id,
                    status: protocol.STATUS_ERROR,
                    message: 'Unknown op: ' + op + ' (known: ' + protocol.OPS.join(', ') + ')',
                };
        }
    }

    _handle_hello(conn, frame) {
        if (conn.authenticated) {
            this._send(conn, { id: frame.id, status: protocol.STATUS_ERROR, message: 'Already authenticated' });
            return;
        }

        const now_seconds = Math.floor(Date.now() / 1000);
        const verified = protocol.verify_hello(frame, this.hmac_key, now_seconds);
        if (!verified.ok) {
            // No retry loop and no partial credit: a failed hello closes the connection.
            this.log('[WARNING] hello rejected on ' + conn.id + ': ' + verified.error);
            this._send(conn, { id: frame.id, status: protocol.STATUS_ERROR, message: verified.error });
            this._destroy(conn);
            return;
        }

        conn.authenticated = true;
        clearTimeout(conn.hello_timer);
        conn.hello_timer = null;

        this.table.set_connection_meta(conn.id, {
            host: verified.meta.host,
            pid: verified.meta.pid,
            group_id: verified.meta.group_id || null,
        });

        this._send(conn, {
            id: frame.id,
            status: protocol.STATUS_OK,
            conn_id: conn.id,
            group_id: verified.meta.group_id || conn.id,
            server: 'rsx-lockd',
            protocol: protocol.PROTOCOL_VERSION,
        });
    }

    // ---- Output ---------------------------------------------------------------------

    _send(conn, frame) {
        if (!conn || !conn.socket || conn.socket.destroyed || !conn.socket.writable) return;
        try {
            conn.socket.write(protocol.encode_frame(frame));
        } catch (err) {
            this.log('[WARNING] Write to ' + conn.id + ' failed: ' + err.message);
        }
    }

    _send_to(conn_id, frame) {
        this._send(this.connections.get(conn_id), frame);
    }
}

module.exports = { Lockd_Server, HELLO_TIMEOUT_MS, KEEPALIVE_DELAY_MS };
