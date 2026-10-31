# rsx/portal — the client portal

## WHAT IS HERE

The parallel application tree for external users, running beside `rsx/app/` with its own
dispatcher, bundle, layout, routing attributes and permission facade.

- `Portal_Spa_Controller.php` — the `@portal_spa` bootstrap; `Portal_Layout.{js,jqhtml}` +
  `portal_layout.scss` — the persistent chrome; `portal_bundle.php` — `Portal_Bundle`,
  the bundle of the SIGNED-IN portal (the whole theme, `rsx/models`, `rsx/lib`, this tree).
- `auth/` — the server-rendered ladder: `Portal_Login_Controller`,
  `Portal_Logout_Controller`, `Portal_Register_Controller` (invite-based),
  `Portal_Share_Link_Controller` (`/shared/:token`, where a share link leads),
  `Portal_Password_Reset_Controller`,
  `Portal_Impersonate_Controller` (the staff "View as Client" stop; starting one is the
  framework's `Portal_Session::begin_impersonation_from_staff()`), plus
  `portal_auth_layout.blade.php` and the seven blades of their outcome states (the second-
  factor challenge page `portal_login_verify.blade.php` among them), and
  `portal_auth_bundle.php` — `Portal_Auth_Bundle`, the bundle those pages and the error
  pages render (see TWO BUNDLES below).
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

- **The site is the framework's, read from the portal URL.** `rsx/portal_main.php` declares
  nothing; by `init()` the framework has resolved the request's site and declared it (site 1
  in single tenancy). Nothing downstream hand-scopes a query.
- **`Portal_Main::pre_dispatch()` stamps portal activity on every client the caller belongs
  to - except during View as Client, which is the staff member looking, not the client
  being active - through `->raw_bulk()->update(...)`** — one raw UPDATE, deliberately firing no
  realtime frame and no per-record hook, because a timestamp bump on every portal click
  would otherwise churn every open staff client view. Follow that shape for any other
  per-request stamp; use an ordinary `save()` for anything a screen should react to.
- **The human-verification widget sits below the auth card**, centred, 22px under it
  (`$auth-below-card-gap`): each auth page puts `<Turnstile_Input $form="<its form id>" />` in
  the layout's `below_card` section, so it stays out of the card and still posts with the native
  form - on a page with several states, only in the state that renders the form.
- **Rebrand** the auth pages in `auth/portal_auth_layout.{blade.php,scss}` and the signed-in
  chrome in `portal_layout.scss`. The error pages in `errors/` extend `Portal_Auth_Layout`,
  so they follow that rebrand with no work of their own. The portal composes the SAME theme components as the staff
  app — see `rsx/theme/components/view/CLAUDE.md` and the other group files for the widget
  vocabulary rather than building portal-only variants.
- **"View as Client" is read-only by default, enforced by the framework.** While the session
  is impersonating, every portal Ajax endpoint is refused unless it is marked
  `#[Portal_Impersonation_Readable]`; mark each READ endpoint you add, and leave writes
  unmarked. A forgotten mark fails safe (the read is refused) rather than leaving a write
  open. A portal upload is refused too, by the framework's upload path. `Portal_Permission::is_read_only()` drives the banner and disabled controls.
- Per-client rules are record-level predicates called in the endpoint body after the gates
  pass (`has_client_access()`, `can_collaborate()`, `client_role()`,
  `accessible_client_ids()`), all defined in `rsx/portal_permission.php`.

## TWO BUNDLES: SIGNED-IN AND SIGN-IN

**The portal's sign-in pages and error pages are reachable without signing in, so they do
not render `Portal_Bundle`.** A bundle is downloaded by everybody who can load any page
that renders it; `Portal_Bundle` carries every portal screen, every model and the component
library, and is for portal users who HAVE signed in. `auth/portal_auth_layout.blade.php`
prints `Portal_Auth_Bundle` instead, which names its assets by file: the theme's global
stylesheets (`rsx/theme/variables.scss`, `responsive.scss`, the Bootstrap build, then
`'rsx/theme/*.scss'`), `auth/` and `errors/`. It includes no shared directory, no
application component and no model - those pages use framework components only
(`Turnstile_Input`, `Two_Factor_Challenge`, `Passkey_Sign_In`, `Sso_Buttons`).

- **Adding an application component, model or script to a sign-in or error page** means
  adding its files to `auth/portal_auth_bundle.php` - both halves of the component and of
  every parent. A missing stylesheet or parent template fails silently, so look at the page.
