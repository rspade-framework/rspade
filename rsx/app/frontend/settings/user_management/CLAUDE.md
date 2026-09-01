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

## HOW TO CUSTOMIZE

- The privacy rule below is the load-bearing convention here; keep it when adding a column
  or a field to any of these screens.
- New screens follow the settings ladder: `../CLAUDE.md` for the two `Settings_Layout` edits
  a new sub-feature needs.
- Note the gate asymmetry to resolve before launch: `Settings_User_Management_Api_Keys_Action`
  declares `can_manage_users` without `is_logged_in`, unlike every sibling.

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

---
---

# Page data

These screens are SPA actions, not Blade pages: a record id arrives as a route parameter in
`this.args` (`/frontend/settings/user_management/:id`), and there is no `@rsx_page_data` in
this tree. Reach for `@rsx_page_data` only on a server-rendered page, where it is the way to
hand a value to that page's static JavaScript.
