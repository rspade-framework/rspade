# rsx/portal/auth - the client portal's sign-in ladder

## WHAT IS HERE

Server-rendered Blade pages and their controllers: everything a portal visitor meets before
(and on the way out of) a portal sign-in. Every controller is class-level
`#[Auth('public')]`, because each page is reached signed out - the gate is the declaration,
and what a page lets a visitor do is decided in its body.

| Controller | Route(s) | What it does |
|---|---|---|
| `Portal_Login_Controller` | `/login` GET+POST, `/login/new-password` GET+POST, `/login/verify` GET, Ajax `verify_2fa`, `send_code`, `passkey_login` | The password form, the forced password change at sign-in ("Choose a new password"), the second-factor screen and its endpoints, passwordless passkey sign-in, and `complete_sign_in()` - the ONE place every portal entrance finishes. |
| `Portal_Register_Controller` | `/register?code=X` GET+POST | Account creation from a portal invitation: set a password, signed in through `complete_sign_in()`. |
| `Portal_Password_Reset_Controller` | `/password/reset` GET+POST, `/password/reset/:token` GET+POST | The forgot-password request (Turnstile first; always answers "sent", so it reveals no address) and the reset form behind the emailed link (`Portal_Password_Reset_Email`, `Portal_Password_Reset_Model`). |
| `Portal_Logout_Controller` | `/logout` | `Portal_Session::logout()`, back to `/login`. |
| `Portal_Impersonate_Controller` | `/impersonate/stop` | Ends staff "View as Client" and shows `portal_impersonate_stopped.blade.php`. Starting one is the framework's `Portal_Session::begin_impersonation_from_staff()`, called from the staff app; it never passes through here. |
| `Portal_Share_Link_Controller` | `/shared/:token` | Where a share link leads: the shared documents, the dashboard, the sign-in page with a notice, or the accept-invite page - deciding the destination, opening nothing itself (`rsx:man portal`, A SHARE LINK LEADS THERE). |

| Page | Rendered by |
|---|---|
| `portal_login_index.blade.php` (+ `.scss`, the "or" divider) | `/login` |
| `portal_login_new_password.blade.php` | `/login/new-password` |
| `portal_login_verify.blade.php` (+ `portal_login_verify.js`) | `/login/verify` |
| `portal_register_index.blade.php` | `/register` |
| `portal_password_reset_request.blade.php` | `/password/reset` |
| `portal_password_reset_reset.blade.php` | `/password/reset/:token`, a live token |
| `portal_password_reset_invalid.blade.php` | `/password/reset/:token`, a missing, unknown, used or expired token |
| `portal_impersonate_stopped.blade.php` | `/impersonate/stop` |

