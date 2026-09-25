# rsx/portal — the client portal

## WHAT IS HERE

The parallel application tree for external users, running beside `rsx/app/` with its own
dispatcher, bundle, layout, routing attributes and permission facade.

- `Portal_Spa_Controller.php` — the `@portal_spa` bootstrap; `Portal_Layout.{js,jqhtml}` +
  `portal_layout.scss` — the persistent chrome; `portal_bundle.php` — the module bundle.
- `auth/` — the server-rendered ladder: `Portal_Login_Controller`,
  `Portal_Logout_Controller`, `Portal_Register_Controller` (invite-based),
  `Portal_Password_Reset_Controller`, `Portal_Request_Access_Controller`,
  `Portal_Impersonate_Controller` (the staff "View as Client" claim and stop), plus
  `portal_auth_layout.blade.php` and the thirteen blades of their outcome states (the second-
  factor challenge page `portal_login_verify.blade.php` among them).
- `dashboard/` — `Portal_Dashboard_Action`, the landing page.
- `workspaces/` — the per-client area: a sublayout plus Overview / Requests / Documents and
  the request-thread screen. Own `CLAUDE.md`.
- `invitations/` — `portal_invitations_controller.php` (accept / decline).
- `notifications/` — `portal_notifications_controller.php` (feed, unread count, mark read),
  reading the framework's `Portal_Notification_Model`.
- `settings/` — `Portal_Settings_Action` and its controller, plus the page-private region
  `Portal_Settings_Security` (passkeys, authenticator app, recovery codes, connected accounts).
- `errors/` — `Portal_Errors_Controller` and two blades: the portal realm's full-page error
  screens, declared as `#[Portal_Route('/error/404')]` and `#[Portal_Route('/error/generic')]`
  and invoked by the framework, never linked to. See PORTAL ERROR PAGES below.

## HOW TO CUSTOMIZE

- **The site is declared, never resolved.** `rsx/portal_main.php` `init()` calls
  `Portal_Session::set_site_id()` from config because this app is mono-site; a multi-tenant
  portal resolves it from the host or the login flow in the same place. Nothing downstream
  hand-scopes a query.
- **`Portal_Main::pre_dispatch()` stamps portal activity on every client the caller belongs
  to, through `->raw_bulk()->update(...)`** — one raw UPDATE, deliberately firing no
  realtime frame and no per-record hook, because a timestamp bump on every portal click
  would otherwise churn every open staff client view. Follow that shape for any other
  per-request stamp; use an ordinary `save()` for anything a screen should react to.
- **Rebrand** the auth pages in `auth/portal_auth_layout.{blade.php,scss}` and the signed-in
  chrome in `portal_layout.scss`. The error pages in `errors/` extend `Portal_Auth_Layout`,
  so they follow that rebrand with no work of their own. The portal composes the SAME theme components as the staff
  app — see `rsx/theme/components/view/CLAUDE.md` and the other group files for the widget
  vocabulary rather than building portal-only variants.
- **"View as Client" is read-only by default, enforced by the framework.** While the session
  is impersonating, every portal Ajax endpoint is refused unless it is marked
  `#[Portal_Impersonation_Readable]`; mark each READ endpoint you add, and leave writes
  unmarked. A forgotten mark fails safe (the read is refused) rather than leaving a write
  open. `Portal_Permission::is_read_only()` drives the banner and disabled controls.
- Per-client rules are record-level predicates called in the endpoint body after the gates
  pass (`has_client_access()`, `can_collaborate()`, `client_role()`,
  `accessible_client_ids()`), all defined in `rsx/portal_permission.php`.

## PORTAL ERROR PAGES

A failure picks its realm from the FAILING request, so a portal URL that 404s, denies or
crashes renders `rsx/portal/errors/` — the portal bundle, the portal's auth chrome, and
`home_url` pointing at the portal rather than at the staff application. The staff twins in
`rsx/app/errors/` are a separate declaration and are never shared.

Two pages, not four: the portal declares `/error/404` and `/error/generic`, and every other
status falls to the generic one. A portal realm with neither would fall to the STAFF pair —
a page is better than no page — which is exactly what makes the portal's own pair worth
declaring.

