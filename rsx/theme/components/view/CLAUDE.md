# rsx/theme/components/view — the content vocabulary of a view page

## WHAT IS HERE

One subdirectory per component (template + SCSS, `.js` only where behaviour exists):

- `entity_header/` — `Entity_Header`: the identity block. Slot-first: `<Slot:title>` H1,
  `<Slot:chips>`, `<Slot:subtitle>`, `<Slot:meta>`.
- `view_fields/` — `View_Fields` (responsive auto-fit grid of facts) and `View_Field`
  (one `$label`/`$icon`/`$inline` label-value fact; the value is loose content).
- `record_table/` — `Record_Table`: the compact record list; `<tr data-href>` makes the
  whole row navigate, respecting real interactive elements inside it.
- `feed_row/` — `Feed_Row`: one "actor did thing" activity line (icon tile + summary + time).
- `author_meta_row/` — `Author_Meta_Row`: the authored-post byline (avatar + author + time).
- `person_avatar/` — `Person_Avatar`: a profile photo or a deterministic initials disc.
- `people_list/` — `People_List`: a scannable stacked list of people; fires `person_click`
  and `person_remove` with the exact person object.
- `kpi_cell/` — `Kpi_Cell`: one bordered KPI cell (label + big value + optional sub-line).
- `sidebar_kpi/` — `Sidebar_Kpi_Group`: the two-up "At a Glance" grid of KPI cells.
- `stat_group/` — `Stat_Group`: the dashboard headline strip of the same KPI cells.
- `stat_row/` — `Stat_Row`: one monospaced money/numeric line. **No consumer today.**
- `widget_grid/` — `Widget_Grid`: the two-up overview grid of independent widget cards.
- `empty_state/` — `Empty_State`: an empty LIST region (icon + title + reason + CTA).
- `empty_value/` — `Empty_Value`: the one muted em-dash for a single empty cell.
- `callout/` — `Callout`: the inline alert banner; `$variant` is validated loudly.
- `placeholder_card/` — `Placeholder_Card`: a whole feature that is not built yet.
- `entity_link/` — `Entity_Link`: a fetched entity reference (type icon + name + link).
- `revision_history/` — `Revision_History`: one record's change timeline, grouped by the
  transaction that made it.

## HOW IT IS USED

These are the nouns a view page is written in; the page itself contributes almost no
markup and near-zero SCSS. Canonical compositions:
`rsx/app/frontend/clients/view/Clients_View_Action.jqhtml` (header, fields, tabs, tables,
sidebar KPIs, callout, revision history) and
`rsx/app/frontend/dashboard/Dashboard_Index_Action.jqhtml` (stat group, widget grid, feed).
The portal composes the same vocabulary, e.g.
`rsx/portal/workspaces/requests/thread/Portal_Request_Thread_Action.jqhtml`.

Every component's row — what it replaces, its arguments, and its gotchas — is in
`rsx/resource/conventions/semantic_component_registry.md` (Layer 4). Choosing between two
of them (`Feed_Row` vs `Author_Meta_Row`, `Empty_State` vs `Empty_Value`, `Kpi_Cell` vs
`Stat_Row`) is the app skill `semantic-components`.

## HOW TO CUSTOMIZE

- **Restyle in the component's own SCSS**, wrapped in its single component class with BEM
  children carrying the exact PascalCase prefix (`.Kpi_Cell { &__value }`). A kebab-case
  child class matches nothing and silently renders unstyled.
- **A new look is a `$variant` arg on the component, never a page-scoped override.** A
  page that restyles a shared component from its own SCSS defeats the whole group.
- `Entity_Link` stores a PLAIN object in `this.data`, never a hydrated model instance —
  a model instance defeats the post-load re-render and the name never paints.
- `Person_Avatar` takes a `file_attachment_id`, never an image URL; the thumbnail
  component fetches the picture so a re-uploaded photo replaces itself everywhere.
- `Revision_History` reads only the record types `Frontend_Revisions_Controller`
  publishes — adding a type is a controller change, not a component change.
- Before adding a component here, run `php artisan rsx:jqhtml:glossary --missing` and read
  the registry: the failure mode this directory prevents is a second component for a shape
  that already has one. Add the new row to the registry in the same pass.
- `Stat_Row` and `Section_Columns` (in `../section/`) are reserved shapes with no live
  consumer; delete them if the fork will never show a money line or a nested split.

## RELATED

App skill `semantic-components` · `rsx/resource/conventions/semantic_component_registry.md` ·
`../section/CLAUDE.md`, `../page/CLAUDE.md`, `../ui/CLAUDE.md` ·
`rsx:man semantic_composition` · skills `rspade:jqhtml`, `rspade:revisions`
