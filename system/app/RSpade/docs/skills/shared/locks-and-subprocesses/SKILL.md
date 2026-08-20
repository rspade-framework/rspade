---
name: locks-and-subprocesses
description: RsxLocks and Rsx_Artisan - cluster vs system locks, named and per-site read/write locks, counting semaphores, wait-forever semantics, FIFO grants and deadlock refusal, lock groups, releasing locks at a task boundary, and the mandate that every artisan subprocess is spawned through Rsx_Artisan. Use when serializing a critical section, guarding a shared resource or tenant, capping concurrency, spawning an artisan command from PHP, asking why locks carry no lease or TTL, or debugging a hang or a "lock daemon restarted" warning.
---

# Locks and Subprocesses

Two subjects, one topic: a lock belongs to a **connection**, and a subprocess opens its own - which is why the artisan-spawn mandate lives here.

```php
use App\RSpade\Core\Locks\RsxLocks;

$token = RsxLocks::named_write_lock('rebuild_report_cache');
try { /* however long this takes */ }
finally { RsxLocks::release_lock($token); }
```

---

## The API

```php
RsxLocks::named_write_lock(string $name, ?int $timeout = null): string   // exclusive
RsxLocks::named_read_lock(string $name, ?int $timeout = null): string    // shared
RsxLocks::site_write_lock(int $site_id, ?int $timeout = null): string    // per-tenant, exclusive
RsxLocks::site_read_lock(int $site_id, ?int $timeout = null): string     // per-tenant, shared
RsxLocks::system_lock(string $name, ?int $timeout = null): string        // THIS BOX ONLY, exclusive only
RsxLocks::upgrade_lock(string $read_token, ?int $timeout = null): string // read -> write, NOT atomic
RsxLocks::release_lock(string $token): bool

RsxLocks::acquire_semaphore(string $name, int $max_slots, ?int $timeout = null): ?string
RsxLocks::release_semaphore(?string $token): void
RsxLocks::get_semaphore_usage(string $name, int $max_slots): int
```

### Which kind - and it is not a tuning knob

`named_*` and `site_*` are **web-cluster** locks served by the `rsx-lockd` daemon; they span every web server the way the cluster shares one database. **Reach for them by default.** `system_lock()` is **this box only** (flock, exclusive only, no readers) and is right only when the contended resource is genuinely local - a local file, a local helper process, a build artifact.

**Test: two servers doing it at once = corruption -> cluster; = merely duplicated work -> system.**

If lockd is unreachable outside maintenance mode, **every cluster lock throws - there is no degraded mode.** (`rsx:health` reports it as "Lock Server".) Under maintenance mode a cluster lock is granted as a no-op instead; see `rspade:maintenance-mode`.

### Semaphores - a max-concurrency quota

```php
$slot = RsxLocks::acquire_semaphore('libreoffice_thumbnail', 2, 30);
if ($slot === null) { throw new \Exception('no concurrency slot'); }   // decide how to degrade
try { /* bounded expensive work */ }
finally { RsxLocks::release_semaphore($slot); }
```

`max_slots <= 0` means UNLIMITED (a sentinel token, no socket opened). A numeric `$timeout` returns **null** instead of throwing - the caller decides how to degrade. `release_semaphore()` is safe with null or a sentinel and never throws.

---

## Holding, waiting, releasing

**A lock is held until you release it or your process dies.** No lease, no TTL, no renewal, no heartbeat - an hours-long critical section keeps its lock, and a `kill -9`'d holder frees it instantly.

**Why there is no lease (history).** Advisory locks once carried a 30-second lease. Nobody asked for it; asked, the answer would have been a hard no. The effect was that every `#[Exclusive]` guard in downstream code - a OneDrive sync among them - silently STOPPED being exclusive after 30 seconds, while every caller still believed it held the lock. That is the general shape of a timeout on your own work: it converts a working operation into a failed one at the worst moment and hands the failure to code that never expected it. Never add a lease, TTL or watchdog deadline here.

**`$timeout` is a WAIT budget and nothing else.** `null` (the default everywhere) = wait forever; a number waits that long and then throws. Wait-forever is right far more often than any number - a blocking acquire arms no timer and polls nothing, the process is parked in the kernel. The timeout message wording is load-bearing (the framework updater greps it to classify a retryable failure): `Failed to acquire WRITE lock for cluster:SITE_1 after 30 seconds`. **Do not reword it.**

**FIFO**: one ordered queue per name, grants come off the head only - a writer at the head is granted when there are no holders and the pass STOPS there (this is what prevents writer starvation); a run of consecutive readers grants as one batch. Nothing bypasses a queued waiter, so request order is grant order.

**Deadlock refusal**: before parking a waiter the daemon walks the wait-for graph, and a request that would close a cycle is **REFUSED immediately** with the cycle printed (every participant, host and pid) rather than parked. That text IS the debugging payload - read it rather than guessing. The check exists precisely because wait-forever is the default. A long queue behind one slow holder is NOT a deadlock and is not refused; that is the system working.

**`release_lock($token)` returns a bool.** `true` = we still held it (including a nested release leaving an outer hold). **`false` = the backend no longer had us as the holder - for a cluster lock that means rsx-lockd RESTARTED while we held it, so mutual exclusion was not in force for the whole critical section.** It is logged as an error either way; check the return value if your section can act on the news. `release_lock()` never throws.

