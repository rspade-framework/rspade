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
  `portal_auth_layout.blade.php` and the twelve blades of their outcome states.
- `dashboard/` — `Portal_Dashboard_Action`, the landing page.
- `workspaces/` — the per-client area: a sublayout plus Overview / Requests / Documents and
  the request-thread screen. Own `CLAUDE.md`.
- `invitations/` — `portal_invitations_controller.php` (accept / decline).
- `notifications/` — `portal_notifications_controller.php` (feed, unread count, mark read),
  reading the framework's `Portal_Notification_Model`.
- `settings/` — `Portal_Settings_Action` and its controller.

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
  chrome in `portal_layout.scss`. The portal composes the SAME theme components as the staff
  app — see `rsx/theme/components/view/CLAUDE.md` and the other group files for the widget
  vocabulary rather than building portal-only variants.
- **Every mutating endpoint guards on `Portal_Permission::is_read_only()` itself.** Every
  Ajax call is a POST, reads included, so a blanket POST block would break the portal;
  read-only impersonation is application policy, and nothing adds the check for you.
- Per-client rules are record-level predicates called in the endpoint body after the gates
  pass (`has_client_access()`, `can_collaborate()`, `client_role()`,
  `accessible_client_ids()`), all defined in `rsx/portal_permission.php`.

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

## Portal Pages

### Dashboard (`/dashboard`)
Landing page for authenticated portal users.

### Settings (`/settings`)
User self-service page (composed from the shared theme components — see
`rsx/theme/components/view/CLAUDE.md`) with:
- **Change Password** — current password verification, min length, confirmation
- **Team Members** — list of portal users sharing the same client membership
- **Active Sessions** — list sessions with IP, last activity, terminate button
- **Account Info** — email, status, last login, member since

### Auth Pages (Server-Side Blade)
- **Login** (`/login`) — email/password, with credential autofill only when `RSPADE_LOGIN_AUTOFILL` is on
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
