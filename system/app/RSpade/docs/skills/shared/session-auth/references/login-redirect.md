# Login_Redirect (intended-URL redirect)

`App\RSpade\Core\Login\Login_Redirect` threads a validated `?redirect=` target through a multi-hop login flow, so a deep-link visitor lands on the page they asked for after authenticating. **It is the ONE redirect sanitizer in the codebase** (logout uses it too). A JS mirror with identical method names handles the client-side / expired-session entry point.

**Never hand-read `$_GET['redirect']`, never hand-build the param string, never write a second validator "just for this hop."** The param name is a private constant on purpose.

## API

```php
Login_Redirect::capture(Request $request): array   // ['redirect' => '/path?query'] or []
Login_Redirect::params(): array                    // the threading idiom, per hop
Login_Redirect::hidden_input(): string             // escaped <input type="hidden" ...>
Login_Redirect::consume(string $default): string   // the terminal call, exactly once
```

- **`capture($request)`** at the auth-rejection point. Returns a value only for a plain GET page request worth returning to; returns `[]` for POST, XHR, framework-internal/asset/Ajax paths (`/_...`), external API paths (`/api/...`), and excluded login-flow routes (loop prevention). The value is the path plus its verbatim query string. The same call serves `Main::pre_dispatch` (staff) and `Portal_Main::pre_dispatch` (portal).
- **`params()`** reads the value off the current request (GET query or POST body), validates it, and returns `['redirect' => $value]` or `[]`. **Merge it into every route built inside the login flow.** `Rsx::Route()` URL-encodes extra params, so a nested query string survives the hops with no special handling.
- **`hidden_input()`** for Blade login-flow forms - emit unescaped (`{!! ... !!}`); the call escapes the value itself. The form-POST hop is the one most often forgotten by hand; this exists to kill that failure mode.
- **`consume($default)`** at the final destination decision after successful authentication, **exactly once**.

## The wiring contract

Every page between the auth rejection and the terminal destination merges `params()` into its outbound login-flow routes and includes `hidden_input()` in its forms. A hop that drops the param **degrades gracefully** - the user lands on the default - so dropping it deliberately is legitimate when a flow owns its own destination (an invite-acceptance flow that always lands on its workspace). Audited before launch: `rsx:man prelaunch_checklist`, entry 1.

```php
// rejection point (rsx/main.php)
if (!Session::is_logged_in()) {
    return redirect(Rsx::Route('Login_Controller', Login_Redirect::capture($request)));
}

// login form (Blade)
<form method="POST" action="{{ Rsx::Route('Login_Controller') }}">
    {!! Login_Redirect::hidden_input() !!}
</form>

// terminal destination - onboarding still wins over the redirect target
if ($needs_onboarding) { return redirect(Rsx::Route('Welcome_Controller')); }
return redirect(Login_Redirect::consume(Rsx::Route('Dashboard_Index_Action')));
```

## Validation

Only a local path is accepted: one leading `/`, optional query string preserved verbatim. Rejected: protocol-relative (`//evil.example`), any scheme (a `:` before the first `/`), any host or absolute URL, backslashes, control characters and newlines, any `#` (fragments never reach the server anyway - a `#` rejects the whole value), login-flow routes (`rsx.login_redirect.excluded_prefixes`, default `['/login','/logout']`), non-page paths (`/_...`, `/api/...`) **in the validator as well as at capture**, values over 2000 characters, non-routable targets (no registered GET route handles them), and the no-op bare landing root (namespace root with NO query string - a bare root WITH a query still carries intent and is kept).

**Invalid, tampered or hostile values degrade SILENTLY** to `[]` / `$default`. The validator never throws and never flashes an error: a hostile `?redirect=` must reproduce exactly the pre-feature behavior. This is a **deliberate, documented exception to the framework's fail-loud rule, scoped to this class's read/validate paths only.**

The target is **URL-threaded, never stashed in the session or browser storage** - a stash breaks multi-tab correctness (two tabs mid-login would fight over one intended URL).

## Portal parity

Everything is context-aware via `Rsx_Portal::is_portal_request()`: in prefix mode the portal prefix is a page namespace (stripped before the base rules apply), staff and portal targets are **isolated in both directions**, and portal loop-prevention uses `config('rsx.login_redirect.portal_excluded_prefixes')`. The template's portal login/register flows are wired end to end (`Portal_Main::pre_dispatch` captures, the forms emit `hidden_input()`, the post-auth decision consumes).

## Pitfalls

- Letting a redirect skip required onboarding - onboarding/welcome flows must win; `consume()` supplies the default, not an override.
- Forgetting `hidden_input()` on a form-POST hop (the most commonly dropped hop).
- Calling `consume()` more than once, or in a middle hop instead of the terminal one.

Details: `php artisan rsx:man login_redirect`.
