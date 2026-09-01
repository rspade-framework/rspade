# rsx/theme/components — the shared component library

Application-wide jqhtml components, grouped by domain. Components used by ONE feature
live with that feature under `rsx/app/`; anything reused, or reusable, belongs here.

**Search before you create.** Duplicating an existing component is the failure mode this
directory exists to prevent — the manifest finds classes by name, so
`grep -ri <concept> rsx/theme/components/` is the whole check, and
`php artisan rsx:jqhtml:glossary --missing` is the second one.

## THE GROUPS

Each group carries its own `CLAUDE.md` with the current inventory and its customization
seams:

| Group | What it holds |
|---|---|
| [`page/`](page/CLAUDE.md) | `Page_Scaffold` (the page shell), the breadcrumb chain, the older `Page_*` header wrappers |
| [`section/`](section/CLAUDE.md) | Card chrome: `View_Section_Abstract`, `Section`, `Card_Widget`, `Detail_Sidebar` |
| [`view/`](view/CLAUDE.md) | The content vocabulary: `Entity_Header`, `View_Fields`, `Record_Table`, `Feed_Row`, `Kpi_Cell`, `Empty_State`, `Callout`, `Entity_Link`, `Revision_History` and friends |
| [`ui/`](ui/CLAUDE.md) | Small primitives: `Status_Badge`, `Action_Menu`, `Count_Pill`, `External_Link`, `Breadcrumb` |
| [`card/`](card/CLAUDE.md) | The original thin Bootstrap card wrappers, largely superseded by `section/` |
| [`tabs/`](tabs/CLAUDE.md) | The neutral display tab strip: `Tab_Bar`, `Tab_Panels`, `Tab_Panel` |
| [`forms/`](forms/CLAUDE.md) | Field chrome: `Form_Field`, `Form_Field_Abstract`, and the form-aware `Rsx_Tabs`/`Rsx_Tab` |
| [`inputs/`](inputs/CLAUDE.md) | The input roster, every member extending `Form_Input_Abstract` |
| [`datagrid/`](datagrid/CLAUDE.md) | The paginated, sortable, selectable table engine |
| [`navigation/`](navigation/CLAUDE.md) | `Sidebar_Nav` and the search widgets |
| [`feedback/`](feedback/CLAUDE.md) | `Loading_Spinner` and the nine error-page components |
| [`notification/`](notification/CLAUDE.md) | The header notification bell |
| [`realtime/`](realtime/CLAUDE.md) | The realtime connection-state badge |
| [`business/`](business/CLAUDE.md) | Application-domain widgets (`Textbox_Click_To_Copy`) |

The semantic vocabulary's per-component rows — arguments, what each replaced, and the
gotchas found while building them — live in
`rsx/resource/conventions/semantic_component_registry.md`, which is updated in the same
pass as any change here.

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
skills `rspade:jqhtml`, `rspade:scss-rules`, `rspade:form-engine`, `rspade:form-input-contract`, app skills `theme`, `form-components`, `semantic-components`

Scaffold a new one:
`php artisan rsx:app:component:create --name=<name>_component --path=rsx/theme/components/<group>`
