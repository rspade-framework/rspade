# rsx-lockd

A lock server whose **connection is the lock**.

A lock is held from the moment it is granted until the holder releases it or its
connection goes away. There is no lease, no TTL, no renewal and no heartbeat anywhere in
this daemon. A holder that crashes, is `kill -9`'d, or falls off the network releases
*immediately*; a holder that is alive keeps its lock for as long as it needs it - a
minute, an hour, a day. The only clock in the entire program is the optional per-waiter
timeout a caller explicitly asks for.

It is a single Node process, zero npm dependencies (node core only), speaking
newline-delimited JSON over a unix socket and/or TCP.

## Why liveness instead of a clock

Clock-based locks (a redis key with a TTL, an advisory row with an expiry) all share one
defect: pick a duration and you have picked a moment at which mutual exclusion silently
stops being true. A critical section that outruns the TTL has two holders who both
believe they are alone, with no error anywhere. The usual patch - leases plus renewal
plus heartbeats - adds three moving parts to preserve the same wrong idea.

A socket already answers the only question that matters: *is the holder still there?*
The kernel tracks it, delivers it on process death, and TCP keepalive extends it to a
peer that vanished without a FIN. So the lock is the connection, and the whole lease
apparatus disappears.

## Install and run

Requires Node 18+ and nothing else.

```
node lockd.js run                      # foreground (what a process supervisor invokes)
node lockd.js start                    # daemonize, recording the pid in the pidfile
node lockd.js stop                     # SIGTERM the pid in the pidfile
node lockd.js restart
node lockd.js dump [--json]            # what is held, who is waiting
node lockd.js exec --name=X -- <cmd>   # hold a lock for exactly as long as <cmd> runs
node lockd.js --help                   # and `node lockd.js <command> --help`
```

Under a supervisor, invoke the wrapper with an explicit interpreter:

```
[program:rsx-lockd]
command=bash /path/to/rsx-lockd/lockd-run.sh
autostart=true
autorestart=true
startsecs=5
```

`lockd-run.sh` waits for `lockd.js` to exist before `exec`ing node, so a box whose files
have not been delivered yet stays in "starting" rather than burning its supervisor
retries.

## Configuration

JSON, searched in this order - first hit wins:

1. `--config=<path>`
2. `LOCKD_CONFIG=<path>` in the environment
3. `<project>/lockd.conf` (three directories above `lockd.js`)
4. `lockd.conf` shipped beside `lockd.js`

Inside an RSpade app, **edit the project-root file, not the shipped one**: `system/bin/`
is a framework-owned zone that `rsx:framework:pull` hard-syncs, so edits to the shipped
copy are overwritten on the next pull.

The shipped defaults:

```json
{
  "unix":   { "enabled": false, "path": "/var/run/rsx-lockd.sock", "mode": "0660" },
  "tcp":    { "enabled": true,  "port": 6210, "bind": "0.0.0.0" },
  "auth":   { "hmac_key_env": "APP_KEY" },
  "pidfile": "/var/run/rsx-lockd.pid",
  "logfile": null
}
```

| Key | Type | Meaning |
|---|---|---|
| `unix.enabled` | boolean | Listen on a unix domain socket. Preferred by the CLI when enabled. |
| `unix.path` | string | Socket path. A stale file from a crashed instance is probed and removed; a live one is refused. |
| `unix.mode` | string | Octal permissions applied to the socket, e.g. `"0660"`. |
| `tcp.enabled` | boolean | Listen on TCP. Required for a multi-host cluster. |
| `tcp.port` | integer | TCP port. |
| `tcp.bind` | string | Bind address. `0.0.0.0` binds everywhere; the CLI dials `127.0.0.1` in that case. |
| `auth.hmac_key_env` | string | Name of the environment variable holding the shared HMAC key. |
| `pidfile` | string | Written after a successful bind, removed on clean shutdown. |
| `logfile` | string or null | Append log lines here. `null` means stdout, which is what a supervisor captures. |

Both listeners disabled, an unknown key, or a wrong type is a **startup error with a
message naming the key** - never a silent default. Whole-line `//` comments are tolerated
in the file; everything else is plain JSON.

The daemon also reads a `.env` file three directories above `lockd.js` if one exists, so
`APP_KEY` can live there. Real environment variables always win over the file.

## Protocol

Newline-delimited JSON over the socket. Strict request/response: **the server never sends
an unsolicited frame.** Every frame it writes answers exactly one request. Include an
`id` on a request and it is echoed on the response - required, because responses can
arrive out of order (a blocking `acquire` parks while a later `ping` answers at once).

