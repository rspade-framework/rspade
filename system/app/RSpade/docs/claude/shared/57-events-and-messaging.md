<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL STATEMENT of the event system; other fragments name only their own events. -->

## EVENTS

Attribute-based hooks discovered by the manifest — **no registration**. A handler is a public static method in `/rsx/handlers/` marked `#[OnEvent('some.event', priority: 10)]`.

Four trigger kinds: **`Rsx::trigger_action()`** (fire-and-forget), **`trigger_filter()`** (each handler transforms and returns the data, chained), **`trigger_gate()`** (first non-`true` return denies; **`true` permits**, and a gate with NO handlers is OPEN), **`trigger_resolve()`** (first NON-NULL result wins; `null` = declined, all-declined = the framework default). **Handlers run inline, in the request** — hand heavy work to `Task::dispatch()`.

Skill `rspade:event-hooks` (return contracts, priority, the manifest-lifecycle family, the event catalog). `rsx:man event_hooks`.

## EMAIL & SMS

**Queue-based delivery — every message is queued, never sent inline**, so a slow or down mail host can never slow a user's action. `Rsx_Mail::send($to, $subject, $template, $data, $category)` (plus `send_to_contact()` / `send_to_portal_user()`) and its deliberate mirror `Rsx_Sms::send($to, $body, $category)`.

**Categories**: `TRANSACTIONAL` (always delivers, ignores opt-out), `NOTIFICATION` (default; respects unsubscribe), `MARKETING`. Blocklist verbs: `is_blocked()`, `block()`, `unblock()`, `block_all()`. Templates are Blade files in `/rsx/emails/` using the `Rsx_Mail_Layout::header()/footer()` wrapper.

**REAL OUTBOUND DELIVERY IS NOT WIRED YET** (backlogged; verified 2026-08-17). The send task renders each queued message and marks it **`STATUS_HELD_BACK`** — recorded with `rendered_html` but NOT delivered. **Dev-site safety is automatic** when the hostname contains `.dev.` (`EMAIL_DEV_*` / `SMS_DEV_*` catchall, suppression and whitelists).

Skill `rspade:email-and-sms`. Details: `rsx:man email`, `rsx:man sms`.
