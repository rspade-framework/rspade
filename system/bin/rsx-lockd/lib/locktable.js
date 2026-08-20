/**
 * rsx-lockd lock state machine.
 *
 * THE MODEL: the connection is the lock. A lock is held from the moment it is granted
 * until the holder releases it or its connection goes away. There is no lease, no TTL, no
 * renewal and no heartbeat anywhere in this file - the only clock present is the OPTIONAL
 * per-waiter timeout a caller explicitly asked for.
 *
 * Everything here is pure state manipulation. No sockets, no logging, no process globals.
 * Delivery of a deferred answer (a waiter that later gets granted, times out, or is
 * cancelled) goes through the injected `deliver(conn_id, frame)` callback, and timers go
 * through injected set_timeout/clear_timeout, so a test can drive the whole machine
 * deterministically with a fake clock and a collector array.
 *
 * INVARIANTS a change must not break:
 *   1. FIFO. One queue per lock name. Grants come off the head only; nothing bypasses a
 *      queued waiter, ever. Consecutive readers at the head grant as one batch.
 *   2. A waiter receives NO frame until it is granted, times out, is cancelled, or its
 *      connection dies. Waiting is silence.
 *   3. drop_connection() releases every hold and removes every wait for that connection,
 *      then re-runs grants. This is the entire correctness model for crashes.
 *   4. Deadlock is refused at ENQUEUE time, before parking. Wait-forever is the default,
 *      so an undetected cycle is a permanent hang.
 */

const protocol = require('./protocol');

const MODE_READ = protocol.MODE_READ;
const MODE_WRITE = protocol.MODE_WRITE;

// Hard ceiling on wait-for graph expansion during one deadlock probe. The graph is one
// node per connected client, so this is unreachable in practice; it exists so a
// pathological state can never turn one enqueue into an unbounded walk.
const MAX_GRAPH_EXPANSIONS = 100000;

class Lock_Table {
    constructor(options = {}) {
        this.deliver = options.deliver || function () {};
        this.now = options.now || function () { return Date.now(); };
        this.set_timeout = options.set_timeout || function (fn, ms) { return setTimeout(fn, ms); };
        this.clear_timeout = options.clear_timeout || function (handle) { return clearTimeout(handle); };

        // name -> { name, holders: Map<conn_id, {mode, token, since}>, queue: [entry] }
        this.locks = new Map();

        // name -> { name, max_slots, holders: Map<token, {conn_id, slot, since}>, queue: [entry] }
        this.semaphores = new Map();

        // conn_id -> { conn_id, group_id, meta, since, tokens: Set<token>, waits: [entry] }
        this.connections = new Map();

        // token -> { kind: 'lock'|'sem', conn_id, name, mode?, slot? }
        this.tokens = new Map();

        this.token_seq = 0;
        this.waiter_seq = 0;

        // Lifetime counters, reported by stats/dump. Cheap and invaluable when a box is
        // behaving oddly at 3am.
        this.counters = {
            granted: 0,
            released: 0,
            timed_out: 0,
            deadlocked: 0,
            dropped_connections: 0,
        };
    }

    // ---- Connections ----------------------------------------------------------------

    register_connection(conn_id, meta) {
        this.connections.set(conn_id, {
            conn_id: conn_id,
            // A connection with no declared group is its own group. That makes "the
            // connection is the lock" the DEFAULT and group inheritance the opt-in, so
            // every existing client keeps byte-identical behavior.
            group_id: (meta && meta.group_id) || conn_id,
            meta: meta || {},
            since: this.now(),
            tokens: new Set(),
            waits: [],
        });
    }

    set_connection_meta(conn_id, meta) {
        const conn = this.connections.get(conn_id);
        if (!conn) return;
        conn.meta = Object.assign({}, conn.meta, meta || {});
        if (meta && meta.group_id) {
            conn.group_id = meta.group_id;
        }
    }

    /** The group a connection belongs to; an unknown connection is its own group. */
    _group_of(conn_id) {
        const conn = this.connections.get(conn_id);
        return conn ? conn.group_id : conn_id;
    }

    /**
     * Strongest mode this group already holds on this lock, or null.
     *
     * A GROUP is one process tree: a parent that spawned a synchronous subprocess and is
     * blocked waiting for it. Without this, the child queues behind its own parent and the
     * two wait on each other forever - a cycle the deadlock detector structurally cannot
     * see, because the parent's half of it is an OS waitpid, not a lock wait.
     */
    _group_hold_mode(lock, group_id) {
        let mode = null;
        for (const [holder_conn_id, holder] of lock.holders) {
            if (this._group_of(holder_conn_id) !== group_id) continue;
            if (holder.mode === MODE_WRITE) return MODE_WRITE;
            mode = MODE_READ;
        }
        return mode;
    }

