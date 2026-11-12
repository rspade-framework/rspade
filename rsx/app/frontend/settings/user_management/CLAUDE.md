# rsx/app/frontend/settings/user_management — managing site users

## WHAT IS HERE

Four SPA actions under `Settings_Layout` and one controller
(`Frontend_Settings_User_Management_Controller`, gated `can_manage_users` at class level):

- `list/` — `Settings_User_Management_Index_Action` and `Users_DataGrid` over `User_Model`,
  with the add-user modal and the sent-invitation dialog.
- `view/` — `Settings_User_Management_View_Action` (`/frontend/settings/user_management/:id`),
  the edit-user modal, resend-invitation, the user's **Sessions** on this site, and their
  **History** (the page-private region `Settings_User_Management_View_History`).
- `session_view/` — `Settings_User_Management_Session_View_Action`
  (`/frontend/settings/user_management/:id/sessions/:user_session_id`), one of those
  sessions, with its page-private `User_Session_Activity` region.
- `api_keys/` — `Settings_User_Management_Api_Keys_Action`, an administrator's view of
  another user's API keys, with revoke (it mints none). A revocation here (`revoke_user_api_key`)
  is recorded and announced by the framework's `api_key.revoked` through
  `rsx/handlers/Api_Key_Event_Handlers`: a row on the holder's History naming the
  administrator, and an `Api_Key_Notice_Email` to the holder.
- `add_user/`, `edit_user/`, `send_invite/`, `reset_password/` — the modal bodies those screens open.

Two endpoints carry an additional method-level gate: `export_csv`
(`#[Auth('can_export_data')]`) and `get_user_activity` (`#[Auth('can_view_user_activity')]`,
HISTORY below).

## EQUAL-OR-LOWER ADMINISTRATION

A caller creates, edits, re-roles, disables and re-enables only users whose role is EQUAL to
or LOWER than their own - the role's `can_admin_roles` list (`User_Model::$enums`, where every
administering role names itself and the roles below it), read through `can_admin_role()`.
Every write asks it of the target's CURRENT role (`__administrable_user()`: `get_user_for_edit`,
`save_user`, `set_user_enabled`, `get_user_for_password_reset`, `set_user_password`, `revoke_user_api_key`, `terminate_user_session`,
`terminate_all_user_sessions`) and of any NEW role
(`_validate_role_id()`: `add_user`, `save_user`); a refusal is `ERROR_UNAUTHORIZED` and
changes nothing - not the role, the email, the second-factor rule or API access. A
non-selectable role (Root Admin) is never assigned through the form; a user who already holds
it keeps it on save, which is why the edit form lists every role the caller administers
rather than only the selectable ones. Reads (`get_user`, the grid, `get_user_api_keys`,
`get_user_session`, `get_user_session_activity`) stay open to every holder of
`can_manage_users`; `get_user` and `get_user_session` report `can_administer` and the pages
show Edit, Enable/Disable and Sign out only when they would be accepted.

**Adding a user sends ONE invitation.** `add_user` creates the pending membership, mints its
code, emails it and returns `invite_url`; the list page shows that link with
`Send_User_Invite_Modal.show_sent()` and never calls `send_invite` after a create, which would
mint a second code and kill the first email's link.

**Resending an invitation is a write like any other.** `send_invite` mints a new code - which
ends the link the invitee already holds - emails it and returns `invite_url`, and only for a
pending user whose role the caller administers (`__administrable_user()`); a superior's
invitation is refused as `unauthorized`, and the view shows Resend Invite only when
`can_administer`. So a link for a role above the caller's never reaches them, and nobody below
an invitee's level can quietly break their invitation.

**Disabling is `users.is_enabled`** (`set_user_enabled`), never a role: the framework refuses
a disabled membership at sign-in and ends its live sessions, while the role and ACL rows are
kept so re-enabling restores them.

**Nobody acts against their own access.** `set_user_enabled` refuses to switch the caller
off, and `save_user` refuses a role on the caller's own record lower than the one they hold
(`role_id` field error, "You cannot lower your own role"). The view page hides Enable/Disable
on the caller's own row and the edit form offers only the role they already hold
(`get_user_for_edit` reports `is_self`). Peer administration otherwise stands: a Site Owner
may still demote or disable another Site Owner.

