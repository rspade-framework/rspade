# CSRF

**There is nothing for an application developer to do.** Write `#[Ajax_Endpoint]` methods and `<Rsx_Form>` components normally; the framework attaches, transmits and verifies the token end to end. Do not attach, read, or verify a token yourself, and never hand-roll a hidden `_csrf_token` input.

## What is enforced

Two complementary layers, at the dispatch seam, **POST only** (GET never mutates and is never checked):

1. **Origin / Referer** - every POST, session or not. A cross-site browser POST always carries an `Origin` the attacker cannot forge; a foreign host is rejected. Requests with neither header (curl, server-to-server) are allowed - they are not cross-site browser POSTs. **This is the layer that closes login-CSRF** (forcing a victim into the attacker's account), which `SameSite=Lax` cannot: a login POST needs no existing cookie, so there is nothing for SameSite to withhold.
2. **Synchronizer token** - session-bearing POSTs only. If a session exists the token is required and must match the one bound to that session (constant-time). **If no session exists the request is allowed** - there is no victim session to forge against - which is what lets anonymous reads and the login POST itself work token-free.

## The token

A 32-byte random token, minted ONCE when the session is created and stored on `_sessions.csrf_token`. **Stable for the life of the session** - not rotated on login, identity change, or impersonation (RSpade's session-token immutability). It reaches JavaScript only as `window.rsxapp.csrf`, which is populated on page render **only when a session already exists** (an anonymous first-visit page has `null` - no session is created merely to emit a token). It never lives in a page-readable cookie.

## Transport (handled for you)

- **Ajax and uploads**: the token rides the `X-CSRF-Token` header, attached in ONE place - the framework's jQuery `$.ajax` chokepoint - so every `Controller.method()` call (direct and batched) and every `/_upload` multipart POST carries it. Never attached to external cross-domain requests.
- **Native `<form>` POSTs** (login/auth pages): a global submit listener injects a hidden `_csrf_token` input just before submit. `<Rsx_Form>` submits via Ajax, so it uses the header path; a stray hidden field is excluded from its serialized values.
- **Blade**: use `@csrf`. RSpade overrides the directive to emit the browser's one token, and it emits **nothing** when there is no session yet - correct, because a session-less POST needs none.
- The server reads the header first, then the `_csrf_token` body field.

## Portal parity

There is **one session per browser** - one `rsx` cookie, one `_sessions` row, one token - shared by the staff app and the portal. So there is exactly one token and nothing to fork on: a staff form and a portal form carry the same value, both dispatchers verify against the same row, and `Rsx_Csrf::enforce()` takes no realm argument. Portal code writes `@csrf` exactly like staff code; **a hand-rolled portal variant is always wrong.**

## Enforcement point and failure shape

One POST-gated call (`App\RSpade\Core\Session\Rsx_Csrf::enforce($request)`) in the staff Dispatcher and an identical one in `Portal_Dispatcher`, placed after the external-API branch. It covers `/_ajax/:controller/:action`, `/_ajax/_batch`, `/_upload` and native `#[Route(POST)]` controllers, on both the staff and portal spellings of those paths.

A failure returns a clean auth-style error with no stack trace - the Ajax error contract for `/_ajax` and `/_upload`, a **419** for native form POSTs. The rejection is an already-rendered response carried by an `HttpResponseException` honored **before** the handler chain, so no error handler can replace it with a 500. The check uses `has_session()` and never creates a session for a token-less anonymous caller.

**Exempt**: the external API (`#[Api_Endpoint]`, `/api/vN/*`) - it authenticates with a Bearer token and a cookie-less headless session, so there is no ambient cookie to forge, and it branches off before this seam.

`Session::get_csrf_token()` / `Session::verify_csrf_token($token)` exist for the framework's own use and for an exotic transport you are building yourself; ordinary application code never calls them.

Details: `php artisan rsx:man csrf`.
