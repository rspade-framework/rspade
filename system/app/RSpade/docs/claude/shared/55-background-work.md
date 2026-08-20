<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL STATEMENT of the task/lock/subprocess mandates; other fragments carry one-line pointers here. -->

## BACKGROUND WORK (TASKS, LOCKS, SUBPROCESSES)

Background work is a `public static` Service method (a class extending `Rsx_Service_Abstract`, in `/rsx/services/`) marked `#[Task]`, taking `(Task_Instance $task, array $params = [])` and optionally `#[Schedule('daily at 3am')]`. Run one with `$id = Task::dispatch('Service', 'method', $params)` — enqueues, spawns a detached worker when due, returns a **pollable id** for `Task::status($id)`. One cron entry drives everything: `* * * * * php artisan rsx:task:process`.

**KEY: tasks run concurrently and UNGUARDED.** No automatic application lock exists anywhere — multiple workers run at once, and a task shares tables with web requests and other tasks. **A `#[Task]` must never assume it is the only writer.** `#[Exclusive]` and `#[Debounce(seconds)]` (mutually exclusive) guard **ONE identity against itself**, never a shared table against other writers — use a lock for that. **You never add a timeout to your own task**; the framework reaper's cap is infrastructure.

**Locks**: `RsxLocks::named_write_lock($name)` / `named_read_lock()` for a critical section, `site_write_lock($site_id)` / `site_read_lock()` per tenant, `system_lock($name)` for a this-box-only resource, plus `upgrade_lock()` and counting semaphores. **Always release in a `finally`** (`RsxLocks::release_lock($token)`). **A lock is held until you release it or your process dies** — no lease, no TTL, no renewal; `$timeout` is a WAIT budget only and defaults to wait-forever.

**MANDATE: spawn `php artisan` ONLY through `Rsx_Artisan`** (`App\RSpade\Core\Console\Rsx_Artisan`) — never `passthru`/`exec_safe`/`shell_exec`/`system`/`popen`/`proc_open` with an artisan command line.

`Rsx_Artisan::passthru($cmd)` streams, `run($cmd, $args, $output)` captures, `dispatch_detached($cmd)` is fire-and-forget.

**Why it is a mandate: a subprocess is a different lockd connection, so it queues behind locks its own PARENT holds while the parent blocks in `waitpid` — a permanent hang the deadlock detector structurally cannot see.** Command name and argv tokens stay SEPARATE. In-process `Artisan::call()` needs none of this. Enforced by `ARTISAN-SPAWN-01`.

Skills: `rspade:background-tasks` (the `Task_Instance` API, `#[Schedule]` phrases, the worker pool, retry semantics), `rspade:locks-and-subprocesses` (cluster vs system locks, semaphores, FIFO/deadlock refusal, lock groups). Details: `rsx:man tasks`, `rsx:man locks`.
