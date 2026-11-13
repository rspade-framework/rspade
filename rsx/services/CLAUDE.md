# rsx/services — background work and app artisan commands

## WHAT IS HERE

Six services extending `Rsx_Service_Abstract`, one of them in a directory of its own. Every method is
`public static function name(Task_Instance $task, array $params = [])`, reports through
`$task` (status, progress, stdout, state, summary) and returns `null` on success - the
return value is the run's return code, never data.

- **`Portal_Invitation_Service::expire_stale`** — `#[Task]` + `#[Schedule('0 * * * *')]`.
  Marks pending portal invitations past their window as expired, so a followed link
  lands on the "expired, you can still create an account" page instead of a dead error and
  admin screens show accurate status. Writes a stdout line only when it expired
  something, and always records `{expired: N}` as its state and a one-line summary.
  It sweeps EVERY site (`without_site_scope`): the worker running it serves no tenant, and
  a site-scoped sweep would only ever expire the invitations of whichever site the CLI
  process happened to declare. It reads the due list unlocked, then expires each invitation
  in a `Site_Lock::write()` section on that invitation's site, re-reading it inside (it may
  have been accepted since). Any sweep added here that is install policy rather than one
  tenant's business follows the same shape.
- **`Notification_Service::purge`** — `#[Task]` + `#[Schedule('maintenance')]` +
  `#[Exclusive('maintenance')]`. Deletes staff notifications the recipient has SEEN
  (`read_at` set) once they are older than `rsx.notifications.purge_seen_after_days` days
  (default 90, from `created_at`). An unseen notification is never deleted - expired,
  orphaned or old - and `0` disables the purge (the run deletes nothing and reports itself
  disabled). Expiry (`default_expiry_days`) only decides what the badge and dropdown show.
  The notification endpoints are readers and delete nothing, so this nightly pass is the one
  place rows go. It walks every site, declaring each in turn
  (`Session::set_temporary_site_id()`), and deletes in batches of 500, one
  `Site_Lock::write()` section per batch whose delete re-states the rule. Reports the cutoff
  per site, progress over the sites, `{deleted, purge_seen_after_days, disabled}` as its
  state, a stdout line and a summary.
- **`Password_Reset_Link_Service::sweep`** — `#[Task]` + `#[Schedule('maintenance')]` +
  `#[Exclusive('maintenance')]`. Deletes every SPENT password reset link, staff
  (`login_user_password_resets`) and portal (`portal_password_resets`): used (completed,
  superseded by a newer request, or ended by a password change) or past `expires_at`. The rule
  is `Password_Reset_Token::query_spent()` (`rsx/lib/password_reset/`), restated in every
  delete; a live link is never touched, and a spent link and an unknown one answer the same
  "invalid link" page, so nobody can tell a row went. The staff table is not site-scoped and is
  deleted in batches of 500 with no site lock; the portal table walks every site
  (`Session::set_temporary_site_id()`), one `Site_Lock::write()` section per batch. Reports
  each phase, progress over the sites, `{deleted_staff, deleted_portal}` as its state, a
  stdout line and a summary.
- **`Password_Audit_Service::record_password_event`** — `#[Task]`, dispatched ONLY by
  `rsx/handlers/Password_Event_Handlers` (a password change, or a reset request that mailed a
  link). Runs as nobody: who acted (`actor_type`/`actor_id`), from where (`audit_session_id`)
  and the cause travel in its params. Writes one action-log row (`TYPE_PASSWORD_CHANGED` with
  `metadata.cause`, or `TYPE_PASSWORD_RESET_REQUESTED`) on EVERY site the identity belongs to
  (a portal user: its one site), each in its own `Site_Lock::write()` section with that site
  declared for the section only; the subject is the person's membership there, the actor the
  person who acted - the identity itself for a reset link or the forced change. Then, for a
  change the owner made (`NOTIFIED_CAUSES`: self, reset_link, forced_change, unknown), queues
  `Password_Changed_Email` on site 0 (staff) or the portal's site. Reports `{recorded,
  notified}` and a summary.
- **`Api_Key_Audit_Service::record_api_key_event`** — `#[Task]`, dispatched ONLY by
  `rsx/handlers/Api_Key_Event_Handlers` (a key minted, or a live key revoked or deleted; never
  a CLI temporary key or an `/apidocs` tester key). Runs as nobody: the actor, the audit session and the key's facts (`name`,
  `key_prefix`, `read_only`, `scopes`, `expires_at`, `how`) travel in its params. In ONE
  `Site_Lock::write()` section on the holder's site it writes the action-log row
  (`TYPE_API_KEY_CREATED` / `TYPE_API_KEY_REVOKED`, subject the holder's membership, the key's
  facts as metadata) and queues `Api_Key_Notice_Email` to the holder's sign-in address -
  for every event, whoever acted (the holder, an administrator, the command line). Reports
  `{site_id, notified}` and a summary.
- **`Mass_Edit_Service`** — `delete_clients`, `delete_contacts`, `delete_projects`: the
  datagrid footer "Delete" mass actions, each `#[Task]` + `#[Run_As_Dispatcher]` (it acts as
  the person who clicked, so the action log, revisions and authorship name them) +
  `#[Task_Access('dispatcher')]` (that person may watch and stop it). The worked example of a
  MASS EDIT (`rsx:man site_locks`, HOLDING A LOCK WELL, rule 8): the controller's `bulk_delete`
  only validates the selection payload and dispatches; the task reads the selection once with
  no lock (the grid's own query rebuilt from the on-screen filters, `apply_selection()`), then
  takes ONE `Site_Lock::write()` section per record that re-decides - the person may still
  edit data (`Permission::can_edit_data()`; if not, the rest is refused and the run stops),
  the record is still in the selection (else skipped) - and deletes through the model with its
  action-log entry, exactly as the single-record delete does. Not all-or-nothing: a stopped or
  refused run keeps what it already deleted. Reports status, `progress_count()` and a
  heartbeat per item, a stdout line per item not deleted, `{selected, deleted, skipped,
  refused}` as its state and a summary, which the grid shows through
  `Mass_Edit_Progress_Modal` (`rsx/theme/components/datagrid/`). Add a mass action by adding a
  task here that calls the same per-item shape, and an endpoint that validates and dispatches.
- **`sample_data/` - `Sample_Data_Service::seed`** — `#[Task]` + `#[Command('rsx_app:seed')]`.
  Fills an EMPTY site with the sample dataset: fictional clients, contacts, projects (with a
  subproject), tasks (with subtasks, client-level and unattached ones), documents of every
  preview type, request threads, announcements, parties and products. **It refuses a site
  that already holds application data**, in every mode - it is a starting point, never a
  merge. Runs as the site's first user, so records, revisions and the activity log are
  attributed; one `Site_Lock::write()` section (write lock, transaction, revision unit) per client. Every email is a test address and every phone a
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

**Tasks run concurrently, with no automatic lock.** A task shares its tables with web
requests and with other tasks. **A task body is an enforcement window**: a write to a
site-scoped model fatals unless it runs inside `Site_Lock::write($site_id, fn () => ...)` -
one section per item, re-reading the record inside - or a `Site_Lock::bypass()` with a
reason for a pass that tolerates concurrent writers. Change the declared site only between
sections, never inside one. `#[Exclusive]` and `#[Debounce]` guard one identity
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
