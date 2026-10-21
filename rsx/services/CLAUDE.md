# rsx/services — background work and app artisan commands

## WHAT IS HERE

Two classes extending `Rsx_Service_Abstract`. Every method is
`public static function name(Task_Instance $task, array $params = [])`, reports through
`$task` (status, progress, stdout, state, summary) and returns `null` on success - the
return value is the run's return code, never data.

- **`Portal_Invitation_Service::expire_stale`** — `#[Task]` + `#[Schedule('0 * * * *')]`.
  Bulk-marks pending portal invitations past their window as expired, so a followed link
  lands on the "expired, you can still create an account" page instead of a dead error and
  admin screens show accurate status. Writes a stdout line only when it expired
  something, and always records `{expired: N}` as its state and a one-line summary.
  It sweeps EVERY site (`without_site_scope`): the worker running it serves no tenant, and
  a site-scoped sweep would only ever expire the invitations of whichever site the CLI
  process happened to declare. Any sweep added here that is install policy rather than
  one tenant's business follows the same shape.
- **`Seeder_Service`** — five `#[Task]`s building the demo dataset: `seed_clients`,
  `seed_contacts`, `seed_projects`, `seed_tasks`, and `seed_all`, which chains the other
  four through `Task::internal()` (each step its own inline run with its own summary) and
  reports the steps as `progress_count()` of four, a `status()` per step and one stdout
  line per step's summary. Each step reports its counts with `summary()`. Every one
  refuses to run in production and every one is additive and idempotent (an entity that
  already has children is skipped). Every one is stoppable: it checks `is_stop_requested()`
  and beats `heartbeat()` per client, project or task (`seed_all` per step) and ends with a
  "Stopped after ..." summary; the two all-or-nothing batches (subprojects, task chains) are
  checked before they start, never inside, since their idempotency check would leave a
  half-seeded batch incomplete forever. `seed_clients` and `seed_contacts` also report a
  `progress_count()` (clients). `seed_tasks` also backfills the derived `tasks.project_id` and builds
  polymorphic parent chains so that code path gets exercised. `seed_clients` and
  `seed_contacts` declare one revision unit of work per client
  (`Revision::begin_unit_of_work()` / `Revision::unit_of_work()`), so the history shows one
  entry per seeded client rather than one for the whole run - the shape any importing task
  follows (`rsx:man revisions`, THE UNIT OF WORK).

`seed_all` additionally carries `#[Command('rsx_app:seed', ...)]`.

## HOW IT IS USED

`Task::dispatch('Seeder_Service', 'seed_all')` enqueues and returns the run's id
(`Task_Run_Model`); `#[Schedule]` recurrence is driven by the one `rsx:task:process` cron
entry. Every run - dispatched, scheduled, or run by hand - is recorded with its reports and
output, and shows in `/_sys/tasks`.

**A `#[Command]` beside a `#[Task]` IS the artisan command** — an application writes no
command classes. `php artisan rsx_app:seed` is `rsx:task:run Seeder_Service seed_all` under
a friendlier name, with the same parameters, output and exit codes: the task's `stdout()`
is the command's stdout, its `stderr()` and every `status()` change the command's stderr
(`-q` silences it), and its return code the exit code.

**Tasks run concurrently and unguarded.** No application lock is taken for you: a task
shares its tables with web requests and with other tasks, so a service that writes must say
how it tolerates a second writer. `#[Exclusive]` and `#[Debounce]` guard one identity
against itself, never a shared table — that needs a lock. Never add a timeout to your own
work here.

**A task that may run long reports as it goes.** A task has no limit on how long it runs so
long as it keeps reporting; one that reports nothing for 30 minutes is taken for stuck and
killed. Every loop here calls `$task->heartbeat()` and a progress report on every item, as
the seeders do — copy that shape into any service that works through a list.

## HOW TO CUSTOMIZE

- **Add a service**: a class in this directory extending `Rsx_Service_Abstract`, one
  `#[Task]` method per unit of work, a `#[Schedule(...)]` if it recurs, and
  `#[Command('yourapp:verb', 'Description')]` if a human should be able to run it.
- **A cleanup that runs once a day or less is a maintenance task**:
  `#[Schedule('maintenance')]` (or `'maintenance weekly on <day>'` / `'maintenance monthly'`)
  plus `#[Exclusive('maintenance')]`, reporting each phase with `status()`, a backlog with
  `progress_count()`, and what it changed with a `stdout()` line and a `summary()`. The
  window opens at `rsx.tasks.maintenance_hour` (default 2am; set it in
  `rsx/resource/config/rsx.php`). `rsx:man tasks`, THE MAINTENANCE WINDOW.
- **Delete `Seeder_Service` before launch**, or at least verify its production refusal
  still holds — it exists to populate a demo database and has no place in a live one.
- Keep the environment guard at the top of any seeding or destructive task; it is the only
  thing standing between a convenience command and a live dataset.
- Heavy work triggered from a request belongs here, dispatched — an `#[OnEvent]` handler
  runs inline in the request and must stay cheap.

## RELATED

`rsx/handlers/CLAUDE.md` · skills `rspade:background-tasks`, `rspade:task-commands`,
`rspade:locks-and-subprocesses` · `rsx:man tasks`, `rsx:man task_commands`, `rsx:man locks`
