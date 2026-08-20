# rsx/theme/components — the shared component library

Application-wide jqhtml components, grouped by domain: `business/ card/
datagrid/ feedback/ forms/ inputs/ navigation/ notification/ page/ realtime/
section/ tabs/ ui/ view/`. Components used by ONE feature live with that
feature under `rsx/app/`; anything reused, or reusable, belongs here.

**Search before you create.** Duplicating an existing component is the failure
mode this directory exists to prevent — the manifest finds classes by name, so
`grep -ri <concept> rsx/theme/components/` is the whole check.

## Invariants

- **Name what things ARE.** `<User_Card>`, `<Kpi_Cell>` — not
  `<div class="card">`. A page should read as a composition of named concepts.
  Even a template-only component (markup + SCSS, no `.js`) is worth making.
- **A component owns its complete look in its own SCSS file**, wrapped in a
  single class matching the component name, BEM children prefixed with the exact
  component name (`.User_Card { &__meta }` → `class="User_Card__meta"`, no
  kebab-case). A page-scoped override of a shared component defeats the point —
  add a `$variant` arg instead.
- **Displayed content is innerHTML** — `content()` or a named `<Slot:name>` —
  **never an attribute.** Args carry DATA (`this.args.user_id`) and behavioral
  flags. The one sanctioned exception is dual-channel chrome: a structural
  wrapper may take a plain-text `$title`/`$label` arg AND a matching slot that
  wins when present. HTML inside an arg string is always a defect.
- **Variables first.** Check `rsx/theme/variables.scss` before writing a color,
  spacing, or size literal.
- Input components extend `Form_Input_Abstract` and follow their own rules — see
  `inputs/CLAUDE.md`. They have no `on_load()`, so they never use `this.data`,
  and they render EMPTY (the form calls `val()` after render).

## Pointers

`rsx:man jqhtml` · `rsx:man semantic_composition` · `rsx:man scss` ·
`rsx:man form_input` (responsive breakpoints live in `rsx:man scss`) ·
skills `rspade:jqhtml`, `rspade:scss`, `rspade:forms`, `rspade:form-input`

Scaffold a new one:
`php artisan rsx:app:component:create --name=<name>_component --path=rsx/theme/components/<group>`
