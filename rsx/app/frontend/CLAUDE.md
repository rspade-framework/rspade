# rsx/app/frontend — the authenticated SPA

The main staff application. One PHP bootstrap controller
(`Frontend_Spa_Controller.php`) plus many JavaScript actions that navigate
without page reloads. `Frontend_Spa_Layout.{js,jqhtml}` is the persistent chrome
and **the place the primary nav is defined**; `frontend_bundle.php` is the asset
bundle. Everything else is a feature directory.

## The shape of a feature

```
<feature>/
├── <feature>_controller.php      Ajax endpoints ONLY — no routes, no HTML
├── list/   <Feature>_Index_Action.{js,jqhtml} + datagrid backend/template
├── view/   <Feature>_View_Action.{js,jqhtml}
└── edit/   <Feature>_Edit_Action.{js,jqhtml}   (one action, dual @route)
```

`rsx/app/frontend/clients/` is the canonical reference — read it before writing a
new feature, and copy its structure rather than inventing one.

## Invariants

- **`#[Auth]` on every controller endpoint, `@auth` on every `@route` action.**
  Mandatory. The manifest build FAILS without it. There is no attribute-free
  "open".
- A feature controller in an SPA module exposes **Ajax endpoints only**. The
  route lives on the JS action's `@route` decorator.
- **Never hardcode a URL.** `Rsx.Route('Contacts_View_Action', id)` / PHP
  `Rsx::Route(...)`. Enforced by `rsx:check`.
- **Nav honesty**: the layout filters nav items through
  `Permission.can_access(target)` and drops sections left empty, so a sidebar can
  never advertise a screen the user cannot open. Add a nav item by pointing it at
  the destination's route name — never by hand-writing a role test.
- Data loads in `on_load()` (never in an event handler; call `this.reload()`).
  View actions use the three-state pattern: `<Loading_Spinner>` →
  `<Universal_Error_Page_Component>` → content.
- Page/action SCSS should be near-empty. A page's look lives in the components it
  composes.

## Pointers

`rsx:man spa` · `rsx:man crud` · `rsx:man view_action_patterns` ·
`rsx:man primary_secondary_nav_breadcrumbs` · `rsx:man auth_gates` ·
skills `rspade:spa`, `rspade:crud-patterns`, `rspade:jqhtml`, `rspade:auth-gates`

Sublayouts (e.g. `settings/`, `system/`) nest a second `@layout` with its own
`$sid="content"`; see `System_Layout.jqhtml` for a worked example.
