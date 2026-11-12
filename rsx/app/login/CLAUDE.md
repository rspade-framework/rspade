# rsx/app/login — the server-rendered staff auth ladder

## WHAT IS HERE

Blade pages, not SPA actions: this is what an unauthenticated visitor sees. Every
controller is class-level `#[Auth('public')]` with a written justification in its docblock.

**There is no self-signup.** A staff account comes into being in exactly two ways: accepting
an administrator's invitation (`/accept-invite`, below), and the framework's initial user on an
empty database (`Rsx_Initial_User::create()` - the first-run screen, the `RSPADE_DEFAULT_*`
post-migrate step, the test seed). Nothing on these pages lets a visitor create an account of
their own, and a federated sign-in never creates one either.

**A staff password is reset three ways**: by its owner from the login page ("Forgot your
password?" -> an emailed link, `password_reset/`), by an administrator in User Management
(Reset Password - a TEMPORARY password the user must replace at their next sign-in,
`rsx/app/frontend/settings/user_management/CLAUDE.md`), and by an operator
(`rsx:users:password:set [--require-reset]`). A password that must be replaced is chosen AT
SIGN-IN, on the "Choose a new password" step (`/login/new-password`), never by email. See
PASSWORD RESET below.

| Rung | Class / file | Route | What it does |
|---|---|---|---|
| Login | `Login_Controller` (`login_controller.php`) | `/login` GET+POST | Turnstile, then `RsxAuth::verify_credentials($credentials, record: false)` - the password is checked and NOBODY is signed in. A failure records `STATUS_FAILED_PASSWORD` itself. On success `__account_refusal()` is asked (the application's own account rules; it admits everybody as shipped), then `__continue_sign_in()`: a second factor issues the challenge, otherwise `complete_sign_in(sign_in: true)`. A correct password on an identity flagged `password_reset_required` goes to the Choose a new password rung instead. `complete_sign_in()` (which calls `RsxAuth::login()`) and a passed challenge are the only things that sign anybody in. The page links "Forgot your password?" under the password field. |
| Choose a new password | `Login_Controller::new_password` (`login_new_password.blade.php`) | `/login/new-password` GET+POST | The forced password change at sign-in. `index()` caught `Auth_Password_Reset_Required_Exception`, asked `__account_refusal()` of `$e->identity`, and called `RsxAuth::begin_forced_password_change()` (signs the staff realm out, parks the identity - nobody is signed in) and redirected here. The POST checks `rsx.auth.password_min_length` and the confirmation, then `RsxAuth::complete_forced_password_change()` (refuses the CURRENT password, draws on the sign-in throttle, saves through the model: the flag clears, other sign-ins end) and continues through `__continue_sign_in()` - the second factor NEXT, then `complete_sign_in(sign_in: true)`. So a forced change is never a way around a second factor. Nothing pending -> `/login`; `?cancel=1` forgets the parked identity; a membership switched off meanwhile records `STATUS_FAILED_DISABLED` and answers the login form's generic error. No email, no token, no Turnstile (the step answers only the identity parked on the caller's own session). |
| Forgot password | `Password_Reset_Controller` (`password_reset/`) | `/password/reset` GET+POST, `/password/reset/:token` GET+POST | Request: Turnstile, then `Login_Throttle::require_not_throttled($email)` (its message on refusal), then a link is minted (`Login_User_Password_Reset_Model::create_for()`) and `Password_Reset_Email` (SECURITY) queued ONLY for a live identity holding an enabled membership (`RsxAuth::has_enabled_membership()`) - in a `Site_Lock::write()` section on the visitor's site (0), the queue row being site-scoped. EVERY well-formed submission answers the same "if an account exists for that address, a link is on its way". Reset: the link is found by `find_by_secret()`; used / expired / unknown answer `Password_Reset_Invalid`; the POST checks `rsx.auth.password_min_length` + confirmation, then in one transaction `claim()`s the link (single use under a race), `set_password($password, Password_Change::CAUSE_RESET_LINK)` + `save()` (the password-change event, after the commit, ends the identity's other open links) - and sends the visitor to `/login` with `Login_Notice` `password_reset`. **Completing a reset signs nobody in**: the sign-in (and its second factor) runs as any other. A signed-in visitor is sent to the dashboard. |
| Challenge | `Login_Controller::verify` + `verify_2fa` | `/login/verify` GET + an `#[Ajax_Endpoint]` | The screen hosting `<Two_Factor_Challenge>` for a second factor (an authenticator code, a passkey or a recovery code - this application emails no staff codes), with `$cancel_url` = `/login`, and the endpoint it posts to. A signed-in visitor is sent to the dashboard (the challenge was answered in another tab); nothing pending redirects back to `/login`. `login_verify.js` hides the recovery-code hint when the widget fires `completed`. |
| Passkey sign-in | `Login_Controller::passkey_login` | an `#[Ajax_Endpoint]` | The endpoint `<Passkey_Sign_In>` on the login page posts to: `Rsx_Two_Factor::verify_passkey_login()`, then `complete_sign_in(sign_in: false)`. Passwordless - no password stage, no second factor after it. |
| Logout | `Login_Controller::logout` | `/logout` | `RsxAuth::logout()` then `Login_Redirect::consume($default)`. |
| Accept invite | `Accept_Invite_Controller` (`accept_invite/`) | `/accept-invite`, `/accept-invite/create-account`, `/accept-invite/success` | Six states (invalid, expired, email mismatch, already accepted, not logged in, logged in) plus the create-account form for an invitee with no login account. `create_account_submit` validates Turnstile first, refuses any address but the invited one, creates the account with the invitation's address and `is_verified = 1` (the invitation proved the mailbox), and signs it in through `Login_Controller::complete_sign_in(sign_in: true)`; when that refuses (the invited membership or its site disabled since) the account still exists and the visitor is sent to `/login` with a flash. `accept` (a signed-in user) marks that identity verified too. |
| Site selection | `Site_Selection_Controller` | `/login/select-site`, `/login/site/:id` | The picker: one button per site from `usable_sites()` (the identity's `->active()` memberships across every site, by name), rendered by the `Login_Site_Picker` partial (`login_site_picker.blade.php`). Not signed in -> `/login`; no usable site -> logout; exactly one -> selected. `select` accepts only an active membership (`->active()`, unscoped by the session's current site; error card otherwise), enters the site with `RsxAuth::enter_site()` and lands on the dashboard. |
| Site unauthorized | `Site_Unauthorized_Controller` | `/login/site-unauthorized` | The signed-in identity's session names a site it is not a member of; offers the sites it does have (`usable_sites()` through the same `Login_Site_Picker` partial). Nothing in this mono-site template routes to it - the framework ends such a session itself (see SITE MEMBERSHIP below), so it is kept as the pattern for an app that declares the requested site from the host. |

