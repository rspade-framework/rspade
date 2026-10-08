# rsx/app/frontend/settings/user_management — managing site users

## WHAT IS HERE

Three SPA actions under `Settings_Layout` and one controller
(`Frontend_Settings_User_Management_Controller`, gated `can_manage_users` at class level):

- `list/` — `Settings_User_Management_Index_Action` and `Users_DataGrid` over `User_Model`,
  with the add-user and send-invite modals.
- `view/` — `Settings_User_Management_View_Action` (`/frontend/settings/user_management/:id`),
  the edit-user modal, and resend-invitation.
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
`save_user`, `set_user_enabled`, `revoke_user_api_key`) and of any NEW role
(`_validate_role_id()`: `add_user`, `save_user`); a refusal is `ERROR_UNAUTHORIZED` and
changes nothing - not the role, the email, the second-factor rule or API access. A
non-selectable role (Root Admin) is never assigned through the form; a user who already holds
it keeps it on save, which is why the edit form lists every role the caller administers
rather than only the selectable ones. Reads (`get_user`, the grid, `get_user_api_keys`) stay
open to every holder of `can_manage_users`; `get_user` reports `can_administer` / `is_self`
and the view page shows Edit and Enable/Disable only when they would be accepted.

**An invitation link is never shown for a role above the caller's.** `send_invite` re-sends
the email for any pending user of the site (the email goes to the invitee), but returns
`invite_url` only when the caller administers the invitee's role; otherwise it returns
`invite_url_hidden: true` and `Send_User_Invite_Modal` says the link is hidden because the
selected user's access level exceeds the caller's own.

**Disabling is `users.is_enabled`** (`set_user_enabled`), never a role: the framework refuses
a disabled membership at sign-in and ends its live sessions, while the role and ACL rows are
kept so re-enabling restores them. Nobody disables themselves.

`get_user` lists the user's recent sessions ON THIS SITE only - an identity's sessions on
other sites are not this site's business.

## TWO-FACTOR

`users.is_2fa_required` is this application's own policy column (the framework decides only
whether an identity HAS a factor). It is edited from the checkbox in
`edit_user/edit_user_modal_form.jqhtml`, written by `save_user()` with the
checkbox-absent-means-off idiom, and read by the login requirement
`rsx/app/login/two_factor_enrollment_requirement.php`: a flagged identity with no factor reads
as signed out everywhere but `/login/two_factor_setup` and the enrollment endpoints.

**On a CONFIRMED change of the flag `save_user()` calls `Login_Requirements::recheck_user()` and
pushes a realtime user refresh** - and only then. The recheck re-evaluates the requirement on
every live session the user holds (otherwise it would apply only from their next sign-in); the
push makes their open tabs ask again, and the first refused call sends them to the setup
screen.

The view page shows a **Two-Factor** row carrying two different facts: whether the account has
a factor (`is_2fa_enrolled`, from `Rsx_Two_Factor::is_enabled()` on the `login_user_id`) and
whether an administrator requires one (`is_2fa_required`). Enrollment state is the one
authentication fact these screens show, and it is shown because a "Required" badge with no
answer to "have they done it?" tells an administrator nothing actionable - see the privacy
principle below, which it is a deliberate, narrow exception to.

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

## The two named exceptions

Both are read-only facts an administrator cannot do their job without, and neither is ever a
form field on these screens.

1. **Two-factor enrollment state** (`Rsx_Two_Factor::is_enabled()` on the `login_user_id`) -
   see TWO-FACTOR above: a "Required" badge with no answer to "have they done it yet?" tells
   an administrator nothing actionable.
2. **The developer flag** (`login_users.is_developer`) - DISPLAYED as a chip on the user list
   and the user view, because an administrator must know which accounts can reach a
   developer-only surface. It is set by hand in the database and by nothing else: no screen,
   no endpoint and no form in this tree writes it.

---
---

# Page data

These screens are SPA actions, not Blade pages: a record id arrives as a route parameter in
`this.args` (`/frontend/settings/user_management/:id`), and there is no `@rsx_page_data` in
this tree. Reach for `@rsx_page_data` only on a server-rendered page, where it is the way to
hand a value to that page's static JavaScript.
