# rsx/theme/components/tabs — the neutral display tab strip

## WHAT IS HERE

- `tab_bar.{jqhtml,js,scss}` — `Tab_Bar`: the strip itself. `$tabs` is an array of
  `{key, label, icon?, count?}`; `$hash` persists the active tab in the URL; `$divided`
  draws the border-top seam. Fires `tab_change` `{key}` and exposes `activate(key)` /
  `get_active_key()`.
- `tab_panels.{jqhtml,js}` — `Tab_Panels`: locates the sibling `Tab_Bar`, follows its
  `tab_change`, and shows exactly the matching panel.
- `tab_panel.{jqhtml,js,scss}` — `Tab_Panel`: one panel, `$key` matching a tab; it
  self-registers with its container and starts hidden.

## HOW IT IS USED

The tabbed entity view: a `Tab_Bar` followed by a `Tab_Panels` block, both inside a
`Card_Widget`. Live in `rsx/app/frontend/clients/view/Clients_View_Action.jqhtml` and
`rsx/app/frontend/projects/view/Projects_View_Action.jqhtml` (five view pages total).

**This trio is NEUTRAL: it carries no form or validation coupling.** The form-aware tabs
that badge a pane with its error count are `Rsx_Tabs` / `Rsx_Tab` in `../forms/`. Use
these for displaying an entity's sections; use those inside a long edit form.

`$hash` makes a tab bookmarkable, and a clickable `Kpi_Cell` (`../view/kpi_cell/`) exposes
`data-kpi-tab` so a sidebar KPI can jump to a tab — the owning action delegates the click
to `this.sid('<tab-bar>').activate(key)`. Registry rows: Layer 4 of
`rsx/resource/conventions/semantic_component_registry.md`.

## HOW TO CUSTOMIZE

- Restyle in `tab_bar.scss` / `tab_panel.scss`, single-class wrapped with `Tab_Bar__`
  BEM children.
- Tab buttons are matched by an attribute filter, **not** by an id selector — the jqhtml
  `escape_jq_selector` helper prepends `#` and will not find them. Keep the delegated
  click in `on_render()` idempotent; it re-fires on every render.
- A tab's count is a `Count_Pill` rendered from the `count` key of the descriptor; give it
  a number, not a formatted string.
- Do not add validation awareness here — that is what `../forms/rsx_tabs` already is.

## RELATED

`../forms/CLAUDE.md` (the form-aware pair) · `../view/CLAUDE.md` ·
app skill `semantic-components` · `rsx/resource/conventions/semantic_component_registry.md` ·
`rsx:man semantic_composition` · skill `rspade:jqhtml` (events, `$sid`)
