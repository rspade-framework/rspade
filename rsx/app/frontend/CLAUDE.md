# rsx/app/frontend — the authenticated SPA

The main staff application. One PHP bootstrap controller
(`Frontend_Spa_Controller.php`) plus many JavaScript actions that navigate
without page reloads. `Frontend_Spa_Layout.{js,jqhtml}` is the persistent chrome
and **the place the primary nav is defined**; `frontend_bundle.php` is the asset
bundle. Everything else is a feature directory.

## WHAT IS HERE

- `Frontend_Spa_Controller.php` — the `#[SPA]` bootstrap; every action in this
  module names it in `@spa(...)`.
- `Frontend_Spa_Layout.{js,jqhtml}` + `frontend_spa_layout.scss` — the persistent
  chrome (sidebar, header, breadcrumbs, notification dropdown) and the two layout
  seams documented under HOW TO CUSTOMIZE.
- `frontend_bundle.php` — the module's one asset bundle.
- `frontend_pagemodal_layout.blade.php` — the bare Blade shell used when a page is
  opened inside a modal frame.
- Feature directories: `dashboard/`, `calendar/`, `action_logs/`, `clients/`,
  `contacts/`, `projects/`, `tasks/`, `party/`, `notifications/`, `revisions/`,
  `reports/`, `settings/` (its own sublayout), `system/` (its own sublayout).
  `clients/`, `settings/`, `system/` and `action_logs/` carry their own `CLAUDE.md`;
  `contacts/`, `projects/` and `tasks/` carry pointer files naming what differs from
  `clients/`.

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

## HOW TO CUSTOMIZE

**The primary nav is `Frontend_Spa_Layout.js`, `on_create()`, `this.state.nav_sections`** —
an array of `{title, items:[{label, icon, route, href}]}`. That array is the whole nav:
add a screen by adding an entry whose `route` is the destination ACTION NAME and whose
`href` is `Rsx.Route(<that name>)`. `Sidebar_Nav` (`rsx/theme/components/navigation/`) is
only the renderer — it holds no list of its own, so a nav change is never a theme change.

**Nav honesty is automatic and must stay that way.** `on_create()` filters every item
through `Permission.can_access(item.route)` and then drops any section left empty (the
authored-empty `Recent` section is exempt), so a link the user's gates would deny never
renders. Tighten a page's `@auth` and its nav entry disappears by itself — never
hand-write a role test at the link site.

**`scaffolded = true` on an action yields page width and padding to `Page_Scaffold`.**
`on_action()` clears the content pane's width modifiers and, when the incoming action
declares the flag, stamps `Frontend_Spa_Layout__page-content--scaffolded` instead of the
default `--constrained` (the other opt-ins being `full_width` and `constrained_wider`).
Declare it on any action whose template composes `<Page_Scaffold>`, or the scaffold's
max-width and `--page-pad` are doubled by the layout's own. The same flag, read the same
way, is honoured by `settings/Settings_Layout.js`, `system/System_Layout.js`,
`rsx/portal/Portal_Layout.js` and `rsx/portal/workspaces/Portal_Workspace_Layout.js`,
each stamping its own `--scaffolded` modifier — a new layout that owns page padding
should follow the same pattern.

Rebranding the chrome is `frontend_spa_layout.scss` plus the components in
`rsx/theme/`; adding a feature is a new directory in the shape above plus its nav entry.

## Pointers

`rsx:man spa` · `rsx:man crud` · `rsx:man view_action_patterns` ·
`rsx:man primary_secondary_nav_breadcrumbs` · `rsx:man auth_gates` ·
skills `rspade:spa`, `rspade:jqhtml`, `rspade:auth-gates`, app skill `crud-patterns`

Sublayouts (e.g. `settings/`, `system/`) nest a second `@layout` with its own
`$sid="content"`; see `System_Layout.jqhtml` for a worked example.
