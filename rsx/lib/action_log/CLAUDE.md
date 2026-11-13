# rsx/lib/action_log — recording and rendering "who did what"

## WHAT IS HERE

- `action_log.php` — `Action_Log`, the whole write and read API:
  `record(int $type, object $subject, array $related = [], array $metadata = [], ?object $actor = null, ?int $audit_session_id = null)`
  and `get_for_entity(object $entity, ?int $limit = null)`.
- `action_log_renderer.php` — `Action_Log_Renderer`, one `public static` method per entry
  type (`client_created`, `contact_updated`, `task_deleted`, `password_changed`, `api_key_created`, … nineteen
  in all), each taking the log row and returning a one-line HTML summary with `Rsx::Route()`
  links. The enum's
  `renderer` property names the method; `Action_Log_Model::render()` calls it.
- `activity_feed.js` — `Activity_Feed.decorate(type_id)`, mapping a type id to the
  `{icon, variant}` pair a feed row is drawn with, and `Activity_Feed.can_view()`, whether
  the current user may see action-log rows at all.

## HOW IT IS USED

`record()` writes one **`Action_Log_Model`** row — site, type, the polymorphic actor
(`class_basename` of the signed-in identity, null for a system action), the polymorphic
subject, a JSON metadata blob and `audit_session_id` (`Session::get_audit_session_id()`: where
it was done from - null from a command or a plain task) — plus one **`Action_Log_Related_Model`** row per
`[$model, $role_id]` pair, so an entry can be found from any entity it touched.
`get_for_entity()` reads back everything where the entity is the subject OR a related row.

The feature controllers call `record()` after a successful write; nothing records
automatically. Code recording an act it was HANDED rather than doing - a task writing what an
event reported - passes `$actor` and `$audit_session_id` itself (null takes the session's,
which in a task names nobody).

**The address is never stored on a row.** `Action_Log_Model::audit_ip_address()` reads it from
the row's audit session (`Session::get_audit_session()`); a renderer that prints it calls that,
and a screen showing its LOCATION resolves `Rsx_Geo_Ip::label()` on every load and carries
`<Geo_Ip_Credit />` (`rsx:man geo_ip`).

**Account security (types 50-59).** `TYPE_PASSWORD_CHANGED` (`metadata.cause` = the
framework's `Password_Change::CAUSE_*`: `self`, `reset_link`, `admin`, `forced_change`, `cli`,
`unknown`) and `TYPE_PASSWORD_RESET_REQUESTED` are written by ONE place,
`Password_Audit_Service::record_password_event` (dispatched by `rsx/handlers/Password_Event_Handlers`
from the framework's `login_user.password.changed` / `portal_user.password.changed` and the
application's `*.password.reset_requested`) - never by a controller, so no path that changes a
password can be missed. One row per site the identity belongs to; the subject is the affected
person's membership of that site (`User_Model`) or the portal user; the actor whoever did it
(the user themself - also for a reset link or the forced change, where nobody is signed in - or
the administrator; null from the command line). Both renderers PRINT the address
(`... reset their password from an emailed link from 203.0.113.7`). `TYPE_API_KEY_CREATED` and
`TYPE_API_KEY_REVOKED` are written by ONE place too, `Api_Key_Audit_Service::record_api_key_event`
(dispatched by `rsx/handlers/Api_Key_Event_Handlers` from the framework's `api_key.created` /
`api_key.revoked`, never for a CLI temporary key or an `/apidocs` tester key): one row on the holder's site, the subject the
holder's membership, the actor whoever minted or ended the key (null from the command line),
the metadata the key's facts (`api_key_id`, `name`, `key_prefix`, `read_only`, `scopes`,
`expires_at`, and `how` - `revoked` | `deleted` - on a revocation), since the key may be deleted
later; both renderers name the key and print the address. Do not confuse this with the framework's REVISION history, which records
field-level diffs on its own — the action log is the human-readable narrative an application
chooses to keep.

**Rendered by `Feed_Row`** (`rsx/theme/components/view/feed_row/`): an icon tile, an
"actor did thing" summary and a relative time. Every entity view page mounts it in an
Activity tab, decorating the rows client-side with `Activity_Feed.decorate()`; the dashboard
mounts the same component but decorates server-side in its own controller — the deliberate
twin of `decorate()`, and the pair to keep in step. The browsable list and detail screens
are `rsx/app/frontend/action_logs/`, which uses a datagrid rather than `Feed_Row`.

**The action log is for administrators.** Every surface that returns its rows carries
`can_view_user_activity`: the Action Log screens, `Action_Log_Model::fetch()`, each view
page's `*_activity` endpoint (a method-level `#[Auth]` on top of the class's
`can_view_data`) and the dashboard's feed (filled only for a holder). On the client
`Activity_Feed.can_view()` - `Permission.can_access('Action_Logs_Index_Action')` - decides
whether a view page fetches its feed and draws the Activity tab and KPI, and whether the
dashboard draws its Recent Activity widget. A record's REVISION history is a different
surface with a different audience: every staff member who may read the record.

## HOW TO CUSTOMIZE

- **Add an entry type**: a `TYPE_*` constant and `type_id` enum value in `Action_Log_Model`'s `$columns`
  (`rsx:constants:regenerate` afterwards), a renderer method here, and an icon bucket in
  `Activity_Feed.decorate()` AND its server twin `Frontend_Dashboard_Controller::_activity_icon()`
  — buckets are `Math.floor(type_id / 10)`, so a new entity takes the next free ten (60 next).
- **A new surface showing action-log rows** names `can_view_user_activity` on the server and
  asks `Activity_Feed.can_view()` before drawing them.
- **Keep renderer output narrow.** It is HTML in a feed line: a verb, the actor, a link to
  the subject. Anything larger belongs on the record's own page.
- **Every renderer must survive a deleted subject** — the log outlives what it describes,
  and `actor_name()` already falls back to `System`.
- The whole subsystem is deletable: drop this directory, the two models, the
  `action_logs/` feature and the `Action_Log::record()` calls in the feature controllers.

## RELATED

`../CLAUDE.md` · `../notification/CLAUDE.md` · `rsx/app/frontend/action_logs/CLAUDE.md` ·
`rsx/theme/components/view/CLAUDE.md` (`Feed_Row`) · app skill
`action-log-and-notifications` · `rsx:man action_log` · skill `rspade:revisions`
