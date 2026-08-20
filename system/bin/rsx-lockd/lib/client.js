/**
 * rsx-lockd client, used by the `dump` and `exec` CLI commands.
 *
 * Deliberately small and dependency-free. The server never sends an unsolicited frame, but
 * responses CAN arrive out of order (a blocking acquire parks while a later ping answers
 * straight away), so every request carries an id and the reply is matched on it.
 */

const net = require('net');
const os = require('os');

const protocol = require('./protocol');

const DEFAULT_CONNECT_RETRIES = 3;
const DEFAULT_CONNECT_RETRY_DELAY_MS = 1000;

class Lockd_Client {
    constructor(socket) {
        this.socket = socket;
        this.reader = new protocol.Frame_Reader();
        this.pending = new Map();     // id -> { resolve, reject }
        this.seq = 0;
        this.closed = false;
        this.close_reason = null;

        socket.on('data', (chunk) => this._on_data(chunk));
        socket.on('close', () => this._on_close('Connection closed by server'));
        socket.on('error', (err) => {
            this.close_reason = err.message;
        });
    }

    _on_data(chunk) {
        const result = this.reader.push(chunk);
        for (const line of result.lines) {
            const decoded = protocol.decode_frame(line);
            if (!decoded.ok) continue;

            const frame = decoded.value;
            const waiter = this.pending.get(frame.id);
            if (waiter) {
                this.pending.delete(frame.id);
                waiter.resolve(frame);
            }
        }
        if (result.error) {
            this._on_close(result.error);
        }
    }

    _on_close(reason) {
        if (this.closed) return;
        this.closed = true;
        const message = this.close_reason || reason;
        for (const [, waiter] of this.pending) {
            waiter.reject(new Error(message));
        }
        this.pending.clear();
    }

    /**
     * Send one request and resolve with its response frame. There is NO client-side
     * deadline: an acquire that is waiting is supposed to wait, possibly forever. A caller
     * that wants a bound passes `timeout` in the frame and lets the SERVER answer.
     */
    request(frame) {
        return new Promise((resolve, reject) => {
            if (this.closed) {
                reject(new Error(this.close_reason || 'Connection closed'));
                return;
            }
            this.seq++;
            const id = 'r' + this.seq;
            const out = Object.assign({}, frame, { id: id });
            this.pending.set(id, { resolve: resolve, reject: reject });
            this.socket.write(protocol.encode_frame(out));
        });
    }

    close() {
        this.closed = true;
        try {
            this.socket.end();
            this.socket.destroy();
        } catch (err) {
            // Nothing to do; the daemon releases on close either way.
        }
    }
}

function connect_once(target) {
    return new Promise((resolve, reject) => {
        const socket = target.socket_path
            ? net.connect(target.socket_path)
            : net.connect(target.port, target.host);

        const on_error = (err) => {
            socket.destroy();
            reject(err);
        };
        socket.once('error', on_error);
        socket.once('connect', () => {
            socket.removeListener('error', on_error);
            if (!target.socket_path) {
                socket.setNoDelay(true);
            }
            resolve(socket);
        });
    });
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Decide where to connect. An explicit --host/--port always wins; otherwise the unix
 * socket is preferred when the config enables it (no TCP stack, no port exposure), and TCP
 * is the fallback.
 */
function resolve_target(config, options) {
    if (options.host || options.port) {
        const bind = config.tcp.bind;
        const host = options.host
            || ((bind === '0.0.0.0' || bind === '::') ? '127.0.0.1' : bind);
        const port = options.port || config.tcp.port;
        return { host: host, port: port, label: host + ':' + port };
    }

    if (config.unix.enabled) {
        return { socket_path: config.unix.path, label: config.unix.path };
    }

    // 0.0.0.0 is a bind address, not a destination: dial loopback instead.
    const host = (config.tcp.bind === '0.0.0.0' || config.tcp.bind === '::') ? '127.0.0.1' : config.tcp.bind;
    return { host: host, port: config.tcp.port, label: host + ':' + config.tcp.port };
}

/**
 * Connect and complete the hello handshake, retrying the CONNECT (not the handshake - a
 * rejected key will never become accepted) a few times, because the common failure is a
 * daemon that is restarting.
 */
async function connect(config, options) {
    const target = resolve_target(config, options || {});
    const retries = (options && options.retries !== undefined) ? options.retries : DEFAULT_CONNECT_RETRIES;
    const delay_ms = (options && options.retry_delay_ms !== undefined)
        ? options.retry_delay_ms
        : DEFAULT_CONNECT_RETRY_DELAY_MS;

    let last_error = null;
    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            const socket = await connect_once(target);
            const client = new Lockd_Client(socket);

            const hello = protocol.build_hello(
                os.hostname(),
                process.pid,
                Math.floor(Date.now() / 1000),
                (options && options.hmac_key) || '',
                (options && options.group_id) || null
            );
            const response = await client.request(hello);
            if (response.status !== protocol.STATUS_OK) {
                client.close();
                throw new Error('hello rejected: ' + (response.message || 'unknown reason'));
            }

            client.conn_id = response.conn_id;
            client.target_label = target.label;
            return client;
        } catch (err) {
            last_error = err;
            if (attempt < retries) {
                await sleep(delay_ms);
            }
        }
    }

    throw new Error('Cannot reach rsx-lockd at ' + target.label + ': ' + (last_error ? last_error.message : 'unknown'));
}

module.exports = {
    Lockd_Client,
    connect,
    connect_once,
    resolve_target,
    DEFAULT_CONNECT_RETRIES,
    DEFAULT_CONNECT_RETRY_DELAY_MS,
};
