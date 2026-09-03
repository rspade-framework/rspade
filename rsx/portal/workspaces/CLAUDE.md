# rsx/portal/workspaces — the per-client workspace and its tabs

## WHAT IS HERE

`Portal_Workspace_Layout.{js,jqhtml,scss}` — the sublayout — plus three tabs and the
request-thread screen beneath one of them.

| Directory | Action / controller | Route | What it shows |
|---|---|---|---|
| `overview/` | `Portal_Workspace_Overview_Action` | `/workspace/:id` | The default tab: a summary and a few client vitals derived from portal-safe fields. |
| `requests/` | `Portal_Workspace_Requests_Action` + `portal_request_threads_controller.php` | `/workspace/:id/requests` | The client's request threads. |
| `requests/thread/` | `Portal_Request_Thread_Action` | one thread | Two columns: the timeline (messages as chat cards, status changes as centred event cards) plus a reply composer, and a vitals rail with participants and the Awaiting Review / Accepted document buckets. Its two modals live beside it: `portal_document_detail_modal` (download plus read-only review state) and `portal_participant_card_modal`. |
| `documents/` | `Portal_Workspace_Documents_Action` + `portal_documents_controller.php` | `/workspace/:id/documents` | Documents the firm shared with this client. |
| (root) | `portal_workspaces_controller.php` | — | `list()` and `get()`, the membership-gated source every screen here reads. |

## HOW IT IS USED

**The layout is a parametrized sublayout, and that shapes its code.** Declared
outermost-first — `@layout('Portal_Layout')` then `@layout('Portal_Workspace_Layout')` —
with `@portal_spa('Portal_Spa_Controller::index')`. The SPA reuses one layout instance by
class name across every tab AND across different workspace ids, so the layout takes no args
at construction: it reads `:id` from `on_action(url, action_name, args)` on each dispatch.
`on_action` fires AFTER the sub-action has been mounted into `$content`, so the layout must
never call `render()` — it writes the header and the pill hrefs directly and reloads the
header only when the id actually changes. `static NAV_CONFIG` maps action names to pills
(the thread action aliases to `requests`), and the same `on_action` stamps
`Portal_Workspace_Layout__content--scaffolded` from the action's `scaffolded` flag.

**The membership gate is the three-state error state.** There is no gate to write here:
`Portal_Workspaces_Controller.get()` fails closed for a non-member, every tab loads through
it, and a denial renders `Universal_Error_Page_Component` in the tab body. The layout's own
header load uses the same endpoint and simply leaves the header blank on failure, so the
error is stated once.

**That gate call runs IN PARALLEL with the tab's own load, not before it.** Each tab action
puts both under one `Promise.all`, because the workspace lookup is not a PRECONDITION for
the tab payload - the arguments are independent, and each endpoint enforces
`Portal_Permission::has_client_access()` for itself, fail-closed. Neither branch carries a
`.catch()`: a non-member must see the error, so both stay fatal and the batch rejects into
`this.data.error_data`. Sequencing them would only buy a second serial round-trip; it would
buy no safety, because the second endpoint was never trusting the first.

**The read-only guard is per mutating endpoint.** Every Ajax call is a POST, reads included,
so a blanket block would break the portal. `Portal_Request_Threads_Controller::reply()`
shows the shape — `if (Portal_Permission::is_read_only()) { ... }` as its first act — and
the reply composer is additionally hidden client-side when the thread is closed or the
caller's role cannot collaborate. Hiding the control is courtesy; the endpoint check is the
enforcement.

## HOW TO CUSTOMIZE

- **Add a tab**: a directory with its action (three decorators as above, `scaffolded = true`
  if it composes `<Page_Scaffold>`), a pill in `Portal_Workspace_Layout.jqhtml`, and a
  `NAV_CONFIG` row. Load through `Portal_Workspaces_Controller.get()` so the membership gate
  comes for free - alongside the tab's own fetch in one `Promise.all`, per above, and give
  the tab's endpoint its own `has_client_access()` check rather than leaning on that call.
- **Every new write endpoint gets its own `is_read_only()` refusal.** Nothing adds it for
  you, and staff impersonation is read-only by application policy, not framework policy.
- **Never hand-scope a query by `site_id`** — the portal's site is declared in
  `rsx/portal_main.php` and every framework seam reads that declaration.
- Restyle in `Portal_Workspace_Layout.scss` and the per-action SCSS; the thread screen is
  the one place in the portal with substantial layout of its own.

## RELATED

`../CLAUDE.md` · `rsx/portal_permission.php` · app skill `portal-app` ·
skill `rspade:portal-core` · `rsx:man portal`
