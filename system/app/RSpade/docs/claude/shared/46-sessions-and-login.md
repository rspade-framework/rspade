<!-- single-source: never duplicate into another fragment. -->

## SESSIONS & LOGIN FLOWS

**Always use `Session`** (static facade) — **never Laravel Auth, never `$_SESSION`.** Readers create nothing: `is_logged_in()`, `has_session()`, `get_user()` (site `User_Model`) / `get_user_id()`, `get_login_user()` / `get_login_user_id()` (cross-site identity), `get_site_id()`, `get_csrf_token()`, `is_impersonating()`. Writers create a session if needed: `get_session_id()`, `set_login_user_id($id, $touch_last_login = true)` (null = logout), `logout()`, `set_site_id()`, `begin_impersonation()`/`stop_impersonation()`, plus `terminate_session()`/`terminate_all_other_sessions()` (SELF) and `terminate_*_for_user()` (another user, framework-guarded by `can_admin_role`).

**`get_session_id()` is `: int` and NEVER null.** A null-ish/zero-ish test on it (`=== null`, `empty()`, `??`, `== 0`, `> 0`) is dead code that already created the session it meant to prevent — FATAL at manifest build (`SESSION-ID-01`). "Is there a session?" is `has_session()`.

**A guarded termination REFUSAL THROWS `AjaxUnauthorizedException`; ABSENCE returns false/0** — never conflate them (that conflation hid a broken admin-terminate button for months). **Never implement "Remember Me"** — the cookie is long-lived and the ROW expires on its own schedule.

**`RsxAuth::attempt()` records the login outcome itself** — never call `Login_History::record_success()` beside it, or you record twice. Account state (`status_id`/`is_activated`/`is_verified`) is **APPLICATION vocabulary**: `attempt()` checks a live identity plus the password and nothing else, so enforce your statuses in your own login function and in `Main::pre_dispatch()` (`init()` is bootstrap and cannot eject anybody).

**CSRF is framework-handled** on every internal transport (Ajax, uploads, `@csrf`), portal included — never hand-roll one. **Never hand-roll `?redirect=` handling** either: `Login_Redirect` (PHP + identical JS mirror; `capture()`/`params()`/`hidden_input()`/`consume()`) is the ONE redirect sanitizer, staff/portal aware, degrading silently on hostile input.

**Turnstile is built in — do NOT install a captcha package.** `<Turnstile_Input />` renders a real hidden `__turnstile` field that is ALWAYS submitted (sentinel `'inactive'` while the feature is off), so the endpoint calls `Rsx_Turnstile::validate($request[, $params])` as the **FIRST statement of the POST branch** — unconditionally, before any credential or email work, so it gates enumeration too. Submitting the field without validating it throws "Turnstile implementation incomplete"; fix it, never silence it.

Skills: `rspade:session-auth` (references `csrf`, `login-redirect`) for login pages, device-session screens, admin termination, impersonation, lifecycle windows and the concurrent cap; `rspade:turnstile`. Details: `rsx:man session`, `rsx:man turnstile`.