The method is called directly with `($request, ['error' => $error])`, an
`App\RSpade\Core\Errors\Error_Context`: status, title, message, path, method, realm,
home_url, preview, detail. That is all a page may read — the failing request is over, the
caller may be anonymous, and the page is inspectable with curl. The portal pages
deliberately render NO exception detail even where the context carries it: the reader is a
client, and the trace is already in the log.

**In this template a signed-in portal user rarely sees the 404 server-side**, because
`Portal_Spa_Controller` declares `#[Portal_Route('/*')]` and the SPA answers an unknown path
client-side (`rsx/theme/components/feedback/errors/`). What reaches the page is an
`abort(404)`, a record that does not exist, and an unmatched URL from a visitor with no
portal session.

Preview in development (never served in a sealed build):

```bash
php artisan rsx:debug /_portal/error/404 --portal --portal-user=1
php artisan rsx:debug /_portal/error/generic --portal --portal-user=1
```

Adding a page for another status is a method with `#[Portal_Route('/error/<status>')]`, a
blade beside it, and `#[Auth('public')]`; `ROUTE-ERROR-01` fails the manifest build on a
malformed pattern. Deleting one falls back to generic, then to the staff pair, then to the
framework's own page.

## Key Differences from /rsx/app/

| Aspect | `/rsx/app/` | `/rsx/portal/` |
|--------|-------------|----------------|
| Users | Internal staff (Login_User_Model) | External users (Portal_User_Model) |
| Session | Session class | Portal_Session class |
| Routes | `#[Route]`, `#[SPA]` | `#[Portal_Route]`, `@portal_spa()` |
| URL Helper | `Rsx::Route()` / `Rsx.Route()` | `Rsx_Portal::Route()` / `Rsx_Portal.Route()` |
| Bundle | Frontend_Bundle, etc. | Portal_Bundle |

## Routing

### URL Strategy

**Development** (no `PORTAL_DOMAIN` configured):
- URLs prefixed with `/_portal/`
- Example: `/_portal/login`, `/_portal/dashboard`, `/_portal/settings`

**Production** (with `PORTAL_DOMAIN`):
- Dedicated domain, no prefix
- Example: `https://portal.example.com/login`

### Route Attributes

PHP controllers use `#[Portal_Route]`:

```php
#[Portal_Route('/login', methods: ['GET', 'POST'])]
public static function index(Request $request, array $params = []) { }
```

### SPA Actions

Use `@portal_spa()` decorator (NOT `@spa()`):

```javascript
@route('/settings')
@layout('Portal_Layout')
@portal_spa('Portal_Spa_Controller::index')
class Portal_Settings_Action extends Spa_Action { }
```

### JS Route Resolution

`Rsx_Portal.Route()` resolves both PHP controller routes and SPA action routes. PHP `#[Portal_Route]` attributes are automatically compiled into the Portal_Bundle during bundle compilation — no `include_routes` configuration needed.

```javascript
// PHP controller routes
Rsx_Portal.Route('Portal_Login_Controller')     // /_portal/login
Rsx_Portal.Route('Portal_Logout_Controller')    // /_portal/logout

// SPA action routes
Rsx_Portal.Route('Portal_Dashboard_Action')     // /_portal/dashboard
Rsx_Portal.Route('Portal_Settings_Action')      // /_portal/settings
```

The SPA only intercepts links to known SPA routes. Links to server-side controllers (login, logout, register) pass through as normal full-page navigations.

## Authentication

### Session Management

```php
Portal_Session::is_logged_in()
Portal_Session::get_portal_user()         // Portal_User_Model
Portal_Session::get_portal_user_id()
Portal_Session::get_site_id()             // throws if no site was declared
Portal_Session::set_portal_user_id($user_id)            // Login (uses the declared site)
Portal_Session::logout()
```

### Portal Site

The framework does NOT resolve which site the portal serves - the application
declares it with `Portal_Session::set_site_id()` before anything asks, and an
undeclared site throws. This app is mono-site and declares it in
`rsx/portal_main.php` `init()` from `config('rsx.portal.site_id')`. See
`php artisan rsx:man portal` (PORTAL SESSIONS).

### Auth Flow

- **Authorization is declarative**: `#[Auth]` on every `#[Portal_Route]` and `@auth` on
  every `@portal_spa` action, evaluated in the PORTAL realm before any application code.
  `Portal_Main::pre_dispatch()` performs NO authorization — it runs after the gates.
