# Configuration Override System

## Purpose

This directory holds the application's configuration. Each file deep-merges over the framework
configuration of the same name (`system/config/`), so the application changes framework
behaviour, and declares its own settings, without touching `system/` - which is framework
property, reset by every framework update.

The full list of framework keys and their defaults is `system/config/rsx.php` (never edit that
file); `php artisan rsx:man config_rsx` is its documented form. How to add or override a key,
config vs `.env`, and registering middleware: skill `rspade:environment-config`.

## How the merge works

`rsx/resource/config/<name>.php` deep-merges (`array_merge_deep`) over `config('<name>')` at
boot: a scalar here REPLACES the framework value, an array is COMBINED with the framework's,
nested arrays merge recursively. So a file here states only the keys it changes - a copied
framework block silently freezes the other keys at the version copied.

## Available Configuration Files

| File | Purpose |
|------|---------|
| `rsx.php` | Framework overrides plus this application's own keys |

**Any file here is merged the same way**, whichever Laravel or framework config file it names.
So `mail.php` adds or changes a mailer in Laravel's mail config (`rsx:man email`, MAIL
TRANSPORTS) and `services.php` supplies a package's service credentials. Only `rsx.php` ships
here; create the others when you need them.

## Configuration Sections in rsx.php

**Framework overrides** - keys whose defaults live in `system/config/rsx.php`:
`development`, `ide_integration`, `code_quality`, `console_debug`, `bundle_aliases`,
`bundle_processors`, `manifest_modules`, `public`, `csp`, `exception_handlers`, `mail`
(email branding only; delivery is framework config and the transport is Laravel's `MAIL_*`),
`theme` (the attributes dark mode sets on `<html>`, `rsx:man dark_mode`).

**This application's own choices**:
- `login_notices` - the sentences `Login_Notice` shows on a sign-in page.
- `auth` - the sign-in posture: accounts come only from invitations (no self-signup);
  `require_two_factor` (staff forced enrollment, hard-coded off); invitation expiry.
- `portal` - invitation, password-reset and shared-link expiry, password length, and
  `emailed_sign_in_codes` (portal 2FA by email, hard-coded off). Which site a portal request
  serves is the framework's job, read from the URL - nothing here configures it.
- `first_run.sample_data_task` - the `#[Task]` the first-run screen offers to seed sample data.
- `notifications`, `formatting`, `response`, `api`, `analytics`, `log_browser_errors`,
  `js_model_base_class`.

## Deployment-specific values

A value that differs per BOX - a host, a credential, a toggle one environment sets - belongs in
`.env`, read through `env()` from this file. Application BEHAVIOUR belongs here, in version
control, never in `.env`:

```php
'log_browser_errors' => env('LOG_BROWSER_ERRORS', false),
```

Any new key added this way is also added to the project-root `.env.dist` with a sensible default
(never a secret) - that is how it reaches every other box.

## Framework Developer Note

Framework DEFAULTS belong in `system/config/rsx.php`. This file holds the template application's
own choices, and it ships: an edit here is a deliberate change to the starter template.
