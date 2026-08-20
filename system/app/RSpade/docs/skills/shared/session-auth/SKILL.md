---
name: session-auth
description: Working with RSpade sessions and the login flow - Session facade reads/writes, RsxAuth::attempt() and login history, account-state enforcement, device-session screens, self vs admin termination guards, session lifecycle windows and the concurrent cap, and web impersonation (begin_impersonation/stop_impersonation). Use when building a login or logout page, a "where you're signed in" screen, an admin sign-this-user-out button, an account-status check, or a "log in as user" feature - and see the csrf and login-redirect references for those two subsystems.
---

# Sessions and Login

`Session` is a static facade. **Never Laravel Auth, never `$_SESSION`.** The always-on fragment carries the API surface and the mandates; this skill is the how-to for the flows built on them.

`Portal_Session` is the portal twin over the same row - see `rspade:portal`.

---

## Reading identity

```php
Session::is_logged_in();          // bool
Session::has_session();           // bool - does a session exist? CREATES NOTHING
Session::get_user();              // User_Model (this site's membership) or null
Session::get_user_id();
Session::get_login_user();        // Login_User_Model (cross-site identity) or null
Session::get_login_user_id();
Session::get_site();
Session::get_site_id();           // 0 if not set
```

Two identity models, and the distinction matters: `Login_User_Model` is the person (one login, valid across sites); `User_Model` is their membership in the CURRENT site (role, per-site enablement). Roles and permissions hang off the membership.

**None of the readers create a session.** `get_session()`, `get_session_id()`, `set_login_user_id()` and `set_site_id()` do. If you only want to know whether a session exists, `has_session()` is the answer - `get_session_id()` would have created one while you were asking, which is exactly what `SESSION-ID-01` makes a build-fatal.

---

## The login flow

```php
#[Route('/login', methods: ['GET','POST'])]
#[Auth('public')]
public static function index(Request $request, array $params = [])
{
    if ($request->is_post()) {
        Rsx_Turnstile::validate($request);       // FIRST statement of the POST branch

        $login_user = RsxAuth::attempt([
            'email'    => $params['email'] ?? '',
            'password' => $params['password'] ?? '',
        ]);

        if (!$login_user) {
            Flash_Alert::error('Invalid credentials.');
            return redirect(Rsx::Route('Login_Controller', Login_Redirect::params()));
        }

        // YOUR account-state policy goes here - the framework has no opinion
        if ($login_user->status_id !== Login_User_Model::STATUS_ACTIVE) {
            Flash_Alert::error('This account is not active.');
            return redirect(Rsx::Route('Login_Controller'));
        }

        Session::set_login_user_id($login_user->id);
        Session::set_site_id($site_id);

        return redirect(Login_Redirect::consume(Rsx::Route('Dashboard_Index_Action')));
    }

    return rsx_view('Login_Index');
}
```

### `RsxAuth::attempt()` records the outcome itself

`RsxAuth::attempt(array $credentials, bool $record = true, bool $touch_last_login = true)` classifies and records SUCCESS / FAILED_NOT_FOUND / FAILED_PASSWORD - **it is the only place that can tell those apart**. So **never call `Login_History::record_success()` beside it**; you would record twice. `record: false` is the opt-out for a fixture, a harness, or a two-factor pre-check that will record on the real completion.

`$touch_last_login = false` skips the `last_login` stamp - pass it whenever the login is not a real login (impersonation, a harness, an unfinished second factor). **In CLI the stamp never happens at all.**

### Login history APIs, and their window

```php
Login_History::get_failed_attempts_count($email);     // within the failure window ONLY
Login_History::get_failed_attempts_count_by_ip($ip);  // same
```

Successes are `_login_history` rows, pruned on `rsx.sessions.login_history_retention_days` (default 365). **Failures are ephemeral - no row at all**: a per-email and a per-IP counter expiring on `rsx.sessions.login_failure_window_minutes` (default 15), plus one `Log::warning` line. `/login` is anonymous-reachable, and a persisted failure row was an unauthenticated INSERT anyone on the internet could drive.

Consequences you must design around: **a 30-day failed-attempt count is not answerable** - shrink such a stat to the window, or state the window in the label. And **throttling built on these counters fails OPEN** (a stopped cache must never lock users out); the enforcement policy is yours to write.

