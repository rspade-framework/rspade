---
name: portal
description: Building client-portal screens and access rules - #[Portal_Route] and @portal_spa routing, Portal_Main::init() site declaration, the Portal_Session facade over the shared session row, Portal_Permission plus portal_fetch()/portal_can_read() record rules, the internal-endpoint channel and Ajax.upload(), View-as-Client impersonation, and the template's invite/membership model. Use when adding a portal page or endpoint, when Portal_Session::get_site_id() throws, when a portal Ajax call resolves in the staff realm, when exposing a model to portal JavaScript, or when enforcing read-only during impersonation.
---

# Client Portal

A second authenticated experience for external users (clients, vendors), running **parallel** to the staff app with its own dispatcher, routing table and permission facade. The always-on fragment carries the mandates; this skill is how to build in it.

**Framework** (`App\RSpade\Core\Portal\`, `App\RSpade\Core\Models\`): `Portal_Session`, `Portal_Dispatcher`, `Rsx_Portal` (PHP+JS), `Portal_Main_Abstract`, `Portal_Permission_Abstract`, `Portal_User_Model`, `Portal_Notification_Model`, `#[Portal_Route]` / `@portal_spa` manifest support.
**Application** (`/rsx/portal/` + `rsx/portal_main.php`, `rsx/portal_permission.php`): the middleware, the SPA bootstrap, the layout, auth controllers, screens, and every app-specific model.

---

## Part A - Routing

**URL detection** is `config('rsx.portal.domain')` and `config('rsx.portal.prefix')`: with a domain set, the portal is every request on that host; with `domain` null, it is every request whose path starts with the prefix (default `/_portal`), which is stripped before routes are matched. `Rsx_Portal::is_portal_request()` / `Rsx_Portal.is_portal()` report context; `Rsx_Portal::get_normalized_path()` gives the prefix-free path.

### Server-rendered pages

```php
#[Portal_Route('/login', methods: ['GET','POST'])]
#[Auth('public')]
public static function index(Request $request, array $params = []) { }
```

Public static methods only; the pattern may contain `:params`; `methods` defaults to `['GET']`. These land in the manifest's **portal route table, entirely separate from the main `#[Route]` table**. Use them for login, register, password reset, logout. As with main routes, `$params` carries the GET query string AND the URL params (URL params win on a clash) - read query values from `$params`, never `$request->query()`. POST data is not in `$params`; use `$request`.

### Authenticated SPA screens

```javascript
/** @decorator */
@route('/projects/:id')
@layout('Portal_Layout')
@portal_spa('Portal_Spa_Controller::index')
@auth('is_logged_in')
class Portal_Project_View_Action extends Spa_Action { }
```

The portal counterpart of `@spa`: GET routes served through the single `Portal_Spa_Controller` bootstrap, navigating client-side. Nested layouts work exactly as in the staff SPA.

### URLs - mandatory, never hardcode

```php
Rsx_Portal::Route('Portal_Login_Controller')            // /_portal/login
Rsx_Portal::Route('Portal_Project_View_Action', 123)    // integer -> 'id'
Rsx_Portal::Route('...Action', ['id' => 1, 'tab' => 'x'])
Rsx_Portal::url($action, $params)                       // absolute
```

JS: `Rsx_Portal.Route(...)`, `Rsx_Portal.is_portal()`, `Rsx_Portal.user()`, `Rsx_Portal.is_impersonating()`.

---

## Part B - Portal_Main and the site declaration

One `rsx/portal_main.php` extending `Portal_Main_Abstract`:

| hook | when |
|---|---|
| `init()` | once per process, at the top of the first portal request - **the FIRST application code in the portal stack** |
| `pre_dispatch($req, $params)` | before every route, **AFTER the `#[Auth]` gates have already run**; return null to continue, a Response to halt |
| `unhandled_route($req, $params)` | no portal route matched; return null for the default 404 |

Order: `Portal_Main::init` -> dev auth -> CSRF -> route match -> **`#[Auth]` gates** -> `Portal_Main::pre_dispatch` -> controller `pre_dispatch` -> handler.

**`pre_dispatch` performs NO authorization.** It is for tenant setup, redirects, and the login-redirect capture.

### Declaring the site

**RSpade never resolves which site a portal request serves - no detection, no default, no hook.** `init()` runs before everything that might ask, which is why the declaration lives there:

```php
// rsx/portal_main.php - the mono-site recipe
public static function init(): void
{
    Portal_Session::set_site_id((int) config('rsx.portal.site_id'));  // an APP config key
    Portal_Session::init();
}
```

A flow that only learns the site later (an invite code, a workspace selection) declares it at that point instead:

```php
$invitation = Portal_Invitation_Model::find_by_code($params['code']);
Portal_Session::set_site_id((int) $invitation->site_id);   // before any site-scoped query
```

Rules: **request-scoped declaration only** - it creates nothing. Idempotent for the same id; **throws** on a different id or against a live session's site; a non-positive id throws. `get_site_id()` returns the session's site, else the declaration, else **throws** - **a portal that does not know its tenant must not pick one.** Login carries no site argument (`set_portal_user_id(?int)` uses the declared site). CLI/tests use the same call; `Portal_Session::reset()` clears it.

**Every framework site seam reads that declaration on a portal request** - `Rsx_Site_Model_Abstract::get_current_site_id()` (global scope, forced `site_id` on create, cross-site fatals, site write lock), plus `Rsx_Mail`, `Rsx_Sms`, `Rsx_Time` (the site-timezone tier; portal accounts have no personal zone), `Rsx_Settings`, `Rsx_Throttle`. **So portal code never hand-scopes a query** with `where('site_id', ...)`, and a staff `Session::set_site_id()` is the STAFF app's tenancy only.

---

## Part C - Portal_Session

`Portal_Session` is a static **FACADE over the PORTAL PROPERTIES of the one session the browser has** - not a second session, not a model. There is no `Portal_Session::where()`, no instances, no portal session table.

```php
Portal_Session::init(): void                  // load from cookie (no create)
Portal_Session::is_logged_in(): bool
Portal_Session::has_session(): bool           // creates nothing
Portal_Session::get_user() / get_portal_user()      // ?Portal_User_Model
Portal_Session::get_portal_user_id(): ?int
Portal_Session::set_site_id(int): void        // declare (Part B)
Portal_Session::get_site_id(): int            // THROWS when nothing declared one
Portal_Session::set_portal_user_id(?int): void// log in / log out
Portal_Session::logout(): void
Portal_Session::get_session_id(): int         // the browser's session id (SHARED with Session)
Portal_Session::get_csrf_token(): ?string     // the browser's ONE token (SHARED)
Portal_Session::verify_csrf_token(string): bool
Portal_Session::reset(): void                 // CLI/test clean slate

// device sessions ("your sessions" screen)
Portal_Session::get_sessions_for_user(?int $id = null): array
Portal_Session::terminate_session(int $id): bool      // OWN sessions only, fail-closed
Portal_Session::terminate_all_other_sessions(): int
Portal_Session::terminate_all_sessions_for_user(int $id, ?int $except = null): int
```

**A portal session ENDS by CLEARING the portal properties.** `logout()`, the terminate calls, the sign-in cap and `stop_impersonation()` all clear; **none of them deletes or deactivates the row**, because that row is the browser's session and may carry a staff login. `get_sessions_for_user()` excludes the "View as Client" handoff rows - single-use transport, not devices.

**Never read or write the staff properties from portal code, and never the portal properties from main-app code.** That rule is for APPLICATION code: the framework surfaces serving both experiences resolve the experience themselves, so portal code writes them exactly like staff code -

```php
Flash_Alert::success('Welcome to the Client Portal!');   // this session; stamped as portal
@csrf                                                    // this session's one token
```

**Do not hand-roll a portal variant of either.** A portal-specific CSRF input or flash mechanism reintroduces the coupling these surfaces exist to hide.

**When app code must fork, fork on `Rsx_Portal::is_portal_request()` - never on `is_logged_in()`. Identity is not experience**: both identities set at once on one row is NORMAL (same human, same browser).

---

## Part D - Authorization

### Gates

Declarative, exactly like staff: every portal surface carries `#[Auth(...)]` / `@auth(...)` naming **portal-realm** checks, evaluated by `Portal_Dispatcher` before any application code, and the manifest build FAILS on a surface with none. Public controllers (login, register, password reset, logout) carry `#[Auth('public')]`.

**The gate vocabulary is just the two inherited built-ins** - `public` and `is_logged_in` (resolving against `Portal_Session`). **Every per-client rule is parameterized and therefore record-layer, not a gate.** There is **no `route_is_exempt()` and no `@portal-auth-exempt`** - both are dead syntax.

Realm resolution: an `#[Ajax_Endpoint]` controller under `rsx/portal/` or `app/RSpade/Core/Portal/` defaults to the portal realm; anywhere else, declare `#[Auth_Realm('portal')]`. See `rspade:auth-gates`.

### Portal_Permission

The portal analogue of the staff `Permission`. The marker base `Portal_Permission_Abstract` is framework core; the concrete `Portal_Permission` lives in the app (`rsx/portal_permission.php`) because its membership methods reference app models. All static:

```php
Portal_Permission::is_logged_in(): bool            // also #[Auth_Check] - nameable from #[Auth]
Portal_Permission::current_user(): ?Portal_User_Model
Portal_Permission::current_user_id(): ?int
Portal_Permission::site_id(): int
Portal_Permission::has_client_access(int $client_id): bool
Portal_Permission::client_role(int $client_id): ?int      // VIEWER=1 / COLLABORATOR=2
Portal_Permission::can_collaborate(int $client_id): bool  // role >= COLLABORATOR
Portal_Permission::accessible_client_ids(): array         // for whereIn() scoping
Portal_Permission::can_access(string $target): bool
```

The parameterized helpers are NOT gates (a gate takes no arguments) - they stay inline in function bodies. JS mirror `Portal_Permission.js` reads `window.rsxapp.portal` (`is_auth`, `user`, `client_roles`) and is for **hiding UI affordances only**; the server is the enforcement boundary.

### Record level: portal_fetch() + portal_can_read()

```php
use App\RSpade\Core\Portal\Portal_Authorizable;

class Project_Model extends Rsx_Site_Model_Abstract
{
    use Portal_Authorizable;

    public function portal_can_read(): bool
    {
        return Portal_Permission::has_client_access($this->client_id);
    }
}
```

The trait supplies a gated `portal_fetch($id)` that (1) requires a portal session and (2) defers to `portal_can_read()`. **The single ORM endpoint dispatches to `portal_fetch()` in a portal request and `fetch()` otherwise** - one ORM, the method chosen by context.

`portal_can_read()` is **FAIL-CLOSED** and picks **exactly ONE** visibility rule:

| rule | shape |
|---|---|
| own-record | `$this->id === Portal_Session::get_portal_user_id()` |
| shared-recipient | the row's recipient matches the portal user's linked contact |
| membership-scoped | `Portal_Permission::has_client_access($this->client_id)` |

Core models' implementations use `Portal_Session`; app models' use `Portal_Permission` (no core-to-app dependency).

**`PORTAL-MODEL-FETCH-01`** (`rsx:check`): a model exposing `portal_fetch()` must declare `portal_can_read()`, and a hand-rolled `portal_fetch()` must call it. This is the record layer, which gates deliberately do not cover.

---

## Part E - The internal-endpoint channel

The three framework transports carry BOTH a `#[Route]` and a `#[Portal_Route]`, served by the same handlers:

```
/_ajax/:controller/:action    Ajax_Endpoint_Controller::dispatch
/_ajax/_batch                 Ajax_Batch_Controller::batch
/_upload                      File_Attachment_Controller::upload
```

A portal page calls them under the portal's own base - `/_portal/_ajax/...` in prefix mode, `/_ajax/...` on a portal domain. The client derives it:

```javascript
Rsx_Portal.internal_url('/_ajax/Foo/bar');
//   staff page               -> /_ajax/Foo/bar
//   portal page, prefix mode -> /_portal/_ajax/Foo/bar
//   portal page, domain mode -> /_ajax/Foo/bar
```

`Ajax.call()` (and therefore every generated controller stub) already goes through it. **Multipart uploads MUST go through `Ajax.upload(form_data)`** - it owns both the channel and the CSRF header, because multipart cannot ride the `$.ajax` chokepoint that normally attaches it. **A raw `fetch('/_upload')` hardcodes the staff path and sends no CSRF token.**

**Why it matters**: on the portal channel the request IS a portal request - CSRF verifies against the portal session, the gates evaluate in the portal realm, and `Orm_Controller` resolves `portal_fetch()`. Calling the bare staff path from a portal page gets the staff realm for all three, and the Ajax and ORM seams additionally REFUSE the cross-realm call outright. **This is what makes prefix mode behave like domain mode.**

---

## Part F - Impersonation ("View as Client")

Staff open the portal AS a contact to see exactly what that client sees, in a READ-ONLY session. (The staff-on-staff equivalent is a same-cookie in-place swap and is full read/write - see `rspade:session-auth`.)

Tracked as PROPERTIES on the browser's session row, **never in the cookie**: `impersonator_user_id` (staff `User_Model` id; NULL = a normal portal login), `impersonation_started_at`, `handoff_token`, `handoff_expires_at`.

**Cross-domain-safe handoff** - staff are on the main domain and cannot set the portal cookie directly:

1. A staff endpoint calls `Portal_Session::create_impersonation_session($portal_user_id, $staff_user_id, $site_id)` -> a single-use handoff token. It inserts a single-use HANDOFF row (pure transport, excluded from device lists), sets no cookie, and **does NOT touch the contact's `last_login`**.
2. Staff JS opens `Rsx_Portal::Route('Portal_Impersonate_Controller::claim', ['t' => $token])` with `target=_blank`.
3. The claim route (`#[Auth('public')]`) calls `Portal_Session::claim_impersonation($token)`: validate + burn, stamp the impersonated portal identity onto **the claiming browser's own session** (minting one if it has none), redirect to the dashboard. Any portal identity already in that browser is replaced; the staff login on the same session is untouched. An expired/used token renders an "invalid link" page.

**Never put the real session token in a URL** - the single-use, short-TTL handoff token exists for exactly this reason.

```php
Portal_Session::create_impersonation_session($portal_user_id, $impersonator_user_id, $site_id): string
Portal_Session::claim_impersonation(string $handoff_token): bool
Portal_Session::stop_impersonation(): void
Portal_Session::is_impersonating(): bool
Portal_Session::get_impersonator_user_id(): ?int
```

### READ-ONLY IS THE APP'S JOB

**The framework only exposes `is_impersonating()`.** Two duties:

**1. Block writes, per endpoint.** **A blanket `pre_dispatch` POST block does NOT work** - all Ajax endpoints are POST, reads included (datagrids, profile, model fetch), so a blanket block breaks the portal's ability to even load a page.

```php
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
public static function reply(Request $request, array $params = [])
{
    if (Portal_Permission::is_read_only()) {
        return response_unauthorized('This is a read-only session; changes are disabled.');
    }
    // ... normal write
}
```

The template adds `Portal_Permission::is_read_only()` (returning `Portal_Session::is_impersonating()`) and guards every portal write it ships. **A real app must guard ALL of its writes - an unguarded endpoint stays writable.**

**2. Show a read-only experience.** A persistent banner and disabled submit controls when `Rsx_Portal.is_impersonating()`, with an "Exit read-only view" link to the stop route. **The server check is the boundary; the JS is affordance only.**

Who may impersonate is the app's call (the template gates it on `Permission::has_role(User_Model::ROLE_MANAGER)`).

---

## Part G - Accounts, invites and memberships

`Portal_User_Model` (framework-core, table `portal_users`, site-scoped) is the **account/identity**: `STATUS_*`, `can_login()`, `check_password()`, `set_password()`, `touch_last_login()`, `find_by_email($site_id, $email)`, plus `portal_fetch()`/`portal_can_read()` (own record only). Override it via the class-override pattern, and **keep app concepts (CRM links, memberships) in separate app models**, never bolted onto the core model.

**Onboarding is invite-only by default** - no open self-registration. Staff invite a contact, a single-use expiring code is emailed, the recipient sets a password. **The invitation proves email ownership, so accounts are created already verified** (`is_verified = true`) with no separate verification step. **An app adding open signup must verify the email itself.**

### Reference implementation (the shipped template) - your app may differ

Everything below is the template app's model, not a framework contract. `Portal_Membership_Model`, `Portal_Invitation_Model` and the client concept are APP models.

**Account is not access.** The account is the login (with self-service password - the firm never manages portal passwords); a per-client **membership** is the access layer. One account can hold many client memberships, and authorization is membership-based. A clientless account may still sign in (the dashboard shows a "no active client access" empty state).

**Invites are per-client.** A new person's registration link onboards the account AND joins the invited client. Inviting an **existing** account creates a **pending per-client invitation** the logged-in user must **Accept** (Accept creates the membership, Decline consumes it) - **no auto-add**.

Invitations carry `status_id` (PENDING/USED/EXPIRED/REVOKED) and expire after `rsx.portal.invitation_expiry_days` (framework default 7; the template overrides to 14), flipped PENDING -> EXPIRED by an hourly task. Following a link never dead-ends on "invalid" unless the code is bogus or REVOKED:

| state | outcome |
|---|---|
| existing account | login page (`message=account_exists`, forgot-password offered) |
| live PENDING | register + join the invited client + land on its workspace |
| EXPIRED | **account-only** creation (verified, NO membership - an admin re-invites) |
| REVOKED | "cancelled" page |

**Only PENDING grants membership.** Admins **Cancel** (revoke) a pending invite on the per-client members screen. "Disable Access" removes a client membership (account, contact and login persist) - distinct from the de-emphasized global account suspend on the Portal Users admin screen. Force-password-reset is **not** a staff action (self-service only).

---

## Part H - Notifications (the framework-core activity primitive)

`App\RSpade\Core\Models\Portal_Notification_Model` is the bus portal features emit into - the dashboard "what's new" feed, announcements, request activity. **One row per recipient**: emitting to N users writes N rows, which is the natural shape for a per-user feed with read state. Site-scoped, and `portal_can_read()` is own-recipient (fail-closed).

**App-agnostic by design**: there is NO hard FK to application tables. The recipient is core (`portal_users`), and an app-specific subject is referenced **polymorphically** through the type-ref system (`subject_type` + `subject_id`). **Never FK the primitive to an app table.**

```php
Portal_Notification_Model::emit($portal_user_ids, string $type, array $opts = []);
//   $opts: subject_type (class basename), subject_id, payload (array), site_id
Portal_Notification_Model::feed($portal_user_id, ['limit' => 50, 'unread_only' => true, 'since' => $iso]);
Portal_Notification_Model::unread_count($portal_user_id);
Portal_Notification_Model::mark_read($portal_user_id, $id);      // ownership-guarded, idempotent
Portal_Notification_Model::mark_all_read($portal_user_id);
```

`emit()` works from a staff request (an announcement broadcast) or a portal request alike, because the row's own `site_id` scopes both the write and the realtime publish. Every read/mutate call takes the recipient id and scopes to it - a user can only ever see or change their own. The shipped `Portal_Notifications_Controller` **derives the recipient from the session and never accepts a `portal_user_id` from the client**; do the same in anything you add.

---

## Part I - Config and testing

`config('rsx.portal.*')`: `domain`, `prefix`, `session_lifetime_days` (30), `invitation_expiry_days`, `password_min_length` (8), `password_reset_expiry_hours` (1). **There is NO framework key for the portal's site**, deliberately - a mono-site app keeps its own (the template's `rsx.portal.site_id`) and a multi-tenant app has none at all.

