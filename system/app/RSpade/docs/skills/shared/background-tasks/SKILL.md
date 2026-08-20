---
name: background-tasks
description: Writing and running RSpade background tasks - #[Task] service methods, the Task_Instance API, Task::dispatch and Task::status, #[Schedule] recurrence, #[Exclusive]/#[Debounce] single-identity guards, the worker pool, and what a task must do about concurrency. Use when adding a scheduled job, a queued background job, a cleanup/import/report task, or a controller that kicks off long work and polls it.
---

# Background Tasks

A task is a `public static` method on a Service class (`Rsx_Service_Abstract`) marked `#[Task]`. There is no job class, no queue driver, no `queue:work` daemon - the framework owns the durable queue (`_tasks`) and one shared pool of generic workers.

```php
// /rsx/services/report_service.php
class Report_Service extends Rsx_Service_Abstract
{
    #[Task('Generate the monthly report')]
    public static function generate(Task_Instance $task, array $params = [])
    {
        $task->info('Starting');
        $task->update_progress(50, 'Halfway');
        return ['rows' => 1500];          // the return value IS the result
    }
}
```

The signature is fixed: `(Task_Instance $task, array $params = [])`. The return value is JSON-encoded into the row's `result`.

---

## Running one

```bash
php artisan rsx:task:run Report_Service generate --month=12   # synchronous, STDOUT, no row
php artisan rsx:task:list                                     # discovered tasks
php artisan rsx:tasks:list                                    # running instances + every schedule
```

```php
$id = Task::dispatch('Report_Service', 'generate', ['month' => 12]);
```

`dispatch()` inserts a pending row **and**, when the task is due now, spawns a detached worker so it starts within ~1 second. It **returns a pollable id** - for an `#[Exclusive]`/`#[Debounce]` identity that coalesces onto an already-pending run, the id of that pending row. Options: `queue` (a label), `timeout`, and `scheduled_for` - **a future `scheduled_for` is the only thing that defers the run.** `Task::internal($service, $task, $params)` runs it in-process and returns the task's value.

### `Task::status($id)` returns an ARRAY

```php
$s = Task::status($id);          // null if the id is unknown
// [ 'id','class','method','queue','status',   // pending|running|completed|failed|killed
//   'params' => array, 'result' => array|null, 'logs' => string[], 'error' => string|null,
//   'scheduled_for','started_at','completed_at','created_at','updated_at' ]
if ($s['status'] === 'completed') { $path = $s['result']['output_path']; }
```

A polling endpoint is an ordinary gated Ajax endpoint:

```php
#[Ajax_Endpoint]
#[Auth('can_run_reports')]
public static function start(Request $request, array $params = []) {
    return ['task_id' => Task::dispatch('Report_Service', 'generate', ['month' => (int) $params['month']])];
}

#[Ajax_Endpoint]
#[Auth('can_run_reports')]
public static function poll(Request $request, array $params = []) {
    $s = Task::status((int) $params['task_id']);
    return ['status' => $s['status'] ?? 'unknown', 'result' => $s['result'] ?? null];
}
```

---

## The `Task_Instance` API - the whole of it

```php
$task->info($message);                                  // logging (timestamped, persisted)
$task->error($message);
$task->debug($message);
$task->log($level, $message);                           // two args - the one-arg form is a TypeError
$task->update_progress(int $percent, ?string $message = null);   // 0-100
$task->set_result($value);                              // publish a result mid-run
$task->heartbeat();                                     // call periodically in a long task
$dir = $task->get_temp_dir();                           // auto-deleted on completion/failure
$task->get_id(); $task->get_status(); $task->get_params(); $task->get_queue();
```

**There is no `warning()`, no `progress()`, no `set_status()`, and no `is_cancelled()`.** The temp-dir accessor is `get_temp_dir()`, not `get_temp_directory()`.

`heartbeat()` records a DB heartbeat AND refreshes this worker's pool slot, so a run longer than `rsx.tasks.worker_heartbeat_ttl` (90s) is not mistaken for a dead worker and pruned. Call it inside long loops.

---

## Recurrence: `#[Schedule]`

```php
#[Task('Drain the outgoing email queue')]
#[Exclusive]
#[Schedule('every 5 minutes')]
public static function drain(Task_Instance $task, array $params = []) { ... }
```

Standard 5-field cron works (`'0 3 * * *'`, `'0 9 * * 1-5'`), but **prefer the human-readable phrases**: `'every minute'`, `'every 5 minutes'`, `'every 6 hours'`, `'hourly'`, `'daily'`, `'daily at 2am'`, `'daily at 14:30'`, `'weekly on monday at 9:30am'`, `'monthly'`. **Why: a cron step token contains `*` followed by `/`, and that pair terminates a `/* */` block comment and corrupts the file.**

Schedule edits take effect within one cron tick - the processor compares the manifest expression to the stored one and regenerates the tracker.

**A scheduled task is never permanently terminal.** One that throws recycles its tracker to PENDING and retries next cadence (failures counted on the row, surfaced by `rsx:tasks:list` and an `rsx:health` WARN at `rsx.tasks.failing_schedule_warn_after`, default 3). A one-shot dispatch that throws goes FAILED with no retry.