**`portal_auth_layout.blade.php`** (+ `.scss`) is the card shell every page here and every
portal error page (`rsx/portal/errors/`) extends, with a `below_card` section where each form's
`<Turnstile_Input $form="<its form id>" />` sits. **`portal_auth_bundle.php`**
(`Portal_Auth_Bundle`) is served to visitors who have not signed in, so it names its assets BY
FILE - the theme's global stylesheets, the Bootstrap build, this directory and
`rsx/portal/errors` - and no shared directory; the framework components on these pages
(`Turnstile_Input`, `Two_Factor_Challenge`, `Passkey_Sign_In`, `Sso_Buttons`) arrive with the
core bundle. Adding an application component to a page here means adding its files (both
halves, and its parents') to that bundle; `rsx/tests/Public_Bundle_Contents_Test.php` holds
the line (`rsx/portal/CLAUDE.md`, TWO BUNDLES).

## HOW IT IS USED

- **Login** (`/login`) — email/password (no development credential prefill: a portal account belongs to a
  client), plus passkey sign-in and, when enabled, federated sign-in
- **Two-factor challenge** (`/login/verify`) — `<Two_Factor_Challenge>` for a portal user
  holding a second factor, or for everyone when emailed sign-in codes are on (with the
  `send_code` endpoint); its Cancel (`$cancel_url`) returns to `/login`. A signed-in visitor is
  sent to their destination (the challenge was answered in another tab), and
  `portal_login_verify.js` hides the recovery-code hint when the widget fires `completed`
- **Registration** (`/register?code=X`) — invitation-based account creation
- **Password Reset** (`/password/reset`) — request + reset token flow, on the token rules the
  staff reset shares (`Password_Reset_Token`, `rsx/lib/password_reset/`): only the SHA-256 of the
  link's secret is stored, a new request ends the old link, and the link is single-use even
  under concurrent submissions: the form calls `Portal_Password_Reset_Model::claim()` (one
  conditional UPDATE that marks it used only while unused and unexpired), writes the new
  password only when its own claim succeeded (`set_password(..., Password_Change::CAUSE_RESET_LINK)`);
  the framework's `portal_user.password.changed`, after the commit, ends every other open link
  (`invalidate_open_for()`, in `rsx/handlers/Password_Event_Handlers`, for EVERY portal
  password change) and records the change on the site's action log with the security notice
  email. A request that mailed a link fires the application event
  `portal_user.password.reset_requested`, recorded the same way. Used and expired links are
  deleted nightly by `Password_Reset_Link_Service::sweep` (`rsx/services/`). A flagged account is NOT
  sent here: it chooses its new password at sign-in (below)
- **Choose a new password** (`/login/new-password`) — the forced password change at sign-in,
  the staff rung's twin (`rsx/app/login/CLAUDE.md`). `index()` catches
  `Auth_Password_Reset_Required_Exception` for a correct password on a portal user flagged
  `password_reset_required` (set by staff or an operator), asks `can_login()` of
  `$e->identity`, calls `Portal_Auth::begin_forced_password_change()` (signs the portal out,
  parks the portal user - nobody is signed in), parks the invited-client id and the redirect
  AFTER it (the sign-out clears portal session values), and redirects here. The POST checks
  `rsx.portal.password_min_length` and the confirmation, then
  `Portal_Auth::complete_forced_password_change()` (refuses the CURRENT password, draws on the
  sign-in throttle, saves through the model with the cause `forced_change` in a
  `Site_Lock::write()` section on the portal's site: the flag clears, other sign-ins end) and
  continues through `__continue_sign_in()` - the second step NEXT when `challenge_accepts()`
  owes one, then `complete_sign_in(sign_in: true)`. So a forced change is never a way around a
  second factor. Nothing pending -> `/login`; `?cancel=1` forgets the parked portal user; an
  account deactivated meanwhile answers the login form with the `can_login()` refusal. No
  email, no token, no Turnstile (the step answers only the portal user parked on the caller's
  own session, which passed Turnstile with the password)
- **Logout** (`/logout`) — clears portal session, redirects to login

### The sign-in ladder, second factors and federated sign-in

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
  inactive) between the check and the sign-in. `Auth_Throttled_Exception` is shown as the
  form's error; `Auth_Password_Reset_Required_Exception` (a correct password on a portal user
  flagged `password_reset_required`) goes to Choose a new password above, and the framework
  clears the flag when the new password saves.
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
- **One completion function.** Every way in - the password form (and its forced password change), the challenge, a passkey, a
  registration from an invitation (`Portal_Register_Controller`) and a federated sign-in -
  finishes in `Portal_Login_Controller::complete_sign_in($portal_user, $sign_in, $client_id)`:
  `sign_in: true` (password, registration) signs in there with
  `Portal_Session::set_portal_user_id()` and records the success; `sign_in: false` (challenge,
  passkey, SSO) follows the framework's own sign-in. It raises the welcome flash and answers
  `destination()` - the threaded redirect, the invited client, or the dashboard. Nothing else
  in this application calls `set_portal_user_id()`.
- **Writes under the site locks.** The portal's site comes from its URL, so nothing here
  switches site. The sign-in Ajax endpoints (`verify_2fa`, `send_code`, `passkey_login`)
  declare `#[Writer]` - the sign-in stamps the site-scoped portal user. The page routes take
  no automatic lock, so each write they make is a `Site_Lock::write(Portal_Session::get_site_id(), ...)`
  section: the password sign-in in `complete_sign_in(sign_in: true)`, the forced change's save
  (inside `Portal_Auth::complete_forced_password_change()`), registration (account,
  membership, used invitation and sign-in in ONE section - `complete_sign_in()` reuses it),
  the reset request (token and email) and the reset itself (claim and new password), and a
  share link's `mark_accessed()`.
- **Registration verifies the address.** `Portal_Register_Controller` creates the portal user
  with `is_verified = 1` (the invitation proved the mailbox) and signs them in through
  `complete_sign_in()` - with no emailed code even when `emailed_sign_in_codes` is on, since
  the invitation already proved the address.
- **An existing account is sent to sign in, and the invitation stays PENDING.** A registration
  link for an address that already has a portal account goes to the sign-in page with the
  `account_exists` notice and consumes nothing (a GET, which a mail scanner's prefetch also
  reaches); once signed in, the dashboard's Accept card (`Portal_Invitations_Controller::accept`)
  creates the membership.
