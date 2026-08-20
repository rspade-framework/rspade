---
name: maintenance-mode
description: Taking an RSpade app down and bringing it back - rsx:maintenance:enable/disable, what the CLI gate blocks and what still runs (migrate does), the service stop/start order and why it is load-bearing, how locks/cache/realtime degrade for code you run inside the window, recovering a stuck flag, and how the framework pull and rsx:git raise the same window. Use when planning downtime, doing database or filesystem surgery on a live app, or debugging odd behavior that turns out to be maintenance mode.
---

# Maintenance mode

```bash
php artisan rsx:maintenance:enable --reason="database surgery"
php artisan rsx:maintenance:disable
```

One flag file (`storage/rsx-framework/.maintenance.mode.framework.update`) whose **content is your reason**. Both commands are **intercepted PRE-BOOT in `system/artisan`** and shell out to `system/bin/maintenance-mode.sh`, which is why they work on a broken tree, can never be refused by the gate they control, and appear in no `php artisan list`. Both are idempotent.

`--no-services` does the flag half only (no supervisorctl, no task kill).

---

## The runbook

**Planned downtime:**

```bash
php artisan rsx:maintenance:enable --reason="upgrading the customer index"
# ... do the work ...
php artisan rsx:health           # runs fine in the window
php artisan rsx:maintenance:disable
```

**Inside the window you can work normally.** `migrate`, `rsx:health`, `rsx:check`, `rsx:clean`, builds, `rsx:man`, `rsx:ajax` - all allowed. The gate is **allow-most-deny-some**, and it exists to stop AUTOMATION (the cron tick, task workers, web traffic) firing into a stopped environment. **It is not there to babysit humans, who are presumed to know they turned it on.**

