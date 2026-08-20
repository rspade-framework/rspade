#!/usr/bin/env node

/**
 * rsx-lockd - a lock daemon whose CONNECTION IS THE LOCK.
 *
 * A lock is held from the moment it is granted until the holder releases it or its
 * connection goes away. There is no lease, no TTL, no renewal and no heartbeat: a holder
 * that crashes, is kill -9'd, or falls off the network releases immediately, and a holder
 * that is alive keeps its lock for as long as it needs it - a minute, an hour, a day.
 * The only clock in the whole daemon is the optional per-waiter timeout a caller asks for.
 *
 * Usage:
 *   node lockd.js run                          foreground (what supervisor invokes)
 *   node lockd.js start | stop | restart       daemonize / signal via pidfile
 *   node lockd.js dump [--json]                what is held and who is waiting
 *   node lockd.js exec --name=X -- <cmd...>    hold a lock for the life of a command
 *
 * Zero npm dependencies - node core only, so this directory is extractable to its own
 * repo. See README.md for the protocol, config keys and exit codes.
 */

const child_process = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const config_lib = require('./lib/config');
const client_lib = require('./lib/client');
const protocol = require('./lib/protocol');
const { Lockd_Server } = require('./lib/server');

const VERSION = '1.0.0';

// This file lives at <project>/system/bin/rsx-lockd/lockd.js inside RSpade. Outside it,
// PROJECT_ROOT is simply three levels up and the only thing it is used for is locating an
// optional .env and an optional <project>/lockd.conf - both absent is fine.
const SHIPPED_DIR = __dirname;
const PROJECT_ROOT = path.resolve(__dirname, '..', '..', '..');

// Exit codes. 124/125 follow GNU `timeout`'s convention of reserving the high codes for
// "the tool itself gave up", so a caller can tell them from any code the child produced.
const EXIT_OK = 0;
const EXIT_FAILURE = 1;
const EXIT_TIMEOUT = 124;
const EXIT_DEADLOCK = 125;

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------

/**
 * Hand-rolled .env reader, same as the other node helpers in system/bin. Only fills keys
 * that are not already in the real environment, so an explicit export always wins. Absent
 * file is not an error: outside RSpade the key just comes from the environment.
 */
function load_env_file(env_path) {
    if (!fs.existsSync(env_path)) return;

    const content = fs.readFileSync(env_path, 'utf8');
    for (const line of content.split('\n')) {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) continue;
        const eq = trimmed.indexOf('=');
        if (eq === -1) continue;
        const key = trimmed.substring(0, eq);
        let value = trimmed.substring(eq + 1);
        if ((value.startsWith('"') && value.endsWith('"'))
            || (value.startsWith("'") && value.endsWith("'"))) {
            value = value.slice(1, -1);
        }
        if (!process.env[key]) {
            process.env[key] = value;
        }
    }
}

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------

/**
 * Split argv into { command, flags, rest }. Everything after a bare `--` goes to `rest`
 * untouched - that is the command `exec` runs, and it must survive verbatim including its
 * own flags.
 *
 * Pure: exported and unit-testable.
 */
function parse_argv(argv) {
    const out = { command: null, flags: {}, rest: [] };
    let seen_separator = false;

    for (const arg of argv) {
        if (seen_separator) {
            out.rest.push(arg);
            continue;
        }
        if (arg === '--') {
            seen_separator = true;
            continue;
        }
        if (arg.startsWith('--')) {
            const body = arg.slice(2);
            const eq = body.indexOf('=');
            if (eq === -1) {
                out.flags[body] = true;
            } else {
                out.flags[body.slice(0, eq)] = body.slice(eq + 1);
            }
            continue;
        }
        if (out.command === null) {
            out.command = arg;
        } else {
            out.rest.push(arg);
        }
    }

    return out;
}

