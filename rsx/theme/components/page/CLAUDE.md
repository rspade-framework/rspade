# rsx/theme/components/page — the page shell and its header chrome

## WHAT IS HERE

- `page_scaffold.{jqhtml,js,scss}` — **the shell every SPA page uses**: a 2300px centered
  column with the outer page padding, `<Slot:main>` plus an optional `<Slot:sidebar>`, and
  a `$ratio` split (`8/4` default; `9/3`, `7/5`, `6/6`). The JS stamps the ratio modifier
  and collapses to full width when the sidebar slot rendered nothing.
- `breadcrumb_nav.{jqhtml,scss}` — the header breadcrumb chain, rendered from a `$crumbs`
  array of `{label, url, is_active, resolved}`; a null label paints a loading placeholder.
- `page_header.{jqhtml,scss}` + `Page_Header.js`, `page_header_left.jqhtml`,
  `page_header_right.jqhtml`, `page_title.jqhtml`, `page_subtitle.jqhtml`,
  `page.{jqhtml,js}`, `page_section.{jqhtml,js,scss}` — the older flex header/title/section
  containers, thin wrappers over Bootstrap utility classes with no behaviour.

## HOW IT IS USED

`Page_Scaffold` is the default page shell: 48 templates compose it, e.g.
`rsx/app/frontend/clients/view/Clients_View_Action.jqhtml` and
`rsx/app/frontend/dashboard/Dashboard_Index_Action.jqhtml`. An action that composes it
also declares `scaffolded = true` on its class, which is how `Frontend_Spa_Layout` (and
the `Settings_`/`System_`/`Portal_` sublayouts) drop their own padding and width cap so
the scaffold owns them — see the layout-reconciliation notes in
`rsx/resource/conventions/semantic_component_registry.md` (Layer 1).

`Breadcrumb_Nav` is mounted from JS, not from a template:
`rsx/app/frontend/Frontend_Spa_Layout.js` feeds it the resolved crumb chain.

The `Page_*` header family predates the scaffold and survives only in
`rsx/app/dev/` and `rsx/app/ssr_test/` (e.g. `rsx/app/ssr_test/components/SSR_Test_Page.jqhtml`).
**A new page uses `Page_Scaffold` + `Entity_Header` (`../view/`), not `Page_Header`.**

## HOW TO CUSTOMIZE

- The page's max-width, outer padding and column gutter live in `page_scaffold.scss` and
  the tokens it reads (`--page-pad`, `--col-gutter` in `rsx/theme/composition_tokens.scss`).
  Change them ONCE here; a page never sets its own width or page padding.
- A new column split is a curated modifier: add the ratio to the class list in
  `page_scaffold.js` and the matching grid rule in `page_scaffold.scss`. Arbitrary ratios
  are deliberately not accepted.
- **Never re-add page padding inside a page's own SCSS** — it doubles against the
  scaffold's, and the sublayouts zero `--page-pad` on the assumption the scaffold owns it.
- The `Page_*` header family is deletable once `dev/` and `ssr_test/` are removed; check
  with `grep -rE "<Page_(Header|Title|Subtitle|Section)[ />]" rsx/` first.

## RELATED

App skill `semantic-components` · `rsx/resource/conventions/semantic_component_registry.md`
(Layer 1) · `../view/CLAUDE.md`, `../section/CLAUDE.md` · `rsx:man semantic_composition` ·
skill `rspade:spa` (layouts and `on_action`)
