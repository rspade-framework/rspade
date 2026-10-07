# rsx/app/frontend/system — the system console and the sublayout worked example

## WHAT IS HERE

`System_Layout.{js,jqhtml}` + `system_layout.scss` — the sublayout, and the clearest
example in the app of nesting a second layout inside `Frontend_Spa_Layout`. Then six
actions in five directories, all `@auth('is_logged_in', 'can_manage_site_settings')`; every
screen but Background Tasks is `scaffolded = true`:

| Screen | Action | Route | What it reads |
|---|---|---|---|
| Status | `System_Status_Action` | `/frontend/system/status` | Nothing — a `<Placeholder_Card>` standing in for an unbuilt feature. |
| Background Tasks | `System_Tasks_Action` | `/frontend/system/tasks` | The signed-in user's own task runs through the framework's `Rsx_Task` API - `Rsx_Task.page({}, null, 50)`, which the application's `task.view.scope` handler narrows to the runs the user started (`rsx/handlers/Task_Gate_Handlers.php`) - each a `System_Task_Run_Card`. Reloads on `Task_List_Changed_Topic`. "Start a showcase task" dispatches `Task_Showcase_Service::walk` (`rsx/services/`) through `System_Tasks_Controller::start_showcase` and opens the new run's card. |
| Email Configuration | `System_Email_Config_Action` | `/frontend/system/email_config` | `Rsx_Mail_Transport::delivery_mode()`/`describe()`, `Rsx::is_dev_site()`, the `rsx.mail.*` config keys (driver, from address, dev-site catchall and whitelists, retry, retention) and per-status counts on `Email_Queue_Model`. |
| Email Queue | `System_Email_Queue_Action` | `/frontend/system/email_queue` | `Email_Queue_Model`, paginated, status filter + search on recipient/subject; per-row resend. |
| Email (one message) | `System_Email_View_Action` | `/frontend/system/email_queue/view/:id` | One `Email_Queue_Model` row plus its rendered HTML through `Rsx_Mail::displayable_html()` (inline `cid:` images become `data:` URIs of the bytes recorded on the row), shown in an iframe `srcdoc`, and its `attachments()` rows (file name, mime type, attachment-vs-inline, and the blob's size) when the message carries any; resend returns it to `STATUS_PENDING`. |
| Email Recipients | `System_Email_Recipients_Action` | `/frontend/system/email_recipients` | `Email_Recipient_Model`; toggles `is_blocked_notification` / `is_blocked_marketing` / `is_blocked_all`. |

`email_config/system_email_controller.php` (`System_Email_Controller`) serves all three mail
screens: `get_config`, `queue_fetch`, `queue_preview`, `queue_get`, `queue_resend`,
`recipients_fetch`, `recipients_toggle_block`. `tasks/system_tasks_controller.php`
(`System_Tasks_Controller`) has one endpoint, `start_showcase` (dispatches the showcase task
as the signed-in user and answers `{task_id}`): reading and controlling runs needs no
controller here, because the framework's `Rsx_Task_Controller` asks the application's task
gates. The Status placeholder has no controller at all.

`tasks/System_Task_Run_Card` (`$task` = a status row from `Rsx_Task.page()`, `$open`) is one
run: name, id, start time and a live `Task_Status_Badge`; a `Task_Report` progress bar
(`$kind="progress_text"`) while the run is live or once it reported progress; opened (click
the head), Stop (a live run, `Rsx_Task.stop()`) or Run again (a finished one,
`Rsx_Task.rerun()`), then the run's `Task_Report_Browser` and its live `Task_Output`
console. The framework's control gate allows or refuses each button's action.

## HOW IT IS USED

**The sublayout stack, outermost first** — copy these six lines when adding a screen here:

```javascript
@route('/frontend/system/status')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('System Status')
@auth('is_logged_in', 'can_manage_site_settings')
```

`System_Layout` extends `Spa_Layout`, renders its own `$sid="content"` inside the frontend
layout's, and keeps its sidebar in the template — two authored sections, **System** and
**Email**. Active-item tracking is `static NAV_CONFIG` (`<Action_Name>: '<nav id>'`, with
`System_Email_View_Action` aliasing to `email_queue` so the list item stays lit) read by
`on_action()`. The same `on_action()` stamps `system-content--scaffolded` from the
action's `scaffolded` flag, the seam described in `../CLAUDE.md`.

This is the mail operator's console and the user's view of their background tasks.
Delivery itself is the framework's (`rsx:man email`); the mail screens are the app's window
onto its queue. The task screen is the reference demonstration of showing a user their own
runs with the framework's task widgets (`rsx:man tasks`, WIDGETS).

The queue tables are framework SYSTEM tables (`_email_queue`, `_email_recipients`,
`_email_attachments`), so every screen here reads them through the models and never by
name. `queue_get` returns the attachment list from `Email_Queue_Model::attachments()`,
sized from the content-addressed blob rather than from the attachment row: the same file
mailed to a thousand people is one blob, and that blob's size is the honest number.

## HOW TO CUSTOMIZE

- **Add a screen**: the six decorators above, an anchor in `System_Layout.jqhtml`, and a
  `NAV_CONFIG` row. Give it its own controller rather than growing the mail one.
- **Finish the placeholder**: Status is a `<Placeholder_Card>` body with no backing
  endpoint — replace the card with real content or delete the directory and its nav anchor.
- **Background Tasks** shows whatever the task gates let the user see: widen or narrow it in
  `rsx/handlers/Task_Gate_Handlers.php`, never with a filter here. Replace the showcase
  button with the application's own tasks once it has them.
- **The whole console is an administrative surface.** `System_Email_Controller` and every
  action here are gated `can_manage_site_settings`, because a rendered message body carries
  whatever bearer links the message did - staff invitations, portal password resets, portal
  invitations - and reading it is a way into those accounts. The primary nav's System entry
  disappears for everyone else through `Permission.can_access()`. The sidebar inside does no
  filtering of its own, which is right only while every screen shares the one gate: a new
  screen with a different gate adds a `can_access()` test to its anchor.
- Restyle in `system_layout.scss`; the screens compose theme components and carry almost
  no SCSS of their own.

## RELATED

`../CLAUDE.md` · `../settings/CLAUDE.md` (the sibling sublayout) ·
`rsx/emails/CLAUDE.md` · `rsx/handlers/CLAUDE.md` (the task gates) · skills `rspade:spa`,
`rspade:email-and-sms`, `rspade:background-tasks` · `rsx:man spa`, `rsx:man email`,
`rsx:man auth_gates`, `rsx:man tasks`
