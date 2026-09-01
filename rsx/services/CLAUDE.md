# rsx/services — background work and app artisan commands

## WHAT IS HERE

Two classes extending `Rsx_Service_Abstract`. Every method is
`public static function name(Task_Instance $task, array $params = [])`.

- **`Portal_Invitation_Service::expire_stale`** — `#[Task]` + `#[Schedule('0 * * * *')]`.
  Bulk-marks pending portal invitations past their window as expired, so a followed link
  lands on the "expired, you can still create an account" page instead of a dead error and
  admin screens show accurate status. Reports a count only when it changed something.
- **`Seeder_Service`** — five `#[Task]`s building the demo dataset: `seed_clients`,
  `seed_contacts`, `seed_projects`, `seed_tasks`, and `seed_all`, which chains the other
  four through `Task::internal()` and reports progress. Every one refuses to run in
  production and every one is additive and idempotent (an entity that already has children
  is skipped). `seed_tasks` also backfills the derived `tasks.project_id` and builds
  polymorphic parent chains so that code path gets exercised.

`seed_all` additionally carries `#[Command('rsx_app:seed', ...)]`.

## HOW IT IS USED

`Task::dispatch('Seeder_Service', 'seed_all')` enqueues and returns a pollable id;
`#[Schedule]` recurrence is driven by the one `rsx:task:process` cron entry.

**A `#[Command]` beside a `#[Task]` IS the artisan command** — an application writes no
command classes. `php artisan rsx_app:seed` is `rsx:task:run Seeder_Service seed_all` under
a friendlier name, with the same parameters, the same JSON on stdout and the same exit
codes; `$task->info()` narration goes to stderr so stdout stays pipeable
(`php artisan rsx_app:seed 2>/dev/null | jq`).

**Tasks run concurrently and unguarded.** No application lock is taken for you: a task
shares its tables with web requests and with other tasks, so a service that writes must say
how it tolerates a second writer. `#[Exclusive]` and `#[Debounce]` guard one identity
against itself, never a shared table — that needs a lock. Never add a timeout to your own
work here.

## HOW TO CUSTOMIZE

- **Add a service**: a class in this directory extending `Rsx_Service_Abstract`, one
  `#[Task]` method per unit of work, `#[Schedule('daily at 3am')]` if it recurs, and
  `#[Command('yourapp:verb', 'Description')]` if a human should be able to run it.
- **Delete `Seeder_Service` before launch**, or at least verify its production refusal
  still holds — it exists to populate a demo database and has no place in a live one.
- Keep the environment guard at the top of any seeding or destructive task; it is the only
  thing standing between a convenience command and a live dataset.
- Heavy work triggered from a request belongs here, dispatched — an `#[OnEvent]` handler
  runs inline in the request and must stay cheap.

## RELATED

`rsx/handlers/CLAUDE.md` · skills `rspade:background-tasks`, `rspade:task-commands`,
`rspade:locks-and-subprocesses` · `rsx:man tasks`, `rsx:man task_commands`, `rsx:man locks`
