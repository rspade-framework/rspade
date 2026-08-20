---
name: email-and-sms
description: Sending mail and SMS in RSpade - Rsx_Mail::send / send_to_contact / send_to_portal_user, the three categories and unsubscribe semantics, Blade email templates with Rsx_Mail_Layout, dev-site safety (EMAIL_DEV_* / SMS_DEV_*), the blocklist API, the queue lifecycle and the HELD_BACK reality, and the Rsx_Sms mirror. Use when a feature must notify somebody by email or text, when writing an email template, or when a queued message did not arrive.
---

# Email and SMS

**Every message is QUEUED, never sent inline.** `Rsx_Mail::send()` writes a row and returns it; a background task drains the queue. Nothing in your request path talks to an SMTP server, so a slow or down mail host can never slow or fail a user's action.

```php
use App\RSpade\Core\Mail\Rsx_Mail;

Rsx_Mail::send($to, $subject, $template, $data, $category, $to_name = null,
               $related_type = null, $related_id = null): Email_Queue_Model;

Rsx_Mail::send_to_contact($contact, $subject, $template, $data, $category);
Rsx_Mail::send_to_portal_user($portal_user, $subject, $template, $data, $category);
```

```php
Rsx_Mail::send(
    $user->email,
    'Your invitation to Acme',
    'User_Invitation_Email',                          // the @rsx_id of a Blade file in /rsx/emails/
    ['name' => $user->name, 'action_url' => url(Rsx::Route('Accept_Invite_Controller', ['code' => $code]))],
    Rsx_Mail::TRANSACTIONAL
);
```

`$related_type`/`$related_id` are an optional polymorphic reference to whatever triggered the message.

---

## THE THING TO KNOW: real delivery is not wired yet

**REAL OUTBOUND DELIVERY IS NOT WIRED YET** (backlogged; re-verified 2026-08-17). `send()` persists a queue row; the send task (`Mail_Queue_Service::send_pending_queue`, `#[Exclusive]` + a 5-minute schedule) renders each pending email and marks it **`STATUS_HELD_BACK`** ("Held (dev)") - recorded, with `rendered_html` stored so a developer can recover the content and any link that never left the box - and **delivers nothing**. `HELD_BACK` is a distinct status from `SENT` / `FAILED` / `BLOCKED`.

Consequences for what you build: a flow may not depend on the recipient actually receiving the mail to be testable. Read the queue row (or the admin screen) instead, and surface invite/reset links in the UI or the log where the flow needs to be exercised. The same is true of SMS.

`send()` kicks a prompt background drain on web requests, so a queued message is processed within about a second rather than waiting for the next cron tick.

---

## Categories and opt-out

| Constant | Behavior |
|---|---|
| `Rsx_Mail::TRANSACTIONAL` (1) | **Always delivers.** Ignores the blocklist. Receipts, password resets, invitations, security notices. |
| `Rsx_Mail::NOTIFICATION` (2) | The default. Respects unsubscribe. |
| `Rsx_Mail::MARKETING` (3) | Respects unsubscribe. |

Pick by what the message IS, not by how much you want it delivered - marking marketing as transactional is how a system earns a spam complaint.

A non-transactional send to an opted-out address is **not silently dropped**: it is recorded as a BLOCKED queue row, so the audit trail shows the attempt.

```php
Rsx_Mail::is_blocked($email, Rsx_Mail::MARKETING);
Rsx_Mail::block($email, Rsx_Mail::MARKETING);
Rsx_Mail::unblock($email, Rsx_Mail::MARKETING);
Rsx_Mail::block_all($email);                       // all non-transactional
```

Unsubscribe URLs are HMAC-signed and **auto-injected into non-transactional templates as `$unsubscribe_url`** (null for transactional). Generate one manually with `Rsx_Mail::unsubscribe_url($email, $category)`; the endpoint verifies with `verify_unsubscribe_signature()`.

---

## Templates

Blade files in `/rsx/emails/`, wrapped by the PHP layout helper:

```blade
@rsx_id('My_Notification_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? '') !!}

<p>Hello {{ $name }},</p>
<p>Your content here.</p>

<p style="text-align: center;">
    <a href="{{ $action_url }}" class="email-button">Click Here</a>
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
```

Auto-injected variables: **`$subject`** and **`$unsubscribe_url`**. Everything else comes from the `$data` array you passed. Available classes: `.email-button` (CTA), `.email-muted`, `.email-divider`.