- **A dead invitation offers no account.** Unknown, revoked, used and expired codes go to the
  sign-in page with the `invitation_expired` notice (`rsx.login_notices`, `Login_Notice`). The
  redirect survives every hop: register, account-exists, forgot password, and the second factor,
  where it is parked beside the challenge (`REDIRECT_KEY`).
- **A share link drives the invitation.** `Portal_Share_Link_Controller` sends a follower to
  their documents, the sign-in page with a notice, or the accept-invite page, carrying the link
  back as the redirect (`rsx:man portal`, A SHARE LINK LEADS THERE).
- **Portal access is by invitation only, extended by the site's staff.** There is no portal
  self-signup and no public "request access" page - registering from an invitation is the only
  way a portal account comes into being: the
  login page says access is by invitation and names who to contact (`config('rsx.name')`), and
  staff invite and resend from the client screen (`Frontend_Clients_Controller`). An
  application may add a request page of its own; it then owns the questions such a page raises
  - it must not tell a stranger which addresses have accounts or invitations, and must not let
  an anonymous visitor extend an invitation's expiry.
- The framework components are realm-aware on their own - on a portal page they talk to the
  portal controllers - so this app passes them no realm argument.
- "View as Client" cannot enroll, remove, link or unlink anything: the framework refuses it.

## CREDENTIAL FIELDS

Every field here that asks for a sign-in identifier, a password or a one-time code carries an
`autocomplete` token and a stable id, by the SAME rules and the SAME id vocabulary as the staff
pages - one origin, so a password manager replays an id saved on one page on any other. The
token table, the id convention and the reasoning are in `rsx/app/login/CLAUDE.md`, CREDENTIAL
FIELDS; the portal's fields:

| Page | Fields (id, `autocomplete`) |
|---|---|
| `portal_login_index.blade.php` | `email` `username`, `password` `current-password` |
| `portal_login_new_password.blade.php` | hidden read-only `email` `username` (the parked address, unnamed), `new_password` / `new_password_confirmation` `new-password` |
| `portal_login_verify.blade.php` | `<Two_Factor_Challenge $autofill_id="code">` - `code` `one-time-code` |
| `portal_register_index.blade.php` | hidden read-only `email` `username` (the invited address), `new_password` / `new_password_confirmation` `new-password` |
| `portal_password_reset_request.blade.php` | `email` `username` |
| `portal_password_reset_reset.blade.php` | hidden read-only `email` `username` (the account being reset), `new_password` / `new_password_confirmation` `new-password` |
| Settings > Change Password (`rsx/portal/settings/Portal_Settings_Action.jqhtml`) | hidden read-only `email` `username`, `current_password` `current-password`, `new_password` / `new_password_confirmation` `new-password` (jqhtml: `$autofill_id` on each `$sid` field) |
| Settings > authenticator app (`Portal_Settings_Security.jqhtml`) | `<Totp_Enrollment $autofill_id="code">` |
| "Confirm it's you" (`rsx/lib/password_confirmation/`) | `password` `current-password` |

On these Blade pages the `id` follows the convention and the `name` stays what the controller
reads (`password`, `password_confirm`; `password_confirmation` on Choose a new password, as on
the staff twin), so the endpoints are untouched. The identifier on a
password-setting page carries NO name: it is a hint to the password manager and is never
posted - the account comes from the invitation or the reset token, never from the form.

## HOW TO CUSTOMIZE

- **Rebrand**: `portal_auth_layout.{blade.php,scss}`; the error pages follow.
- **Change where a signed-in portal user lands, or what a sign-in owes**:
  `Portal_Login_Controller::complete_sign_in()` / `destination()`, and nowhere else.
- **Emailed sign-in codes**: `rsx.portal.emailed_sign_in_codes` (off as shipped);
  `challenge_accepts()` is the policy.
- **The SSO account policy**: `rsx/handlers/Portal_Sso_Handlers.php`; the feature is
  `rsx.sso.portal_enabled`.
- **A new page or field**: a controller with `#[Auth('public')]`, a blade extending
  `Portal_Auth_Layout`, Turnstile validated first in any POST branch, its assets named in
  `Portal_Auth_Bundle`, and every credential field per CREDENTIAL FIELDS above.

## RELATED

`rsx/portal/CLAUDE.md` · `rsx/app/login/CLAUDE.md` (the staff ladder, and the credential-field
rules) · app skill `portal-app` · skills `rspade:portal-core`, `rspade:two-factor`,
`rspade:sso`, `rspade:turnstile` · `rsx:man portal`, `rsx:man two_factor`,
`rsx:man two_factor_codes`, `rsx:man sso`