```bash
php artisan rsx:debug /dashboard --portal --portal-user=1
php artisan rsx:debug /_portal/mail --portal --portal-user=client@x.com
```

`--portal` selects the portal stack; `--portal-user` takes an id or email; the path works with or without the prefix; disabled in production. **The harness declares no site of its own** - it browses the portal the application serves, so the site is whatever `Portal_Main::init()` declared, and a `--portal-user` belonging to a DIFFERENT site is refused loudly rather than signed in against the served tenant.

In PHP tests (CLI), declare the site exactly as the app does:

```php
Portal_Session::set_site_id(self::SITE_ID);
Portal_Session::cli_set_portal_user_id($portal_user->id);   // 0 = signed out
// ...
Portal_Session::reset();                                    // clean slate
```

---

## Troubleshooting

- **`Portal_Session::get_site_id()` throws.** Nothing declared the site for this request. Add the `set_site_id()` call to `Portal_Main::init()`, or to the flow that learns the site (invite, workspace). The throw is deliberate - the framework will not guess a tenant.
- **`set_site_id()` throws on a value you thought was fine.** It is idempotent only for the SAME id; a different id, or any id against a live session's site, throws. Declare once, early.
- **A portal Ajax call is denied, or resolves staff data.** The request went to the bare `/_ajax/...` path, or the controller resolved to the staff realm. Use the generated stubs / `Rsx_Portal.internal_url()`, and declare `#[Auth_Realm('portal')]` on any portal controller outside a portal root.
- **An upload from a portal page 419s or hits the staff path.** It used `fetch('/_upload')`. Use `Ajax.upload(form_data)`.
- **A model returns "not found" to portal JS.** Either it has no `portal_fetch()` (add `Portal_Authorizable` + `portal_can_read()`), or `portal_can_read()` returned false - which is what it should do, fail-closed, for a record outside the user's memberships.
- **Staff logged out when a portal session ended (or vice versa).** Something deleted or deactivated the row instead of clearing the experience's properties. Use the facade; never write session rows by hand.
- **Writes still succeed while impersonating.** Read-only is the app's job - guard each mutating endpoint on `Portal_Permission::is_read_only()`.

Details: `php artisan rsx:man portal`. Related: `rspade:auth-gates`, `rspade:session-auth`, `rspade:model-fetch`.