| | |
|---|---|
| BLOCKED | `rsx:task:process`, `rsx:task:worker` |
| BLOCKED unless `--force` | `rsx:task:run` |
| ALLOWED | everything else |
| Bypasses classification | any command carrying `--_framework-update-override` (the pull's own sub-calls) |

Refusals exit 75 and always name the way out (`php artisan rsx:maintenance:disable`). Classification is pre-boot and autoload-free, so it holds even when `vendor/` is half-synced.

**Running one task by hand during the window** is legitimate and supported: `php artisan rsx:task:run My_Service method --force`. (`force` is in `Task_Run_Command`'s `$skip_builtins`, so it is never mistaken for a task parameter.)

---

## What the web serves

While PHP still runs: **503 with `Retry-After: 120`** and a plain-text body quoting your reason, emitted before Composer's autoloader. The IDE bridge (`/_ide/service/*`) is intentionally exempt so the editor keeps working.

**Once enable has stopped php-fpm, no PHP runs at all**, so your web server answers its own upstream error (nginx: 502) instead. Both mean "down"; do not go hunting for why the 503 body disappeared.

---

## The service order, and why it is load-bearing

**Enable**: write the flag **FIRST** -> `rsx:tasks:kill-all` -> quiesce the node RPC helpers -> `supervisorctl stop` realtime, fpc-proxy, php-fpm, rsx-lockd, **redis LAST**.

The flag goes up before anything stops because **a process booting into the window must already SEE maintenance mode** - otherwise it selects the live backends (cluster locks over rsx-lockd, the Redis cache) and hard-fatals on the refused connect. The lock daemon stops AFTER its PHP consumers so no live request loses a lock out from under itself.

**Disable**: refuse if the repo has unmerged paths (see below) -> start redis FIRST, then rsx-lockd, **wait for rsx-lockd to actually answer** before starting php-fpm, then php-fpm, realtime, fpc-proxy -> remove the flag LAST. The readiness wait exists because spawning is not serving: a request taking a cluster lock against a daemon that is not yet listening waits with no deadline and no error ("the site is slow", nothing in any log). That wait has **no timeout** by design - it says "still waiting for rsx-lockd" every 5s rather than admitting traffic on a silent deadline.

Disable is **stateless**: it starts whichever of the five units exist, with no record of what enable stopped - so a unit you had deliberately stopped beforehand gets started. Deliberate; determinism beats a state file that can go stale.

**The node RPC helpers are a special case.** The build helpers (js-parser, js-transformer, minify, jqhtml-compile, js-sanitizer, js-code-quality) and ssr-server are spawned on demand by whatever PHP process needed one, then abandoned to PID 1 - **no `supervisorctl stop` reaches them**, and stopping php-fpm kills the workers, never the daemons they left behind. They are found by `pgrep -f -- '--socket=<storage>/rsx-tmp/'` and TERM/settle/KILLed. They must die because a maintenance window is by definition the moment the code under them changes. **Disable does not restart them**, deliberately: they hold no state, the next process that needs one spawns it, and that is what guarantees the post-window daemon runs post-window code.

---

## Writing/running code inside the window

rsx-lockd and redis are stopped, so the framework degrades on purpose. If you run a script or a task by hand in the window, this is what it sees:

- **Cluster locks (`named_*`, `site_*`) are granted as NO-OPS** - a `maint:` token, instant, no file, no wait, no daemon. The reasoning is exact: a lock exists to exclude a concurrent writer, and the window has already removed every one of them (web 503, task runners refused, workers killed). Reentrancy, release and shutdown bookkeeping are unchanged; `upgrade_lock` on a no-op READ mints the WRITE identity carrying the count; semaphores return the unlimited sentinel; **a cluster lock cannot time out here**.
- **`system_lock()` keeps working normally** - still flock, still exclusive, still able to time out with its canonical message (the pull's retry classification greps for it). Its contenders are a concurrent artisan command or a lingering local process, **which is exactly what maintenance does NOT stop**. Maintenance formerly degraded cluster locks to flock too; that bought exclusion against a peer already removed and cost real php-fpm contention during `rsx:debug` (owner ruling 2026-08-11).
- **Cache**: reads miss silently, writes are dropped with ONE log warning per process.
- **Realtime**: frames are dropped (publish, control, emitter-hash) and the subscription registry reads EMPTY. Deliberately not a throw - the frames are semantically void (relay stopped, registry flushed) and **a throw would abort `migrate` inside the update window**.
- Each tolerance **also** engages on a connect failure when the flag is on disk, which covers straggler processes that booted before enable. **A connect failure with NO flag stays as loud as ever** - the tolerance is never a general excuse for a missing service.

**Consumer note**: `Framework_Maintenance::is_active()` reads `RSPADE_MAINT_MODE`, a per-process snapshot taken from ONE stat at boot, so a long-running process never sees the flag flip mid-run. Use `is_active_on_disk()` when you specifically want the live view.

---

## Stuck flag, and who else raises the window

If a crash leaves maintenance up, **just run `php artisan rsx:maintenance:disable`** - it is idempotent, it does not care how the flag got there, and it works pre-boot.

`rsx:framework:pull` raises the SAME window for the duration of an update (reason: `framework update in progress`) and lifts it on every exit path. `rsx:git`'s tree-rewriting operations enter through the same script.

**The unmerged-paths guard**: disable REFUSES while the repository has unmerged index entries, listing them and naming `--force`. Bringing services up over a half-merged tree serves broken code. This is also what holds `rsx:git`'s app-conflict halt in place - when a proxied pull/merge/rebase conflicts in APP files it stops and leaves the window UP so you can resolve with no traffic:

```bash
# resolve the conflicted files
php artisan rsx:git commit
php artisan rsx:maintenance:disable
```

Only unmerged entries block; a resolved-but-uncommitted merge passes. `--no-services` is unaffected by the guard (it starts nothing) but still clears the flag, so it is not a way around it.

Laravel's own `down`/`up` still exist but are hidden from the command list - **RSpade has one maintenance mode**.

---

Details: `php artisan rsx:man maintenance_mode`. Related: `rspade:locks-and-subprocesses`, `rspade:realtime`, `rspade:application-modes-deployment`.
