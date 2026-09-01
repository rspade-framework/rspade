# rsx/app/frontend/settings — the settings ladder and its sublayout

## WHAT IS HERE

`Settings_Layout.{js,jqhtml}` + `settings_layout.scss` — the sublayout every screen in
this tree nests inside, plus one directory per sub-feature:

| Directory | Action | Route | Gate | The screen |
|---|---|---|---|---|
| `general/` | `Settings_General_Action` | `/frontend/settings` and `/frontend/settings/general` | `is_logged_in` | No template: `on_ready()` bounces to Profile. The tree's entry point. |
| `profile_display/` | `Settings_Profile_Display_Action` | `.../profile_display` | `is_logged_in` | Read-only own profile. |
| `profile_edit/` | `Settings_Profile_Edit_Action` | `.../profile_edit` | `is_logged_in` | Own-profile form; email disabled; `$max_length` from `Model.field_length()`. |
| `user_settings/` | `Settings_User_Settings_Action` | `.../user_settings` | `is_logged_in` | Timezone + theme, saved through the framework's `Rsx_Timezone_Controller` / `Rsx_Dark_Mode_Controller`. |
| `password_security/` | `Settings_Password_Security_Action` | `.../password_security` | `is_logged_in` | Change password + active sessions. **Both endpoints are TODO stubs and the session list is hardcoded sample data.** |
| `api_keys/` | `Settings_Api_Keys_Action` | `.../api_keys` | `is_logged_in` | Own API keys datagrid + create / scope-preview / revoke modals. |
| `user_management/` | `Settings_User_Management_Index_Action`, `..._View_Action`, `..._Api_Keys_Action` | `.../user_management[/:id[/api_keys]]` | `can_manage_users` | Users datagrid, one-user view, another user's keys. Own `CLAUDE.md`. |
| `group_management/` | `Settings_Group_Management_Index_Action`, `..._View_Action` | `.../group_management[/:id]` | `is_logged_in`, `can_manage_users` | `User_Group_Model` datagrid + add/edit/delete modals. |
| `portal_users/` | `Settings_Portal_Users_Index_Action` | `.../portal_users` | `is_logged_in` | Portal-user datagrid; suspend/reactivate delegate to `Portal_User_Admin_Actions`, whose endpoints live on `Frontend_Clients_Controller`. |
| `site_settings/` | `Settings_Site_Settings_Action` | `.../site_settings` | `is_logged_in` | Site name/description form. **Scaffolding: `update` validates and persists nothing.** |

Each sub-feature keeps its Ajax endpoints in its own `*_controller.php`; only
`user_management` and `group_management` add a per-method gate (`can_export_data` on
`export_csv`).

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
`Permission.has_api_access()`, and each Administration link asks
`Permission.can_access('<Action>')`, with the heading itself dropped when nothing survives.

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
- **The stubs above are yours to finish or remove.** Password change, session revocation,
  user settings `update` and site settings `update` all return success without doing the
  work; the mock session list is sample data, not a bug to route around.
- Gate widths differ deliberately-looking but are worth a review before launch: Site
  Settings and Portal Users sit under the Administration heading yet gate only on
  `is_logged_in`, so `can_access()` shows them to every signed-in user.

## RELATED

`../CLAUDE.md` · `user_management/CLAUDE.md` · `../system/CLAUDE.md` (the sibling
sublayout) · app skills `crud-patterns`, `form-components` · `rsx:man spa`,
`rsx:man auth_gates` · skills `rspade:spa`, `rspade:auth-gates`