Statuses: `ok`, `granted`, `timeout`, `deadlock`, `error`.

Every op except `hello` and `ping` requires a completed `hello`.

### hello

Authenticates the connection. HMAC-SHA256 over `host:pid:ts` keyed by the shared secret,
hex encoded. `ts` is unix seconds and must be within 60 seconds of the server's clock. A
failed hello closes the connection - there is no retry and no partial credit. A
connection that has not said hello within 10 seconds is dropped.

```json
--> {"op":"hello","host":"web-01","pid":4242,"ts":1786000000,"sig":"9f86d0...","id":"r1"}
<-- {"id":"r1","status":"ok","conn_id":"c7","group_id":"c7","server":"rsx-lockd","protocol":1}
```

An optional `group_id` joins a lock group (see LOCK GROUPS below): 1-128 characters of
`[A-Za-z0-9_.:-]`. Absent, the connection is its own group. It is deliberately NOT covered
by the signature - the client signs its own claim either way, so the HMAC key is the whole
trust boundary.

```json
--> {"op":"hello","host":"web-01","pid":4243,"ts":1786000000,"sig":"9f86d0...",
     "group_id":"g-4242-a91c3f","id":"r1"}
```

### acquire

```json
--> {"op":"acquire","name":"SITE_1","mode":"write","timeout":null,"id":"r2"}
<-- {"id":"r2","status":"granted","name":"SITE_1","mode":"write","token":"lk18"}
```

- `mode` is `read` or `write`; absent means `write`.
- `timeout` is seconds, or `null` (the default) to wait forever.
- **Waiting is silence.** If the lock is not available the request parks and *no frame is
  sent* until it is granted, times out, or is refused. `timeout: null` arms no timer at
  all - there is no polling and no upper bound anywhere.
- Possible answers: `granted`, `timeout`, `deadlock`, `error`.

```json
<-- {"id":"r2","status":"timeout","name":"SITE_1","mode":"write",
     "message":"Failed to acquire WRITE lock for SITE_1 after 30 seconds"}
```

Acquiring a lock this connection already holds is a client bug (reentrancy belongs in the
client) and is answered `error` rather than corrupting the holder set.

### release

By token, or by name with an optional mode.

```json
--> {"op":"release","token":"lk18","id":"r3"}
<-- {"id":"r3","status":"ok","name":"SITE_1","held":true}
```

`held: false` means this connection was not holding it - which is how a client discovers
that the daemon restarted underneath it.

### release_all

Drops every hold and cancels every wait for this connection without closing it. Parked
requests are answered `error` ("Wait cancelled by release_all") rather than abandoned.

```json
--> {"op":"release_all","id":"r4"}
<-- {"id":"r4","status":"ok","released":3,"cancelled":0}
```

### upgrade

Converts a held READ lock to WRITE, by token or name.

```json
--> {"op":"upgrade","token":"lk20","timeout":null,"id":"r5"}
<-- {"id":"r5","status":"granted","name":"CACHE","mode":"write","token":"lk21","upgraded":true}
```

**Not atomic, deliberately.** The read hold is dropped first and the write request joins
the tail of the queue like any other, so another writer may run in between - re-verify
whatever you read. The upside is that two readers upgrading at once cannot deadlock: each
has already released its read, so one is granted and the other waits behind it. Upgrading
a lock already held WRITE is a no-op that returns the same token with `upgraded: false`.

### sem_acquire / sem_release

A counting semaphore: at most `max_slots` holders at once. Slots are small integers, and
a granted slot is held until released or the connection dies, exactly like a lock.
`max_slots <= 0` means unlimited and is granted immediately with `slot: null`.

```json
--> {"op":"sem_acquire","name":"thumbnails","max_slots":4,"timeout":null,"id":"r6"}
<-- {"id":"r6","status":"granted","name":"thumbnails","token":"lk22","slot":2}

--> {"op":"sem_release","token":"lk22","id":"r7"}
<-- {"id":"r7","status":"ok","name":"thumbnails","held":true}
```

### stats

```json
--> {"op":"stats","name":"SITE_1","id":"r8"}
<-- {"id":"r8","status":"ok","name":"SITE_1","readers_active":0,"writer_active":true,
     "writer_conn":"c7","readers_waiting":2,"writers_waiting":1,"queue_length":3,
     "connections":9,"counters":{"granted":18,"released":15,"timed_out":1,
     "deadlocked":0,"dropped_connections":6}}
```

