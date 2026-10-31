---
name: portal-app
description: "The client-portal screens this application ships - portal_main.php (the portal's init hook) and portal_permission.php (has_client_access / client_role / accessible_client_ids / is_read_only), the Blade auth ladder (login with its second-factor challenge, passkey sign-in and federated sign-in, register from an invite, password reset, impersonate stop, logout), Portal_Layout and Portal_Workspace_Layout, the dashboard, the workspace tabs (Overview / Requests / Documents) with the request-thread UI, invitations accept/decline, settings, and the #[Portal_Impersonation_Readable] marks that keep View-as-Client read-only. Use when adding or changing a portal screen or endpoint, wiring a new workspace tab, changing what an invite grants, branding the portal auth pages, or deciding which portal endpoints stay callable during impersonation (mark reads #[Portal_Impersonation_Readable])."
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
application code in a portal request. The SITE is already declared when it runs: the
framework resolves it from the portal URL (site 1 in single tenancy, the address's
`:slug` / `:site_id` / custom domain in multi) and `Portal_Session::get_site_id()`
answers it. `init()` only starts the portal session:

```php
public static function init(): void
{
    Portal_Session::init();
}
```

`pre_dispatch()` here performs **no authorization** - the `#[Auth]` gates have already
run by the time it is called.

`rsx/portal_permission.php` (`Portal_Permission extends Portal_Permission_Abstract`) is the
app's portal permission facade, app-side because its membership methods reference app
models:

| Method | Answers |
|---|---|
| `is_logged_in()` | the one gate check (`#[Auth('is_logged_in')]`) |
| `current_user()` / `current_user_id()` / `site_id()` | the acting identity |
| `has_client_access($client_id)` | is there a LIVE membership (its client portal-enabled and not deleted) |
| `client_role($client_id)` | VIEWER=1 / COLLABORATOR=2 |
| `can_collaborate($client_id)` | role >= COLLABORATOR |
| `accessible_client_ids()` | for `whereIn()` scoping |
| `is_read_only()` | the View-as-Client affordance - banner, disabled controls (returns `Portal_Session::is_impersonating()`) |

The parameterized ones are NOT gates (a gate takes no arguments) - they stay inline in
function bodies and in models' `portal_can_read()`.

## The screens

Every route below is a path INSIDE the portal. Where that is on the web is `PORTAL_URL`
(blank here: `/_portal/...` on the application host; `rsx:man portal`), so `/login` is
browsed at `/_portal/login` today - and every link to it is `Rsx_Portal::Route()`, never a
literal. **A link built on a staff page names the record's site and handles false**:
`Rsx_Portal::Route(..., site_id: $client->site_id)` answers false when that site has no client
portal (`PORTAL_ENABLED` off, `sites.is_portal_enabled` off, or no address the configuration
can serve), and the staff endpoint then refuses with "This site has no client portal." before
writing or sending anything (`Frontend_Clients_Controller`, `Portal_Invitation_Model::get_invitation_url()`
returning `string|false`, View as Client). In the browser the same answer is
`window.rsxapp.portal.base` null - the View as Client button hides on it. A site's portal is
switched on or off, and its custom domain set, on the staff Site Settings screen.

**Server-rendered auth ladder** (`rsx/portal/auth/`, Blade under
`portal_auth_layout.blade.php`, every controller `#[Auth('public')]`):

| Route | Controller | What it does |
|---|---|---|
| `/login` GET+POST | `Portal_Login_Controller` | email + password checked by `Portal_Auth::verify_credentials()` (a flagged `password_reset_required` account is refused toward "Forgot your password?"), TWO-STAGE when the portal user holds a second factor or `rsx.portal.emailed_sign_in_codes` is on (`Rsx_Portal_Two_Factor::begin_challenge()` with `challenge_accepts()`); the page also offers `<Passkey_Sign_In>` (Ajax `passkey_login`) and, when `rsx.sso.portal_enabled`, `<Sso_Buttons />` |
| `/login/verify` GET | `Portal_Login_Controller::verify` | the challenge: `<Two_Factor_Challenge>` posting to the Ajax `verify_2fa`, plus `send_code` (code AND sign-in link in `Portal_Sign_In_Code_Email`) when emailed codes are on; a signed-in visitor goes to their destination |
| `/register` GET+POST | `Portal_Register_Controller` | invite-code registration (the account is created `is_verified`, the invitation having proved the address); `invalid` / `expired` / `cancelled` pages |

**One completion function**: every portal sign-in - password, challenge, passkey, registration,
federated - finishes in `Portal_Login_Controller::complete_sign_in($portal_user, $sign_in,
$client_id)`, which signs in when the caller has not (`sign_in: true`), raises the welcome flash
and answers `destination()`. Nothing else here calls `Portal_Session::set_portal_user_id()`.
There is no portal self-signup and no public "request access" page: portal accounts come from
invitations only, extended by the site's staff (the client screen's invite and resend). That is
the template's recommended position; an application that wants a public request page adds its
own, and then owns the enumeration and invitation-refresh questions such a page raises.
| `/password/reset` and `/password/reset/:token` | `Portal_Password_Reset_Controller` | request + reset, with an `invalid` page |
| `/impersonate/stop` GET | `Portal_Impersonate_Controller` | ends a staff "View as Client" (signs the view out; the browser's own portal sign-in, if any, is current again); `stopped` page. Starting one is the staff endpoint `Frontend_Contacts_Controller::begin_portal_impersonation` -> `Portal_Session::begin_impersonation_from_staff()` |
| `/logout` GET | `Portal_Logout_Controller` | signs the portal account out of this browser (portal session values cleared); any staff sign-in stays |

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
gets nothing and is refused. A portal upload is refused by the framework too. A write the app makes on EVERY request is not an endpoint, so it guards itself: `portal_main.php` skips its activity stamp while `Portal_Session::is_impersonating()`, because a staff member looking is not the client being active:

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

**Only PENDING grants membership.** Admins **Cancel** (revoke) a pending invite on the per-client members screen. "Disable Access" removes a client membership (account, contact and login persist) - distinct from the de-emphasized global account suspend on the Portal Users admin screen. Force-password-reset is **not** a staff action in this app (self-service only); an operator sets the framework's `portal_users.password_reset_required` with `rsx:users:force_password_reset --portal-user=<id>`.

**A membership grants access only while its client is live.** "Disable Portal" (`clients.portal_enabled` off) and deleting the client revoke every member at once WITHOUT touching a membership row; reopening restores them. The portal side reads memberships only through `Portal_Membership_Model::has_membership()` / `find_for_user_and_client()` / `get_for_user()`, which count live rows only, so every `Portal_Permission` method and every `portal_can_read()` built on it inherits the rule. Staff screens and the code that CREATES a membership read the rows themselves (`has_membership_row()`, `get_all_for_user()`, `get_for_client()`), so accepting an invitation to a closed portal creates the row (no duplicate) and grants nothing until it reopens.

**A document share is per contact and expires.** A client document is visible to a portal user only while an unexpired `Shared_Item_Model` row names THEIR contact (`Shared_Item_Model::find_valid_share()`) and they hold a live membership of the client - on the Documents tab and on the file routes alike (`rsx/handlers/Portal_File_Access_Handlers.php`). A portal-realm file URL is answered for the PORTAL identity only; a staff session never opens one.

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