### Account state is APPLICATION vocabulary

`attempt()` verifies a live identity plus the password and **nothing else**. `login_users.status_id` / `is_activated` / `is_verified` mean whatever your app decides. Enforce your statuses in **two** places:

1. Your login function (above) - so a bad-state login never starts.
2. `Main::pre_dispatch()` - return non-null to halt, which ejects a session whose account went bad AFTER sign-in. **`init()` is a bootstrap hook and cannot eject anybody.**

`users.is_enabled` is the framework's own per-site disable trait, checked by the framework.

---

## Device sessions ("where you're signed in")

```php
$sessions = Session::get_sessions_for_user();                 // current user, most recent first
$sessions = Session::get_sessions_for_user($login_user_id);   // admin view
$info     = Session::get_current_session_info();              // this session, or null
```

Only ACTIVE sessions are returned, and **the WHOLE set of them on purpose** - a user cannot terminate a session they were not shown. Two things keep the set small, neither a cap on the query: the concurrent-session cap, and retention by type. (`Portal_Session::get_sessions_for_user()` is the same query on `portal_user_id`, additionally excluding "View as Client" handoff rows - single-use transport, not a device anyone is on.)

### Terminating - pick by whose sessions, and who is asking

```php
// SELF, one device. Another user's session id matches zero rows -> false.
// Refuses the caller's CURRENT session - that is logout()'s job.
Session::terminate_session($session_id): bool

// SELF, everything else - "sign out my other devices" (what a password change wants)
Session::terminate_all_other_sessions(): int

// ANOTHER USER - the ADMIN primitives, authorized first
Session::terminate_session_for_user($login_user_id, $session_id): bool
Session::terminate_all_sessions_for_user($login_user_id, $except_session_id = null): int

// FRAMEWORK-INTERNAL, NO AUTHORIZATION - for a caller with no acting user
Session::_deactivate_sessions_for_user($login_user_id): int
```

**The authorization rule on the two cross-user functions - two ways to be authorized, only two:**

1. **The target IS the actor** - managing your own device list needs no role.
2. **The actor's role may administer the target's** - `Permission::can_admin_role()` against the target's per-site `users.role_id`. That list is strictly "roles below mine", so **a PEER is refused and a SUPERIOR is refused, by construction**. Two Site Admins cannot sign each other out. A target with no `users` row on the acting site is refused too.

**REFUSAL THROWS `AjaxUnauthorizedException` (403); ABSENCE returns false/0** ("no such active row"). **Never conflate them** - that conflation is what hid a broken admin-terminate button for months: the endpoint read "false" as "nothing to do" and reported success while every call was actually being refused. Your endpoint still declares its own `#[Auth]` gate; the target rule is the framework's.

The rule **assumes a single site** (a role is per-site, a session identity is cross-site); multi-tenant termination is not supported yet (B-92). Deactivating a row signs out the PORTAL identity on it too - one row is one browser, and that is intended.

Every terminated row pushes a realtime refresh (the browser reloads into anonymity) and fires an event:

```php
#[OnEvent('session.terminated')]
public static function on_terminated(array $payload) {
    // {actor_login_user_id, target_login_user_id, session_id, scope: 'self'|'admin'|'internal'}
}
```

**The concurrent-session cap evicts silently** (no event, no push), so that stream is not a complete record of deactivations.

---

## Lifecycle windows

**Never implement "Remember Me"**: the cookie is long-lived, the ROW expires. Every session is stamped at creation with a `type_id` - a **writer-side fact, never re-derived from the user-agent**.

| type | who mints it | idle window |
|---|---|---|
| `TYPE_WEB` (with an identity) | a browser sign-in | `rsx.sessions.web_timeout_minutes` (3mo) |
| `TYPE_WEB` (anonymous) | a first visit | `anonymous_timeout_minutes` (30d) |
| `TYPE_API` | reserved - no writer (Bearer requests are cookie-less) | 7d |
| `TYPE_PLAYWRIGHT` | the `rsx:debug` harness | 1d |
| `TYPE_CLI` | any artisan/task/test process | 1d |

