# rsx/emails — the application's email classes and templates

## WHAT IS HERE

Six `X_Email extends Rsx_Email_Abstract` classes, each beside its own blade, plus one
stylesheet. All six are `const CATEGORY = self::TRANSACTIONAL`.

| Class | Sent from |
|---|---|
| `Portal_Invitation_Email` | `Frontend_Clients_Controller` (new-account and existing-account invites) and `Portal_Request_Access_Controller` (resend). Its subject branches on whether the recipient already has an account. |
| `Portal_Password_Reset_Email` | `Portal_Password_Reset_Controller` — only when the user can log in, while the page reports success either way (no enumeration). |
| `Portal_Request_Reply_Email` | `Portal_Request_Threads_Controller` — tells the staff owner a client replied; the body is a truncated snippet, never the whole message. |
| `Portal_Shared_Content_Email` | `Frontend_Clients_Controller` when a document is shared. Carries a link, never bytes. |
| `User_Invitation_Email` | `Frontend_Settings_User_Management_Controller`, on create and on resend. |
| `Welcome_Email` | **Nothing sends it.** It is the reference example — the smallest complete email in the tree. |

`email.scss` is not a bundle and never reaches a browser: its presence **replaces** the
framework's default email stylesheet outright (there is no merge). The build compiles it,
inlines the result onto elements because mail clients strip `<style>`, and keeps a `<style>`
block so media queries survive. Rules are table-safe — no flexbox, no grid, no custom
properties. It defines `.email-wrapper`, `.email-container`, `.email-header`, `.email-logo`,
`.email-body`, `.email-button`, `.email-muted`, `.email-divider`, `.email-footer`.

## HOW IT IS USED

**The class name IS the template id.** Each blade opens with `@rsx_id('<Class_Basename>')`,
and the manifest fails the build when no blade in the tree declares a class's id, or when
two classes share a basename. The filename convention (`x_email.php` beside
`x_email.blade.php`) is for humans; the `@rsx_id` line is the wiring.

Every blade is body content between two framework calls —
`Rsx_Mail_Layout::header($subject)` and `Rsx_Mail_Layout::footer($unsubscribe_url ?? null)`,
the footer taking no argument on a staff-facing message.

**`sample()` is mandatory and must not touch the database.** The manifest build FATALs on an
email class without one, with the signature in the error text. It exists so
`php artisan rsx:mail:test you@example.com --email=Welcome_Email` can render and send any
email on demand — the flag takes the class BASENAME, and an unknown name prints the list of
known ones. Of the six here, two build unsaved model instances and four use scalars only.

**`EMAIL-TEMPLATE-01`** (high) polices these blades and nothing else: a datetime must be
formatted server-side through `Rsx_Time::` / `Rsx_Date::` (a mail client re-formats nothing,
and the message is frozen the moment the transport takes it), and every `Route()` must be
wrapped in `rsx_absolute_url()` (a mail client has no origin, so a root-relative link fails
silently in somebody else's inbox with the queue row still saying SENT).

## HOW TO CUSTOMIZE

- **Branding**: `rsx.mail.branding.logo_url` and `.footer_text` in
  `rsx/resource/config/rsx.php`, both null as shipped and both HTML-escaped by the layout.
  The look is `email.scss`; transport, delivery mode and dev-site recipient rules are
  framework config, not this directory's.
- **Add an email**: a class with `const CATEGORY`, `subject()`, `data()` and a DB-free
  `sample()`, plus a blade declaring `@rsx_id` with the class basename. Pick the category
  honestly — `TRANSACTIONAL` ignores unsubscribe, so a message a recipient could reasonably
  want to stop is `NOTIFICATION`.
- **Format datetimes in `data()`** rather than in the blade, so the value is frozen into the
  queue row exactly as it will be read.
- `Welcome_Email` is unwired: send it from your signup flow or delete both its files.

## RELATED

`rsx/app/frontend/system/CLAUDE.md` (the queue, config and recipient screens) ·
skill `rspade:email-and-sms` · `rsx:man email`