    /**
     * The connection is gone (clean close, crash, kill -9, or a dead TCP peer detected by
     * keepalive). Release EVERYTHING it held, remove it from every queue, then grant what
     * that unblocks. This is the whole reason this daemon exists, so it is deliberately
     * exhaustive and order-independent: holds first, then waits, then one grant pass over
     * every touched lock.
     */
    drop_connection(conn_id) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return { released: 0, cancelled: 0 };
        }

        const touched_locks = new Set();
        const touched_semaphores = new Set();
        let released = 0;

        // Holds.
        for (const token of Array.from(conn.tokens)) {
            const record = this.tokens.get(token);
            if (!record) continue;
            if (record.kind === 'lock') {
                const lock = this.locks.get(record.name);
                if (lock && lock.holders.delete(conn_id)) {
                    released++;
                    touched_locks.add(lock);
                }
            } else {
                const semaphore = this.semaphores.get(record.name);
                if (semaphore && semaphore.holders.delete(token)) {
                    released++;
                    touched_semaphores.add(semaphore);
                }
            }
            this.tokens.delete(token);
        }
        conn.tokens.clear();

        // Waits. The waiter gets no frame - its socket is gone.
        let cancelled = 0;
        for (const entry of Array.from(conn.waits)) {
            this._remove_waiter(entry, false);
            cancelled++;
            if (entry.kind === 'lock') {
                const lock = this.locks.get(entry.name);
                if (lock) touched_locks.add(lock);
            } else {
                const semaphore = this.semaphores.get(entry.name);
                if (semaphore) touched_semaphores.add(semaphore);
            }
        }

        this.connections.delete(conn_id);
        this.counters.dropped_connections++;
        this.counters.released += released;

        for (const lock of touched_locks) this._run_grants(lock);
        for (const semaphore of touched_semaphores) this._run_semaphore_grants(semaphore);

        this._gc_locks(touched_locks);
        this._gc_semaphores(touched_semaphores);

        return { released: released, cancelled: cancelled };
    }

    // ---- Locks ----------------------------------------------------------------------

    /**
     * Request a lock. Returns a frame to send back IMMEDIATELY, or null when the request
     * parked - in which case the answer arrives later through deliver().
     *
     * req: { id?, name, mode, timeout }
     */
    acquire(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        const name = this._normalize_name(req.name);
        if (!name) {
            return this._error(req, 'acquire requires a non-empty string name');
        }

        const mode = protocol.normalize_mode(req.mode);
        if (!mode) {
            return this._error(req, "acquire mode must be 'read' or 'write'");
        }

        const timeout = protocol.normalize_timeout(req.timeout);
        if (!timeout.ok) {
            return this._error(req, 'acquire timeout must be null or a non-negative number of seconds');
        }

        const lock = this._ensure_lock(name);

        // Reentrancy is the CLIENT's job (the PHP client keeps the counts, so a nested
        // acquire never leaves the process). The server therefore sees at most one acquire
        // per connection per lock; a second one is a client bug. Refuse it loudly rather
        // than corrupting the holder set - and name the upgrade path, since read-then-write
        // is the mistake this catches most often.
        if (lock.holders.has(conn_id)) {
            const held_mode = lock.holders.get(conn_id).mode;
            const hint = (held_mode === MODE_READ && mode === MODE_WRITE)
                ? " - use op 'upgrade' to convert a held READ lock to WRITE"
                : '';
            return this._error(
                req,
                'Connection already holds ' + held_mode.toUpperCase() + ' lock ' + name + hint
            );
        }
        if (this._find_wait(conn, 'lock', name)) {
            return this._error(req, 'Connection is already waiting for lock ' + name);
        }

        // GROUP INHERITANCE - and note it deliberately bypasses the FIFO queue below.
        //
        // This connection's group already holds the lock, which means the holder is this
        // connection's parent, blocked in waitpid on us. Making us queue would be the exact
        // deadlock this exists to prevent, and queueing behind a FOREIGN waiter would
        // deadlock just as hard - that waiter cannot be granted until the parent releases,
        // and the parent cannot release until we exit.
        //
        // Bypassing costs the foreign waiter nothing: the group already excluded it, and
        // adding a second same-group holder does not extend that exclusion by one lock
        // acquisition - it ends when the parent releases either way.
        //
        // Read-held + write-wanted is NOT inherited: that is a cross-process upgrade, and
        // inventing one here would let a group hold read and write at once. Such a parent
        // should take the WRITE lock before it spawns. See README (LOCK GROUPS).
        const group_mode = this._group_hold_mode(lock, conn.group_id);
        if (group_mode === MODE_WRITE || (group_mode === MODE_READ && mode === MODE_READ)) {
            return this._grant_lock(lock, conn, mode, req);
        }

        // Strict FIFO: an empty queue is a precondition for an immediate grant. A grantable
        // request that jumped a queued waiter would starve writers.
        if (lock.queue.length === 0 && this._can_grant(lock, mode)) {
            return this._grant_lock(lock, conn, mode, req);
        }

        const cycle = this._detect_deadlock(conn_id, { kind: 'lock', name: name, mode: mode });
        if (cycle) {
            this.counters.deadlocked++;
            return {
                id: req.id,
                status: protocol.STATUS_DEADLOCK,
                name: name,
                mode: mode,
                message: 'Deadlock: acquiring ' + mode.toUpperCase() + ' lock ' + name
                    + ' would close a wait-for cycle',
                cycle: cycle,
            };
        }

        this._enqueue(lock, conn, {
            kind: 'lock',
            name: name,
            mode: mode,
            timeout: timeout.value,
            req: req,
        });

        return null;
    }

    /**
     * Release a held lock, by token or by name (+ optional mode). Always answers
     * immediately. `held` is false when this connection did not hold it - which is how a
     * client learns the daemon restarted under it.
     */
    release(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        let name = null;
        if (req.token !== undefined && req.token !== null) {
            const record = this.tokens.get(req.token);
            if (!record || record.conn_id !== conn_id || record.kind !== 'lock') {
                return { id: req.id, status: protocol.STATUS_OK, held: false };
            }
            name = record.name;
        } else {
            name = this._normalize_name(req.name);
            if (!name) {
                return this._error(req, 'release requires a token or a non-empty string name');
            }
        }

        const lock = this.locks.get(name);
        if (!lock || !lock.holders.has(conn_id)) {
            return { id: req.id, status: protocol.STATUS_OK, name: name, held: false };
        }

        const holder = lock.holders.get(conn_id);
        if (req.mode !== undefined && req.mode !== null) {
            const mode = protocol.normalize_mode(req.mode);
            if (mode && mode !== holder.mode) {
                return { id: req.id, status: protocol.STATUS_OK, name: name, held: false };
            }
        }

        this._release_hold(lock, conn, holder);
        this._run_grants(lock);
        this._gc_locks([lock]);

        return { id: req.id, status: protocol.STATUS_OK, name: name, held: true };
    }

    /**
     * Drop every hold and every wait belonging to this connection, without closing it.
     * The PHP client sends this at end of script; correctness does not depend on the frame
     * arriving, because the socket close does the same thing.
     */
    release_all(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        const touched_locks = new Set();
        const touched_semaphores = new Set();
        let released = 0;

        for (const token of Array.from(conn.tokens)) {
            const record = this.tokens.get(token);
            if (!record) continue;
            if (record.kind === 'lock') {
                const lock = this.locks.get(record.name);
                if (lock && lock.holders.delete(conn_id)) {
                    released++;
                    touched_locks.add(lock);
                }
            } else {
                const semaphore = this.semaphores.get(record.name);
                if (semaphore && semaphore.holders.delete(token)) {
                    released++;
                    touched_semaphores.add(semaphore);
                }
            }
            this.tokens.delete(token);
        }
        conn.tokens.clear();
        this.counters.released += released;

        // A connection that is waiting cannot also be sending release_all in the PHP
        // client (it is synchronous), but the protocol permits it. Answer the parked
        // request rather than abandoning it - silence is reserved for "still waiting".
        let cancelled = 0;
        for (const entry of Array.from(conn.waits)) {
            const parked = entry.req;
            this._remove_waiter(entry, false);
            cancelled++;
            if (entry.kind === 'lock') {
                const lock = this.locks.get(entry.name);
                if (lock) touched_locks.add(lock);
            } else {
                const semaphore = this.semaphores.get(entry.name);
                if (semaphore) touched_semaphores.add(semaphore);
            }
            this.deliver(conn_id, {
                id: parked.id,
                status: protocol.STATUS_ERROR,
                name: entry.name,
                message: 'Wait cancelled by release_all',
            });
        }

        for (const lock of touched_locks) this._run_grants(lock);
        for (const semaphore of touched_semaphores) this._run_semaphore_grants(semaphore);
        this._gc_locks(touched_locks);
        this._gc_semaphores(touched_semaphores);

        return { id: req.id, status: protocol.STATUS_OK, released: released, cancelled: cancelled };
    }

    /**
     * Convert a held READ lock to WRITE. NOT atomic, by design and matching the semantics
     * this replaces: the read hold is dropped FIRST and a write request joins the TAIL of
     * the queue like any other. A caller must therefore re-verify whatever it read before
     * the upgrade - another writer may have run in between.
     *
     * Dropping the read first is also what makes the classic upgrade deadlock impossible:
     * when two readers upgrade at once, each has already given up its read, so one is
     * granted and the other simply waits behind it. An atomic upgrade would hang both.
     * The deadlock check still runs, because an upgrade can close a cycle through OTHER
     * locks the connection holds.
     */
    upgrade(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        let name = null;
        if (req.token !== undefined && req.token !== null) {
            const record = this.tokens.get(req.token);
            if (!record || record.conn_id !== conn_id || record.kind !== 'lock') {
                return this._error(req, 'upgrade token is not a lock held by this connection');
            }
            name = record.name;
        } else {
            name = this._normalize_name(req.name);
            if (!name) {
                return this._error(req, 'upgrade requires a token or a non-empty string name');
            }
        }

        const timeout = protocol.normalize_timeout(req.timeout);
        if (!timeout.ok) {
            return this._error(req, 'upgrade timeout must be null or a non-negative number of seconds');
        }

        const lock = this.locks.get(name);
        const holder = lock ? lock.holders.get(conn_id) : null;
        if (!holder) {
            return this._error(req, 'Connection does not hold lock ' + name);
        }

        // Already exclusive: a no-op that hands back the same token, so a caller can call
        // upgrade unconditionally.
        if (holder.mode === MODE_WRITE) {
            return {
                id: req.id,
                status: protocol.STATUS_GRANTED,
                name: name,
                mode: MODE_WRITE,
                token: holder.token,
                upgraded: false,
            };
        }

        this._release_hold(lock, conn, holder);

        if (lock.queue.length === 0 && this._can_grant(lock, MODE_WRITE)) {
            const frame = this._grant_lock(lock, conn, MODE_WRITE, req);
            frame.upgraded = true;
            return frame;
        }

        const cycle = this._detect_deadlock(conn_id, { kind: 'lock', name: name, mode: MODE_WRITE });
        if (cycle) {
            // The read hold is already gone. Put the connection back where it was so a
            // refused upgrade does not silently downgrade it to holding nothing.
            this._restore_hold(lock, conn, holder);
            this.counters.deadlocked++;
            return {
                id: req.id,
                status: protocol.STATUS_DEADLOCK,
                name: name,
                mode: MODE_WRITE,
                message: 'Deadlock: upgrading lock ' + name + ' would close a wait-for cycle',
                cycle: cycle,
            };
        }

        this._enqueue(lock, conn, {
            kind: 'lock',
            name: name,
            mode: MODE_WRITE,
            timeout: timeout.value,
            req: req,
            upgraded: true,
        });

        // Dropping the read hold may have unblocked a queued writer ahead of us.
        this._run_grants(lock);

        return null;
    }

    // ---- Semaphores -----------------------------------------------------------------

    /**
     * Take one of `max_slots` slots on a counting semaphore. max_slots <= 0 means
     * unlimited: granted immediately with slot null, so a caller never has to special-case
     * "no quota configured".
     */
    sem_acquire(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        const name = this._normalize_name(req.name);
        if (!name) {
            return this._error(req, 'sem_acquire requires a non-empty string name');
        }

        const max_slots = req.max_slots;
        if (!Number.isInteger(max_slots)) {
            return this._error(req, 'sem_acquire requires an integer max_slots');
        }

        const timeout = protocol.normalize_timeout(req.timeout);
        if (!timeout.ok) {
            return this._error(req, 'sem_acquire timeout must be null or a non-negative number of seconds');
        }

        if (max_slots <= 0) {
            return {
                id: req.id,
                status: protocol.STATUS_GRANTED,
                name: name,
                token: this._issue_token(),
                slot: null,
                unlimited: true,
            };
        }

        const semaphore = this._ensure_semaphore(name, max_slots);
        // Last writer wins on the quota. The quota is a caller-supplied configuration
        // value, not daemon state; refusing a changed value would mean a config edit could
        // never take effect without a restart.
        semaphore.max_slots = max_slots;

        if (semaphore.queue.length === 0 && semaphore.holders.size < semaphore.max_slots) {
            return this._grant_semaphore(semaphore, conn, req);
        }

        const cycle = this._detect_deadlock(conn_id, { kind: 'sem', name: name });
        if (cycle) {
            this.counters.deadlocked++;
            return {
                id: req.id,
                status: protocol.STATUS_DEADLOCK,
                name: name,
                message: 'Deadlock: acquiring semaphore ' + name + ' would close a wait-for cycle',
                cycle: cycle,
            };
        }

        this._enqueue(semaphore, conn, {
            kind: 'sem',
            name: name,
            mode: null,
            timeout: timeout.value,
            req: req,
        });

        return null;
    }

    sem_release(conn_id, req) {
        const conn = this.connections.get(conn_id);
        if (!conn) {
            return this._error(req, 'Unknown connection');
        }

        if (req.token === undefined || req.token === null) {
            return this._error(req, 'sem_release requires a token');
        }

        const record = this.tokens.get(req.token);
        if (!record || record.conn_id !== conn_id || record.kind !== 'sem') {
            // Includes the unlimited sentinel, which never entered the tokens map.
            return { id: req.id, status: protocol.STATUS_OK, held: false };
        }

        const semaphore = this.semaphores.get(record.name);
        let held = false;
        if (semaphore && semaphore.holders.delete(req.token)) {
            held = true;
            this.counters.released++;
        }
        this.tokens.delete(req.token);
        conn.tokens.delete(req.token);

        if (semaphore) {
            this._run_semaphore_grants(semaphore);
            this._gc_semaphores([semaphore]);
        }

        return { id: req.id, status: protocol.STATUS_OK, name: record.name, held: held };
    }

    // ---- Introspection --------------------------------------------------------------

    stats(req) {
        const name = this._normalize_name(req.name);
        if (!name) {
            return this._error(req, 'stats requires a non-empty string name');
        }

        const lock = this.locks.get(name);
        const semaphore = this.semaphores.get(name);

        let readers_active = 0;
        let writer_active = false;
        let writer_conn = null;
        let readers_waiting = 0;
        let writers_waiting = 0;
        let queue_length = 0;

        if (lock) {
            for (const [conn_id, holder] of lock.holders) {
                if (holder.mode === MODE_WRITE) {
                    writer_active = true;
                    writer_conn = conn_id;
                } else {
                    readers_active++;
                }
            }
            for (const entry of lock.queue) {
                if (entry.mode === MODE_WRITE) writers_waiting++;
                else readers_waiting++;
            }
            queue_length = lock.queue.length;
        }

        const frame = {
            id: req.id,
            status: protocol.STATUS_OK,
            name: name,
            readers_active: readers_active,
            writer_active: writer_active,
            writer_conn: writer_conn,
            readers_waiting: readers_waiting,
            writers_waiting: writers_waiting,
            queue_length: queue_length,
            counters: Object.assign({}, this.counters),
            connections: this.connections.size,
        };

        if (semaphore) {
            frame.semaphore = {
                max_slots: semaphore.max_slots,
                slots_used: semaphore.holders.size,
                waiting: semaphore.queue.length,
            };
        }

        return frame;
    }

    /** Full machine state: every connection with what it holds and waits for, per lock. */
    dump(req) {
        const now = this.now();

        const connections = [];
        for (const [conn_id, conn] of this.connections) {
            const holds = [];
            const semaphores = [];
            for (const token of conn.tokens) {
                const record = this.tokens.get(token);
                if (!record) continue;
                if (record.kind === 'lock') {
                    const lock = this.locks.get(record.name);
                    const holder = lock ? lock.holders.get(conn_id) : null;
                    if (!holder) continue;
                    holds.push({
                        name: record.name,
                        mode: holder.mode,
                        token: token,
                        held_ms: now - holder.since,
                    });
                } else {
                    const semaphore = this.semaphores.get(record.name);
                    const holder = semaphore ? semaphore.holders.get(token) : null;
                    if (!holder) continue;
                    semaphores.push({
                        name: record.name,
                        slot: holder.slot,
                        token: token,
                        held_ms: now - holder.since,
                    });
                }
            }

            const waiting = conn.waits.map((entry) => ({
                kind: entry.kind,
                name: entry.name,
                mode: entry.mode,
                timeout: entry.timeout,
                waiting_ms: now - entry.since,
            }));

            connections.push({
                conn_id: conn_id,
                // Null when this connection is its own group - the ordinary case. A
                // non-null value is the thing worth seeing in a dump: two connections
                // sharing one is a parent and the subprocess it is waiting on.
                group_id: conn.group_id === conn_id ? null : conn.group_id,
                host: conn.meta.host || null,
                pid: conn.meta.pid || null,
                remote: conn.meta.remote || null,
                connected_ms: now - conn.since,
                holds: holds,
                semaphores: semaphores,
                waiting: waiting,
            });
        }

        const locks = [];
        for (const [name, lock] of this.locks) {
            locks.push({
                name: name,
                holders: Array.from(lock.holders.entries()).map(([conn_id, holder]) => ({
                    conn_id: conn_id,
                    mode: holder.mode,
                    held_ms: now - holder.since,
                })),
                queue: lock.queue.map((entry) => ({
                    conn_id: entry.conn_id,
                    mode: entry.mode,
                    timeout: entry.timeout,
                    waiting_ms: now - entry.since,
                })),
            });
        }

        const semaphores = [];
        for (const [name, semaphore] of this.semaphores) {
            semaphores.push({
                name: name,
                max_slots: semaphore.max_slots,
                holders: Array.from(semaphore.holders.entries()).map(([token, holder]) => ({
                    conn_id: holder.conn_id,
                    slot: holder.slot,
                    token: token,
                    held_ms: now - holder.since,
                })),
                queue: semaphore.queue.map((entry) => ({
                    conn_id: entry.conn_id,
                    waiting_ms: now - entry.since,
                })),
            });
        }

        return {
            id: req.id,
            status: protocol.STATUS_OK,
            connections: connections,
            locks: locks,
            semaphores: semaphores,
            counters: Object.assign({}, this.counters),
        };
    }

    /**
     * Operator escape hatch: drop every holder of a lock and let the queue proceed.
     * Waiters are NOT killed - the point is to unstick them. The evicted holders are told
     * nothing (they hold a token that will simply report held:false on release), which is
     * the honest outcome: force_clear is a declaration that those holders are wrong.
     */
    force_clear(req) {
        const name = this._normalize_name(req.name);
        if (!name) {
            return this._error(req, 'force_clear requires a non-empty string name');
        }

        let holders_cleared = 0;
        let slots_cleared = 0;

        const lock = this.locks.get(name);
        if (lock) {
            for (const [conn_id, holder] of Array.from(lock.holders)) {
                const conn = this.connections.get(conn_id);
                if (conn) conn.tokens.delete(holder.token);
                this.tokens.delete(holder.token);
                lock.holders.delete(conn_id);
                holders_cleared++;
            }
            this._run_grants(lock);
            this._gc_locks([lock]);
        }

        const semaphore = this.semaphores.get(name);
        if (semaphore) {
            for (const [token, holder] of Array.from(semaphore.holders)) {
                const conn = this.connections.get(holder.conn_id);
                if (conn) conn.tokens.delete(token);
                this.tokens.delete(token);
                semaphore.holders.delete(token);
                slots_cleared++;
            }
            this._run_semaphore_grants(semaphore);
            this._gc_semaphores([semaphore]);
        }

        return {
            id: req.id,
            status: protocol.STATUS_OK,
            name: name,
            holders_cleared: holders_cleared,
            slots_cleared: slots_cleared,
        };
    }

    // ---- Internals ------------------------------------------------------------------

    _error(req, message) {
        return { id: req ? req.id : undefined, status: protocol.STATUS_ERROR, message: message };
    }

    _normalize_name(name) {
        if (typeof name !== 'string') return null;
        const trimmed = name.trim();
        return trimmed.length > 0 ? trimmed : null;
    }

    _issue_token() {
        this.token_seq++;
        return 'lk' + this.token_seq;
    }

    _ensure_lock(name) {
        let lock = this.locks.get(name);
        if (!lock) {
            lock = { name: name, holders: new Map(), queue: [] };
            this.locks.set(name, lock);
        }
        return lock;
    }

    _ensure_semaphore(name, max_slots) {
        let semaphore = this.semaphores.get(name);
        if (!semaphore) {
            semaphore = { name: name, max_slots: max_slots, holders: new Map(), queue: [] };
            this.semaphores.set(name, semaphore);
        }
        return semaphore;
    }

    /** Drop empty lock records so a box that has touched a million names does not leak. */
    _gc_locks(locks) {
        for (const lock of locks) {
            if (lock && lock.holders.size === 0 && lock.queue.length === 0) {
                this.locks.delete(lock.name);
            }
        }
    }

    _gc_semaphores(semaphores) {
        for (const semaphore of semaphores) {
            if (semaphore && semaphore.holders.size === 0 && semaphore.queue.length === 0) {
                this.semaphores.delete(semaphore.name);
            }
        }
    }

    _can_grant(lock, mode) {
        if (mode === MODE_WRITE) {
            return lock.holders.size === 0;
        }
        for (const holder of lock.holders.values()) {
            if (holder.mode === MODE_WRITE) return false;
        }
        return true;
    }

    _grant_lock(lock, conn, mode, req) {
        const token = this._issue_token();
        lock.holders.set(conn.conn_id, { mode: mode, token: token, since: this.now() });
        this.tokens.set(token, { kind: 'lock', conn_id: conn.conn_id, name: lock.name, mode: mode });
        conn.tokens.add(token);
        this.counters.granted++;

        return {
            id: req.id,
            status: protocol.STATUS_GRANTED,
            name: lock.name,
            mode: mode,
            token: token,
        };
    }

    _release_hold(lock, conn, holder) {
        lock.holders.delete(conn.conn_id);
        this.tokens.delete(holder.token);
        conn.tokens.delete(holder.token);
        this.counters.released++;
    }

    /** Put back a hold removed speculatively (the refused-upgrade path). */
    _restore_hold(lock, conn, holder) {
        lock.holders.set(conn.conn_id, holder);
        this.tokens.set(holder.token, {
            kind: 'lock',
            conn_id: conn.conn_id,
            name: lock.name,
            mode: holder.mode,
        });
        conn.tokens.add(holder.token);
        this.counters.released--;
    }

    _grant_semaphore(semaphore, conn, req) {
        const used = new Set();
        for (const holder of semaphore.holders.values()) used.add(holder.slot);
        let slot = 0;
        while (used.has(slot)) slot++;

        const token = this._issue_token();
        semaphore.holders.set(token, { conn_id: conn.conn_id, slot: slot, since: this.now() });
        this.tokens.set(token, { kind: 'sem', conn_id: conn.conn_id, name: semaphore.name, slot: slot });
        conn.tokens.add(token);
        this.counters.granted++;

        return {
            id: req.id,
            status: protocol.STATUS_GRANTED,
            name: semaphore.name,
            token: token,
            slot: slot,
        };
    }

    /**
     * Park a request. The ONLY timer in this daemon is armed here, and only when the
     * caller explicitly passed a timeout - timeout null arms nothing at all, which is what
     * "wait forever, with no upper bound anywhere" actually means in code.
     */
    _enqueue(target, conn, spec) {
        this.waiter_seq++;
        const entry = {
            waiter_id: this.waiter_seq,
            conn_id: conn.conn_id,
            kind: spec.kind,
            name: spec.name,
            mode: spec.mode,
            timeout: spec.timeout,
            req: spec.req,
            upgraded: spec.upgraded === true,
            since: this.now(),
            timer: null,
        };

        target.queue.push(entry);
        conn.waits.push(entry);

        if (entry.timeout !== null) {
            entry.timer = this.set_timeout(() => {
                this._fire_timeout(entry);
            }, Math.round(entry.timeout * 1000));
        }

        return entry;
    }

    _fire_timeout(entry) {
        const target = entry.kind === 'lock'
            ? this.locks.get(entry.name)
            : this.semaphores.get(entry.name);

        this._remove_waiter(entry, true);
        this.counters.timed_out++;

        const message = entry.kind === 'lock'
            ? protocol.timeout_message(entry.name, entry.mode, entry.timeout)
            : 'Failed to acquire semaphore slot for ' + entry.name + ' after ' + entry.timeout + ' seconds';

        this.deliver(entry.conn_id, {
            id: entry.req.id,
            status: protocol.STATUS_TIMEOUT,
            name: entry.name,
            mode: entry.mode,
            message: message,
        });

        // Removing a blocked waiter can unblock the ones behind it (a timed-out head
        // writer no longer holds back a run of readers).
        if (target) {
            if (entry.kind === 'lock') {
                this._run_grants(target);
                this._gc_locks([target]);
            } else {
                this._run_semaphore_grants(target);
                this._gc_semaphores([target]);
            }
        }
    }

    _remove_waiter(entry, already_fired) {
        if (entry.timer !== null && !already_fired) {
            this.clear_timeout(entry.timer);
        }
        entry.timer = null;

        const target = entry.kind === 'lock'
            ? this.locks.get(entry.name)
            : this.semaphores.get(entry.name);
        if (target) {
            const index = target.queue.indexOf(entry);
            if (index !== -1) target.queue.splice(index, 1);
        }

        const conn = this.connections.get(entry.conn_id);
        if (conn) {
            const index = conn.waits.indexOf(entry);
            if (index !== -1) conn.waits.splice(index, 1);
        }
    }

    _find_wait(conn, kind, name) {
        for (const entry of conn.waits) {
            if (entry.kind === kind && entry.name === name) return entry;
        }
        return null;
    }

    /**
     * FIFO grant pass. Walks the queue from the head only:
     *   - head is a writer: granted iff there are no holders at all; otherwise stop.
     *   - head is a reader: granted iff no writer holds; then keep going, so a run of
     *     consecutive readers grants as one batch.
     * Stopping at the first ungrantable head is what prevents writer starvation.
     */
    _run_grants(lock) {
        while (lock.queue.length > 0) {
            const entry = lock.queue[0];
            if (!this._can_grant(lock, entry.mode)) break;

            const conn = this.connections.get(entry.conn_id);
            this._remove_waiter(entry, false);
            if (!conn) continue;   // connection vanished between enqueue and grant

            const frame = this._grant_lock(lock, conn, entry.mode, entry.req);
            if (entry.upgraded) frame.upgraded = true;
            this.deliver(entry.conn_id, frame);
        }
    }

    _run_semaphore_grants(semaphore) {
        while (semaphore.queue.length > 0 && semaphore.holders.size < semaphore.max_slots) {
            const entry = semaphore.queue[0];
            const conn = this.connections.get(entry.conn_id);
            this._remove_waiter(entry, false);
            if (!conn) continue;

            this.deliver(entry.conn_id, this._grant_semaphore(semaphore, conn, entry.req));
        }
    }

    // ---- Deadlock detection ---------------------------------------------------------

    /**
     * Who stands between `want` and the requester, right now.
     *
     * For a lock: every conflicting HOLDER, plus every connection already QUEUED on that
     * lock. The queued ones count because FIFO forbids bypassing them - a request behind
     * them cannot be granted until they are, so they are genuinely blocking.
     *
     * For a semaphore: every slot holder plus everyone queued, same reasoning.
     */
    _blockers(want, requester) {
        const out = new Set();

        if (want.kind === 'lock') {
            const lock = this.locks.get(want.name);
            if (!lock) return out;
            for (const [conn_id, holder] of lock.holders) {
                if (conn_id === requester) continue;
                if (want.mode === MODE_WRITE || holder.mode === MODE_WRITE) {
                    out.add(conn_id);
                }
            }
            for (const entry of lock.queue) {
                if (entry.conn_id !== requester) out.add(entry.conn_id);
            }
            return out;
        }

        const semaphore = this.semaphores.get(want.name);
        if (!semaphore) return out;
        for (const holder of semaphore.holders.values()) {
            if (holder.conn_id !== requester) out.add(holder.conn_id);
        }
        for (const entry of semaphore.queue) {
            if (entry.conn_id !== requester) out.add(entry.conn_id);
        }
        return out;
    }

    /**
     * Would parking this request close a wait-for cycle? Breadth-first reachability from
     * the requester back to itself over "waits for -> blocked by" edges. A global visited
     * set is complete here because reachability from a node does not depend on the path
     * taken to reach it.
     *
     * Returns an array of human-readable hop strings describing the cycle, or null.
     * This runs on EVERY enqueue precisely because wait-forever is the default: without
     * it, one lock-ordering mistake is an unrecoverable hang instead of an error message.
     */
    _detect_deadlock(requester, want) {
        const parent = new Map();   // conn_id -> { from, want }
        const frontier = [];
        let expansions = 0;
        let closing = null;

        const expand = (from, wants) => {
            for (const w of wants) {
                for (const blocker of this._blockers(w, from)) {
                    expansions++;
                    if (expansions > MAX_GRAPH_EXPANSIONS) return { aborted: true };
                    if (blocker === requester) {
                        return { closing: { from: from, want: w } };
                    }
                    if (!parent.has(blocker)) {
                        parent.set(blocker, { from: from, want: w });
                        frontier.push(blocker);
                    }
                }
            }
            return null;
        };

        let result = expand(requester, [want]);
        while (!result && frontier.length > 0) {
            const conn_id = frontier.shift();
            const conn = this.connections.get(conn_id);
            result = expand(conn_id, conn ? conn.waits.map((e) => ({
                kind: e.kind,
                name: e.name,
                mode: e.mode,
            })) : []);
        }

        if (!result || result.aborted || !result.closing) return null;
        closing = result.closing;

        // Walk parent pointers back to the requester, then render the cycle in request
        // order so a human reads it as a story: A waits on X held by B, B waits on Y held
        // by A.
        const chain = [{ from: closing.from, want: closing.want, to: requester }];
        let node = closing.from;
        while (node !== requester) {
            const link = parent.get(node);
            if (!link) break;
            chain.unshift({ from: link.from, want: link.want, to: node });
            node = link.from;
        }

        return chain.map((hop) => this._describe_hop(hop));
    }

    _describe_hop(hop) {
        const conn = this.connections.get(hop.from);
        const blocker = this.connections.get(hop.to);
        const who = this._describe_conn(hop.from, conn);
        const blocked_by = this._describe_conn(hop.to, blocker);
        const what = hop.want.kind === 'lock'
            ? (hop.want.mode || MODE_WRITE).toUpperCase() + ' lock ' + hop.want.name
            : 'semaphore ' + hop.want.name;
        return who + ' waits for ' + what + ' -> blocked by ' + blocked_by;
    }

    _describe_conn(conn_id, conn) {
        if (!conn || !conn.meta || !conn.meta.host) return conn_id;
        return conn_id + ' [' + conn.meta.host + ':' + (conn.meta.pid || '?') + ']';
    }
}

module.exports = { Lock_Table, MODE_READ, MODE_WRITE, MAX_GRAPH_EXPANSIONS };
