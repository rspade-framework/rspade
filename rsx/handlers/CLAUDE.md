# rsx/handlers — the application's event handlers

## WHAT IS HERE

Six classes, each a plain `public static` class in `Rsx\Handlers` discovered by the
manifest from its `#[OnEvent]` attributes. There is no registration step.

- **`File_Upload_Handlers`** — `#[OnEvent('file.upload.authorize', priority: 10)]`. Returns
  `true` when either realm is signed in (`Session::is_logged_in()` or
  `Portal_Session::is_logged_in()`), a 403 JSON response otherwise. Both realms are allowed
  deliberately: portal users attach files to request threads through the same transport, and
  the per-feature check happens where the file is claimed.
- **`Initial_User_Handlers`** — `#[OnEvent('user.initial.created', priority: 10)]`. Given
  `{user, login_user, site_id, source}` it promotes the founder to `ROLE_ROOT_ADMIN` **only
  if no role was chosen**, creates the site's `Administrators` `User_Group_Model` with
  `deletion_protection` on, and attaches the founder to it.
- **`User_Profile_Url_Handlers`** — `#[OnEvent('user.view_profile_url')]` (resolve). Where
  this application shows a staff user: the viewer's own profile page for their own record,
  the user-management detail screen for anyone else, each resolved through
  `Auth_Gates::accessible_route()` so the link exists exactly when the viewer may follow it;
  null in the portal realm. With no answer `<Record_Author>` renders plain text.
- **`Sso_Handlers`** — the federated-sign-in hooks. `sso.identity.unlinked`
  (`match_verified_email_of_existing_account()`) is the policy decision, and the policy is
  that SSO signs in an account that ALREADY EXISTS and does nothing else: a **verified**
  provider email matching a `login_users` row is connected and signed in through
  `Rsx_Sso::consume_pending_and_login()`; anything else - an unverified email, no account,
  an open invitation to the address - declines and the framework fails closed. Accounts are
  created in advance, by an administrator's invitation accepted with a password. The rest
  are wiring — `sso.login.authorize` permits (it MIRRORS the password door, which
  enforces only a live `login_users` row), `sso.two_factor.verify_url` returns
  `Rsx::Route('Login_Controller::verify')`, `sso.login.destination` delegates to
  `Login_Controller::post_login_destination()`, and `sso.link.destination` returns to the
  Password & Security screen.
- **`Portal_Sso_Handlers`** — the five PORTAL federated-sign-in hooks (`portal.sso.*`), a set
  deliberately separate from `Sso_Handlers` so the staff policy never governs a client, and
  reached only while `rsx.sso.portal_enabled` is on. `portal.sso.identity.unlinked` connects a
  **verified** provider email to the portal user this site already has for it
  (`Portal_User_Model::find_by_email(Portal_Session::get_site_id(), ...)`) and signs them in
  through `Rsx_Portal_Sso::consume_pending_and_login()`; anything else declines - a portal
  account is created only by an invitation, never by a provider. `portal.sso.login.authorize`
  permits (the framework already applies `can_login()`), `portal.sso.two_factor.verify_url`
  returns the portal `/login/verify` page, `portal.sso.login.destination` delegates to
  `Portal_Login_Controller::post_auth_destination()`, and `portal.sso.link.destination`
  returns to portal Settings.
- **`Portal_File_Access_Handlers`** — `#[OnEvent('file.thumbnail.authorize')]` and
  `#[OnEvent('file.download.authorize')]`, both priority 10, both delegating to one
  fail-closed `_authorize()`, which forks on the REALM of the request
  (`Rsx_Portal::is_portal_request()`) and judges the gate payload's own `user` - never
  whichever identity happens to be on the session. On a staff URL an ACTIVE staff membership
  (`User_Model::is_active()`) passes. On a portal URL only the portal user passes, and only
  for (a) a client `documents` file shared with THEIR OWN contact by an UNEXPIRED share
  (`Shared_Item_Model::find_valid_share()`) of a client they hold a live membership of, or
  (b) an attachment on a request thread of such a client. A staff session on a portal file
  URL gets nothing. These are the template's only read-gate handlers, and the framework
  refuses every file read when a read gate has none (`Rsx_File_Gates`).

## HOW IT IS USED

**The upload gate is mandatory.** Both upload transports THROW when no
`file.upload.authorize` handler is registered — an unhandled gate would be an anonymous
upload endpoint — so `File_Upload_Handlers` is not optional scaffolding. Its payload carries
`request`, `user`, `params`, `file`, `filename`, `size`, `mime_type`, `extension` and
`tmp_path`, so a stricter policy can read the real bytes and reject before anything persists.

**Gate semantics**: every handler must return `true`; the first non-`true` return denies. The
three FILE gates are the exception to "a gate with no handlers is open": the framework asks
them fail-closed (`Rsx_File_Gates`), so removing a handler here switches that kind of file
access off.

**Handlers run inline, in the request.** Anything slow belongs in `Task::dispatch()`.

**An unverified provider email is never matched to an account.** A provider that lets a user
type any address into a profile hands over a CLAIM, not a fact, so matching one would let
anybody who can name your address at such a provider sign in as you — no password, no
notification. `Sso_Handlers::match_verified_email_of_existing_account()` reads
`email_verified` for exactly that reason. The other policy modes an application can implement
there — auto-provision, finish-registration — are named in the method's docblock and written
out in `php artisan rsx:man sso`.

`user.initial.created` is a handler and not a migration on purpose: a migration runs once per
database at a fixed point in history, while this event also fires for the first-run setup
screen and for the test-suite baseline seed, so a test may rely on the group existing.

## HOW TO CUSTOMIZE

- **Tighten the upload gate** by editing `File_Upload_Handlers::require_authentication` —
  a size or extension policy belongs there, where it runs before any byte is stored. Never
  delete the handler to "open uploads"; that closes them instead, loudly.
- **Change the SSO account policy** by rewriting
  `Sso_Handlers::match_verified_email_of_existing_account()` — it is one method and one
  decision. Adding an account-state rule (suspended, pending approval) goes in
  `Sso_Handlers::authorize_login()` **and** in `Login_Controller::index()`, in the same
  change: a check that exists on one door only leaves the other one open.
- **Change what the founder gets** in `Initial_User_Handlers` — extra rows for the first
  account go here, never in a migration keyed to user id 1.
- **Add a handler**: a new class or method in this directory with `#[OnEvent('name')]`.
  Match the trigger kind's return contract — action (ignored), filter (return the
  transformed data), gate (`true` to permit), resolve (`null` to decline).
- Keep one concern per class; the file names are the index a reader scans.

## RELATED

`rsx/services/CLAUDE.md` · `rsx/app/login/CLAUDE.md` (the ladder `Sso_Handlers` routes
into) · skills `rspade:event-hooks`, `rspade:file-attachments`, `rspade:portal-core` ·
`rsx:man event_hooks`, `rsx:man file_upload`, `rsx:man initial_user`, `rsx:man sso`