## RESET PASSWORD

The view page's **Reset Password** sets a TEMPORARY password: the administrator types it
(shown in clear) and reads it out; `set_user_password` saves it with
`Login_User_Model::set_password()` and `require_password_reset()` in the SAME save (the flag
holds even over a user who was already flagged), so the user's next password sign-in stops at
"Choose a new password" (`rsx/app/login/CLAUDE.md`). The model ends every sign-in of the user
and forgets their trusted browsers; the save states `Password_Change::CAUSE_ADMIN`, and the
framework's `login_user.password.changed` - handled once, by `rsx/handlers/Password_Event_Handlers`
- ends every open "Forgot your password?" link of the identity and records the reset on the
user's History (and the administrator's). Nothing is emailed: the administrator reads the
password out, and the user's own replacement of it at the next sign-in sends the "your password
was changed" notice.

`Reset_User_Password_Modal.show()` first awaits `Password_Confirmation_Modal.require()` (the
endpoint calls `Rsx_Password_Confirmation::require_confirmed()`: this hands the caller a way into
another account), then `Modal.form()` over `Reset_User_Password_Modal_Form`, which loads
`get_user_for_password_reset` (name + the SIGN-IN address for the hidden `autocomplete="username"`
field, so a password manager files the password under the target, never over the
administrator's own login) behind the spinner; on success an alert repeats the password to read
out and says what happens next.

**Refused, and the button absent** (`get_user` reports `can_reset_password`, from the same
`__password_reset_refusal()` the endpoints ask):

- **on your own account** - Change Password asks for the current password first, and this
  would walk around it;
- for a user whose role you do not administer (`__administrable_user()`);
- for a user with no sign-in yet (an invitation not accepted);
- for an identity that is also a member of ANOTHER site - BY DESIGN, not a gap: its password is
  one credential across every site, and this site's administrator has no authority over the
  others; that user resets it themselves ("Forgot your password?"), or an operator does
  (`rsx:users:password:set --require-reset`). An application that is single-site in practice
  (every identity a member of one site) never meets this refusal; one that wants a path for
  such a user anyway - an install-wide administrator, a support tool - adds its OWN endpoint
  with its own authority rule, rather than loosening `__password_reset_refusal()`.

## HISTORY

**A user's history shows what was done TO them as well as what they did.** `get_user_activity`
returns this site's action-log rows whose SUBJECT is the user (`User_Model`, their id) OR whose
ACTOR is their login identity (`Login_User_Model`), newest first, the most recent
`ACTIVITY_LIMIT` (50, the window of every record's Activity tab; the Action Log screen has the
rest). So an administrator's Reset Password of user B is listed on B's page (B is its subject)
and on the administrator's (they are its actor); so are B's own password changes and reset
requests (`rsx/lib/action_log/CLAUDE.md`, account security). Each row carries `ip_address`
(from its audit session - the renderer already prints it in the line) and
`ip_address__location` (`Rsx_Geo_Ip::label()`, every load, never stored), shown beside the line,
with `<Geo_Ip_Credit />` under the region when any row has a location.

**Gate**: `can_view_user_activity` - the Action Log screen's own permission - on top of the
class's `can_manage_users`; the page asks `Activity_Feed.can_view()` before fetching it and draws
the region only then, so a manager without it sees no History rather than an error.

## SESSIONS

Where the user is signed in ON THIS SITE - an identity's sessions on other sites are not this
site's business. `__user_sessions()` asks `Session::get_sessions_for_user($login_user_id,
$site_id, true)` (one entry per browser, impersonations of the user included and labelled,
signed-out ones too); every screen cuts it with `Session_Listing` (`rsx/lib/sessions/`):
active within the last three months, one row shape, `can_sign_out` on a signed-in entry that
is not an API key's use and not the caller's own browser. The session tables are the
framework's and are never queried here.

- `get_user` returns the list as `sessions`; the view page's Sessions section shows device,
  status (signed in, or why and when it ended), IP with its location and last activity, each
  row opening the session page, with Sign out per row and Sign out everywhere.
- **Locations**: every address these screens show travels with `ip_address__location`
  (`Rsx_Geo_Ip::label()` - "City, State, USA", or the network's name for a listed
  cloud/hosting/CDN/AI network - from `Session_Listing::row()` and from
  `get_user_session_activity`), resolved on every load and never stored; it renders through `<Ip_Address_Display>`, and each region
  showing one carries `<Geo_Ip_Credit />` (DB-IP's attribution). `rsx:man geo_ip`.
- **The record rule**: `get_user_session`, `get_user_session_activity` and
  `terminate_user_session` take a `user_session_id` that must be one the view page lists for
  that user (`Session_Listing::find()`); another user's, another site's or a dormant one is
  `ERROR_NOT_FOUND`, the same answer as an id that does not exist.
- `get_user_session_activity` pages the session's audit sessions
  (`Audit_Session::list_page_for_user_session()`, 25 a page, newest first) as address (with
  its location), device and first/last seen. A row exists only where the sign-in did something attributed
  to it; browsing records nothing.
- `terminate_user_session` ends one through the framework's guarded
  `Session::terminate_session_for_user()` (recorded "Ended by an administrator", or "Ended
  from another device" on your own); `terminate_all_user_sessions` ends every signed-in,
  non-API session of the user ON THIS SITE the same way, listed or not, sparing the caller's
  own browser. Neither reaches another site.

## TWO-FACTOR

The view page shows a **Two-Factor** row: whether the account has a factor (`is_2fa_enrolled`,
from `Rsx_Two_Factor::is_enabled()` on the `login_user_id`). Enrollment state is the one
authentication fact these screens show - see the privacy principle below, which it is a
deliberate, narrow exception to. This application has no "2FA required" setting: an
administrator can see who has a factor, not require one.

## HOW TO CUSTOMIZE

- The privacy rule below is the load-bearing convention here; keep it when adding a column
  or a field to any of these screens.
- New screens follow the settings ladder: `../CLAUDE.md` for the two `Settings_Layout` edits
  a new sub-feature needs.

---

# User Management - Privacy Principle

**CRITICAL**: User management screens display `users` table data only, never `login_users` table data.

## Rationale

The `login_users` table contains authentication information private to the user (email verification status, activation status, last login time). Site administrators manage user profiles, not authentication records.

## Implementation

**DO**:
- Use `$user->email`, `$user->is_enabled`, `$user->invite_accepted_at`
- Show user profile and role information from `users` table

**DON'T**:
- Use `$user->login_user->email`, `$user->login_user->is_verified`, `$user->login_user->last_login`
- Expose authentication-specific fields to administrators

## The named exceptions

Each is a fact an administrator cannot do their job without, and none is ever a form field
on these screens.

1. **Two-factor enrollment state** (`Rsx_Two_Factor::is_enabled()` on the `login_user_id`) -
   see TWO-FACTOR above: an administrator helping a locked-out user has to know whether a
   factor is in play.
2. **The developer flag** (`login_users.is_developer`) - DISPLAYED as a chip on the user list
   and the user view, because an administrator must know which accounts can reach a
   developer-only surface. It is set by hand in the database and by nothing else: no screen,
   no endpoint and no form in this tree writes it.
3. **Where the user is signed in on this site** (SESSIONS above) - device, address and
   activity of this site's sign-ins only, so an administrator can end one that should not
   be there.
4. **The password, write-only** (RESET PASSWORD above) - an administrator SETS a temporary
   password and never reads anything of the credential; the Reset Password dialog alone reads
   the sign-in address (`get_user_for_password_reset`), as a password-manager hint.

---
---

# Page data

These screens are SPA actions, not Blade pages: a record id arrives as a route parameter in
`this.args` (`/frontend/settings/user_management/:id`), and there is no `@rsx_page_data` in
this tree. Reach for `@rsx_page_data` only on a server-rendered page, where it is the way to
hand a value to that page's static JavaScript.
