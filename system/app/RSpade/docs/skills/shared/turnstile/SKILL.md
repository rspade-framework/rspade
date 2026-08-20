---
name: turnstile
description: Wiring Cloudflare Turnstile human verification into a form and its endpoint - the TURNSTILE_* .env keys, <Turnstile_Input /> placement, Rsx_Turnstile::validate() as the first statement of the POST branch, the __turnstile field contract and 'inactive' sentinel, the rsx.post_dispatch completeness guard, and the dummy-key dev recipe. Use when adding bot protection to a login, registration, password-reset or public contact form, when a submit fails with "Turnstile implementation incomplete", or when a token is rejected as already spent.
---

# Turnstile

Cloudflare Turnstile is built into RSpade - **do NOT install a captcha package.** It is active ONLY when `config('rsx.turnstile.enabled')` is true; application mode has no bearing on it.

Two things to do per protected form: put the widget in the form, and call the validator first in the endpoint. Everything else is the framework's.

Cloudflare's `api.js` reaches the page through the **external-resources registry** (identifier `turnstile`, declared in `system/app/RSpade/Core/Js/turnstile.externals.php`), not a hand-written `<script>` tag: `Turnstile.js` just `await Rsx.load_external('turnstile')`. That declaration is also where the CSP directives Turnstile needs are stated (the frame it opens, the host it calls, the styles it injects), plus `mirror:false` and the `onload=__rsx_turnstile_onload` readiness handshake. Nothing to configure. Skill `rspade:external-resources`; `rsx:man csp`.

---

## Configuration

```
# .env - the only switch. Both keys are required when enabled (either missing THROWS).
# The keys below are Cloudflare's TESTING pair: fine in development and debug, but a
# strictly-production build configured with any testing key THROWS at the
# window.rsxapp population point (the always-pass testing secret verifies nothing).
TURNSTILE_ENABLED=true
TURNSTILE_SITE_KEY=1x00000000000000000000AA                  # public - exported as window.rsxapp.turnstile
TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA     # server-only, never exported
```

