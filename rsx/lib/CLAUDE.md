# rsx/lib — the application's shared utilities

## WHAT IS HERE

Code that more than one module needs and that belongs to no single feature. Classes are
found by name, so a subdirectory is organisation only.

| Path | What it is |
|---|---|
| `action_log/` | `Action_Log` (record + read), `Action_Log_Renderer` (per-type HTML summaries), `Activity_Feed` (JS icon/variant map). Own `CLAUDE.md`. |
| `notification/` | `Notification` (send, unread count, dropdown feed, expiry) and `Notification_Renderer` (per-type text + link). Own `CLAUDE.md`. |
| `modal/` | `Modal`, `Modal_Abstract` and the `Rsx_Modal` chrome component — every dialog in the app. Own `CLAUDE.md`. |
| `topics/` | `Portal_Notification_Topic` — the realtime topic `Portal_Notification_Model::emit()` publishes on. `can_subscribe()` is fail-closed: the filter's `portal_user_id` must equal the caller's. |
| `analytics/` | `Analytics` plus `analytics.externals.php`. Loads gtag.js only when `rsx.analytics.measurement_id` is set; the app's worked example of an external-resource declaration. |
| `portal_user_admin/` | `Portal_User_Admin_Actions.suspend()/reactivate()` — confirm-then-call staff actions on a portal user, shared by the clients portal panel and the settings screen. |
| `formatters.php` / `formatters.js` | `Formatters`: phone (libphonenumber), currency, percentage, file size, date, datetime. The two halves are deliberate twins. |
| `file_download.js` | `trigger_file_download()` + `base64_to_bytes()` — turns an Ajax-returned export into a browser download (an XHR cannot itself be one; xlsx travels base64). |
| `quill_utils.js` | `quill_ready(callback)` — defers work until the Quill editor bundle has loaded. |
| `portal_demo_autoshare.php` | `Portal_Demo_Autoshare` — dev-site-only demo behaviour that auto-shares an uploaded client document so the portal Documents tab has content. Inert off a dev site and in tests. |

## HOW IT IS USED

A `lib` class is `public static` and namespaced under `Rsx\Lib\...`; nothing here holds
request state or renders a page. The JS files are plain classes and free functions picked
up by whichever bundle includes `rsx/lib` — every module bundle does.

**Before adding anything here, check the framework's standard library first**
(`rsx:man helpers`, `rsx:man js_functions`, skill `rspade:rsx-stdlib`). Debouncing, deep
equality, HTML escaping, byte and duration formatting, dot-path array access and date/time
formatting are all already provided in both languages; `Formatters` exists for the
DISPLAY conventions this application chose, not to re-implement them.

## HOW TO CUSTOMIZE

- **Everything here is deletable.** `portal_demo_autoshare.php` is demo scaffolding and is
  the first thing to remove from a real fork; `analytics/` goes with the tracking decision;
  `quill_utils.js` goes if no form uses the WYSIWYG input.
- **A new utility earns its place in `lib/` only when a second caller appears.** One caller
  means it belongs beside that caller.
- Keep the two `Formatters` in step — a rule added to one half and not the other shows up
  as a value that changes shape when the page re-renders from the client.
- An external script or stylesheet is declared in a `*.externals.php` beside its consumer,
  as `analytics/` does, and never injected by hand: the CSP whitelist derives from the
  declaration.

## RELATED

`action_log/CLAUDE.md` · `notification/CLAUDE.md` · `modal/CLAUDE.md` ·
app skills `action-log-and-notifications`, `modals` · skills `rspade:rsx-stdlib`,
`rspade:external-resources`, `rspade:realtime` · `rsx:man helpers`, `rsx:man js_functions`,
`rsx:man external_resources`
