# rsx/app/login — the server-rendered staff auth ladder

## WHAT IS HERE

Blade pages, not SPA actions: this is what an unauthenticated visitor sees. Every
controller is class-level `#[Auth('public')]` with a written justification in its docblock.

| Rung | Class / file | Route | What it does |
|---|---|---|---|
| Login | `Login_Controller` (`login_controller.php`) | `/login` GET+POST | Turnstile, then `RsxAuth::attempt()`. On success: an invite `code` goes to accept-invite; more than one enabled `User_Model` goes to site selection; exactly one sets the site and lands on the dashboard; none goes to site-unauthorized. |
| Logout | `Login_Controller::logout` | `/logout` | `RsxAuth::logout()` then `Login_Redirect::consume($default)`. |
| Signup | `Signup_Controller` (`signup/`) | `/signup` GET + an `#[Ajax_Endpoint]` `submit` | Gated by `config('rsx.auth.signup_mode')` (`invite_only` by default, also `disabled` / open). Creates the `Login_User_Model`. |
| Accept invite | `Accept_Invite_Controller` (`accept_invite/`) | `/accept-invite`, `/accept-invite/create-account`, `/accept-invite/success` | Six states (invalid, expired, email mismatch, already accepted, not logged in, logged in) plus the create-account form for an invitee with no login account. |
| Site selection | `Site_Selection_Controller` | `/login/select-site`, `/login/site/:id` | `select` re-checks membership and sets the site. The picker page itself is a stub. |
| Site unauthorized | `Site_Unauthorized_Controller` | `/login/site-unauthorized` | The signed-in identity has no membership on this site; offers the sites it does have. |

`invite_helper.php` (`Invite_Helper`) is the shared validator — **an invitation is a
`User_Model` row** carrying `invite_code` / `invite_expires_at` / `invite_accepted_at`, not
a separate table. `login_bundle.php` is the module bundle (theme variables and responsive
first, then bootstrap5, `rsx/theme/components`, `rsx/lib`, then this whole directory).

## HOW IT IS USED

**Turnstile.** `<Turnstile_Input />` sits in `login_index.blade.php` and
`signup/signup_index.blade.php`; the endpoint answers it as the FIRST statement of the POST
branch — `Rsx_Turnstile::validate($request)` in `login_controller.php:44`, and the two-arg
`validate($request, $params)` in `signup_controller.php:141` because a batched sub-call
carries the field in `$params`. The field always submits (sentinel `inactive` while the
feature is off), so validating it is not optional.

**The throttle.** `login_controller.php:74` catches `Auth_Throttled_Exception` around the
whole attempt and surfaces `$e->getMessage()` verbatim ahead of the wrong-password branch,
so a lockout is never reported as bad credentials. `RsxAuth::attempt()` throws it as its
own first statement; nothing here counts failures itself.

**`Login_Redirect`.** One call site: `login_controller.php:169`, `consume($default)` on
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

- **Rebrand**: `login_layout.blade.php` is the card shell every blade extends;
  `login_layout.scss` holds the centred card and the hardcoded brand gradient — the one
  literal colour pair in the module, and the first thing to change. `login_index.scss` only
  narrows the card; `signup/signup_index.scss` is an empty placeholder.
- **Add a rung**: a controller with `#[Auth('public')]` and a justification, a blade
  extending `Login_Layout`, and Turnstile validated first in any POST branch.
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
`rsx:man turnstile`, `rsx:man auth_gates`
