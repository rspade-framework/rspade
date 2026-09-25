---
name: portal-app
description: "The client-portal screens this application ships - portal_main.php (the site declaration) and portal_permission.php (has_client_access / client_role / accessible_client_ids / is_read_only), the Blade auth ladder (login with its second-factor challenge, passkey sign-in and federated sign-in, register from an invite, request-access, password reset, impersonate claim/stop, logout), Portal_Layout and Portal_Workspace_Layout, the dashboard, the workspace tabs (Overview / Requests / Documents) with the request-thread UI, invitations accept/decline, settings, and the #[Portal_Impersonation_Readable] marks that keep View-as-Client read-only. Use when adding or changing a portal screen or endpoint, wiring a new workspace tab, changing what an invite grants, branding the portal auth pages, or deciding which portal endpoints stay callable during impersonation (mark reads #[Portal_Impersonation_Readable])."
---

# The portal application

> **Living skill.** This skill ships with the template application and is yours. It describes
> the CURRENT state of `rsx/portal/`, `rsx/portal_main.php` and `rsx/portal_permission.php`; the
> directory files `rsx/portal/CLAUDE.md` and `rsx/portal/workspaces/CLAUDE.md` are its
> companions. When this feature changes, update this skill and those files in the same pass.

The portal MACHINERY is framework core and is not yours: `Portal_Session`,
the portal realm of the `Dispatcher`, `Rsx_Portal`, `Portal_Main_Abstract`, `Portal_Permission_Abstract`,
`Portal_Authorizable`, `Portal_User_Model`, `Portal_Notification_Model`,
`#[Portal_Route]` / `@portal_spa`. Skill `rspade:portal-core` is that contract, and
`rsx:man portal` is the spec. **Everything below is application code you may change,
extend or delete.**

## The two hook files

`rsx/portal_main.php` (`Portal_Main extends Portal_Main_Abstract`) is the earliest
application code in a portal request, which is why it **declares the site**:

```php
public static function init(): void
{
    Portal_Session::set_site_id((int) config('rsx.portal.site_id'));  // an APP config key
    Portal_Session::init();
}
```

This app is MONO-SITE. A multi-tenant portal resolves the site from the request (host,
invite code, chosen workspace) and makes the same call from wherever it learns the answer;
the only rule is that it is declared before anything asks. `pre_dispatch()` here performs
**no authorization** - the `#[Auth]` gates have already run by the time it is called.

`rsx/portal_permission.php` (`Portal_Permission extends Portal_Permission_Abstract`) is the
app's portal permission facade, app-side because its membership methods reference app
models:

| Method | Answers |
|---|---|
| `is_logged_in()` | the one gate check (`#[Auth('is_logged_in')]`) |
| `current_user()` / `current_user_id()` / `site_id()` | the acting identity |
| `has_client_access($client_id)` | is there a membership |
| `client_role($client_id)` | VIEWER=1 / COLLABORATOR=2 |
| `can_collaborate($client_id)` | role >= COLLABORATOR |
| `accessible_client_ids()` | for `whereIn()` scoping |
| `is_read_only()` | the View-as-Client affordance - banner, disabled controls (returns `Portal_Session::is_impersonating()`) |

The parameterized ones are NOT gates (a gate takes no arguments) - they stay inline in
function bodies and in models' `portal_can_read()`.

## The screens

**Server-rendered auth ladder** (`rsx/portal/auth/`, Blade under
`portal_auth_layout.blade.php`, every controller `#[Auth('public')]`):

| Route | Controller | What it does |
|---|---|---|
| `/login` GET+POST | `Portal_Login_Controller` | email + password, TWO-STAGE when the portal user holds a second factor (`Rsx_Portal_Two_Factor::begin_challenge()`); the page also offers `<Passkey_Sign_In>` (Ajax `passkey_login`) and, when `rsx.sso.portal_enabled`, `<Sso_Buttons />` |
| `/login/verify` GET | `Portal_Login_Controller::verify` | the second-factor challenge: `<Two_Factor_Challenge>` posting to the Ajax `verify_2fa` |
| `/register` GET+POST | `Portal_Register_Controller` | invite-code registration; `invalid` / `expired` / `cancelled` pages |
| `/request-access` GET+POST | `Portal_Request_Access_Controller` | a would-be user asks for an invite; resends a live one |
| `/password/reset` and `/password/reset/:token` | `Portal_Password_Reset_Controller` | request + reset, with an `invalid` page |
| `/impersonate/claim` and `/impersonate/stop` GET | `Portal_Impersonate_Controller` | burns the staff handoff token; `invalid` / `stopped` pages |
| `/logout` GET | `Portal_Logout_Controller` | clears the portal properties only |

**SPA screens** (`@portal_spa('Portal_Spa_Controller::index')`, `@auth('is_logged_in')`):

| Route | Action | Layouts |
|---|---|---|
| `/` and `/dashboard` | `Portal_Dashboard_Action` | `Portal_Layout` |
| `/settings` | `Portal_Settings_Action` | `Portal_Layout` |
| `/workspace/:id` | `Portal_Workspace_Overview_Action` | + `Portal_Workspace_Layout` |
| `/workspace/:id/requests` | `Portal_Workspace_Requests_Action` | + `Portal_Workspace_Layout` |
| `/workspace/:id/requests/:thread_id` | `Portal_Request_Thread_Action` | + `Portal_Workspace_Layout` |
| `/workspace/:id/documents` | `Portal_Workspace_Documents_Action` | + `Portal_Workspace_Layout` |