- **`Portal_Bundle` is deliberately wide** and stays that way: its users are identified,
  and a screen added to the signed-in portal needs no bundle edit.
- `rsx/tests/Public_Bundle_Contents_Test.php` fails when `Portal_Auth_Bundle`'s compiled
  output carries anything of the application it does not name. Skill `rspade:bundles`.

## PORTAL ERROR PAGES

A failure picks its realm from the FAILING request, so a portal URL that 404s, denies or
crashes renders `rsx/portal/errors/` — `Portal_Auth_Bundle`, the portal's auth chrome, and
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
| Bundle | Frontend_Bundle, etc. | Portal_Bundle (signed in), Portal_Auth_Bundle (sign-in and error pages) |

## Routing

### URL Strategy

Where the portal is served is `PORTAL_URL` in `.env` (framework config, `rsx:man portal`).
This application leaves it blank, so the portal lives under `/_portal/` on the
application's own host (`/_portal/login`, `/_portal/dashboard`). Setting it moves every
screen with no code change - another prefix (`https://myapp.com/client-portal`) or a host
of its own (`https://portal.myapp.com/` -> `https://portal.myapp.com/login`) - because
every portal URL here is built with `Rsx_Portal::Route()`, which answers an absolute URL
when called off the portal's host (the staff-side invitation and notification links
included). A staff-side link names the RECORD's site (`site_id: $client->site_id`) and
handles `false` - the site has no client portal - by refusing with "This site has no client
portal." rather than sending a broken link (`Frontend_Clients_Controller`,
`Portal_Invitation_Model::get_invitation_url()`, View as Client). Changing it after launch re-registers the portal SSO redirect URI and strands
passkeys enrolled under an old host (`rsx:man launch_checklist`).

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

`Rsx_Portal.Route()` resolves both PHP controller routes and SPA action routes. PHP `#[Portal_Route]` attributes of the controllers a portal bundle includes are automatically compiled into it during bundle compilation — no `include_routes` configuration needed.

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

The framework resolves which site a portal request serves from its URL and declares
it before `Portal_Main::init()` runs - site 1 in single tenancy (this app as shipped),
the `:slug` / `:site_id` / custom domain the address names in multi tenancy. The app
declares nothing; `Portal_Session::get_site_id()` answers it everywhere in a portal
request. See `php artisan rsx:man portal`.

### Auth Flow

- **Authorization is declarative**: `#[Auth]` on every `#[Portal_Route]` and `@auth` on
  every `@portal_spa` action, evaluated in the PORTAL realm before any application code.
  `Portal_Main::pre_dispatch()` performs NO authorization — it runs after the gates.
- Public surfaces declare `#[Auth('public')]`: login, register, password reset, logout, the
  impersonation stop.
- Per-client rules are record-level predicates in the endpoint body, after the gates pass.

### Passkeys, second factors and federated sign-in

The portal realm has the framework's whole second-factor subsystem (`Rsx_Portal_Two_Factor`,
credentials in `_portal_two_factor_credentials`) and its own federated sign-in
(`Rsx_Portal_Sso`, links in `_portal_sso_identities`). Neither shares anything with the staff
realm but the provider registry, so a staff passkey or a staff Google link never signs anybody
in here. How this app uses them:

