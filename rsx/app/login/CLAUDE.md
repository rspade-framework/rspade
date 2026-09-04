# rsx/app/login — the server-rendered staff auth ladder

## WHAT IS HERE

Blade pages, not SPA actions: this is what an unauthenticated visitor sees. Every
controller is class-level `#[Auth('public')]` with a written justification in its docblock.

| Rung | Class / file | Route | What it does |
|---|---|---|---|
| Login | `Login_Controller` (`login_controller.php`) | `/login` GET+POST | Turnstile, then `RsxAuth::attempt($credentials, record: false, touch_last_login: false)` - the PASSWORD stage only. A failure records `STATUS_FAILED_PASSWORD` itself. On success: a second factor issues the challenge, otherwise `RsxAuth::login()` + `record_success()` and `post_login_destination()`. |
| 2FA challenge | `Login_Controller::verify` + `verify_2fa` | `/login/verify` GET + an `#[Ajax_Endpoint]` | The screen hosting `<Two_Factor_Challenge>`, and the endpoint it posts to. Nothing pending redirects back to `/login`. |
| 2FA setup | `Login_Controller::two_factor_setup` | `/login/two_factor_setup` GET | The forced-enrollment interstitial, the one method-level `#[Auth('is_logged_in')]` in this module. |
| Logout | `Login_Controller::logout` | `/logout` | `RsxAuth::logout()` then `Login_Redirect::consume($default)`. |
| Signup | `Signup_Controller` (`signup/`) | `/signup` GET + an `#[Ajax_Endpoint]` `submit` | Gated by `config('rsx.auth.signup_mode')` (`invite_only` by default, also `disabled` / open). Creates the `Login_User_Model`. |
| Accept invite | `Accept_Invite_Controller` (`accept_invite/`) | `/accept-invite`, `/accept-invite/create-account`, `/accept-invite/success` | Six states (invalid, expired, email mismatch, already accepted, not logged in, logged in) plus the create-account form for an invitee with no login account. |
| Site selection | `Site_Selection_Controller` | `/login/select-site`, `/login/site/:id` | `select` re-checks membership and sets the site. The picker page itself is a stub. |
| Site unauthorized | `Site_Unauthorized_Controller` | `/login/site-unauthorized` | The signed-in identity has no membership on this site; offers the sites it does have. |

The login page also carries FEDERATED SIGN-IN: below the Sign In button,
`login_index.blade.php` renders an `or` divider and the framework's provider buttons, both
inside one `@if (Rsx_Sso::is_enabled())` — the component renders nothing on its own when no
provider is switched on, so the divider has to ask the same question. `login_index.scss`
holds the divider rule. Nothing else in this module changes: the ceremony is entirely the
framework's (`/_sso/...`), and this application's policy lives in
`rsx/handlers/Sso_Handlers.php`.

`invite_helper.php` (`Invite_Helper`) is the shared validator — **an invitation is a
`User_Model` row** carrying `invite_code` / `invite_expires_at` / `invite_accepted_at`, not
a separate table. `login_bundle.php` is the module bundle (theme variables and responsive
first, then bootstrap5, `rsx/theme/components`, `rsx/lib`, then this whole directory).

## HOW IT IS USED

**Turnstile.** `<Turnstile_Input />` sits in `login_index.blade.php` and
`signup/signup_index.blade.php`; the endpoint answers it as the FIRST statement of the POST
branch — `Rsx_Turnstile::validate($request)` in `login_controller.php:68`, and the two-arg
`validate($request, $params)` in `signup_controller.php:141` because a batched sub-call
carries the field in `$params`. The field always submits (sentinel `inactive` while the
feature is off), so validating it is not optional.

**The throttle.** `login_controller.php` catches `Auth_Throttled_Exception` around the
whole attempt and surfaces `$e->getMessage()` verbatim ahead of the wrong-password branch,
so a lockout is never reported as bad credentials. `RsxAuth::attempt()` throws it as its
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

**There is no Turnstile on `verify_2fa`, deliberately.** `<Two_Factor_Challenge>` posts
`{code}` or `{assertion}` and renders no widget, so there is no `__turnstile` field; the
framework's completeness guard fires only when a token WAS submitted. The endpoint is not
unguarded - it answers only from the challenge parked on the caller's own session, and
`verify_challenge()` spends the brute-force budget as its first statement.

