# Client Portal Module

## Purpose

The `/rsx/portal/` directory contains all client portal pages and components. This is the portal equivalent of `/rsx/app/` - a parallel application tree for external users (customers, clients, vendors).

## Directory Structure

```
/rsx/portal/
├── CLAUDE.md                      # This file
├── portal_bundle.php              # Portal asset bundle
├── Portal_Spa_Controller.php      # SPA bootstrap controller
├── Portal_Layout.js               # Portal layout component
├── Portal_Layout.jqhtml           # Portal layout template
├── portal_layout.scss             # Portal layout styles
├── auth/                          # Authentication pages (server-side Blade)
│   ├── Portal_Login_Controller.php
│   ├── Portal_Logout_Controller.php
│   ├── Portal_Register_Controller.php
│   ├── Portal_Password_Reset_Controller.php
│   └── *.blade.php                # Auth page templates
├── dashboard/                     # Portal dashboard (SPA action)
│   ├── Portal_Dashboard_Action.js
│   └── Portal_Dashboard_Action.jqhtml
└── settings/                      # Portal user settings (SPA action)
    ├── portal_settings_controller.php  # Ajax endpoints
    ├── Portal_Settings_Action.js
    └── Portal_Settings_Action.jqhtml
```

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

- `portal_main.php` `pre_dispatch()` checks auth for all non-public routes
- Public routes: login, register, password reset, logout
- SPA controller `pre_dispatch()` redirects unauthenticated users to login

## Portal Pages

### Dashboard (`/dashboard`)
Landing page for authenticated portal users.

### Settings (`/settings`)
User self-service page with:
- **Change Password** — current password verification, min length, confirmation
- **Team Members** — list of portal users sharing the same client membership
- **Active Sessions** — list sessions with IP, last activity, terminate button
- **Account Info** — email, status, last login, member since

### Auth Pages (Server-Side Blade)
- **Login** (`/login`) — email/password with dev auto-fill on debug sites
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