The login page also carries the OTHER WAYS IN: below the Sign In button,
`login_index.blade.php` renders one `.login-alternatives` block: an `or` divider, then
`<Passkey_Sign_In>` (always offered), then the framework's provider buttons inside
`@if (Rsx_Sso::is_enabled())`. In a browser without passkeys the passkey control renders
nothing and marks itself `Passkey_Sign_In--unsupported`, and `login_index.scss` hides the
whole block when nothing in it is visible, so the divider never hangs over nothing. Nothing else in this module changes: the ceremony is entirely the
framework's (`/_sso/...`), and this application's policy lives in
`rsx/handlers/Sso_Handlers.php`.

`invite_helper.php` (`Invite_Helper`) is the shared validator — **an invitation is a
`User_Model` row** carrying `invite_code` / `invite_expires_at` / `invite_accepted_at`, not
a separate table.

**`login_bundle.php` is served to visitors who have not signed in, so it names its assets
BY FILE.** Every page here is anonymous, and whatever the bundle includes is downloaded by
anyone who loads `/login` - so it includes no directory of the shared trees (`rsx/theme`,
`rsx/lib`, `rsx/models`), which would publish the component library, every model's ORM
definition and every controller's Ajax stubs. It lists:

- the theme's global stylesheets - `rsx/theme/variables.scss`, `rsx/theme/responsive.scss`,
  `Bootstrap5_Src_Bundle`, then `'rsx/theme/*.scss'` (the theme directory's own
  stylesheets: composition tokens, layout, badges - not its subdirectories, which are the
  component library). That is what makes the auth ladder look like the staff application
  rather than like a separate product;
