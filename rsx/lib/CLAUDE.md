# rsx/lib — the application's shared utilities

## WHAT IS HERE

Code that more than one module needs and that belongs to no single feature. Classes are
found by name, so a subdirectory is organisation only.

| Path | What it is |
|---|---|
| `action_log/` | `Action_Log` (record + read), `Action_Log_Renderer` (per-type HTML summaries), `Activity_Feed` (JS icon/variant map). Own `CLAUDE.md`. |
| `notification/` | `Notification` (send, unread count, dropdown feed, expiry) and `Notification_Renderer` (per-type text + link). Own `CLAUDE.md`. |
| `attachments/` | `Record_Attachment_List` — the files attached to one record: `rows()` for a view page's `<Record_Attachments>` section, `claim($params, $record, $keys)` to attach uploaded keys (all-or-nothing, asking the framework's duplicate-upload question about each file the record already holds; returns the response to send instead, or null when attached), and the one `CATEGORY` every record's list is filed under. Any controller uses it; tasks are the worked example. |
| `inline_file/` | Files embedded in rich text. `Inline_File_Controller` (`resolve_attachment` - a just-uploaded file's card; `resolve_files` - a document's cards in one call, leaving out what the viewer may not see), `Inline_File_Tokens` (the `{{#file:N}}` token to and from the embed element) and `Inline_File_Renderer` (draws `Inline_File_Card`s, used by `Rich_Text_Display` and `Wysiwyg_Input`). Ownership is `Rich_Text::after_model_saved()` over the framework's `claim_for_embed()`; `rsx:man file_upload`, FILES EMBEDDED IN TEXT. |
| `modal/` | `Modal`, `Modal_Abstract` and the `Rsx_Modal` chrome component — every dialog in the app, plus the application's ONE registered handler for server-driven form questions (`Modal.on_app_modules_define()`). Own `CLAUDE.md`. |
| `text_types/` | `Raw_Text` and `Rich_Text` - the declared TEXT column types. Each is a PHP class (the encoding) plus a JS class of the same name (which component prints it, which one edits it). Own `CLAUDE.md`. |
| `password_confirmation/` | `Password_Confirmation_Modal.require()` — this application's "confirm it's you" dialog: `Modal.show()` hosting the framework's `<Password_Confirmation_Form>`, three tries, answering true at once while a confirmation is live. Every gated button awaits it first (Password & Security, API Keys, portal settings). `rsx:man password_confirmation`. |
| `password_reset/` | `Password_Reset_Token` — the trait behind every emailed "choose a new password" link (`Login_User_Password_Reset_Model`, `Portal_Password_Reset_Model`): the table stores only `token_hash` = SHA-256 of the link's secret (`get_secret()` is set on the minting instance alone), minting ends the identity's other open links, `find_by_secret()`, `is_valid()` / `is_used()` / `is_expired()`, the race-safe `claim()`, `invalidate_open_for()` (called on every password change by `rsx/handlers/Password_Event_Handlers`) and `query_spent()` (used or expired - what the nightly `Password_Reset_Link_Service::sweep` deletes). |
| `sessions/` | `Session_Listing` — this application's "where you're signed in" convention over the framework's `get_sessions_for_user()`: `recent()` (active within `LISTED_WITHIN_MONTHS`, 3), `row()` (the one payload, with `can_sign_out` and `ip_address__location` - `Rsx_Geo_Ip::label()`, resolved per load, never stored; a screen showing it carries `<Geo_Ip_Credit />`), `find()` (the record rule for an id an endpoint is handed). Used by user management, Password & Security and portal settings. |
| `topics/` | `Portal_Notification_Topic` — the realtime topic `Portal_Notification_Model::emit()` publishes on. `can_subscribe()` is fail-closed: the filter's `portal_user_id` must equal the caller's. |
| `analytics/` | `Analytics` plus `analytics.externals.php`. Loads gtag.js only when `rsx.analytics.measurement_id` is set; the app's worked example of an external-resource declaration. |
| `portal_user_admin/` | `Portal_User_Admin_Actions.suspend()/reactivate()` — confirm-then-call staff actions on a portal user, shared by the clients portal panel and the settings screen. |
| `field_checks/` | `Field_Checks` — the server-side value checks every save endpoint shares: `length_errors()` (each value against its column's `Model::field_length()`, a field error instead of a database exception), `is_enum_value()` (a declared enum value), `is_web_url()` / `website_short_url()` (http(s) only). Used by the staff saves and the external API; `Client_Model::value_errors()` and `Contact_Model::value_errors()` compose it into each model's one rule set. A date is `Rsx_Date::is_date()` directly, and an amount of money `Model::money_from_submitted('col', $value)`. |
| `formatters.php` / `formatters.js` | `Formatters`: phone (libphonenumber), percentage, file size, date, datetime in PHP; the JS half is `phone()`, the deliberate twin of the PHP one. An amount of money is never formatted here: an `Rsx_Money` prints itself (`format()`, `<%= %>`). |
| `file_download.js` | `trigger_file_download()` + `base64_to_bytes()` — turns an Ajax-returned export into a browser download (an XHR cannot itself be one; xlsx travels base64). |
| `quill_utils.js` | `quill_ready(callback)` — defers work until the Quill editor bundle has loaded. |

## HOW IT IS USED

A `lib` class is `public static` and namespaced under `Rsx\Lib\...`; nothing here holds
request state or renders a page. `text_types/` is the one exception to the static rule:
a text value is an instance because each one IS a distinct value, which is the whole
point of it. The JS files are plain classes and free functions picked
up by whichever bundle includes `rsx/lib` — every signed-in module bundle does. The
bundles served to visitors who have not signed in (`Login_Bundle`, `Errors_Bundle`,
`Portal_Auth_Bundle`) include no shared directory: `Login_Bundle` names the files it uses
from here (`formatters.js`, the modal library) one by one, and a page of theirs that
starts using another needs that file added to its bundle.

**Before adding anything here, check the framework's standard library first**
(`rsx:man helpers`, `rsx:man js_functions`, skill `rspade:rsx-stdlib`). Debouncing, deep
equality, HTML escaping, byte and duration formatting, dot-path array access and date/time
formatting are all already provided in both languages; `Formatters` exists for the
DISPLAY conventions this application chose, not to re-implement them.

## HOW TO CUSTOMIZE

- **Everything here is deletable.** `analytics/` goes with the tracking decision;
  `quill_utils.js` goes if no form uses the WYSIWYG input.
- **`text_types/` is meant to be adapted, not just kept.** The framework ships the abstract
  and nothing else; which types exist and what they permit is entirely this application's
  decision. Changing `Rich_Text`'s allow-list, or adding a type of your own, is the
  expected use of that directory rather than a customisation of it.
- **A new utility earns its place in `lib/` only when a second caller appears.** One caller
  means it belongs beside that caller.
- Keep the two `Formatters` in step — a rule added to one half and not the other shows up
  as a value that changes shape when the page re-renders from the client.
- An external script or stylesheet is declared in a `*.externals.php` beside its consumer,
  as `analytics/` does, and never injected by hand: the CSP whitelist derives from the
  declaration.

## RELATED

`action_log/CLAUDE.md` · `notification/CLAUDE.md` · `modal/CLAUDE.md` ·
`text_types/CLAUDE.md` ·
app skills `action-log-and-notifications`, `modals` · skills `rspade:rsx-stdlib`,
`rspade:external-resources`, `rspade:realtime` · `rsx:man helpers`, `rsx:man js_functions`,
`rsx:man external_resources`
