---
name: semantic-components
description: "Building UI in this application from its own widget vocabulary - the semantic component registry, `php artisan rsx:jqhtml:glossary --missing`, and which existing theme component fits a given data shape (Section, Page_Scaffold, Entity_Header, View_Fields, Record_Table, Feed_Row, Status_Badge, Empty_State, Kpi_Cell, People_List). Use when building or refactoring any page, panel, card, table, list, badge, byline, empty region or KPI strip, when tempted to write `<div class=\"card card-body\">` or page-level SCSS by hand, when naming a new reusable component, or when a component renders unstyled because a BEM class was written in kebab-case."
---

# Building UI from this app's widget vocabulary

> **Living skill.** This skill ships with the template application and is yours. It describes
> the CURRENT state of `rsx/theme/components/`; the directory file
> `rsx/theme/components/CLAUDE.md` (and each component group's own `CLAUDE.md` beside it) is
> its companion, alongside the registry
> `rsx/resource/conventions/semantic_component_registry.md`. When this feature changes,
> update this skill and those files in the same pass.

**The rule: a page is composed of named components, not markup.** A converted page's
template reads like a program's main routine, and **its own SCSS file is near-empty** -
that is the success test, and the thing to check before calling a page done.

## Before you build anything

1. **Read the registry**: `rsx/resource/conventions/semantic_component_registry.md`.
   It is the living index - per component: arguments, what it replaced, gotchas, and
   which pages consume it. The roster summary lives in `rsx/resource/CLAUDE.md`.
2. **Run the glossary check**: `php artisan rsx:jqhtml:glossary --missing` lists
   components with no parsable summary. Keep it at zero for components we own.
3. **Only then decide** whether you are reusing, levering, or extracting.

**Levering beats extracting; extracting beats duplicating.** A new component needs
TWO real consumers before it earns its name - the registry records that evidence
(`Sidebar_Kpi_Row` was renamed `Kpi_Cell` precisely because a second, non-sidebar
consumer proved the old name under-described it).

## Pick by data shape

| The thing on screen | The component |
|---|---|
| A view page's overall shell (main + optional sidebar) | `Page_Scaffold` (`$ratio` 8/4 default) |
| A titled block of content | `Section` (`$icon`/`$title`/`$count` + body; `$flush` for edge-to-edge tables) |
| A page-level card with no header | `Card_Widget` |
| The entity sidebar stack | `Detail_Sidebar`, with `Sidebar_Kpi_Group` + `Kpi_Cell` at the TOP |
| The dashboard headline stat strip | `Stat_Group` (containing the same `Kpi_Cell`) |
| A 2-up grid of independent widget cards | `Widget_Grid` |
| The entity identity header | `Entity_Header` (title + chips + subtitle + meta) |
| Label/value facts | `View_Fields` wrapping `View_Field` |
| A compact record list | `Record_Table` (`<tr data-href>` = whole-row nav) |
| One activity/timeline event line | `Feed_Row` |
| "Who + when" byline | `Author_Meta_Row` (+ `Person_Avatar`) |
| A list of people | `People_List` (fires `person_click` / `person_remove`) |
| A status from a model enum | `Status_Badge` |
| A classification (type/role/category/priority) chip | the `.badge-outline-*` primitive in `rsx/theme/badges.scss` |
| A count beside a title | `Count_Pill` |
| A reference to another record | `Entity_Link` |
| An empty list region | `Empty_State`; a single empty cell is `Empty_Value` |
| An inline warning/error banner | `Callout` (`danger` / `warning`) |
| A whole feature that is not built yet | `Placeholder_Card` |
| Secondary + destructive actions | `Action_Menu` (destructive items live INSIDE it) |
| Tabbed view content | `Tab_Bar` + `Tab_Panels` / `Tab_Panel` |
| Tabs inside a FORM | `Rsx_Tabs` / `Rsx_Tab` - a different, validation-aware set |
| A paginated, sortable list | a `DataGrid_Abstract` subclass (see `rsx/resource/CLAUDE.md`) |

Sources: `rsx/theme/components/{page,section,view,tabs,ui,datagrid,forms,inputs,navigation}/`.

## Gotchas that bite

- **Slot rule (`Section`)**: if you use `<Slot:title>` or `<Slot:actions>`, the body
  MUST move into `<Slot:body>`. The parser forbids loose content beside named slots,
  and `default` is a reserved slot name.
- **`Entity_Link` and `this.data`**: store a PLAIN object, never a hydrated model
  instance. The framework serializes `this.data` to detect change; a model instance
  defeats that and the name never paints (notably inside DataGrid rows).
- **BEM prefixes are the exact PascalCase component name**: `.Feed_Row { &__body }`.
  A kebab-case guess (`feed-row__body`) matches nothing and the element silently gets
  no styles.
- **`$flush` + a self-headed widget** = misaligned header. Wrap self-headed widgets in
  a plain padded `Section`.
- **Variables before new SCSS**: `rsx/theme/variables.scss` and
  `rsx/theme/composition_tokens.scss` already define spacing, colors, radii and the
  four relationship tokens (`--block-gap`, `--card-pad-y`, `--card-pad-x`,
  `--col-gutter`, `--page-pad`). px only, never rem.
- **Serious-tool restraint**: hover effects only on interactive elements; filled
  buttons (`btn-primary`) except in icon-only button groups; no plain Bootstrap
  `.card border-0 shadow`.

## Definition of done

A UI change is not finished until:

1. The page composes existing components, and its own `.scss` is near-empty.
2. **The registry is updated** - a new component row, a lever note on an existing row,
   or an updated "used on" column. That update is part of the change, not a follow-up.
3. `php artisan rsx:jqhtml:glossary --missing` is still clean for components we own.
4. The page renders: `php artisan rsx:debug /path --screenshot` at the widths that
   matter (the responsive tiers are `mobile` 0-1023 / `desktop` 1024+, plus the tier-2
   names - Bootstrap's `col-md-*` does NOT exist here).

Framework-level jqhtml mechanics (lifecycle, slots, `$sid`, events) are the
`rspade:jqhtml` skill's job; this skill is only about WHICH of this app's components
to reach for.
