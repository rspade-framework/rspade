# rsx/app/frontend/system — the system console and the sublayout worked example

## WHAT IS HERE

`System_Layout.{js,jqhtml}` + `system_layout.scss` — the sublayout, and the clearest
example in the app of nesting a second layout inside `Frontend_Spa_Layout`. Then six
actions in five directories, all `@auth('is_logged_in')` and all `scaffolded = true`:

| Screen | Action | Route | What it reads |
|---|---|---|---|
| Status | `System_Status_Action` | `/frontend/system/status` | Nothing — a `<Placeholder_Card>` standing in for an unbuilt feature. |
| Scheduled Tasks | `System_Tasks_Action` | `/frontend/system/tasks` | Nothing — the same placeholder. |
| Email Configuration | `System_Email_Config_Action` | `/frontend/system/email_config` | `Rsx_Mail_Transport::delivery_mode()`/`describe()`, `Rsx::is_dev_site()`, the `rsx.mail.*` config keys (driver, from address, dev-site catchall and whitelists, retry, retention) and per-status counts on `Email_Queue_Model`. |
| Email Queue | `System_Email_Queue_Action` | `/frontend/system/email_queue` | `Email_Queue_Model`, paginated, status filter + search on recipient/subject; per-row resend. |
| Email (one message) | `System_Email_View_Action` | `/frontend/system/email_queue/view/:id` | One `Email_Queue_Model` row plus its rendered HTML, shown in an iframe `srcdoc`; resend returns it to `STATUS_PENDING`. |
| Email Recipients | `System_Email_Recipients_Action` | `/frontend/system/email_recipients` | `Email_Recipient_Model`; toggles `is_blocked_notification` / `is_blocked_marketing` / `is_blocked_all`. |

`email_config/system_email_controller.php` (`System_Email_Controller`) is the **only**
controller in this tree and serves all three mail screens: `get_config`, `queue_fetch`,
`queue_preview`, `queue_get`, `queue_resend`, `recipients_fetch`,
`recipients_toggle_block`. The two placeholder screens have no controller at all.

## HOW IT IS USED

**The sublayout stack, outermost first** — copy these six lines when adding a screen here:

```javascript
@route('/frontend/system/status')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('System Status')
@auth('is_logged_in')
```

`System_Layout` extends `Spa_Layout`, renders its own `$sid="content"` inside the frontend
layout's, and keeps its sidebar in the template — two authored sections, **System** and
**Email**. Active-item tracking is `static NAV_CONFIG` (`<Action_Name>: '<nav id>'`, with
`System_Email_View_Action` aliasing to `email_queue` so the list item stays lit) read by
`on_action()`. The same `on_action()` stamps `system-content--scaffolded` from the
action's `scaffolded` flag, the seam described in `../CLAUDE.md`.

This is the mail operator's console. Delivery itself is the framework's
(`rsx:man email`); these screens are the app's window onto its queue.

## HOW TO CUSTOMIZE

- **Add a screen**: the six decorators above, an anchor in `System_Layout.jqhtml`, and a
  `NAV_CONFIG` row. Give it its own controller rather than growing the mail one.
- **Finish the placeholders**: Status and Scheduled Tasks are `<Placeholder_Card>` bodies
  with no backing endpoint — replace the card with real content or delete the directory
  and its two nav anchors.
- **Unlike the settings sidebar, this one does no `Permission.can_access()` filtering** and
  every route here gates only on `is_logged_in` — the mail queue, message previews and the
  recipient block toggles included. Tighten both together if this console should be
  admin-only: a `can_access()` filter in the template without tighter gates hides links
  without closing routes.
- Restyle in `system_layout.scss`; the screens compose theme components and carry almost
  no SCSS of their own.

## RELATED

`../CLAUDE.md` · `../settings/CLAUDE.md` (the sibling sublayout) ·
`rsx/emails/CLAUDE.md` · skills `rspade:spa`, `rspade:email-and-sms` ·
`rsx:man spa`, `rsx:man email`, `rsx:man auth_gates`