**Reentrancy is client-side**: a nested acquire increments a count and returns the same token; the daemon sees one acquire per lock per connection. `upgrade_lock()` is deliberately NOT atomic - the read hold is dropped first and the write request joins the tail, so **re-verify whatever you read before the upgrade**; the payoff is that two simultaneous upgraders cannot deadlock.

---

## Subprocesses: the `Rsx_Artisan` mandate

**Spawn `php artisan` ONLY through `Rsx_Artisan`** - never `passthru`/`exec_safe`/`shell_exec`/`system`/`popen`/`proc_open` with an artisan command line.

```php
use App\RSpade\Core\Console\Rsx_Artisan;

$exit = Rsx_Artisan::passthru('rsx:bundle:compile');                 // streams output
$exit = Rsx_Artisan::run('migrate', ['--force'], $output);           // captures output
Rsx_Artisan::dispatch_detached('rsx:task:worker');                   // fire and forget
```

Command name and argv tokens stay **separate** (the helper escapes each).

**Why it is a mandate.** A lock belongs to a connection, so a child is a stranger to the daemon **including a stranger to its own parent**. Any lock this process holds that the child also needs produces a child queued behind its own parent while the parent blocks in `waitpid`. Neither can move, and **NOTHING REPORTS IT**: the deadlock detector walks lock state, and the parent's half of this cycle is an OS wait. It wedged a framework test run for twelve hours on 2026-08-11.

*(That incident came from the AUTOMATIC per-tenant write lock, which took `cluster:SITE_<id>` on the first `save()` to a site-scoped model. That mechanism is DISABLED as of 2026-08-11 - backlog B-87 - but the hazard is general: it applies to every lock, and named locks are taken by hand all the time.)*

**Lock groups** are the ONE hole in per-connection ownership. A connection may name a `group_id` at handshake; when its group already holds a lock it is granted immediately, **past any queue**. Bypassing the queue is required, not an optimization - queueing behind a foreign waiter deadlocks just as hard, and it costs that waiter nothing (the group already excluded it). Three boundaries keep the hole from widening:

- **A member releases only what IT acquired.** A dying child never drops the parent's grant; the group is consulted at GRANT time, never at release.
- **Read-held is NOT inherited as write** - that would be a cross-process upgrade letting one group hold read and write at once. A parent that will spawn a writer takes the WRITE lock before spawning.
- **A different group is an ordinary stranger.**

You never construct a group id: `Rsx_Artisan` attaches `--_lock-group=<id>` (the `--_` internal-flag convention, stripped pre-boot) to every **synchronous** spawn, and there is no way to switch it off - there is no case where a blocked parent wants its child to deadlock.

**`dispatch_detached()` does NOT propagate by default**, and its opt-in is spelled `$propagate_locks_and_i_will_wait` for a reason: two processes running concurrently under one lock destroys the exclusion both believe they have, silently. Pass it only when the caller genuinely joins the spawned process before continuing its own critical section.

`ARTISAN-SPAWN-01` (`rsx:check`) enforces this. In-process `Artisan::call()` runs on the same connection, is already reentrant, and is not flagged. A framework test whose SUBJECT is the artisan entrypoint keeps its raw spawn under a rationale'd `@ARTISAN-SPAWN-01-EXCEPTION`.

---

## Locks inside a task

A task worker is ONE long-lived process running many unrelated tasks, so "held until the process exits" is the wrong lifetime - the first task to write to a tenant would hold that tenant against the whole cluster for the worker's entire life. The worker takes `RsxLocks::_checkpoint()` before each task and `RsxLocks::_release_since($checkpoint)` in its `finally`: locks acquired during the task are released at its boundary, locks the WORKER holds survive, reentrancy counts are flattened. Both are framework-internal (`_` prefix); application code has no reason to call them.

**This is a safety net, not a licence.** Release your own in a `finally` - a task that ends holding one is released AND named in a `[WORKER]` warning.

The automatic per-tenant write lock being disabled means a task wanting tenant-wide exclusivity must ask: `RsxLocks::site_write_lock($site_id)`. Think before you do - for a long sync/import/reindex it blocks every web write to that tenant for the whole run, and such a task is usually better written to tolerate concurrent writers. **Backlog B-85** covers making that an explicit declaration.

---

## Troubleshooting

- **A command hangs forever, no error, nothing in the deadlock report.** The waitpid pattern: something spawned artisan outside `Rsx_Artisan` while holding a lock the child needs. Convert the call site.
- **`release_lock()` returned false.** rsx-lockd restarted mid-hold; exclusion was not in force for the whole section. Re-verify or re-run the work.
- **`Deadlock: ... would close a wait-for cycle`.** Read the printed cycle - it names every participant with host and pid. Fix the lock ordering; this is never retryable and must not be treated as a timeout.
- **`Failed to acquire ... after N seconds`.** A WAIT budget elapsed, not a held-too-long lock. Usually the right fix is to drop the number and wait.
- **Every cluster lock throws.** rsx-lockd is down (`rsx:health` -> "Lock Server"). There is no degraded mode by design.

Details: `php artisan rsx:man locks`. Related: `rspade:background-tasks`, `rspade:maintenance-mode`, `rspade:shell-invocation`.