**Ajax controllers**: `portal_workspaces_controller.php` (`list`, `get`),
`portal_request_threads_controller.php` (`list`, `get`, `reply`,
`needs_response_for_user`), `portal_documents_controller.php` (`list`),
`portal_invitations_controller.php` (`pending`, `accept`, `decline`),
`portal_notifications_controller.php`, `portal_settings_controller.php`
(`get_profile`, `change_password`, `get_sessions`, `terminate_session`).

## The two layouts

`Portal_Layout` is the persistent shell - header, nav, footer, and the `$sid="content"`
element every action renders into. It also paints the read-only banner when
`Rsx_Portal.is_impersonating()`.

`Portal_Workspace_Layout` is a NESTED layout (the portal analogue of the staff
`Settings_Layout`): the engagement header, the vertical pills nav for Overview / Requests /
Documents, and its own `$sid="content"`. **It is parameterized by the route `:id`** - the
SPA reuses one layout instance by class name across navigations, so the layout takes no
construction args and reads the current `:id` from `on_action(url, action_name, args)` on
every dispatch. Copy that pattern for any new parameterized sublayout.

Adding a workspace tab: a new action under `rsx/portal/workspaces/<tab>/` with both
`@layout`s and `@route('/workspace/:id/<tab>')`, plus its nav entry in
`Portal_Workspace_Layout`.

## Read-only ("View as Client"): mark the reads

The framework refuses every portal Ajax endpoint while the session is impersonating unless
the endpoint declares `#[Portal_Impersonation_Readable]` - deny by default, because every
Ajax call is a POST and a write cannot be told from a read by its verb. **Every READ endpoint
you add gets the mark** (or "View as Client" cannot load the screen that calls it); a write
gets nothing and is refused:

```php
#[Ajax_Endpoint]
#[Portal_Impersonation_Readable]
public static function list(Request $request, array $params = []) { ... }
```

Marked in this app: `Portal_Workspaces_Controller` (`get`, `list`),
`Portal_Documents_Controller::list`, `Portal_Request_Threads_Controller` (`get`, `list`,
`needs_response_for_user`), `Portal_Notifications_Controller` (`feed`, `unread_count`),
`Portal_Invitations_Controller::pending` and `Portal_Settings_Controller` (`get_profile`,
`get_sessions`). The writes (`reply`, `mark_read`, `mark_all_read`, invitation `accept` /
`decline`, `change_password`, `terminate_session`) carry no mark and no guard of their own.
The passkey, authenticator-app and connected-account controls on Settings need nothing of
this app's either: the framework marks only their list endpoints. The banner and the
disabled controls (`Portal_Permission::is_read_only()`) are affordance only; the server
refusal is the boundary. A `#[Portal_Route]` page that handles a POST is not an Ajax
endpoint and guards itself on `Portal_Session::is_impersonating()`.

## Accounts, invites and memberships (this app's model)

`Portal_Membership_Model`, `Portal_Invitation_Model` and the client/engagement concept are
APP models in `rsx/models/` - none of it is a framework contract, and an app with a
different access model replaces it wholesale.

**Account is not access.** The account is the login (with self-service password - the firm never manages portal passwords); a per-client **membership** is the access layer. One account can hold many client memberships, and authorization is membership-based. A clientless account may still sign in (the dashboard shows a "no active client access" empty state).

**Invites are per-client.** A new person's registration link onboards the account AND joins the invited client. Inviting an **existing** account creates a **pending per-client invitation** the logged-in user must **Accept** (Accept creates the membership, Decline consumes it) - **no auto-add**.

Invitations carry `status_id` (PENDING/USED/EXPIRED/REVOKED) and expire after `rsx.portal.invitation_expiry_days` (framework default 7; this app overrides to 14), flipped PENDING -> EXPIRED by an hourly task. Following a link never dead-ends on "invalid" unless the code is bogus or REVOKED:

| state | outcome |
|---|---|
| existing account | login page (`message=account_exists`, forgot-password offered) |
| live PENDING | register + join the invited client + land on its workspace |
| EXPIRED | **account-only** creation (verified, NO membership - an admin re-invites) |
| REVOKED | "cancelled" page |

**Only PENDING grants membership.** Admins **Cancel** (revoke) a pending invite on the per-client members screen. "Disable Access" removes a client membership (account, contact and login persist) - distinct from the de-emphasized global account suspend on the Portal Users admin screen. Force-password-reset is **not** a staff action (self-service only).

## Record-level access

Every app model reachable from portal JavaScript uses `Portal_Authorizable` and declares a
fail-closed `portal_can_read()` built on `Portal_Permission` (never on `Portal_Session`,
so the core stays app-independent). `PORTAL-MODEL-FETCH-01` enforces the pair.

```php
public function portal_can_read(): bool
{
    return Portal_Permission::has_client_access($this->client_id);
}
```

## Testing a portal screen

```bash
php artisan rsx:debug /dashboard --portal --portal-user=1
php artisan rsx:debug /_portal/workspace/1/requests --portal --portal-user=client@example.com
```

Related: `rspade:portal-core` (the framework contract), `rspade:auth-gates`,
`rspade:model-fetch`, `rspade:rsx-debug`, app skills `form-components`, `modals`,
`semantic-components`. Contract: `php artisan rsx:man portal`.
