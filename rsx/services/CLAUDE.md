# rsx/services — background work and app artisan commands

## WHAT IS HERE

Two services extending `Rsx_Service_Abstract`, one of them in a directory of its own. Every method is
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
- **`sample_data/` - `Sample_Data_Service::seed`** — `#[Task]` + `#[Command('rsx_app:seed')]`.
  Fills an EMPTY site with the sample dataset: fictional clients, contacts, projects (with a
  subproject), tasks (with subtasks, client-level and unattached ones), documents of every
  preview type, request threads, announcements, parties and products. **It refuses a site
  that already holds application data**, in every mode - it is a starting point, never a
  merge. Runs as the site's first user, so records, revisions and the activity log are
  attributed; one revision unit per client. Every email is a test address and every phone a
  555 number, so it can never reach anyone, and it creates no account. `--site=<id>` names
  the site (required when there is more than one). The first-run setup screen offers it while
  `rsx.first_run.sample_data_task` names it (`rsx/resource/config/rsx.php`). Its own
  `CLAUDE.md` is the procedure for refreshing the data.

## HOW IT IS USED

`Task::dispatch('Sample_Data_Service', 'seed', ['site' => 1])` enqueues and returns the run's id
(`Task_Run_Model`); `#[Schedule]` recurrence is driven by the one `rsx:task:process` cron
entry. Every run - dispatched, scheduled, or run by hand - is recorded with its reports and
output, and shows in `/_sys/tasks`.

**A `#[Command]` beside a `#[Task]` IS the artisan command** — an application writes no
command classes. `php artisan rsx_app:seed` is `rsx:task:run Sample_Data_Service seed` under
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
the sample-data seeder does — copy that shape into any service that works through a list.

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
- **Sample data is yours to change**: edit `sample_data/resource/sample_data.json` (and
  regenerate its documents) following `sample_data/CLAUDE.md`, name a seeder of your own in
  `rsx.first_run.sample_data_task`, or remove that key to stop the first-run screen offering
  one. Delete the directory if the application never wants sample data.
- Keep the empty-site refusal at the top of any seeding task; it is the only thing standing
  between a convenience command and a live dataset.
- Heavy work triggered from a request belongs here, dispatched — an `#[OnEvent]` handler
  runs inline in the request and must stay cheap.

## RELATED

`rsx/handlers/CLAUDE.md` · skills `rspade:background-tasks`, `rspade:task-commands`,
`rspade:locks-and-subprocesses` · `rsx:man tasks`, `rsx:man task_commands`, `rsx:man locks`