- Public surfaces declare `#[Auth('public')]`: login, register, request access, password
  reset, logout, the impersonation claim.
- Per-client rules are record-level predicates in the endpoint body, after the gates pass.

### Passkeys, second factors and federated sign-in

The portal realm has the framework's whole second-factor subsystem (`Rsx_Portal_Two_Factor`,
credentials in `_portal_two_factor_credentials`) and its own federated sign-in
(`Rsx_Portal_Sso`, links in `_portal_sso_identities`). Neither shares anything with the staff
realm but the provider registry, so a staff passkey or a staff Google link never signs anybody
in here. How this app uses them:

- **Password login is two-stage** in `Portal_Login_Controller::index()`: a portal user holding
  a second factor is parked with `Rsx_Portal_Two_Factor::begin_challenge()` (the invited-client
  id rides the session under `CLIENT_ID_KEY`) and sent to `/login/verify`, where
  `<Two_Factor_Challenge>` posts to `verify_2fa`.
- **Passwordless**: the login page's `<Passkey_Sign_In>` posts to `passkey_login`, which calls
  `Rsx_Portal_Two_Factor::verify_passkey_login()` - a complete sign-in, no second factor after.
- **Federated sign-in** appears on the login page only when `rsx.sso.portal_enabled` is true
  (off by default) and a provider is live. The policy is `rsx/handlers/Portal_Sso_Handlers.php`:
  a verified provider email that matches a portal user of this site is connected and signed in;
  anything else declines. Register the portal callback (`Rsx_Portal_Sso::callback_url('<key>')`)
  in each provider's console.
- Every way in lands on `Portal_Login_Controller::post_auth_destination()`.
- The framework components are realm-aware on their own - on a portal page they talk to the
  portal controllers - so this app passes them no realm argument.
- "View as Client" cannot enroll, remove, link or unlink anything: the framework refuses it.

## Portal Pages

### Dashboard (`/dashboard`)
Landing page for authenticated portal users.

### Settings (`/settings`)
User self-service page (composed from the shared theme components — see
`rsx/theme/components/view/CLAUDE.md`) with:
- **Change Password** — current password verification, min length, confirmation
- **Passkeys** — `Portal_Settings_Security`: the portal user's passkeys and authenticator app
  (remove), `<Passkey_Register />`, an on-demand `<Totp_Enrollment />`, the recovery-code count
  and regeneration
- **Connected Accounts** — same region, only when the portal realm offers federated sign-in:
  connections (disconnect) and `<Sso_Buttons $intent="link" />`
- **Team Members** — list of portal users sharing the same client membership
- **Active Sessions** — list sessions with IP, last activity, terminate button
- **Account Info** — email, status, last login, member since

### Auth Pages (Server-Side Blade)
- **Login** (`/login`) — email/password (no credential autofill: a portal account belongs to a
  client), plus passkey sign-in and, when enabled, federated sign-in
- **Two-factor challenge** (`/login/verify`) — `<Two_Factor_Challenge>` for a portal user
  holding a second factor
- **Registration** (`/register?code=X`) — invitation-based account creation
- **Password Reset** (`/password/reset`) — request + reset token flow
- **Logout** (`/logout`) — clears portal session, redirects to login

## Testing

```bash
# Test portal pages via rsx:debug
php artisan rsx:debug /_portal/dashboard --portal --portal-user=1
php artisan rsx:debug /_portal/settings --portal --portal-user=1 --screenshot-path=/tmp/portal.png
```

## Security Considerations

1. **Property Isolation**: there is ONE session per browser (one `rsx` cookie, one `_sessions` row).
   The portal identity lives in its own columns (`portal_user_id`, `portal_site_id`,
   `impersonator_user_id`); the staff identity in its own (`login_user_id`, `site_id`). Both set at
   once is normal. Never read or write the other experience's properties.
2. **Route Isolation**: Portal routes only accessible via portal context
3. **Data Access**: Use `portal_fetch()` methods for portal-safe data exposure
4. **CSRF**: one shared token per browser session - the same value both dispatchers verify

## RELATED

`workspaces/CLAUDE.md` · `rsx/portal_main.php`, `rsx/portal_permission.php` ·
`rsx/theme/components/view/CLAUDE.md` · app skill `portal-app` ·
skills `rspade:portal-core`, `rspade:auth-gates` · `rsx:man portal`
