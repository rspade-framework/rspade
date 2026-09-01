# rsx/theme/components/section — card chrome and the section anatomy

## WHAT IS HERE

- `view_section_abstract.{jqhtml,js,scss}` — the ONE home of card chrome (background,
  border, radius, shadow), which IS Bootstrap's `.card`. Never used as a tag; the concrete
  wrappers re-parent it with `extends=` and restate its root classes.
- `section.{jqhtml,js,scss}` — the workhorse: header anatomy (`$icon`, `$title` or
  `<Slot:title>`, `$count` rendered as a `Count_Pill`, `<Slot:actions>`) over a padded
  body. `$flush` strips the body padding for edge-to-edge content such as a table.
- `card_widget.jqhtml` — a standalone page-level card: chrome plus a padded body with the
  standard block rhythm, and no header anatomy.
- `detail_sidebar.jqhtml` — the entity sidebar card: KPIs at the top, then fact fields,
  then actions, stacked on the same rhythm.
- `section_columns.{jqhtml,scss}` — a nested 2:1 split (`<Slot:primary>` /
  `<Slot:secondary>`) inside a section body; carries no chrome of its own. **No consumer
  today** — kept as the reserved shape for the first nested split.

## HOW IT IS USED

`Section` is what almost every panel on a view page is (36 templates), e.g.
`rsx/app/frontend/clients/view/Clients_View_Action.jqhtml` and
`rsx/app/frontend/dashboard/Dashboard_Index_Action.jqhtml`. `Card_Widget` is the plain
container when there is nothing to put in a header; `Detail_Sidebar` fills
`Page_Scaffold`'s `<Slot:sidebar>`.

**The slot rule** (jqhtml, not negotiable): if you use `<Slot:title>` or `<Slot:actions>`,
the body MUST move into `<Slot:body>` — loose content beside named slots is a parse error,
and `default` is a reserved slot name. With no named slots, the body is loose content.

**The section owns the flush, the table never cancels padding from inside**: a
`Record_Table` goes inside `<Section $flush=true>`.

Registry rows (usage, replaces, gotchas):
`rsx/resource/conventions/semantic_component_registry.md`, Layer 2.

## HOW TO CUSTOMIZE

- **Reskinning every card in the app is one edit**: `view_section_abstract.scss`, which
  points Bootstrap's card padding tokens at the app's composition tokens. Do not restyle
  `.card` from a page.
- A new section FLAVOUR is a new component with `extends="View_Section_Abstract"` that
  restates the shared root classes and renders its own template — the pattern `Section`,
  `Card_Widget` and `Detail_Sidebar` already follow.
- `View_Section_Abstract` deliberately has **no `overflow: hidden`**: dropdowns and
  popovers rendered inside a section must be able to escape it. Do not add one.
- Header anatomy is dual-channel by design (`$title` for plain text, `<Slot:title>` for
  markup). HTML inside the `$title` arg is a defect, not a shortcut.
- `Section_Columns` may be deleted at the next residue sweep if nothing adopts it.

## RELATED

App skill `semantic-components` · `rsx/resource/conventions/semantic_component_registry.md` ·
`../page/CLAUDE.md`, `../view/CLAUDE.md`, `../card/CLAUDE.md` · `rsx:man semantic_composition` ·
skill `rspade:jqhtml` (slots, `extends=`)