- `Form_Field` with its parent `Form_Field_Abstract`, `Text_Input` and `Hidden_Input`
  (create-account) - the `.jqhtml` and `.js` of each;
- the modal library (`rsx/lib/modal/modal.js`, `rsx_modal.{jqhtml,js,scss}`) - invite
  acceptance reports a failure with `Modal.alert()`, and the framework shows an unhandled
  Ajax error through it. `Modal.select()` does not work here: `Select_Input` is not in the
  bundle;
- this directory.

The framework's own components on these pages (`Rsx_Form`, `Form_Errors`,
`Turnstile_Input`, `Two_Factor_Challenge`, `Passkey_Sign_In`, `Sso_Buttons`) arrive with
the framework's core bundle and are not listed. No model is included: nothing in this
module's JavaScript reads one.

## HOW IT IS USED

**The layout is the staff theme's Bootstrap, and nothing else.**
`login_layout.blade.php` centres one Bootstrap `.card` (`.card-header` / `.card-body`), with an optional `below_card` section under it, on
a `var(--bs-tertiary-bg)` page; `login_layout.scss` holds ONLY the centring, the 500px
card width, the header/body padding and the mobile adjustment, all under `.Login_Layout`
in BEM (`.Login_Layout__viewport`, `__card`, `__header`, `__title`, `__subtitle`,
`__body`). There is no login-only palette: every colour on these pages is a runtime token
the theme already defines, so the auth ladder follows light and dark exactly as the
dashboard does. A page under this layout writes ordinary Bootstrap markup - `.alert`,
`.form-control`, `.btn-primary`, `.d-grid` - and gets the frontend's own look for free.

**Light and dark are resolved by the framework, not by this module.** The theme is a
class and `data-bs-theme` on `<html>`, put there before first paint by the autotheme
script `Login_Bundle::render()` emits - the same script the authenticated shell gets. An
anonymous visitor has no stored preference, so the configured default applies: AUTO by
default, which follows the operating system, live. A visitor who signs in and then
returns to a login page sees their own stored choice. Nothing about the mode lives in
this directory.

**Turnstile.** On every page that has it the widget sits BELOW the card, centred, in the
layout's `below_card` section (`$auth-below-card-gap`, 22px), tied back to its form by id:
`<Turnstile_Input $form="login-form" />` for the native login form, and
`$form="create-account-form"` for the `<Rsx_Form id="...">` on
`accept_invite/create_account.blade.php`, whose `vals()` collects a hidden field associated by
id; the endpoint answers it as the FIRST statement of the POST branch —
`Rsx_Turnstile::validate($request)` in `Login_Controller::index()`, and the two-arg
`validate($request, $params)` in `Accept_Invite_Controller::create_account_submit()` because a
batched sub-call carries the field in `$params`. The field always submits (sentinel `inactive` while the
feature is off), so validating it is not optional.

**Site membership is the framework's, not this module's.** `users.is_enabled` and
`sites.is_enabled` are the framework's switches for "may this identity use this site", and
a membership is usable only when both are on (`User_Model::is_active()`, the `->active()`
scope): `RsxAuth::verify_credentials()` refuses an identity holding no active membership exactly as it
refuses a wrong password (recording `STATUS_FAILED_DISABLED`), `RsxAuth::login()` refuses it
on the second-factor and federated paths, and `Session::enforce_enabled_membership()` ends a
live session whose membership or site is disabled or deleted, before every dispatch and every
Ajax call. So no controller here restates either column: the site lists
(`complete_sign_in()`, `Site_Selection_Controller::usable_sites()`) and `Site_Selection_Controller::select`
filter with `->active()`, `select` answering the error card for an unusable site,
`complete_sign_in()` has no zero-sites branch (a successful sign-in guarantees at least
one active membership), and `rsx/main.php` checks no membership. Identity state - `status_id`, `is_activated`, `is_verified` - is this application's
vocabulary, and this template enforces none of it: neither `Login_Controller::index()` nor
`Rsx\Main::pre_dispatch()` reads them. `is_verified` is set when an invitation is accepted
(the invitation reached the address) and is shown on the profile screen. An application that wants Suspended to refuse sign-in checks it in both places.
See `rsx:man session`.

