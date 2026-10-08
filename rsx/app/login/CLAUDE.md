# rsx/app/login — the server-rendered staff auth ladder

## WHAT IS HERE

Blade pages, not SPA actions: this is what an unauthenticated visitor sees. Every
controller is class-level `#[Auth('public')]` with a written justification in its docblock.

| Rung | Class / file | Route | What it does |
|---|---|---|---|
| Login | `Login_Controller` (`login_controller.php`) | `/login` GET+POST | Turnstile, then `RsxAuth::verify_credentials($credentials, record: false)` - the password is checked and NOBODY is signed in. A failure records `STATUS_FAILED_PASSWORD` itself. On success `__account_refusal()` is asked (the application's own account rules; it admits everybody as shipped), then a second factor issues the challenge, otherwise `RsxAuth::login()` + `record_success()` and `post_login_destination()`. `RsxAuth::login()` and a passed challenge are the only things that sign anybody in. |
| 2FA challenge | `Login_Controller::verify` + `verify_2fa` | `/login/verify` GET + an `#[Ajax_Endpoint]` | The screen hosting `<Two_Factor_Challenge>` (with `$cancel_url` = `/login`: Cancel discards the challenge and returns to the form), and the endpoint it posts to. Nothing pending redirects back to `/login`. |
| Passkey sign-in | `Login_Controller::passkey_login` | an `#[Ajax_Endpoint]` | The endpoint `<Passkey_Sign_In>` on the login page posts to: `Rsx_Two_Factor::verify_passkey_login()`, then `post_login_destination()`. Passwordless - no password stage, no second factor after it. |
| Logout | `Login_Controller::logout` | `/logout` | `RsxAuth::logout()` then `Login_Redirect::consume($default)`. |
| Signup | `Signup_Controller` (`signup/`) | `/signup` GET + an `#[Ajax_Endpoint]` `submit` | Gated by `config('rsx.auth.signup_mode')` (`invite_only` by default, also `disabled` / open) on BOTH the page and `submit` - a POST never succeeds where the page would refuse. Creates the `Login_User_Model`; an address that already has an account gets the same answer and nothing is written, so the form reveals no existing address. |
| Accept invite | `Accept_Invite_Controller` (`accept_invite/`) | `/accept-invite`, `/accept-invite/create-account`, `/accept-invite/success` | Six states (invalid, expired, email mismatch, already accepted, not logged in, logged in) plus the create-account form for an invitee with no login account. |
| Site selection | `Site_Selection_Controller` | `/login/select-site`, `/login/site/:id` | `select` accepts only an active membership (`->active()`, error card otherwise) and sets the site. The picker page itself is a stub. |
| Site unauthorized | `Site_Unauthorized_Controller` | `/login/site-unauthorized` | The signed-in identity's session names a site it is not a member of; offers the sites it does have. Nothing in this mono-site template routes to it - the framework ends such a session itself (see SITE MEMBERSHIP below), so it is kept as the pattern for an app that declares the requested site from the host. |

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
a separate table. `login_bundle.php` is the module bundle, and it carries the SAME theme set as
`Frontend_Bundle`: `rsx/theme/variables.scss`, `rsx/theme/responsive.scss`,
`Bootstrap5_Src_Bundle`, the whole `rsx/theme` tree (composition tokens, layout, badges
and the component library, not just `components/`), `rsx/lib`, then this directory. That
is what makes the auth ladder look like the staff application rather than like a separate
product.

## HOW IT IS USED

**The layout is the staff theme's Bootstrap, and nothing else.**
`login_layout.blade.php` centres one Bootstrap `.card` (`.card-header` / `.card-body`) on
a `var(--bs-tertiary-bg)` page; `login_layout.scss` holds ONLY the centring, the 500px
card width, the header/body padding and the mobile adjustment, all under `.Login_Layout`
in BEM (`.Login_Layout__viewport`, `__card`, `__header`, `__title`, `__subtitle`,
`__body`). There is no login-only palette: every colour on these pages is a runtime token
the theme already defines, so the auth ladder follows light and dark exactly as the
dashboard does. A page under this layout writes ordinary Bootstrap markup - `.alert`,
`.form-control`, `.btn-primary`, `.d-grid` - and gets the frontend's own look for free.

**Light and dark are resolved by the framework, not by this module.**
`rsx_body_class()` and `rsx_body_attributes()` on the `<body>` tag are the whole
mechanism, the same pair `Spa_App.blade.php` uses for the authenticated shell:
`Rsx_Dark_Mode` paints an explicit light/dark preference server-side in the first bytes of
HTML, and `data-bs-theme` comes from `config('rsx.theme.dark_mode.attributes')`. An
anonymous visitor has no stored preference, so the configured default applies - AUTO by
default, which means the body carries `rsx-theme-auto` with no theme, and
`Rsx_Dark_Mode.js` resolves `prefers-color-scheme` at boot and keeps following it. A
visitor who signs in and then returns to a login page sees their own stored choice,
because the same class answers for both. Nothing about the mode lives in this directory.

**Turnstile.** `<Turnstile_Input />` sits in `login_index.blade.php` and
`signup/signup_index.blade.php`; the endpoint answers it as the FIRST statement of the POST
branch — `Rsx_Turnstile::validate($request)` in `login_controller.php:68`, and the two-arg
`validate($request, $params)` in `signup_controller.php:141` because a batched sub-call
carries the field in `$params`. The field always submits (sentinel `inactive` while the
feature is off), so validating it is not optional.

**Site membership is the framework's, not this module's.** `users.is_enabled` and
`sites.is_enabled` are the framework's switches for "may this identity use this site", and
a membership is usable only when both are on (`User_Model::is_active()`, the `->active()`
scope): `RsxAuth::verify_credentials()` refuses an identity holding no active membership exactly as it
refuses a wrong password (recording `STATUS_FAILED_DISABLED`), `RsxAuth::login()` refuses it
on the second-factor and federated paths, and `Session::enforce_enabled_membership()` ends a
live session whose membership or site is disabled or deleted, before every dispatch and every
Ajax call. So no controller here restates either column: the site lists
(`post_login_destination()`, the site-unauthorized picker) and `Site_Selection_Controller::select`
filter with `->active()`, `select` answering the error card for an unusable site,
`post_login_destination()` has no zero-sites branch (a successful sign-in guarantees at least
one active membership), and `rsx/main.php` checks no membership. Identity state - `status_id`, `is_activated`, `is_verified` - is this application's
vocabulary, and this template enforces none of it: neither `Login_Controller::index()` nor
`Rsx\Main::pre_dispatch()` reads those columns. An application that wants Suspended to refuse
sign-in checks it in both places.
See `rsx:man session`.

**The throttle.** `login_controller.php` catches `Auth_Throttled_Exception` around the
whole attempt and surfaces `$e->getMessage()` verbatim ahead of the wrong-password branch,
so a lockout is never reported as bad credentials. `RsxAuth::verify_credentials()` throws it as its
own first statement, and `verify_2fa` catches the same exception from
`Rsx_Two_Factor::verify_challenge()` and answers it as an `ERROR_VALIDATION` the challenge
component renders inline. Nothing here counts failures itself: `Login_History::record_failure()`
is what feeds the throttle, and it is called once per failed password.

**Two-factor authentication.** The login is TWO STAGES. `index()` verifies the password with
BOTH the recording and the `last_login` stamp suppressed, so a recorded SUCCESS always means
full authentication; if `Rsx_Two_Factor::is_enabled($login_user)` it calls `begin_challenge()`
(which parks the pending identity and LOGS THE SESSION BACK OUT) and redirects to
`/login/verify`. `verify_2fa` calls `Rsx_Two_Factor::verify_challenge($params)` - which signs
the identity in, stamps `last_login` and writes the success row - and answers
`{redirect: <url>}`, which the component follows with `window.location`.

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
`Login_Controller::INVITE_CODE_KEY` with the challenge's own expiry and `verify_2fa` consumes
it once. Both paths then call the ONE destination function, `post_login_destination()` -
invite, site selector, or dashboard - because a destination computed twice drifts.

**Federated sign-in signs in existing accounts only.** `Sso_Handlers` answers the
framework's `sso.identity.unlinked` hook: a VERIFIED provider email matching a `login_users`
row is signed straight in (destination from `Login_Controller::post_login_destination()`,
which the handler calls as its third caller — that is why the method is public), and
everything else declines. Accounts are created in advance - an administrator's invitation,
accepted on `/accept-invite` with a password - so the accept-invite flow never sees a
provider identity.

**No forced enrollment.** This application requires nobody to enroll a second factor: a user
adds one from Settings > Password & Security, and the login flow challenges whoever has one.
There is no "2FA required" setting and no screen that holds a signed-in user until they enroll.

**`Login_Redirect`.** One call site: `login_controller.php:281`, `consume($default)` on
logout. Login itself does not round-trip the parameter — see HOW TO CUSTOMIZE.

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
  only the SSO "or" divider; `signup/signup_index.scss` is an empty placeholder.
- **Add a rung**: a controller with `#[Auth('public')]` and a justification, a blade
  extending `Login_Layout`, and Turnstile validated first in any POST branch.
- **Change where a signed-in user lands**: `post_login_destination()` in
  `login_controller.php`, and nowhere else - the password-only path, the second-factor
  endpoint and `Sso_Handlers::login_destination()` all read it.
- **Change the SSO account policy**: `rsx/handlers/Sso_Handlers.php`, one method. Removing
  the provider buttons from this page is the `@if` in `login_index.blade.php`; switching the
  feature off entirely is `SSO_*_ENABLED` in `.env`, and every trace of it disappears from
  both this page and the settings screen.
- **Require a second factor, or add any step a signed-in user must do first** (accept terms,
  a profile picture): work out what the person still owes in `index()` / `verify_2fa()` after
  the sign-in, park it with `Session::put_value()`, and redirect page requests to the step's
  screen from `Main::pre_dispatch()` while it is set. A step skipped for good is recorded with
  `$login_user->set_variable()`. `rsx:man two_factor` (REQUIRING A SECOND FACTOR),
  `rsx:man user_preference_variables`.
- **Wire `?redirect=` through login.** The framework captures it onto `/login`, but the
  form does not re-emit `{!! Login_Redirect::hidden_input() !!}` and the success branches
  hard-code their destinations, so only `/logout` honours it. The portal login blade shows
  the complete shape.
- **`Accept_Invite_Controller::create_account_submit` has no Turnstile call** while both
  its siblings do — a public account-creation endpoint with no bot gate.
- **The site picker at `/login/select-site` is a stub** that tells the user they will be
  redirected and then does not redirect them; a multi-site user lands there and can only
  log out. The working picker is on the site-unauthorized page.
- Accepting an invite logs the user in immediately, accounts are created `is_verified = 0`,
  and nothing reads that column or sends the verification mail the signup flash promises.
  Decide the policy before launch.

## RELATED

`rsx/permission.php` ·
`rsx/portal/CLAUDE.md` (the portal's own auth ladder) · skills `rspade:session-auth`,
`rspade:turnstile`, `rspade:blade-views`, `rspade:auth-gates` · `rsx:man session`,
`rsx:man turnstile`, `rsx:man auth_gates`, `rsx:man two_factor`, `rsx:man sso`, `rsx:man user_preference_variables`
