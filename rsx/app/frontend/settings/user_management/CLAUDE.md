# rsx/app/frontend/settings/user_management — managing site users

## WHAT IS HERE

Four SPA actions under `Settings_Layout` and one controller
(`Frontend_Settings_User_Management_Controller`, gated `can_manage_users` at class level):

- `list/` — `Settings_User_Management_Index_Action` and `Users_DataGrid` over `User_Model`,
  with the add-user and send-invite modals.
- `view/` — `Settings_User_Management_View_Action` (`/frontend/settings/user_management/:id`),
  the edit-user modal, resend-invitation, and the user's **Sessions** on this site.
- `session_view/` — `Settings_User_Management_Session_View_Action`
  (`/frontend/settings/user_management/:id/sessions/:user_session_id`), one of those
  sessions, with its page-private `User_Session_Activity` region.
- `api_keys/` — `Settings_User_Management_Api_Keys_Action`, an administrator's view of
  another user's API keys, with revoke.
- `add_user/`, `edit_user/`, `send_invite/` — the modal bodies those screens open.

`export_csv` carries an additional `#[Auth('can_export_data')]` — the one per-method gate in
the tree.

## EQUAL-OR-LOWER ADMINISTRATION

A caller creates, edits, re-roles, disables and re-enables only users whose role is EQUAL to
or LOWER than their own - the role's `can_admin_roles` list (`User_Model::$enums`, where every
administering role names itself and the roles below it), read through `can_admin_role()`.
Every write asks it of the target's CURRENT role (`__administrable_user()`: `get_user_for_edit`,
`save_user`, `set_user_enabled`, `revoke_user_api_key`, `terminate_user_session`,
`terminate_all_user_sessions`) and of any NEW role
(`_validate_role_id()`: `add_user`, `save_user`); a refusal is `ERROR_UNAUTHORIZED` and
changes nothing - not the role, the email, the second-factor rule or API access. A
non-selectable role (Root Admin) is never assigned through the form; a user who already holds
it keeps it on save, which is why the edit form lists every role the caller administers
rather than only the selectable ones. Reads (`get_user`, the grid, `get_user_api_keys`,
`get_user_session`, `get_user_session_activity`) stay open to every holder of
`can_manage_users`; `get_user` and `get_user_session` report `can_administer` and the pages
show Edit, Enable/Disable and Sign out only when they would be accepted.

**Resending an invitation is a write like any other.** `send_invite` mints a new code - which
ends the link the invitee already holds - emails it and returns `invite_url`, and only for a
pending user whose role the caller administers (`__administrable_user()`); a superior's
invitation is refused as `unauthorized`, and the view shows Resend Invite only when
`can_administer`. So a link for a role above the caller's never reaches them, and nobody below
an invitee's level can quietly break their invitation.

**Disabling is `users.is_enabled`** (`set_user_enabled`), never a role: the framework refuses
a disabled membership at sign-in and ends its live sessions, while the role and ACL rows are
kept so re-enabling restores them. Nobody disables themselves.

## SESSIONS

Where the user is signed in ON THIS SITE - an identity's sessions on other sites are not this
site's business. `__user_sessions()` asks `Session::get_sessions_for_user($login_user_id,
$site_id, true)` (one entry per browser, impersonations of the user included and labelled,
signed-out ones too); every screen cuts it with `Session_Listing` (`rsx/lib/sessions/`):
active within the last three months, one row shape, `can_sign_out` on a signed-in entry that
is not an API key's use and not the caller's own browser. The session tables are the
framework's and are never queried here.

- `get_user` returns the list as `sessions`; the view page's Sessions section shows device,
  status (signed in, or why and when it ended), IP and last activity, each row opening the
  session page, with Sign out per row and Sign out everywhere.
- **The record rule**: `get_user_session`, `get_user_session_activity` and
  `terminate_user_session` take a `user_session_id` that must be one the view page lists for
  that user (`Session_Listing::find()`); another user's, another site's or a dormant one is
  `ERROR_NOT_FOUND`, the same answer as an id that does not exist.
- `get_user_session_activity` pages the session's audit sessions
  (`Audit_Session::list_page_for_user_session()`, 25 a page, newest first) as address,
  device and first/last seen. A row exists only where the sign-in did something attributed
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

---
---

# Page data

These screens are SPA actions, not Blade pages: a record id arrives as a route parameter in
`this.args` (`/frontend/settings/user_management/:id`), and there is no `@rsx_page_data` in
this tree. Reach for `@rsx_page_data` only on a server-rendered page, where it is the way to
hand a value to that page's static JavaScript.