### dump

Full machine state: every connection with what it holds and waits for, every lock with
its holder set and queue, every semaphore with its slots. This is what `lockd.js dump`
renders.

### force_clear

Operator escape hatch. Drops every holder of a lock (and every slot of a semaphore of the
same name) and lets the queue proceed. Waiters are *not* killed - unsticking them is the
point. Evicted holders are told nothing; their next `release` reports `held: false`.

```json
--> {"op":"force_clear","name":"STUCK","id":"r9"}
<-- {"id":"r9","status":"ok","name":"STUCK","holders_cleared":1,"slots_cleared":0}
```

### ping

Open before `hello` - it discloses nothing, so a health check can confirm the daemon is
answering without holding the key.

```json
--> {"op":"ping","id":"r10"}
<-- {"id":"r10","status":"ok","pong":true}
```

## Behaviors

**The connection is the lock.** On socket close or error - clean exit, crash, `kill -9`,
or a partitioned peer detected by TCP keepalive - every lock and semaphore slot that
connection held is released and every queue it sat in forgets it, then whatever that
unblocks is granted. Accepted TCP sockets get `setKeepAlive(true, 30000)` so a peer that
vanished without a FIN is eventually reaped rather than parked forever, and
`setNoDelay(true)` because these are tiny request/response frames.

**Waiting is silence.** A blocking acquire produces no traffic at all while it waits.
`timeout: null` creates no timer; a numeric timeout arms exactly one.

**FIFO.** One ordered queue per lock name, grants come off the head:

- head is a writer - granted only when there are no holders at all, otherwise the pass
  stops (which is what prevents writer starvation);
- head is a reader - granted when no writer holds, and the pass keeps going, so a run of
  consecutive readers grants as one batch.

Nothing ever bypasses a queued waiter, so request order is grant order. The single
exception is group inheritance, below - and it does not reorder anything, because the
group already held the lock.

**Lock groups: a process tree holds a lock together.** A connection may name a `group_id`
at hello. If this connection's group ALREADY holds the lock, the acquire is granted
immediately, past any queue.

This exists for one shape: a process that holds a lock and then spawns a synchronous
subprocess it waits on. The child is a separate process, so a separate connection, so
without groups it queues behind its own parent while the parent sits in `waitpid` - a
permanent hang, and one the deadlock detector structurally cannot see, because the
parent's half of the cycle is an OS wait rather than a lock wait. It is not hypothetical:
it wedged a build for twelve hours before groups existed.

Granting past the queue costs a foreign waiter nothing. It was already excluded by the
parent, and it remains excluded until the parent releases either way.

The asymmetry that keeps this safe:

- Each connection gets its own holder entry, so a member that dies releases only what IT
  acquired. **A child dying never releases the parent's grant.**
- **Read-held is not inherited as write.** That is a cross-process upgrade and would let
  one group hold read and write at once. A parent that will spawn a writer takes the WRITE
  lock before spawning.
- **A different group is a stranger.** Groups narrow nothing else about exclusion.

Only pass a group id into work you are going to WAIT for. Handing one to a background job
nobody waits on gives two live processes the same lock while both believe they are
exclusive - which is the exact failure this daemon exists to eliminate.

**Deadlock detection at enqueue.** Before parking a waiter the server walks the wait-for
graph - requester, the lock it wants, that lock's holders and queued waiters, what each
of them waits for, and onward. If the walk returns to the requester the request is
refused immediately:

```json
<-- {"id":"r11","status":"deadlock","name":"FILE_BLOB_DISPOSAL","mode":"write",
     "message":"Deadlock: acquiring WRITE lock FILE_BLOB_DISPOSAL would close a wait-for cycle",
     "cycle":[
       "c11 [web-01:900] waits for WRITE lock FILE_BLOB_DISPOSAL -> blocked by c12 [web-01:901]",
       "c12 [web-01:901] waits for WRITE lock FILE_WRITE -> blocked by c11 [web-01:900]"]}
```

This exists *because* wait-forever is the default: without it, one lock-ordering mistake
is a permanent hang instead of an error message. Queued waiters count as blockers, since
strict FIFO forbids bypassing them. Semaphores participate in the same graph.

**Reentrancy is the client's job.** The server sees at most one acquire per connection per
lock; a second one is answered `error`. A client that needs nested acquisition keeps its
own counts so the nesting never leaves the process.

