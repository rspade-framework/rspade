# rsx/app/root — the cross-site root console

## WHAT IS HERE

A Blade module (no SPA, no JavaScript) whose every controller carries the same class-level
gate, `#[Auth('is_logged_in', 'is_root_admin')]` — a `ROLE_ROOT_ADMIN` floor. `Root_Layout`
(`root_layout.blade.php` + `root_layout.scss`) is the only substantive code in the module:
a fixed sidebar fed by a `$nav_sections` array through `<Sidebar_Nav>`, a dark header with
a user dropdown, and a `page-content` main area.

| Controller | Route | State today |
|---|---|---|
| `Root_Index_Controller` | `/root` | **Real** — a one-line redirect to the dashboard. |
| `Root_Dashboard_Controller` | `/root/dashboard` | **Stub.** Renders one card reading "Root dashboard placeholder - content will be added here." |
| `Root_Sites_Controller` | `/root/sites` | **Stub**, same shape. |
| `Root_Email_Controller` | `/root/email` | **Stub**, same shape. |
| `Root_Email_History_Controller` | `/root/email/history` | **Stub**, same shape. |
| `Root_Email_Templates_Controller` | `/root/email/templates` | **Stub**, same shape. |

Five of the six pages are placeholders and none renders real data: each controller passes
`$data = []` to a blade whose body is a single sentence. Their companion `.scss` files
contain comments and no rules. No theme component is used anywhere in this module — the
placeholder blades are raw Bootstrap card markup.

## HOW IT IS USED

This is the operator's console for facts that span every tenant, which is why it sits
outside `frontend/` with its own bundle, layout and role floor rather than as a settings
screen. Nothing links to it: `/root` is URL-only, and the staff nav has no entry for it.

`Root_Bundle` includes theme variables and responsive first, then bootstrap, then
`rsx/theme`, `rsx/models`, `rsx/lib` and this directory.

## HOW TO CUSTOMIZE

- **Build a page**: replace the placeholder blade with real content and load the data in
  the controller. Compose theme components (`Page_Scaffold`, `Section`, `Record_Table`)
  rather than the raw Bootstrap cards the placeholders use.
- **A cross-site query must escape the tenant scope deliberately** —
  `Model::without_site_scope()` — because a site-scoped model filters to the caller's own
  site, which is exactly what a root console must not do.
- **Two dangling links to fix or remove** in `root_layout.blade.php`: the "System" nav item
  points at `Root_System_Controller`, which does not exist; and the two Email subitems build
  their hrefs by string-concatenating onto `Rsx::Route('Root_Email_Controller')` instead of
  routing to `Root_Email_History_Controller` / `Root_Email_Templates_Controller` by name.
- **Delete the module** if the application is single-tenant: nothing outside it refers to it.
- The `is_root_admin` check itself lives in `rsx/permission.php`; widen or rename it there,
  never at a call site.

## RELATED

`../CLAUDE.md` · `rsx/permission.php` · `rsx/theme/components/navigation/CLAUDE.md`
(`Sidebar_Nav`) · skills `rspade:blade-views`, `rspade:auth-gates`, `rspade:permissions-acl` ·
`rsx:man auth_gates`, `rsx:man acls`