**The invite code rides the session across the challenge.** The component's contract carries
no query string and no extra fields, so `index()` parks the code under
`Login_Controller::INVITE_CODE_KEY` with the challenge's own expiry and `verify_2fa` consumes
it once. Both paths then call the ONE destination function, `post_login_destination()` -
invite, site selector, dashboard, or site-unauthorized - because a destination computed twice
drifts.

**Federated sign-in reaches this module twice.** `Sso_Handlers` answers the framework's
`sso.identity.unlinked` hook, and its two outcomes both land here: a VERIFIED provider email
matching a `login_users` row is signed straight in (destination from
`Login_Controller::post_login_destination()`, which the handler calls as its third caller —
that is why the method is public), and an address with an OPEN INVITATION is sent to
`/accept-invite?code=...` with the provider identity still parked as pending.
`Accept_Invite_Controller::create_account_submit()` then connects it with
`Rsx_Sso::link_pending()`, **inside the transaction that creates the account** — and because
that connection becomes the account's credential, the password is OPTIONAL on that one
submit (blank stores an unusable hash; a password that IS typed is validated exactly as
always). The match is the invitation's own address, compared case-insensitively; a pending
identity for any other address is left alone. `create_account.blade.php` says
"You're signing up with {provider}" from the `sso_identity` the controller passes it, and
re-computes it on submit — a page rendered inside the pending window and submitted outside
it asks for a password after all.

**The forced-enrollment interstitial.** `users.is_2fa_required` is an APP column (added by
`rsx/resource/migrations/2026_09_02_133139_add_is_2fa_required_to_users.php`, set from the
edit-user modal). `Rsx\Main::pre_dispatch()` bounces a flagged identity with no factor to
`/login/two_factor_setup`, exempting impersonation and `Session::TYPE_PLAYWRIGHT` (rsx:debug's
dev-auth logs in without a challenge and must not bounce). The handler-prefix check there
covers `Rsx\App\Frontend` only, so this module is outside it and there is no loop.
`login_two_factor_setup.js` mounts the chosen framework enrollment component and sends the user
to `/` on `enrolled`/`registered`.

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
Blade page's JS in this module needs that guard. `login_two_factor_setup.js` is the second
one: `if (!$('.Login_Two_Factor_Setup').exists()) return;`.

## HOW TO CUSTOMIZE

- **Rebrand**: `login_layout.blade.php` is the card shell every blade extends;
  `login_layout.scss` holds the centred card and the hardcoded brand gradient — the one
  literal colour pair in the module, and the first thing to change. `login_index.scss` only
  narrows the card; `signup/signup_index.scss` is an empty placeholder.
- **Add a rung**: a controller with `#[Auth('public')]` and a justification, a blade
  extending `Login_Layout`, and Turnstile validated first in any POST branch.
- **Change where a signed-in user lands**: `post_login_destination()` in
  `login_controller.php`, and nowhere else - the password-only path, the second-factor
  endpoint and `Sso_Handlers::login_destination()` all read it.
- **Change the SSO account policy**: `rsx/handlers/Sso_Handlers.php`, one method. Removing
  the provider buttons from this page is the `@if` in `login_index.blade.php`; switching the
  feature off entirely is `SSO_*_ENABLED` in `.env`, and every trace of it disappears from
  both this page and the settings screen.
- **Change the forced-2FA policy**: the flag is `users.is_2fa_required` and the interception is
  in `rsx/main.php`. Requiring it for a whole role instead of per user is a change to that one
  condition.
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

`rsx/main.php` (`pre_dispatch` bounces a bad site membership here) · `rsx/permission.php` ·
`rsx/portal/CLAUDE.md` (the portal's own auth ladder) · skills `rspade:session-auth`,
`rspade:turnstile`, `rspade:blade-views`, `rspade:auth-gates` · `rsx:man session`,
`rsx:man turnstile`, `rsx:man auth_gates`, `rsx:man two_factor`, `rsx:man sso`