- **The password is checked by the framework**: `Portal_Login_Controller::index()` calls
  `Portal_Auth::verify_credentials(Portal_Session::get_site_id(), $credentials, record: false)`
  (throttle first, the lookup on this portal's site, the hash check), records a miss itself as
  `Portal_Login_History::record_failure(..., STATUS_FAILED_PASSWORD)` and a password-only
  sign-in as `record_success()`, and asks the account questions (`can_login()`: unverified,
  inactive) between the check and the sign-in. Both thrown refusals are shown as the form's
  error: `Auth_Throttled_Exception`, and `Auth_Password_Reset_Required_Exception` for a correct
  password on a portal user flagged `password_reset_required` (pointing at "Forgot your
  password?"; the framework clears the flag when the reset saves the new password).
- **Password login is two-stage**: a portal user holding a second factor is parked with
  `Rsx_Portal_Two_Factor::begin_challenge()` (the invited-client id rides the session under
  `CLIENT_ID_KEY`, parked AFTER the challenge, whose sign-out clears portal session values) and
  sent to `/login/verify`, where `<Two_Factor_Challenge>` posts to
  `verify_2fa`.
- **Every sign-in outcome lands in the portal login history** (`Portal_Login_History`,
  readable per portal user with `get_history_for_user()` / `list_page()`): the challenge, the
  passkey and the federated doors are recorded by the framework, the password form as above.
  No screen of this app shows it.
- **Emailed sign-in codes** (`rsx.portal.emailed_sign_in_codes`, OFF as shipped - a fresh
  install usually sends no mail): when on, EVERY password sign-in owes a second step, accepting
  a six-digit code emailed to the account or any factor the user holds - unless
  `Rsx_Mail::deliverable_to()` says the code could not reach them (mail suppressed or frozen, or a
  blocked address), when the step falls back to the factors they hold, or to none,
  rather than locking the account.
  `Portal_Login_Controller::challenge_accepts()` is that policy in one place (the password
  form and `Portal_Sso_Handlers::two_factor_accepts()` both ask it); `send_code()` issues each
  code, then its sign-in link (`Rsx_Portal_Two_Factor::issued_link(Rsx_Portal::Route(
  'Portal_Login_Controller::verify'))`), and emails both in `Portal_Sign_In_Code_Email`
  (`SECURITY`), capped at `MAX_CODES_PER_SIGN_IN`; the challenge screen sends the first code
  itself and offers "Resend code". Opening the link in the browser that typed the password
  brings it back to `/login/verify`, where the widget submits it - a link opened anywhere else
  says to type the code there. A passed code or link marks the portal user verified. The framework keeps
  ONE live code per portal user, so a resend before it expires re-sends the same digits and
  link and restarts their clock - the email is word for word the original and states the
  configured window. A passkey
  sign-in owes nothing, so a client who registers a passkey never waits for the email.
  `rsx:man two_factor_codes`.
- **Passwordless**: the login page's `<Passkey_Sign_In>` posts to `passkey_login`, which calls
  `Rsx_Portal_Two_Factor::verify_passkey_login()` - a complete sign-in, no second factor after.
- **Federated sign-in** appears on the login page only when `rsx.sso.portal_enabled` is true
  (off by default) and a provider is live. The policy is `rsx/handlers/Portal_Sso_Handlers.php`:
  a verified provider email that matches a portal user of this site is connected and signed in;
  anything else declines. Register the portal callback (`Rsx_Portal_Sso::callback_url('<key>')`)
  in each provider's console.
- **One completion function.** Every way in - the password form, the challenge, a passkey, a
  registration from an invitation (`Portal_Register_Controller`) and a federated sign-in -
  finishes in `Portal_Login_Controller::complete_sign_in($portal_user, $sign_in, $client_id)`:
  `sign_in: true` (password, registration) signs in there with
  `Portal_Session::set_portal_user_id()` and records the success; `sign_in: false` (challenge,
  passkey, SSO) follows the framework's own sign-in. It raises the welcome flash and answers
  `destination()` - the threaded redirect, the invited client, or the dashboard. Nothing else
  in this application calls `set_portal_user_id()`.
- **Registration verifies the address.** `Portal_Register_Controller` creates the portal user
  with `is_verified = 1` (the invitation proved the mailbox) and signs them in through
  `complete_sign_in()` - with no emailed code even when `emailed_sign_in_codes` is on, since
  the invitation already proved the address.
- **A dead invitation offers no account.** Unknown, revoked, used and expired codes go to the
  sign-in page with the `invitation_expired` notice (`rsx.login_notices`, `Login_Notice`). The
  redirect survives every hop: register, account-exists, forgot password, and the second factor,
  where it is parked beside the challenge (`REDIRECT_KEY`).
- **A share link drives the invitation.** `Portal_Share_Link_Controller` sends a follower to
  their documents, the sign-in page with a notice, or the accept-invite page, carrying the link
  back as the redirect (`rsx:man portal`, A SHARE LINK LEADS THERE).
- **Portal access is by invitation only, extended by the site's staff** - the template's
  recommended position. There is no portal self-signup and no public "request access" page: the
  login page says access is by invitation and names who to contact (`config('rsx.name')`), and
  staff invite and resend from the client screen (`Frontend_Clients_Controller`). An
  application may add a request page of its own; it then owns the questions such a page raises
  - it must not tell a stranger which addresses have accounts or invitations, and must not let
  an anonymous visitor extend an invitation's expiry.
- The framework components are realm-aware on their own - on a portal page they talk to the
  portal controllers - so this app passes them no realm argument.
- "View as Client" cannot enroll, remove, link or unlink anything: the framework refuses it.

## Portal Pages

### Dashboard (`/dashboard`)
Landing page for authenticated portal users.

### Settings (`/settings`)
User self-service page (composed from the shared theme components — see
`rsx/theme/components/view/CLAUDE.md`) with:
- **Change Password** — every field rule answered together (current password present, new one
  at least `rsx.portal.password_min_length`, confirmed); then the current password, checked
  throttle-first (`Login_Throttle::require_not_throttled($email)` - the same budget as every
  sign-in of the account and address) with a wrong one recorded through
  `Portal_Login_History::record_failure()`, so a browser left signed in is no unlimited oracle
  for the real password. Field messages arrive in the Ajax error's `metadata`; the throttle's
  message, which names no field, is shown in a dialog
- **Passkeys** — `Portal_Settings_Security`: the portal user's passkeys and authenticator app
  (remove), `<Passkey_Register />`, an on-demand `<Totp_Enrollment />`, the recovery-code count (with its regenerate button only where `issues_recovery_codes` says the deployment issues them)
  and regeneration
- **Connected Accounts** — same region, only when the portal realm offers federated sign-in:
  connections (disconnect) and `<Sso_Buttons $intent="link" />`
- **Confirm it's you** — every action in that region that adds or removes a sign-in method
  asks for the password first (`Password_Confirmation_Modal.require()`, `rsx/lib/password_confirmation/`):
  remove, disconnect and regenerate on click, while `<Passkey_Register />`, the authenticator
  app and `<Sso_Buttons $intent="link" />` - which call their endpoints themselves - are shown
  only after "Add a sign-in method" has asked. Leaving the page clears it (`on_stop()`).
- **Team Members** — list of portal users sharing the same client membership
- **Where You're Signed In** — the account's own sessions (`Portal_Session::get_sessions_for_user()`
  through `Session_Listing`, `rsx/lib/sessions/`: signed in and signed out, the last three
  months, "This device" marked), with device, status, IP, last activity and Sign Out on every
  other signed-in device (`terminate_session`, which takes a `user_session_id` the list shows;
  anything else is not found)
- **Account Info** — email, status, last login, member since

### Auth Pages (Server-Side Blade)
- **Login** (`/login`) — email/password (no credential autofill: a portal account belongs to a
  client), plus passkey sign-in and, when enabled, federated sign-in
- **Two-factor challenge** (`/login/verify`) — `<Two_Factor_Challenge>` for a portal user
  holding a second factor, or for everyone when emailed sign-in codes are on (with the
  `send_code` endpoint); its Cancel (`$cancel_url`) returns to `/login`. A signed-in visitor is
  sent to their destination (the challenge was answered in another tab), and
  `portal_login_verify.js` hides the recovery-code hint when the widget fires `completed`
- **Registration** (`/register?code=X`) — invitation-based account creation
- **Password Reset** (`/password/reset`) — request + reset token flow. The link is single-use
  even under concurrent submissions: the form calls `Portal_Password_Reset_Model::claim()` (one
  conditional UPDATE that marks it used only while unused and unexpired) and writes the new
  password only when its own claim succeeded
- **Logout** (`/logout`) — clears portal session, redirects to login

## Testing

```bash
# Test portal pages via rsx:debug
php artisan rsx:debug /_portal/dashboard --portal --portal-user=1
php artisan rsx:debug /_portal/settings --portal --portal-user=1 --screenshot-path=/tmp/portal.png
```

## Security Considerations

1. **Realm Isolation**: there is ONE session per browser (one session cookie, one `_sessions` row).
   The portal sign-in is a portal user session the row's portal pointer names; the staff sign-in
   is a staff user session behind the other pointer. Both signed in at once is normal. Portal code
   reads and moves only the portal one, through `Portal_Session`.
2. **Route Isolation**: Portal routes only accessible via portal context
3. **Data Access**: Use `portal_fetch()` methods for portal-safe data exposure
4. **CSRF**: one shared token per browser session - the same value both dispatchers verify

## RELATED

`workspaces/CLAUDE.md` · `rsx/portal_main.php`, `rsx/portal_permission.php` ·
`rsx/theme/components/view/CLAUDE.md` · app skill `portal-app` ·
skills `rspade:portal-core`, `rspade:auth-gates` · `rsx:man portal`
