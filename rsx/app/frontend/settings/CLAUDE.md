# rsx/app/frontend/settings — the settings ladder and its sublayout

## WHAT IS HERE

`Settings_Layout.{js,jqhtml}` + `settings_layout.scss` — the sublayout every screen in
this tree nests inside, plus one directory per sub-feature:

| Directory | Action | Route | Gate | The screen |
|---|---|---|---|---|
| `general/` | `Settings_General_Action` | `/frontend/settings` and `/frontend/settings/general` | `is_logged_in` | No template: `on_ready()` bounces to Profile. The tree's entry point. |
| `profile_display/` | `Settings_Profile_Display_Action` | `.../profile_display` | `is_logged_in` | Read-only own profile. Carries a **Developer** chip beside the role when the signed-in login identity has `login_users.is_developer` - a classification, displayed and never editable. The sidebar reads real state: Active/Disabled from the membership's `users.is_enabled`, Email Verified from `login_users.is_verified` (set by accepting an invitation, and by the framework when an emailed code or link is answered - self-signup's confirmation, or the verification an unverified address owes at sign-in), Two-Factor from `Rsx_Two_Factor::is_enabled()` on the login identity, Last Login from `login_users.last_login`. |
| `profile_edit/` | `Settings_Profile_Edit_Action` | `.../profile_edit` | `is_logged_in` | Own-profile form; email disabled; `$max_length` from `Model.field_length()`. |
| `user_settings/` | `Settings_User_Settings_Action` | `.../user_settings` | `is_logged_in` | Preferences, three forms each saved by its own button. Timezone + theme go through the framework's `Rsx_Timezone_Controller` / `Rsx_Dark_Mode_Controller` (a saved theme is applied at once with `Rsx_Dark_Mode.set_mode()`). Notifications and Privacy go through this feature's `get_preferences` / `update`: the two email switches are the recipient opt-out for the user's `users.email` on this site (`Rsx_Mail::is_blocked/block/unblock`, NOTIFICATION and MARKETING - transactional and security mail ignores an opt-out, so the screen states it instead of offering a switch; with no address the switches are not shown); Task Assignments and Profile Visibility are preference variables on the site `User_Model` (`inapp_notify_task_assigned`, `profile_visibility`). **Nothing reads those two variables yet**: `Notification::send()` has no live caller and no screen applies a visibility rule - a feature that sends task notifications or shows a profile to other users reads them. |
| `password_security/` | `Settings_Password_Security_Action` | `.../password_security` | `is_logged_in` | Change password, **two-factor authentication (real)**, **connected accounts (real)**, **where you're signed in (real)**. The two framework-served sections load from `Rsx_Two_Factor_Controller` / `Rsx_Sso_Controller` and the session list from this screen's own `get_sessions` (`Session::get_sessions_for_user()` through `Session_Listing`: every site, never an impersonation of you, the last three months, "This device" marked) in one `Promise.all`; Sign Out per other device (`sign_out_session` -> `Session::terminate_session()`) and Sign Out All Other Sessions (`sign_out_other_sessions` -> `Session::terminate_all_other_sessions()`, which also ends every other browser's two-factor trust). Change Password is an `<Rsx_Form>` posting to `change_password`: refused while impersonating; every rule server-side (current password required, new one at least 8 characters - signup's rule - and confirmed); the current password is checked throttle-first (`Login_Throttle::require_not_throttled()`) and a wrong one recorded through `Login_History::record_failure()`; the new hash is saved through `Login_User_Model`, whose own password-change hook signs every OTHER browser out (API keys untouched), so the screen only flashes that and reloads the session list. |
| `api_keys/` | `Settings_Api_Keys_Action` | `.../api_keys` | `is_logged_in`, `can_use_api` | Own API keys datagrid + create / scope-preview / revoke modals. Every endpoint also re-asks `Session::has_api_access()` - API access takes BOTH the permission and the framework's `users.is_api_access_enabled`. |
| `user_management/` | `Settings_User_Management_Index_Action`, `..._View_Action`, `..._Session_View_Action`, `..._Api_Keys_Action` | `.../user_management[/:id[/api_keys]]`, `.../user_management/:id/sessions/:user_session_id` | `is_logged_in`, `can_manage_users` | Users datagrid, one-user view (edit, enable/disable, resend invite, where they are signed in on this site with Sign out), one of those sessions with its activity, another user's keys. Equal-or-lower administration. Own `CLAUDE.md`. |
| `portal_users/` | `Settings_Portal_Users_Index_Action` | `.../portal_users` | `is_logged_in`, `can_view_data` | Portal-user datagrid (every membership row, live or not); suspend/reactivate delegate to `Portal_User_Admin_Actions`, whose endpoints live on `Frontend_Clients_Controller` (`can_edit_data`). |
| `site_settings/` | `Settings_Site_Settings_Action` | `.../site_settings` | `is_logged_in`, `can_manage_site_settings` | The current site's name, loaded by `get_settings` and saved to `Site_Model` by `update` (required; length from `Site_Model::field_length('name')`, the same number the form's `$max_length` binds), and its **client portal**: the portal address as it is served now (`Rsx_Portal_Url::for_site()`, "No client portal" when false), the `is_portal_enabled` switch (not rendered while `PORTAL_ENABLED` is off) and, only when `PORTAL_CUSTOM_DOMAINS` is not `off`, the `portal_domain` field - normalised and validated by `Site_Model::normalize_portal_domain()` (refused as a field error when it is not a hostname or another site, deleted ones included, holds it; blank clears it). A key the form did not submit is left untouched. |

