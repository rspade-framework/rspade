# Core/Task — the task system's implementation

**Writing a task? Do not read this file.** How to author, dispatch, schedule and guard a
`#[Task]` is `rsx:man tasks` plus the `rspade:background-tasks` skill (the `$task` API,
`#[Exclusive]`/`#[Debounce]`, the human-readable `#[Schedule]` phrases and the block-comment
caution, the `_tasks` schema, and the failure/recycle rules all live there). This file only
says what is in this DIRECTORY.

## What is here

- `Task.php` — the public facade: `dispatch()`, `status()`, coalescing enqueue, prompt
  detached-worker spawn.
- `Task_Instance.php` — the `$task` handle passed to every task method (`info`/`error`/`debug`,
  `update_progress`, `set_result`, `heartbeat`).
- `Task_Concurrency.php` — `#[Exclusive]` / `#[Debounce]` resolution (at most one running +
  one pending per `class::method` identity).
- `Task_Lock.php` — MySQL advisory `GET_LOCK` mutex; the cluster-safe primitive under the
  queue itself. NOT the app-facing lock: that is `RsxLocks` (`Core/Locks/`, `rsx:man locks`).
- `Cron_Parser.php` — normalizes both 5-field cron and the plain-English `#[Schedule]` phrases.
- `Task_Worker_Registry.php` — the Redis worker-slot registry enforcing
  `rsx.tasks.global_max_workers` across the pool.
- `Task_Killer.php`, `Task_Status.php`, `Task_Health_Checks.php`, `Cleanup_Service.php` —
  kill paths, status vocabulary, `rsx:health` probes, retention pruning.

## Invariants to keep when editing here

- **A cron tracker row is never permanently terminal.** One row IS the schedule; parking it in
  `failed`/`killed` stops that schedule forever and silently. Every settle path recycles it to
  `pending`, and `rsx:task:process` loudly revives any tracker found terminal.
- **Tasks run concurrently and unguarded.** Nothing here may reintroduce a global application
  lock; a task serializes its own critical section with `RsxLocks`.
- **The reaper's stuck-task cap is framework infrastructure, not licence to add timeouts.**
  See the no-timeout mandate.
- Attributes are reflection-only — never define `#[Task]`/`#[Schedule]` classes.

## See also

`rsx:man tasks` · `rsx:man locks` · skills `rspade:background-tasks`, `rspade:locks-and-subprocesses`