`verify_timeout_seconds` (30) bounds the siteverify call to Cloudflare - **a sanctioned timeout** (the framework's no-timeout mandate covers your own work, not a wait on an external party that may never answer) and **expiry fails CLOSED**.

---

## In the form

```jqhtml
<Rsx_Form $controller="Login_Controller" $method="submit">
    <Form_Field $label="Email"><Text_Input $name="email" $max_length=255 /></Form_Field>
    <Form_Field $label="Password"><Text_Input $name="password" $type="password" $max_length=255 /></Form_Field>

    <Turnstile_Input />
    <button type="submit" class="btn btn-primary">Sign In</button>
</Rsx_Form>
```

Framework-core, globally available - nothing to import. Args: `$theme` (auto|light|dark), `$size` (normal|flexible|compact), `$appearance` (always|execute|interaction-only).

**Two states, chosen from whether the server exported a site key.** *Active*: Cloudflare's widget renders into the component's container and the hidden field receives the token when the challenge resolves. *Inactive*: an inert, obviously-static placeholder shows where the widget will sit and the hidden field carries `'inactive'`. **The placeholder is a deliberate first-class state** - on a fresh install every wired form shows it, which is how you see at a glance that the form IS wired and the feature is merely off.

**The fixed field.** The component renders a REAL `<input type="hidden" name="__turnstile">`. That one detail is what lets a single core component work on both transports: `Rsx_Form.vals()` sweeps hidden inputs and a native `<form>` POST serializes them. **The name is a contract shared with `Rsx_Turnstile::FIELD`** (as is the `'inactive'` sentinel) and must never be renamed on one side only. Cloudflare is told `response-field: false`, so its own hidden input is never injected - there is exactly one field and RSpade owns it.

**It is NOT a `Form_Input_Abstract`**: `Form_Input_Abstract` and `Rsx_Form` are app-owned, and a framework-core component may not depend on application classes. Practical consequence - it takes no `$name`, produces no field error, and **must never be nested inside a `<Form_Field>` or another input component.**

**Auto-reset on a failed submit** is mandatory machinery, not a nicety: tokens are SINGLE-USE and `validate()` runs BEFORE any field validation, so **every** failed submit has already spent the token - including one that failed on an unrelated field. Inside an `<Rsx_Form>` the component subscribes to the form's `error` event and resets itself. **If you submit some other way, you own the reset.**

---

## In the endpoint

```php
use App\RSpade\Core\Turnstile\Rsx_Turnstile;

#[Route('/login', methods: ['GET','POST'])]
#[Auth('public')]
public static function index(Request $request, array $params = [])
{
    if ($request->is_post()) {
        Rsx_Turnstile::validate($request);          // native #[Route] POST
        // ... credential work only after this line
    }
}

#[Ajax_Endpoint]
#[Auth('public')]
public static function submit(Request $request, array $params = [])
{
    Rsx_Turnstile::validate($request, $params);     // Ajax form of the same call
    // ...
}
```

**It is the FIRST statement of the POST branch**, before any credential or email work - **so it gates enumeration too**. A "does this email exist" probe must not get an answer before proving it is human.

**Never conditional. Never wrapped in `if (config(...))`** - the field is always submitted (sentinel or token), which is exactly what lets the call be unconditional; a config test at the call site re-introduces the branch the sentinel was designed to remove.

A failure **STOPS the request** - it does not accumulate into your field errors - and is shaped for the channel automatically: `AjaxFormErrorException` inside `Ajax::internal()`, the json validation contract on `/_ajax`, flash+redirect on a native POST. **The message rides `_message`, never a `__turnstile` field key** (there is no field to attach it to, and the user cannot "correct" a challenge like a typo).

---

## The completeness guard

If a request submits `__turnstile` and the endpoint never called `validate()`, the framework throws **"Turnstile implementation incomplete"** after dispatch. **Fix it by adding the call; never silence it** - a widget on a form with no server-side validation is worse than no widget, because it looks protected.

It rides **`rsx.post_dispatch`**, an action event fired at every dispatch seam after the handler returns:

- payload `{request, params, result}`
- handlers run **inline**
- handlers **may throw** (that is how this one works)
- handlers **must not mutate `result`**

---

## Testing

Cloudflare's dummy keys work on **any** domain, localhost included - no account, no public hostname:

| Site keys (client) | Secret keys (server) |
|---|---|
| `1x00000000000000000000AA` always passes, visible widget | `1x0000000000000000000000000000000AA` always passes |
| `2x00000000000000000000AB` always blocks | `2x0000000000000000000000000000000AA` always fails |
| `3x00000000000000000000FF` forces an interactive challenge | `3x0000000000000000000000000000000AA` token already spent |

Mix and match: the always-passes SITE key with the always-fails SECRET is how you see the rejection path (and the widget auto-reset) without touching a real challenge. Verdicts from these keys report hostname `example.com`, which is why the hostname check exempts them - and why a strictly-production build carrying any of them throws.

**The test seam**: `Rsx_Turnstile::$force_verify_result_for_tests` substitutes the siteverify verdict (`true` = verified, `false` = rejected, `null` = make the real call). Nothing outside a test run may set it, and a test that sets it must clear it.

```php
Rsx_Turnstile::$force_verify_result_for_tests = false;
try { /* assert the rejection shape */ }
finally { Rsx_Turnstile::$force_verify_result_for_tests = null; }
```

---

## Pitfalls

- **Reusing a token** - single-use, and spent by every rejected submit. Inside `Rsx_Form` the widget resets itself; any other submit path is yours to reset.
- **Expecting a config flip to reach an open page** - a page rendered while the feature was off posts `'inactive'`; one rendered while on posts a token. After a flip the other state is rejected and the user must reload. **Accepted by design: it heals on the next page load and needs no machinery.**
- **A POST arriving without the field** - that is a form missing its widget, and it is rejected. Add `<Turnstile_Input />`; do NOT make `validate()` tolerant of an absent field, or "absent" and "opted out of verification" become the same thing.
- **Enabling with one key** - throws, on purpose. Set both, or set enabled false.

Details: `php artisan rsx:man turnstile`. Related: `rspade:forms`, `rspade:session-auth`, `rspade:event-hooks`.