Each sub-feature keeps its Ajax endpoints in its own `*_controller.php`, gated at class
level as the table says; `user_management` adds a per-method gate
(`can_export_data` on `export_csv`). The own-record screens (profile, preferences, password)
stay on `is_logged_in`: they act on the caller and nobody else. API keys are the caller's
own too, but minting one is a permission (`can_use_api`).

## HOW IT IS USED

Every action here declares its decorators outermost-first —
`@layout('Frontend_Spa_Layout')` then `@layout('Settings_Layout')` — plus
`@spa('Frontend_Spa_Controller::index')`. `Settings_Layout` renders its own
`$sid="content"` pane inside the frontend layout's, so navigation between settings
screens repaints only this pane.

**The sub-nav is authored markup in `Settings_Layout.jqhtml`, not a registry.** Adding a
screen is two edits: an `<a data-page="x" href="<%= Rsx.Route('X_Action') %>">` in the
template, and an `X_Action: 'x'` row in `Settings_Layout.js`'s `static NAV_CONFIG` (a view
action aliases to its index's nav id so the right item stays active). `on_action()` reads
`NAV_CONFIG` and toggles `.settings-sidebar__item.active`.

The sidebar hides what the user cannot reach: the API Keys link asks
`Permission.has_api_access()` AND `Permission.can_access('Settings_Api_Keys_Action')`, and
each Administration link asks `Permission.can_access('<Action>')`, with the heading itself
dropped when nothing survives.

**Connected Accounts is absent, not empty, when no provider is switched on.**
`window.rsxapp.sso` is omitted entirely in that case, so `Rsx_Sso.is_enabled()` is read once
in `on_create()` (the roster cannot change while the page is open) and the whole `<Section>`
is skipped. With providers live it renders one row per provider — Connect
(`Rsx_Sso_Controller.link_begin()`, then `window.location = result.url`, because the callback
must be a top-level navigation) or Disconnect (`Modal.confirm` →
`Rsx_Sso_Controller.identity_unlink()` → `reload()`) — plus a disconnect-only row for a
connection whose provider has since been switched off, since switching one off deletes
nothing and the user must still be able to remove it. The one SCSS rule on this page sizes
the inline brand marks, which arrive as SVG with no intrinsic dimensions.

`site_settings`' `update` shows the length-rule convention: the server asks
`Site_Model::field_length('name')` rather than restating the schema's 255, and `get_settings`
hands the form that same number for its `$max_length`. Copy that shape for any length check
here.

`scaffolded = true` on the ACTION (not the layout) makes `on_action()` stamp
`settings-content--scaffolded` so a `Page_Scaffold`-composing page owns its own padding —
the same seam `Frontend_Spa_Layout` uses, described in `../CLAUDE.md`.

## HOW TO CUSTOMIZE

- **Add a settings screen**: a directory in the shape above, the two `Settings_Layout`
  edits, `scaffolded = true` if the template composes `<Page_Scaffold>`.
- **Restyle**: `settings_layout.scss` owns the sidebar and content pane; the screens
  themselves are near-empty SCSS composing theme components.
- **Delete a screen**: remove the directory, its `NAV_CONFIG` row and its sidebar anchor —
  a stale `NAV_CONFIG` entry is harmless but a stale anchor renders a dead link.
- **Worked examples on the Password & Security page**: the two-factor section is the
  framework's own endpoints and enrollment components, and
  `settings_password_security_action.js` is the worked example of hosting
  `<Totp_Enrollment>` / `<Passkey_Register>` in a modal and reloading on their completion
  event, and Connected Accounts is the worked example of driving the SSO settings endpoints.
- **Change what Connected Accounts offers**: the roster is `config('rsx.sso.providers')`,
  not this page — the section renders whatever is switched on. Account POLICY (what a
  provider identity may do) lives in `rsx/handlers/Sso_Handlers.php`.

## RELATED

`../CLAUDE.md` · `user_management/CLAUDE.md` · `../system/CLAUDE.md` (the sibling
sublayout) · app skills `crud-patterns`, `form-components` · `rsx:man spa`,
`rsx:man auth_gates`, `rsx:man sso` · skills `rspade:spa`, `rspade:auth-gates`