Cron installs the tick:

```
* * * * * php artisan rsx:task:process
```

---

## Concurrency - the one thing you must get right

**Tasks run concurrently and UNGUARDED.** Nothing takes a global application lock - not artisan, not workers, not web requests. Multiple workers run at once and your task shares the database with concurrent web requests and other tasks. **A `#[Task]` must not assume it is the only writer.**

Two mutually-exclusive markers guard ONE identity (`class::method`); declaring both fails `rsx:check` (`TASK-CONCURRENCY-01`):

| Marker | Meaning |
|---|---|
| `#[Exclusive]` | at most one instance runs at a time (`== #[Debounce(0)]`) |
| `#[Debounce(30)]` | same, and the coalesced follow-up waits 30s after the previous run **COMPLETED** |

Both mean **at most one running + at most one pending** per identity. State is durable (`_tasks` rows) and cluster-safe (MySQL advisory `Task_Lock`); the cron poller is the durable backstop. A task with neither marker may run concurrently with copies of itself.

**They guard one identity against ITSELF.** They do not stop a *different* task, or a web request, from writing the same table. For that, take a lock:

```php
use App\RSpade\Core\Locks\RsxLocks;

$token = RsxLocks::named_write_lock('rebuild_report_cache');
try { /* critical section - however long it takes */ }
finally { RsxLocks::release_lock($token); }
```

**Your locks are released when the task ends.** A worker is one long-lived process running many unrelated tasks, and an application lock is otherwise held until the PROCESS exits - so the worker checkpoints before each task and releases anything acquired during it. Release your own in a `finally` anyway: a task that ends still holding one is released AND named in a `[WORKER]` warning, because that is a defect in the task. Full contract: `rspade:locks-and-subprocesses`.

**Never spawn artisan with `passthru`/`exec_safe`/`shell_exec` from a task** - a subprocess is a different lock connection and would queue behind locks this task holds, forever. Use `Rsx_Artisan`.

---

## Timeouts

**You never add a timeout to your own task.** There is a framework-level reaper (a `_tasks.timeout` cap, falling back to `rsx.tasks.default_timeout`) enforced by the cron tick - **that cap is framework infrastructure**, owner-approved, and it is the only timeout in this system. `timeout` is a `Task::dispatch()` option and a `_tasks` column; it is **not** a `#[Task]` argument, and there is no `retries` argument at all.

---

## The worker pool

One shared pool of generic workers drains the queue, capped by `rsx.tasks.global_max_workers` (default 3) via a Redis worker-slot registry: each spawned worker atomically claims a slot and exits immediately if the pool is full. A crashed worker's slot expires after 90s.

Priority is a single order - run-now tasks first (FIFO by enqueue time), then due scheduled tasks. **`queue` is a LABEL, not worker isolation**; it no longer routes work to separate workers.

---

## Patterns

```php
// Cleanup, scheduled. Rsx_Time, never Carbon. Explicit field assignment, never mass assignment.
#[Task('Prune old export files')]
#[Schedule('daily at 4am')]
public static function prune(Task_Instance $task, array $params = [])
{
    $cutoff  = Rsx_Time::subtract(Rsx_Time::now_iso(), 30 * 86400);
    $deleted = Export_Model::where('created_at', '<', $cutoff)->delete();
    $task->info("Deleted {$deleted} exports");
    return ['deleted' => $deleted];
}
```

```php
// Long walk with progress + heartbeat. Iterate, never truncate.
#[Task('Reindex documents')]
#[Debounce(30)]
public static function reindex(Task_Instance $task, array $params = [])
{
    $done = 0;
    foreach (Document_Model::where('needs_index', 1)->result_set() as $doc) {
        $doc->reindex();
        if (++$done % 100 === 0) { $task->heartbeat(); $task->update_progress(0, "{$done} indexed"); }
    }
    return ['indexed' => $done];
}
```

---

## Troubleshooting

- **Dispatched, nothing ran.** The pool was at its cap (the spawned worker self-declines) or `scheduled_for` is in the future. Check `rsx:tasks:list`; the cron tick picks it up regardless.
- **A `#[Schedule]` stopped firing.** It is not terminal - look for consecutive failures on the row (`rsx:tasks:list`) and the `rsx:health` WARN.
- **A worker warning names my task.** It ended holding a lock; add the `finally`.
- **A subprocess hangs forever with no error.** An artisan command spawned outside `Rsx_Artisan` while the task held a lock. See `rspade:locks-and-subprocesses`.
- **`TypeError` on `$task->log(...)`.** `log()` takes `(level, message)`; use `info()`/`error()`/`debug()`.
- **Refused under maintenance mode.** `rsx:task:process`/`:worker` are blocked (exit 75); `rsx:task:run` needs `--force`.

Details: `php artisan rsx:man tasks`, `rsx:man locks`. Colocated: `system/app/RSpade/Core/Task/CLAUDE.md`. Related: `rspade:locks-and-subprocesses`, `rspade:event-hooks`.