The template name passed to `send()` is the `@rsx_id`, not a path. Templates the starter app already ships: `Portal_Invitation_Email`, `Portal_Password_Reset_Email`, `User_Invitation_Email`, `Welcome_Email`, `Portal_Shared_Content_Email` - copy the nearest one rather than starting from a blank file.

---

## Dev-site safety

Automatic whenever the hostname contains `.dev.` (`Rsx::is_dev_site()`). Four `.env` keys:

| Key | Effect |
|---|---|
| `EMAIL_DEV_CATCHALL_ADDRESS` | All non-whitelisted mail redirects here; the original recipient is kept in `dev_original_to`. |
| `EMAIL_DEV_SUPPRESS_EMAIL_DELIVERY` | Render and record, never deliver. |
| `EMAIL_DEV_EMAIL_ADDRESS_WHITELIST` | Comma-separated addresses that deliver as-is. |
| `EMAIL_DEV_EMAIL_DOMAIN_WHITELIST` | Comma-separated domains that deliver as-is. |

Priority: address whitelist -> domain whitelist -> catchall -> queued-but-not-delivered. Production hostnames bypass all of it. (Moot today while everything is held back - but wire it correctly now, because it is what stops a real customer getting a test email the day delivery is switched on.)

---

## Queue, models, admin

`Mail_Queue_Service::send_pending_queue` is `#[Exclusive]` + `#[Schedule('*/5 * * * *')]`, so it needs the ordinary task cron (`rsx:task:process`). Run it by hand with `php artisan rsx:task:run Mail_Queue_Service send_pending_queue`.

**Models are framework-core**: `App\RSpade\Core\Models\Email_Queue_Model` (the queue: status, template data, rendered HTML) and `Email_Recipient_Model` (per-address blocklist + delivery stats). The `Rsx_Mail` facade depends on them; apps override via the class-override pattern rather than by subclassing. `Email_Queue_Model::fetch()` is an `#[Ajax_Endpoint_Model_Fetch]`, login-gated, loading one record for a developer "Email Transaction Log".

Template admin screens: `/frontend/system/email_config` (read-only config + queue stats), `/frontend/system/email_queue` (DataGrid, preview rendered HTML, resend), `/frontend/system/email_recipients` (per-address blocklist toggles + stats).

---

## SMS

`Rsx_Sms` mirrors `Rsx_Mail` deliberately - same shape, same guarantees, no templates.

```php
use App\RSpade\Core\Sms\Rsx_Sms;

Rsx_Sms::send('+15555550123', 'Your code is 4821', Rsx_Sms::TRANSACTIONAL);
Rsx_Sms::block_all($number);        // what a carrier STOP reply calls
Rsx_Sms::unblock($number, Rsx_Sms::MARKETING);
Rsx_Sms::is_blocked($number, Rsx_Sms::MARKETING);
```

`send($to, $body, $category = NOTIFICATION, $related_type = null, $related_id = null): Sms_Queue_Model`. Same three categories, same blocklist semantics, same **HELD_BACK** reality (`Sms_Queue_Service::send_pending_queue`). `body` is the content - there is no subject and no rendered HTML. Dev safety keys are `SMS_DEV_SUPPRESS_DELIVERY`, `SMS_DEV_NUMBER_WHITELIST`, `SMS_DEV_CATCHALL_NUMBER`. Models: `Sms_Queue_Model`, `Sms_Recipient_Model`. Handle a carrier STOP by calling `block_all()`, START by unblocking.

---

## Troubleshooting

- **"The email never arrived."** Expected - everything is HELD_BACK. Read the queue row / `rendered_html`.
- **Row is BLOCKED.** The recipient opted out of that category. Transactional never blocks - check whether the message was mis-categorized.
- **Nothing leaves PENDING.** The task cron is not installed, or the drain task is failing (`rsx:tasks:list`).
- **A dev send went to the catchall, not the recipient.** That is the safety working; whitelist the address or domain.
- **Sends land under the wrong tenant.** Site resolution follows the request's EXPERIENCE (staff session, or the app's portal declaration) and a sessionless CLI/task send resolves to site 0 - call `Session::set_site_id()` first when a task must target a tenant.

Details: `php artisan rsx:man email`, `rsx:man sms`. Related: `rspade:background-tasks`, `rspade:portal`, `rspade:blade-views`.