**The throttle, and a password that must be reset.** `login_controller.php` catches
`Auth_Throttled_Exception` around the whole attempt and surfaces `$e->getMessage()` verbatim
ahead of the wrong-password branch, so a lockout is never reported as bad credentials. It
catches `Auth_Password_Reset_Required_Exception` too - a correct password on an identity
flagged `login_users.password_reset_required` (an administrator's Reset Password,
`rsx:users:password:set --require-reset`, `rsx:users:force_password_reset`) - and sends the
visitor to the Choose a new password rung. `RsxAuth::verify_credentials()` throws the throttle
refusal as its own first statement, and `verify_2fa` catches the same exception from
`Rsx_Two_Factor::verify_challenge()` and answers it as an `ERROR_VALIDATION` the challenge
component renders inline. Nothing here counts failures itself: `Login_History::record_failure()`
is what feeds the throttle, and it is called once per failed password.

**Two-factor authentication.** The login is TWO STAGES. `index()` verifies the password with
BOTH the recording and the `last_login` stamp suppressed, so a recorded SUCCESS always means
full authentication; if `Rsx_Two_Factor::is_enabled($login_user)` it calls `begin_challenge()`
(which signs the browser's staff sign-in out FIRST and then parks the pending identity) and redirects to
`/login/verify`. `verify_2fa` calls `Rsx_Two_Factor::verify_challenge($params)` - which signs
the identity in, stamps `last_login` and writes the success row - and answers
`{redirect: <url>}`, which the component follows with `Rsx.navigate(url, {load: 'full'})` (a fresh signed-in document).

**Passwordless passkey sign-in.** `passkey_login` receives `{assertion}` from
`<Passkey_Sign_In>` and calls `Rsx_Two_Factor::verify_passkey_login()`, which does the whole
sign-in - throttle first, user verification required, identity from the credential, the
membership check, `last_login`, the success row - and owes no second factor afterwards (a
user-verified passkey is a complete sign-in). It carries no invite code: the component posts
nothing but the assertion. Like `verify_2fa` it has no Turnstile, for the same reason.

**There is no Turnstile on `verify_2fa`, deliberately.** `<Two_Factor_Challenge>` posts
`{code}` or `{assertion}` and renders no widget, so there is no `__turnstile` field; the
framework's completeness guard fires only when a token WAS submitted. The endpoint is not
unguarded - it answers only from the challenge parked on the caller's own session, and
`verify_challenge()` spends the brute-force budget as its first statement.

**The invite code rides the session across the challenge.** The component's contract carries
no query string and no extra fields, so `index()` parks the code under
`Login_Controller::INVITE_CODE_KEY` with the challenge's own expiry - AFTER
`begin_challenge()`, because the challenge's sign-out clears the staff realm's session values -
and `verify_2fa` consumes it once.

**Signing in under the site locks.** A sign-in runs before the request has a site, and
entering one writes that site's sign-in records, so the session's site is set ONLY through
`RsxAuth::enter_site($site_id)` - it takes that site's write lock, sets the site, records the
site sign-in, commits and releases. The bare `Session::set_site_id()` is refused inside any
handler. `enter_site()` is refused while a site lock is held, so every caller holds none:

- the page routes (`/login` POST, `/login/site/:id`) declare no lock - a page route takes none;
- the Ajax sign-in endpoints declare `#[Manual_Lock]`: `verify_2fa`, `passkey_login`,
  `Accept_Invite_Controller::create_account_submit` and `accept`;
- the federated sign-in reaches `complete_sign_in()` through the framework's SSO callback,
  a page route that takes no lock.

`complete_sign_in()` calls `enter_site()` when the identity holds exactly one site; the site
picker calls it for the chosen one. The accept-invite endpoints write the invited membership
first, in a `Site_Lock::write($invitation->site_id, ...)` section (the site-scoped write needs
that site's write lock, and `#[Manual_Lock]` took none), and enter the site only AFTER that
section has committed - `enter_site()` inside it, or inside a `DB::transaction()`, is refused.

**One completion function.** Every staff entrance finishes in
`Login_Controller::complete_sign_in($login_user, $sign_in, $invite_code, $welcome)`: the
password form and an accepted invitation pass `sign_in: true` (it calls `RsxAuth::login()`
and records the success, answering null when the identity holds no active membership); a
passed challenge, a passkey and a federated sign-in pass `sign_in: false` (the framework
signed in and recorded already). It parks what the person still owes, then answers the
destination - invite, site picker, or the one site bound and the dashboard. A rule that
belongs to "being signed in" goes there, once. It is public for its two outside callers,
`Accept_Invite_Controller` and `Sso_Handlers`. Outside it, nothing in this application calls
`RsxAuth::login()`.

**Federated sign-in signs in existing accounts only.** `Sso_Handlers` answers the
framework's `sso.identity.unlinked` hook: a VERIFIED provider email matching a `login_users`
row is signed straight in (finishing through `Login_Controller::complete_sign_in()`), and
everything else declines - an unknown address is never given an account. Accounts are
created in advance - an administrator's invitation, accepted on `/accept-invite` with a
password - so the accept-invite flow never sees a provider identity.

**Forced enrollment is written and switched OFF** (`rsx.auth.require_two_factor`, false as
shipped). Off, nobody is required to enroll: a user adds a factor from Settings > Password &
Security, and the login flow challenges whoever has one. On, `complete_sign_in()` parks
`ENROLL_TWO_FACTOR_KEY` for an identity holding no factor (the sign-in itself still succeeds),
and `Rsx\Main::pre_dispatch()` holds the user on `Settings_Password_Security_Action`:
`Login_Controller::outstanding_enrollment_redirect()` redirects every PAGE request there with a
warning flash (that screen and `/logout` excepted), and
`Login_Controller::outstanding_enrollment_refusal()` refuses every Ajax call outside
`ENROLLMENT_AJAX_ALLOWED` - the Password & Security screen's own endpoints, the framework's
second-factor, SSO and password-confirmation endpoints, the layout's unread count, realtime
tokens and the SPA session check - as `unauthorized`, coded with
`two_factor_enrollment_required` and the screen's URL as `redirect`. In the browser,
`Frontend_Bundle::load_rsxapp_data()` publishes `page_data.two_factor_enrollment_owed` and
`Frontend_Spa_Layout` turns SPA navigation off, so every link is a page load the redirect
answers. The step is forgotten on the first request after `Rsx_Two_Factor::is_enabled()`
answers true (`owes_two_factor_enrollment()`). It is parked at sign-in, so turning the setting
on reaches each user at their next sign-in.

**`Login_Redirect`.** One call site: `Login_Controller::logout()`, `consume($default)` on
logout. Login itself does not round-trip the parameter — see HOW TO CUSTOMIZE.

**`Login_Notice`.** The sentence a flow sends a visitor to the sign-in page with (a link that
expired, a session that ended): the sending side puts `Login_Notice::params($code)` on the
URL, `login_index.blade.php` prints `Login_Notice::current()`, and the codes and their
wording are declared in `rsx.login_notices` (`rsx/resource/config/rsx.php`). No free text
crosses the URL, and never a flash, which a second redirect loses.

**`RSPADE_LOGIN_AUTOFILL`.** `login_index.blade.php:20` reads
`config('rsx.development.login_autofill')` and, when it is on, prefills
`config('rsx.default_user.email')`/`.password` and renders a warning banner saying how to
turn it off. An invite-code prefill wins over it; `?fill=false` suppresses it. Off is the
default and the secure state.

**The Blade page guard.** A static `on_app_ready()` fires for every page in the bundle, so
it must guard first: `login_layout.js` is `if (!$('.Login_Layout').exists()) return;` and
then the anti-FOUC reveal paired with the `.preload` rule in `login_layout.scss`. Every
Blade page's JS in this module needs that guard.

## HOW TO CUSTOMIZE

- **Rebrand**: recolouring is the THEME's job, not this module's - change
  `rsx/theme/variables.scss` or the Bootstrap build and the auth pages follow with the rest
  of the app. `login_layout.blade.php` is the card shell every blade extends and
  `login_layout.scss` its geometry (card width, padding, centring); a brand background on
  the sign-in page goes on `.Login_Layout__viewport`, in tokens. `login_index.scss` holds
  only the SSO "or" divider.
- **The site a staff request serves** is the one its sign-in bound (`complete_sign_in()` or
  the site picker): `Rsx\Main::init()` declares site 1 only for a command, task or test, so a
  multi-site member's chosen site is what every later request reads. A visitor who has not signed in has no site.
- **Add a rung**: a controller with `#[Auth('public')]` and a justification, a blade
  extending `Login_Layout`, and Turnstile validated first in any POST branch.
- **Use a component, model or script on one of these pages that the bundle does not
  list**: add its files to `login_bundle.php` - the component's `.jqhtml` and `.js` (and
  `.scss`), both halves of every parent (the JS base class, the `<Define:X extends="Y">`
  template), each model its JavaScript reads, each script it calls. A tag whose files are
  missing renders an empty element with only a console warning; a missing stylesheet or
  parent template renders a page that looks wrong with a clean console - so LOOK at the
  page, in light and dark. `rsx/tests/Public_Bundle_Contents_Test.php` fails when this
  bundle's compiled output carries anything of the application it does not name. Skill
  `rspade:bundles`.
- **Change where a signed-in user lands, or what a sign-in owes**: `complete_sign_in()` in
  `login_controller.php`, and nowhere else - every entrance finishes there.
- **Require a second factor**: `rsx.auth.require_two_factor => true`. Another step a
  signed-in user must do first (accept terms, a profile picture) takes the same shape: park it
  in `complete_sign_in()` with `Session::put_value()`, and from `Main::pre_dispatch()`
  redirect page requests and refuse Ajax calls outside the step screen's allow-list while it
  is set; a step skipped for good is
  `$login_user->set_variable()`. `rsx:man two_factor` (REQUIRING A SECOND FACTOR),
  `rsx:man user_preference_variables`.
- **Change the SSO account policy**: `rsx/handlers/Sso_Handlers.php`, one method. Removing
  the provider buttons from this page is the `@if` in `login_index.blade.php`; switching the
  feature off entirely is `SSO_*_ENABLED` in `.env`, and every trace of it disappears from
  both this page and the settings screen.
- **Wire `?redirect=` through login.** The framework captures it onto `/login`, but the
  form does not re-emit `{!! Login_Redirect::hidden_input() !!}` and the success branches
  hard-code their destinations, so only `/logout` honours it. The portal login blade shows
  the complete shape.

## PASSWORD RESET

**The link** - `Login_User_Password_Reset_Model` (`login_user_password_resets`, NOT site-scoped:
a login identity spans every site) over the shared `Password_Reset_Token` trait
(`rsx/lib/password_reset/`, also `Portal_Password_Reset_Model`'s): the table stores
`token_hash` = SHA-256 of the link's 256-bit secret, never the link, so only the instance that
minted it can build the URL (`get_reset_url()`); ONE live link per identity (minting ends the
others); `rsx.auth.password_reset_expiry_hours` (1); single use by an atomic `claim()`;
ANY change of the identity's password - completing a link, Password & Security, an
administrator's Reset Password, the forced change, the CLI - ends every other open link
(`invalidate_open_for()`, called once, by `rsx/handlers/Password_Event_Handlers` on the
framework's `login_user.password.changed`). Used and expired links are deleted nightly, staff
and portal, by the maintenance task `Password_Reset_Link_Service::sweep` (`rsx/services/`).

**Recorded and announced**: every change, and every request that mailed a link, is an
action-log row on the user's history on each of their sites, with the address it came from;
a change the owner made (their own, a link, the forced change) also mails
`Password_Changed_Email` (SECURITY). Each change states its cause on the way in -
`set_password($password, Password_Change::CAUSE_*)`: `self` in Password & Security,
`reset_link` here, `admin` in User Management (the framework states `forced_change` and `cli`).
The request side fires the application event `login_user.password.reset_requested` from
`__send_link_if_account()` (only when a link was minted). `rsx/lib/action_log/CLAUDE.md`.

**What the request page says** is the same sentence for every address - this template never
reveals whether an address holds an account (an unknown, deleted or disabled address is mailed
nothing and told the same). An application that wants a truthful "no account with that address"
changes `Password_Reset_Controller::request()` and accepts that the page then answers who has an
account, to anyone who passes Turnstile.

**Which password rule**: `rsx.auth.password_min_length` (8) on every staff form that chooses a
password (accept invite, Password & Security, the reset link, the forced change, Reset Password).
The framework itself refuses only an empty password and, on the forced change, the current one.

**Passkey and SSO sign-ins are not refused by the flag** - they never present the password -
so a flagged user who signs in that way keeps the flag until their next password sign-in.

## CREDENTIAL FIELDS

Every field that asks for a sign-in identifier, a password or a one-time code - on these pages,
on the Password & Security screen (`rsx/app/frontend/settings/`) and in the "confirm it's you"
dialog (`rsx/lib/password_confirmation/`) - carries an `autocomplete` TOKEN and a STABLE id.
Browser password managers read the token first; the id-matching extensions save a field's
`id`/`name` and look it up again with an unescaped `querySelector('#' + id)`, so a jqhtml
`<sid>:<cid>` id (it moves with mount order, and its colon is invalid in a selector) makes them
fill nothing. A field with no token is worse still: the framework turns on jqhtml's
`autocomplete_off`, so every template-written `<input>` without one renders
`autocomplete="off"`, and `Rsx_Blade_Autocomplete` gives every Blade-written `<input>` and
`<textarea>` without one the same `off` at boot (`rsx:man jqhtml`, AUTOCOMPLETE AND AUTOFILL).

**The tokens:**

| Field | `autocomplete` |
|---|---|
| The sign-in identifier (email), on sign-in, accept invite, create account, any form setting a password | `username` - even on `type="email"`; `email` alone reads as a contact address |
| The password on a sign-in page or a "confirm it's you" prompt | `current-password` |
| The current-password field of a change-password form | `current-password` |
| A password being chosen, and its confirmation | `new-password` (both) |
| A person's name on create account | `given-name` / `family-name` (`name` when one field) |
| An emailed or authenticator one-time code | `one-time-code` (`Code_Input`'s first box carries it) |
| A field nobody should be offered suggestions in (search, filter, picker typing box) | no token - it renders `off`. A credential field never does |

**A form that sets or changes a password carries the account identifier** as a field with
`autocomplete="username"` - read-only when the user may not change it, `hidden` when it is not
shown - or a password manager saves the new password as a second, nameless entry. Such a field
has no name the endpoint reads (or a name the endpoint already ignores): it is a hint, never
input. Create account shows it read-only (`$readonly`, not `$disabled` - a manager skips a
disabled field); Password & Security renders a hidden one from `get_sign_in_email`.

**The id convention.** The SAME id for the same kind of field on every credential page of the
origin, staff and portal alike, because a manager replays an id saved on one page on another:

| Field | id |
|---|---|
| The sign-in identifier | `email` |
| The password on a sign-in page or confirm prompt | `password` |
| The current password of a change-password form | `current_password` |
| A password being chosen / its confirmation | `new_password` / `new_password_confirmation` |
| A one-time code | `code` |
| First / last name | `given_name` / `family_name` |

Each page shows at most one of each, so the ids are unique per document. A page that could
show one form twice would suffix the second (`email_2`, ...) and say so beside it; none here
does. The `confirm it's you` dialog uses `password` and opens only over pages whose own fields
are `current_password` / `new_password`, so the two never meet.

**How a field gets them.** A Blade page writes `id`, `name` and `autocomplete` on the
`<input>` directly; `name` stays whatever the endpoint reads (`password_confirm` on the portal's
register page carries the id `new_password_confirmation`). A jqhtml field takes `$autofill_id`
on its `$sid` tag (jqhtml renders `id` and `name` from it, `this.$sid()` still finds it); an
input component takes it from the page and forwards it:

```html
<Text_Input $name="password" $type="password" $max_length=-1
            $autofill_id="new_password" $autocomplete="new-password" />
<Two_Factor_Challenge $controller="Login_Controller" $method="verify_2fa" $autofill_id="code" ... />
```

`Text_Input` forwards `$autofill_id` / `$autocomplete` (and takes `$readonly`); the framework's
`Password_Input`, `Code_Input`, `Two_Factor_Challenge`, `Totp_Enrollment` and
`Password_Confirmation_Form` take `$autofill_id`. A new input that can hold a credential, a
name or an address forwards both the same way (skill `rspade:form-input-contract`).

| Page | Fields |
|---|---|
| `login_index.blade.php` | `email` (`username`), `password` (`current-password`) |
| `login_verify.blade.php` | `<Two_Factor_Challenge $autofill_id="code">` (`one-time-code`; the recovery box is `off`) |
| `accept_invite/create_account.blade.php` | `given_name`, `family_name`, `email` (read-only, `username`), `new_password`, `new_password_confirmation` |
| `login_new_password.blade.php` | hidden read-only `email` (`username`, the parked address, unnamed), `new_password`, `new_password_confirmation` (`new-password`) |
| `password_reset/password_reset_request.blade.php` | `email` (`username`) |
| `password_reset/password_reset_reset.blade.php` | hidden read-only `email` (`username`, the link's identity, unnamed), `new_password`, `new_password_confirmation` (`new-password`) |
| `accept_invite/accept_invite.blade.php`, the site picker, site unauthorized, `password_reset/password_reset_invalid.blade.php` | no fields |
| Password & Security (`frontend/settings/password_security/`) | hidden `email` (`username`), `current_password`, `new_password`, `new_password_confirmation`; TOTP enrollment's code `code` |
| `Password_Confirmation_Modal` (`rsx/lib/password_confirmation/`) | `password` (`current-password`) |
| Add / edit user (`frontend/settings/user_management/`) | no credential: the email is SOMEBODY ELSE's address and stays `off` |
| Reset Password (`frontend/settings/user_management/reset_password/`) | hidden read-only `email` (`username`) carrying the TARGET user's sign-in address - so a password manager files the typed password under them, never over the administrator's own login - and `new_password` (`new-password`, shown in clear: it is read out) |

## WHERE A STAFF PASSWORD IS SET

Every path saves through `Login_User_Model::set_password()` + `save()`, once, at a line marked
"The one place ...": Password & Security (`change_password`), the reset link
(`Password_Reset_Controller::reset`), the forced change (inside the framework's
`RsxAuth::complete_forced_password_change()`, called from `Login_Controller::new_password`),
an administrator (`Frontend_Settings_User_Management_Controller::set_user_password`), the
operator (`rsx:users:password:set`); plus an account's first password (accept invite, the
initial user). The model's own consequences follow every one: a cleared forced-reset flag
(unless `require_password_reset()` was asked in the same save), forgotten two-factor trusted
browsers, every other sign-in ended.

## WHO SIGNS ANYBODY IN

The template has NO self-signup, staff or portal (owner ruling, 2026-11-03; always-on
knowledge carries the ruling). Every staff entrance finishes in
`Login_Controller::complete_sign_in()`, every portal one in
`Portal_Login_Controller::complete_sign_in()`. The only other callers that sign anybody in
are the framework acting for those controllers (the 2FA engine for their verify endpoints,
the SSO ceremony under their `sso.*` hooks), `rsx:debug` dev-auth (development tooling) and
impersonation / View as Client (not a sign-in); `Sample_Data_Service::seed` sets an acting
identity for attribution only (a task, not a web path). Checked by grepping every
`RsxAuth::login(`, `set_login_user_id(` and `set_portal_user_id(` caller under `rsx/` - a
new hit is a new door and needs the owner's approval.

## RELATED

`rsx/permission.php` ·
`rsx/portal/CLAUDE.md` (the portal's own auth ladder) · skills `rspade:session-auth`,
`rspade:turnstile`, `rspade:blade-views`, `rspade:auth-gates` · `rsx:man session`,
`rsx:man turnstile`, `rsx:man auth_gates`, `rsx:man two_factor`, `rsx:man sso`, `rsx:man user_preference_variables`