function flag_int(flags, name) {
    if (flags[name] === undefined || flags[name] === true) return null;
    const value = parseInt(flags[name], 10);
    return Number.isNaN(value) ? null : value;
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

function make_logger(logfile) {
    if (!logfile) {
        return function (message) {
            console.log('[lockd] ' + message);
        };
    }

    const stream = fs.createWriteStream(logfile, { flags: 'a' });
    return function (message) {
        stream.write('[' + new Date().toISOString() + '] [lockd] ' + message + '\n');
    };
}

function fail(message) {
    console.error('[lockd] [ERROR] ' + message);
    process.exit(EXIT_FAILURE);
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

function resolve_config(flags) {
    try {
        return config_lib.load_config({
            cli_path: typeof flags.config === 'string' ? flags.config : null,
            env_path: process.env.LOCKD_CONFIG || null,
            project_root: PROJECT_ROOT,
            shipped_dir: SHIPPED_DIR,
        });
    } catch (err) {
        fail(err.message);
        return null;   // unreachable; keeps the shape obvious
    }
}

function resolve_hmac_key(config) {
    const key = process.env[config.auth.hmac_key_env] || '';
    if (!key) {
        fail(config.auth.hmac_key_env + ' is not set - rsx-lockd cannot authenticate clients without it');
    }
    return key;
}

// ---------------------------------------------------------------------------
// Pidfile
// ---------------------------------------------------------------------------

function read_pidfile(pidfile) {
    if (!fs.existsSync(pidfile)) return null;
    const raw = fs.readFileSync(pidfile, 'utf8').trim();
    const pid = parseInt(raw, 10);
    return Number.isNaN(pid) ? null : pid;
}

/** Signal 0 asks the kernel "does this pid exist and may I signal it" without sending. */
function is_alive(pid) {
    if (!pid) return false;
    try {
        process.kill(pid, 0);
        return true;
    } catch (err) {
        return err.code === 'EPERM';   // exists, owned by someone else
    }
}

function write_pidfile(pidfile, pid) {
    const dir = path.dirname(pidfile);
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
    }
    fs.writeFileSync(pidfile, String(pid) + '\n');
}

function remove_pidfile(pidfile) {
    try {
        fs.unlinkSync(pidfile);
    } catch (err) {
        // Already gone, or never ours.
    }
}

// ---------------------------------------------------------------------------
// Command: run
// ---------------------------------------------------------------------------

async function command_run(flags) {
    const resolved = resolve_config(flags);
    const config = resolved.config;
    const hmac_key = resolve_hmac_key(config);
    const log = make_logger(config.logfile);

    const existing = read_pidfile(config.pidfile);
    if (existing && is_alive(existing) && existing !== process.pid) {
        fail('rsx-lockd is already running as pid ' + existing + ' (' + config.pidfile + ')');
    }
    if (existing && !is_alive(existing)) {
        log('[WARNING] Removing stale pidfile ' + config.pidfile + ' (pid ' + existing + ' is not running)');
    }

    log('rsx-lockd ' + VERSION + ' starting (config ' + resolved.path + ', source ' + resolved.source + ')');

    const server = new Lockd_Server({ config: config, hmac_key: hmac_key, log: log });

    try {
        await server.start();
    } catch (err) {
        console.error('[lockd] [ERROR] Failed to start: ' + err.message);
        process.exit(EXIT_FAILURE);
    }

    write_pidfile(config.pidfile, process.pid);
    log('[OK] Ready (pid ' + process.pid + ')');

    let shutting_down = false;
    const shutdown = async (signal) => {
        if (shutting_down) return;
        shutting_down = true;
        log('Shutting down on ' + signal + ' - all held locks are released by the disconnect');
        await server.stop();
        remove_pidfile(config.pidfile);
        process.exit(EXIT_OK);
    };

    process.on('SIGTERM', () => { shutdown('SIGTERM'); });
    process.on('SIGINT', () => { shutdown('SIGINT'); });

    // Survive a terminal disconnect when started by hand.
    process.on('SIGHUP', () => {});

    // A single shared daemon must not die on a stray rejection or a bug in one code path:
    // exiting would drop EVERY lock on the box. Log loudly and keep serving. Matches the
    // ssr-server precedent.
    process.on('unhandledRejection', (err) => {
        log('[ERROR] Unhandled rejection: ' + (err && err.stack ? err.stack : err));
    });
    process.on('uncaughtException', (err) => {
        log('[ERROR] Uncaught exception: ' + (err && err.stack ? err.stack : err));
    });
}

// ---------------------------------------------------------------------------
// Command: start / stop / restart
// ---------------------------------------------------------------------------

async function command_start(flags) {
    const resolved = resolve_config(flags);
    const config = resolved.config;
    resolve_hmac_key(config);

    const existing = read_pidfile(config.pidfile);
    if (existing && is_alive(existing)) {
        console.log('[lockd] Already running as pid ' + existing);
        return EXIT_OK;
    }
    if (existing) {
        console.log('[lockd] [WARNING] Stale pidfile ' + config.pidfile + ' (pid ' + existing + ' is gone)');
        remove_pidfile(config.pidfile);
    }

    const args = [__filename, 'run'];
    if (typeof flags.config === 'string') args.push('--config=' + flags.config);

    // Detached with the parent's stdio dropped: the daemon must outlive this shell. Its
    // output goes to the configured logfile, or to /dev/null when there is none - which is
    // why `run` under supervisor (which captures stdout) is the supported way to see logs.
    const out = config.logfile ? fs.openSync(config.logfile, 'a') : 'ignore';
    const child = child_process.spawn(process.execPath, args, {
        detached: true,
        stdio: ['ignore', out, out],
    });
    child.unref();

    // Wait for the child to write its own pidfile. That write happens after a successful
    // bind, so its appearance is proof the daemon is actually listening - not merely that
    // a process was spawned.
    for (let attempt = 0; attempt < 50; attempt++) {
        await sleep(100);
        const pid = read_pidfile(config.pidfile);
        if (pid && is_alive(pid)) {
            console.log('[lockd] [OK] Started as pid ' + pid);
            return EXIT_OK;
        }
    }

    console.error('[lockd] [ERROR] Daemon did not come up within 5 seconds'
        + (config.logfile ? ' - see ' + config.logfile : ' - run `lockd.js run` in the foreground to see why'));
    return EXIT_FAILURE;
}

async function command_stop(flags) {
    const resolved = resolve_config(flags);
    const config = resolved.config;

    const pid = read_pidfile(config.pidfile);
    if (!pid) {
        console.log('[lockd] Not running (no pidfile at ' + config.pidfile + ')');
        return EXIT_OK;
    }
    if (!is_alive(pid)) {
        console.log('[lockd] [WARNING] Stale pidfile ' + config.pidfile + ' (pid ' + pid + ' is gone) - removing');
        remove_pidfile(config.pidfile);
        return EXIT_OK;
    }

    process.kill(pid, 'SIGTERM');

    for (let attempt = 0; attempt < 100; attempt++) {
        await sleep(100);
        if (!is_alive(pid)) {
            remove_pidfile(config.pidfile);
            console.log('[lockd] [OK] Stopped pid ' + pid);
            return EXIT_OK;
        }
    }

    console.log('[lockd] [WARNING] pid ' + pid + ' ignored SIGTERM for 10s - sending SIGKILL');
    try {
        process.kill(pid, 'SIGKILL');
    } catch (err) {
        // Raced with its own exit.
    }
    remove_pidfile(config.pidfile);
    return EXIT_OK;
}

async function command_restart(flags) {
    const stopped = await command_stop(flags);
    if (stopped !== EXIT_OK) return stopped;
    return await command_start(flags);
}

// ---------------------------------------------------------------------------
// Command: dump
// ---------------------------------------------------------------------------

async function command_dump(flags) {
    const resolved = resolve_config(flags);
    const config = resolved.config;
    const hmac_key = resolve_hmac_key(config);

    let client;
    try {
        client = await client_lib.connect(config, {
            hmac_key: hmac_key,
            host: typeof flags.host === 'string' ? flags.host : null,
            port: flag_int(flags, 'port'),
        });
    } catch (err) {
        console.error('[lockd] [ERROR] ' + err.message);
        return EXIT_FAILURE;
    }

    try {
        const response = await client.request({ op: 'dump' });
        if (response.status !== protocol.STATUS_OK) {
            console.error('[lockd] [ERROR] ' + (response.message || 'dump failed'));
            return EXIT_FAILURE;
        }

        if (flags.json) {
            console.log(JSON.stringify(response, null, 2));
        } else {
            print_dump(response, client.conn_id);
        }
        return EXIT_OK;
    } finally {
        client.close();
    }
}

/** ASCII only - no unicode box drawing, no emoji. */
function print_dump(dump, own_conn_id) {
    const seconds = (ms) => (ms / 1000).toFixed(1) + 's';

    console.log('rsx-lockd state');
    console.log('  connections: ' + dump.connections.length
        + '   locks: ' + dump.locks.length
        + '   semaphores: ' + dump.semaphores.length);
    console.log('  granted: ' + dump.counters.granted
        + '   released: ' + dump.counters.released
        + '   timed_out: ' + dump.counters.timed_out
        + '   deadlocked: ' + dump.counters.deadlocked
        + '   dropped_connections: ' + dump.counters.dropped_connections);
    console.log('');

    if (dump.connections.length === 0) {
        console.log('  (no connections)');
        return;
    }

    for (const conn of dump.connections) {
        const who = (conn.host || '?') + ':' + (conn.pid || '?');
        const self = conn.conn_id === own_conn_id ? '  (this dump)' : '';
        // Only printed when the connection joined someone else's group - two connections
        // showing the same one is a parent and the subprocess it is blocked waiting on.
        const group = conn.group_id ? '  group ' + conn.group_id : '';
        console.log(conn.conn_id + '  ' + who + '  via ' + (conn.remote || '?')
            + '  up ' + seconds(conn.connected_ms) + group + self);

        if (conn.holds.length === 0 && conn.semaphores.length === 0 && conn.waiting.length === 0) {
            console.log('    (holds nothing, waits for nothing)');
        }
        for (const hold of conn.holds) {
            console.log('    HELD     ' + hold.mode.toUpperCase().padEnd(5) + ' '
                + hold.name + '   for ' + seconds(hold.held_ms));
        }
        for (const slot of conn.semaphores) {
            console.log('    HELD     SEM   ' + slot.name + ' slot ' + slot.slot
                + '   for ' + seconds(slot.held_ms));
        }
        for (const wait of conn.waiting) {
            const kind = wait.kind === 'lock' ? String(wait.mode).toUpperCase().padEnd(5) : 'SEM  ';
            const bound = wait.timeout === null ? 'no timeout' : 'timeout ' + wait.timeout + 's';
            console.log('    WAITING  ' + kind + ' ' + wait.name
                + '   for ' + seconds(wait.waiting_ms) + '   (' + bound + ')');
        }
        console.log('');
    }

    if (dump.locks.length > 0) {
        console.log('locks');
        for (const lock of dump.locks) {
            const holders = lock.holders.map((h) => h.conn_id + '/' + h.mode).join(', ') || 'none';
            const queue = lock.queue.map((q) => q.conn_id + '/' + q.mode).join(' -> ') || 'empty';
            console.log('  ' + lock.name);
            console.log('    holders: ' + holders);
            console.log('    queue:   ' + queue);
        }
        console.log('');
    }

    if (dump.semaphores.length > 0) {
        console.log('semaphores');
        for (const semaphore of dump.semaphores) {
            console.log('  ' + semaphore.name + '  ' + semaphore.holders.length
                + '/' + semaphore.max_slots + ' slots, ' + semaphore.queue.length + ' waiting');
        }
    }
}

// ---------------------------------------------------------------------------
// Command: exec
// ---------------------------------------------------------------------------

async function command_exec(flags, rest) {
    const name = typeof flags.name === 'string' ? flags.name : null;
    if (!name) {
        console.error('[lockd] [ERROR] exec requires --name=<lock name>');
        return EXIT_FAILURE;
    }
    if (rest.length === 0) {
        console.error('[lockd] [ERROR] exec requires a command after --');
        return EXIT_FAILURE;
    }

    const mode = protocol.normalize_mode(typeof flags.mode === 'string' ? flags.mode : undefined);
    if (!mode) {
        console.error("[lockd] [ERROR] --mode must be 'read' or 'write'");
        return EXIT_FAILURE;
    }

    const timeout = flag_int(flags, 'timeout');
    const quiet = flags.quiet === true || flags.quiet === 'true';
    const say = (message) => {
        if (!quiet) console.error('[lockd] ' + message);
    };

    const resolved = resolve_config(flags);
    const config = resolved.config;
    const hmac_key = resolve_hmac_key(config);

    // --group joins an existing lock group, so this command inherits whatever that group
    // already holds instead of queueing behind it. Same contract as the PHP client's
    // --_lock-group: only pass it when the group's owner is waiting for this command.
    const group_id = typeof flags.group === 'string' ? flags.group : null;
    if (group_id !== null) {
        const checked = protocol.normalize_group_id(group_id);
        if (!checked.ok) {
            console.error('[lockd] [ERROR] ' + checked.error);
            return EXIT_FAILURE;
        }
    }

    let client;
    try {
        client = await client_lib.connect(config, {
            hmac_key: hmac_key,
            host: typeof flags.host === 'string' ? flags.host : null,
            port: flag_int(flags, 'port'),
            group_id: group_id,
        });
    } catch (err) {
        console.error('[lockd] [ERROR] ' + err.message);
        return EXIT_FAILURE;
    }

    // Only mention waiting if we are actually waiting. Announcing it unconditionally would
    // put a line of noise in front of every fast acquisition.
    const notice = setTimeout(() => {
        say('waiting for ' + mode.toUpperCase() + ' lock ' + name + '...');
    }, 250);

    let response;
    try {
        response = await client.request({ op: 'acquire', name: name, mode: mode, timeout: timeout });
    } catch (err) {
        clearTimeout(notice);
        client.close();
        console.error('[lockd] [ERROR] ' + err.message);
        return EXIT_FAILURE;
    }
    clearTimeout(notice);

    if (response.status === protocol.STATUS_TIMEOUT) {
        client.close();
        console.error('[lockd] [ERROR] ' + response.message);
        return EXIT_TIMEOUT;
    }
    if (response.status === protocol.STATUS_DEADLOCK) {
        client.close();
        console.error('[lockd] [ERROR] ' + response.message);
        for (const hop of (response.cycle || [])) {
            console.error('[lockd]   ' + hop);
        }
        return EXIT_DEADLOCK;
    }
    if (response.status !== protocol.STATUS_GRANTED) {
        client.close();
        console.error('[lockd] [ERROR] ' + (response.message || 'acquire failed'));
        return EXIT_FAILURE;
    }

    say('holding ' + mode.toUpperCase() + ' lock ' + name + ' (token ' + response.token + ')');

    // The command runs DIRECTLY, not through a shell: argv is passed through verbatim so
    // nothing re-interprets quoting. Use `-- bash -c '...'` when shell features are wanted.
    const code = await run_child(rest[0], rest.slice(1));

    // The close alone would release the lock; the explicit release is politeness so `dump`
    // is accurate in the microseconds before the socket teardown lands.
    try {
        await client.request({ op: 'release', token: response.token });
    } catch (err) {
        // Daemon went away while the child ran. The lock is released regardless.
    }
    client.close();

    say('released ' + name);
    return code;
}

/**
 * Run the child with its stdio wired straight to ours, so its output streams live rather
 * than being buffered and replayed. Resolves with the exit code, or 128+N when a signal
 * killed it (the shell convention).
 */
function run_child(command, args) {
    return new Promise((resolve) => {
        let child;
        try {
            child = child_process.spawn(command, args, { stdio: 'inherit' });
        } catch (err) {
            console.error('[lockd] [ERROR] Cannot execute ' + command + ': ' + err.message);
            resolve(EXIT_FAILURE);
            return;
        }

        child.on('error', (err) => {
            console.error('[lockd] [ERROR] Cannot execute ' + command + ': ' + err.message);
            resolve(EXIT_FAILURE);
        });

        child.on('exit', (code, signal) => {
            if (signal) {
                resolve(128 + (os.constants.signals[signal] || 0));
                return;
            }
            resolve(code === null ? EXIT_FAILURE : code);
        });
    });
}

// ---------------------------------------------------------------------------
// Help
// ---------------------------------------------------------------------------

const HELP = {
    _: [
        'rsx-lockd ' + VERSION + ' - lock daemon whose connection is the lock',
        '',
        'Usage: node lockd.js <command> [options]',
        '',
        'Commands:',
        '  run                       Run in the foreground (what supervisor invokes)',
        '  start                     Daemonize, recording the pid in the configured pidfile',
        '  stop                      SIGTERM the daemon named by the pidfile',
        '  restart                   stop then start',
        '  dump [--json]             Show every lock held and every waiter, per connection',
        '  exec --name=X -- <cmd>    Hold a lock for exactly as long as <cmd> runs',
        '',
        'Global options:',
        '  --config=<path>           Config file (else LOCKD_CONFIG, <project>/lockd.conf,',
        '                            then the lockd.conf shipped beside this script)',
        '  --help                    This text, or per-command help after a command',
        '',
        'Client options (dump, exec):',
        '  --host=<host>             Target a remote daemon instead of the configured one',
        '  --port=<port>             Target port (default: tcp.port from the config)',
        '',
        'Exit codes (exec): the child process code, 124 on acquire timeout,',
        '125 on deadlock, 1 on connect or protocol failure.',
    ],
    run: [
        'Usage: node lockd.js run [--config=<path>]',
        '',
        'Runs in the foreground and never detaches. Writes the pidfile after a successful',
        'bind and removes it on SIGTERM/SIGINT. This is the supervisor entry point',
        '(via lockd-run.sh); logs go to stdout unless the config sets a logfile.',
    ],
    start: [
        'Usage: node lockd.js start [--config=<path>]',
        '',
        'Spawns a detached `run` and waits up to 5 seconds for its pidfile to appear.',
        'A stale pidfile (pid not alive) is reported and removed rather than obeyed.',
    ],
    stop: [
        'Usage: node lockd.js stop [--config=<path>]',
        '',
        'SIGTERM the pid in the pidfile and wait up to 10 seconds, then SIGKILL.',
        'Every lock the daemon was tracking disappears with it - by design: the holder',
        'connections are gone, so nothing is held.',
    ],
    restart: [
        'Usage: node lockd.js restart [--config=<path>]',
        '',
        'stop followed by start.',
    ],
    dump: [
        'Usage: node lockd.js dump [--json] [--host=<host>] [--port=<port>]',
        '',
        'Prints every connection with what it holds and what it waits for, then the',
        'per-lock holder set and queue. --json emits the raw response frame.',
    ],
    exec: [
        'Usage: node lockd.js exec --name=<lock> [--mode=read|write] [--timeout=<seconds>]',
        '                         [--group=<id>] [--quiet] [--host=<host>] [--port=<port>]',
        '                         -- <command...>',
        '',
        'Acquires the lock, runs <command> with stdout/stderr streamed through, releases,',
        "and exits with the child's exit code. Without --timeout it waits forever.",
        "The command is executed directly, NOT through a shell - use `-- bash -c '...'`",
        'when shell features are needed.',
        '',
        '  --mode=read|write   Lock mode (default: write)',
        '  --timeout=N         Give up after N seconds and exit 124',
        '  --group=<id>        Join a lock group: inherit what that group already holds',
        '                      instead of queueing behind it. Only pass this when the',
        "                      group's owner is blocked waiting for this command.",
        "  --quiet             Suppress lockd's own messages; never the child's output",
    ],
};

function print_help(command) {
    const lines = HELP[command] || HELP._;
    for (const line of lines) {
        console.log(line);
    }
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function main(argv) {
    load_env_file(path.join(PROJECT_ROOT, '.env'));

    const parsed = parse_argv(argv);
    const command = parsed.command;

    if (parsed.flags.version) {
        console.log('rsx-lockd ' + VERSION);
        return EXIT_OK;
    }

    if (!command) {
        print_help('_');
        return EXIT_OK;
    }
    if (command === 'help') {
        print_help(parsed.rest[0] || '_');
        return EXIT_OK;
    }
    if (parsed.flags.help) {
        print_help(command);
        return EXIT_OK;
    }

    switch (command) {
        case 'run':
            await command_run(parsed.flags);
            return null;                       // run does not return; it serves
        case 'start':
            return await command_start(parsed.flags);
        case 'stop':
            return await command_stop(parsed.flags);
        case 'restart':
            return await command_restart(parsed.flags);
        case 'dump':
            return await command_dump(parsed.flags);
        case 'exec':
            return await command_exec(parsed.flags, parsed.rest);
        default:
            console.error('[lockd] [ERROR] Unknown command: ' + command);
            print_help('_');
            return EXIT_FAILURE;
    }
}

// Export seam: the CLI parser and the command implementations are require()-able for
// tests. Importing this file starts nothing - no port is bound and no config is read
// until main() runs, and main() only runs as a program.
module.exports = {
    VERSION,
    EXIT_OK,
    EXIT_FAILURE,
    EXIT_TIMEOUT,
    EXIT_DEADLOCK,
    parse_argv,
    load_env_file,
    main,
};

if (require.main === module) {
    main(process.argv.slice(2)).then((code) => {
        if (code !== null && code !== undefined) {
            process.exit(code);
        }
    }).catch((err) => {
        console.error('[lockd] [ERROR] ' + (err && err.stack ? err.stack : err));
        process.exit(EXIT_FAILURE);
    });
}