`Session_Cleanup_Service::cleanup_sessions` (hourly, `#[Exclusive]`, chunked) does the deleting. **There is no portal window** - a row carrying `portal_user_id` is a web row with an identity. `rsx:debug` additionally deletes the harness sessions its own RUN minted (`Session::purge_playwright_sessions`) - run-scoped, not request-scoped, because dev-auth signs in the navigation and the page's XHRs ride that session's cookie.

**The concurrent cap** (`rsx.sessions.max_web_sessions_per_user`, default 25; 0/null disables) signs out a user's oldest WEB sessions at every sign-in - the staff cap deactivates the row, the portal cap clears the portal properties off it (the row may still carry a staff login). Machine sessions are never counted or evicted.

**In CLI**, `get_session_id()`/`get_session()` mint a real `TYPE_CLI` row for the process (no cookie, no headers), deleted when the process ends; the identity setters only declare context and mint nothing.

---

## Web impersonation ("log in as user")

Same-cookie, in-place `login_user_id` swap - contrast the portal's cross-domain, read-only handoff.

```php
Session::begin_impersonation(int $target_login_user_id);  // throws if not logged in,
                                                          // already impersonating, or self
Session::stop_impersonation(): bool;                      // graceful restore; false if not impersonating
Session::is_impersonating(): bool;
Session::get_impersonator_login_user_id(): ?int;
Session::get_impersonator_login_user(): ?Login_User_Model;
Session::get_impersonation_started_at(): ?string;
```

**Full read/write** - there is no read-only mandate here (that is a portal-only constraint). **Authorization is the app's job**; core only provides the mechanism, so gate the entry point yourself (the template uses a `can_impersonate` check: manager-or-better AND not already impersonating).

`logout()`/`reset()` **HARD-CLEAR** impersonation - they do not restore to the impersonator; that is `stop_impersonation()`'s job. Neither begin nor stop touches `last_login`.

Client side, `window.rsxapp.impersonation` is `{impersonator_login_user_id, started_at}` or `null` - **ids only**; the app resolves display names for its banner.

```javascript
if (window.rsxapp.impersonation) { /* render the "viewing as" banner + a Stop control */ }
```

---

## Tenant context

`Session::set_site_id(int $id)` switches which site the staff session is operating in - it creates a session if none exists, and everything site-scoped follows it (the model global scope, mail, settings, time zones, throttling). It is the STAFF app's tenancy only; a portal request's site comes from the app's own declaration (`rspade:portal`).

A site-switcher endpoint validates membership itself - the framework will not check that the user belongs to the site you name:

```php
$membership = User_Model::where('login_user_id', Session::get_login_user_id())
    ->where('site_id', $params['site_id'])->first();
if (!$membership) { return response_unauthorized(); }
Session::set_site_id((int) $params['site_id']);
```

---

## Troubleshooting

- **"A session was created where I only wanted to check for one."** Something called `get_session_id()`/`get_session()`/a setter. Use `has_session()` for the question.
- **`SESSION-ID-01` build failure.** A null-ish or zero-ish test on `get_session_id()`. Delete the test - it is dead code (the method is `: int`, never null) and it already created the session it was guarding against. Using the value in arithmetic, a query or a comparison is never flagged.
- **An admin "sign out" button that always reports success but changes nothing.** The classic refusal-vs-absence conflation: the call was throwing `AjaxUnauthorizedException` (or returning false for a genuinely absent row) and the endpoint treated both as "done". Let the throw propagate; treat false as "nothing matched".
- **A user is still signed in after being disabled.** `attempt()` only guards the sign-in. Enforce account state in `Main::pre_dispatch()` too, and terminate their sessions when you disable them.
- **Failed-attempt count reads zero for a user you know just failed.** The window (`login_failure_window_minutes`, default 15) elapsed, or the cache was restarted. Both are expected - the counters are ephemeral and throttling on them fails open.
- **A test signs in but `last_login` moves.** Pass `$touch_last_login = false` (or `record: false` on `attempt()`) for harness logins.

---

## Two subsystems with their own references

- **CSRF** - fully automatic; there is nothing to write. `references/csrf.md`.
- **Intended-URL redirect** - `Login_Redirect`, the one sanitizer; wire every hop. `references/login-redirect.md`.

Details: `php artisan rsx:man session`, `rsx:man auth`. Related: `rspade:auth-gates`, `rspade:permissions-acl`, `rspade:portal`, `rspade:turnstile`.
