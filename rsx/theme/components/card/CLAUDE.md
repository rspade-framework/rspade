# rsx/theme/components/card — the thin Bootstrap card wrappers

## WHAT IS HERE

Five template-only wrappers over Bootstrap's `.card` classes, none with behaviour:

- `card.{jqhtml,js,scss}` — `.card` plus a `.card-body` around its content.
- `card_header.jqhtml` — `.card-header.bg-body-tertiary` with a space-between flex row.
- `card_header_right.jqhtml` — the trailing action cluster inside a header.
- `card_title.jqhtml` — an `<h5 class="mb-0">` title.
- `card_footer.jqhtml` — `.card-footer.bg-body-tertiary`, same flex row as the header.

## HOW IT IS USED

This is the ORIGINAL card vocabulary, largely superseded by `../section/`
(`View_Section_Abstract` + `Section` + `Card_Widget`), which owns the app's card chrome in
one place. What still uses it:

- `Card_Title` is the heading of every DataGrid card, e.g.
  `rsx/app/frontend/clients/list/clients_datagrid.jqhtml` and
  `rsx/app/frontend/settings/user_management/list/users_datagrid.jqhtml`.
- `Card`, `Card_Header`, `Card_Footer` survive only in the SSR harness, e.g.
  `rsx/app/ssr_test/components/Pricing_Card.jqhtml`.

**Build a new panel with `<Section>` or `<Card_Widget>`, not with `<Card>`** — a second
card chrome is exactly the duplication the section group exists to prevent. The registry
(`rsx/resource/conventions/semantic_component_registry.md`, Layer 2) records the
replacement.

## HOW TO CUSTOMIZE

- These wrappers carry no styling of their own beyond the Bootstrap class strings in the
  `<Define>` tags; restyle by changing those classes, or better, by moving the consumer
  onto `Section` and restyling `view_section_abstract.scss` once.
- `Card_Title` is the one member with live consumers — renaming or removing it means
  touching every `*_datagrid.jqhtml`. Check with
  `grep -rE "<Card_Title[ />]" rsx/` before changing it.
- The rest are deletable once `rsx/app/ssr_test/` is removed; delete the whole group only
  after the `Card_Title` consumers are moved.

## RELATED

`../section/CLAUDE.md` (the current card chrome) · `../datagrid/CLAUDE.md` ·
app skill `semantic-components` · `rsx:man semantic_composition`