**Never dies on bad input.** This is one shared process serving every lock holder on the
box, so a single uncaught throw would drop them all. Malformed JSON, unknown ops, hostile
shapes, and internal errors all produce an `error` frame on one connection; a frame over
1 MiB with no newline closes that connection only.

## CLI

### dump

```
node lockd.js dump [--json] [--host=<host>] [--port=<port>] [--config=<path>]
```

Human-readable by default, grouped per connection then per lock. `--json` emits the raw
response frame.

```
rsx-lockd state
  connections: 3   locks: 2   semaphores: 0
  granted: 19   released: 17   timed_out: 1   deadlocked: 2   dropped_connections: 16

c17  web-01:2346918  via 127.0.0.1:48398  up 1.4s
    HELD     WRITE SITE_1   for 1.4s

c18  web-01:2346926  via 127.0.0.1:48406  up 0.7s
    WAITING  WRITE SITE_1   for 0.7s   (no timeout)

locks
  SITE_1
    holders: c17/write
    queue:   c18/write
```

### exec

```
node lockd.js exec --name=<lock> [--mode=read|write] [--timeout=<seconds>] [--group=<id>]
                   [--quiet] [--host=<host>] [--port=<port>] [--config=<path>] -- <command...>
```

Acquires the lock, runs the command with stdout/stderr wired straight through, releases,
and exits with the child's exit code. Without `--timeout` it waits forever. `--quiet`
suppresses lockd's own messages and never the child's output. If the acquisition takes
longer than 250 ms a one-line "waiting for..." notice goes to stderr, so a fast
acquisition prints nothing.

`--group=<id>` joins a lock group, so this command inherits what that group already holds
instead of queueing behind it (see LOCK GROUPS). Pass it only when the group's owner is
blocked waiting for this command.

The command runs **directly, not through a shell** - argv is passed through verbatim and
nothing re-interprets quoting. Use `-- bash -c '...'` when shell features are wanted.

```
$ node lockd.js exec --name=deploy -- bash -c 'echo working; sleep 3'
[lockd] holding WRITE lock deploy (token lk23)
working
[lockd] released deploy
```

### Client connection targeting

`dump` and `exec` prefer the unix socket when `unix.enabled`, otherwise TCP (dialing
`127.0.0.1` when the configured bind is `0.0.0.0`). `--host`/`--port` override both and
target a remote daemon. The connect is retried 3 times at 1-second intervals, because the
common failure is a daemon that is restarting; a rejected key is *not* retried.

## Exit codes

| Code | Meaning |
|---|---|
| child's code | `exec` - the command ran; this is its exit status (128+N if a signal killed it) |
| 0 | success |
| 1 | connect failure, protocol failure, bad configuration, bad arguments |
| 124 | `exec` - acquire timed out (GNU `timeout` convention) |
| 125 | `exec` - acquire refused as a deadlock |

## Troubleshooting

**"Cannot reach rsx-lockd at 127.0.0.1:6210"** - the daemon is not running or is bound
elsewhere. Check the supervisor program, then `node lockd.js dump` against the same
config the daemon was started with. A config mismatch between daemon and client is the
usual cause.

**"APP_KEY is not set"** (or whatever `auth.hmac_key_env` names) - the daemon refuses to
start without the shared secret, because it could not authenticate anyone. Export it or
put it in the `.env` the daemon reads.

**"hello signature rejected"** - client and daemon disagree on the key. Both sides sign
`host:pid:ts`; verify they read the same environment variable from the same file.

**"hello timestamp outside the 60s replay window"** - clock skew between client and
daemon exceeds a minute. Fix NTP; do not widen the window.

**A lock nobody seems to hold** - `dump` shows the owning connection with its host and
pid. If that process is gone, the lock is already released; if `dump` still shows it, the
connection is genuinely still open (a hung process holding a socket). `force_clear` is
the deliberate override.

**Everything hangs and nothing errors** - that is wait-forever behaving as designed.
`dump` shows the queue; the deadlock detector only refuses *cycles*, and a long queue
behind one slow holder is not a cycle. Pass a `timeout` if a caller genuinely cannot wait.

**"Another process is already listening on /var/run/rsx-lockd.sock"** - a second daemon is
live on that path. The daemon probes the socket before removing it, precisely so it never
steals a running instance's path.

**Locks vanished all at once** - the daemon restarted. Every connection died with it, so
by the model nothing is held. Clients see `held: false` on their next release; that is the
signal to log loudly and re-establish.
