# rsx/theme/components/navigation — the sidebar nav and the search widgets

## WHAT IS HERE

- `sidebar/sidebar_nav.{jqhtml,js,scss}` — `Sidebar_Nav`: the hierarchical primary
  navigation. It takes a `$sections` array of `{title, items[]}` where an item is
  `{label, icon, route, href, children?}`, detects the active item from the current URL,
  and auto-expands the parent of an active child.
- `search/search_input.{jqhtml,js}` — `Search_Input`: the bare search box (`$placeholder`)
  used as a DataGrid's filter field.
- `search/search_bar.{jqhtml,scss}` + `Search_Bar.js` — `Search_Bar`: an input-group
  variant with a leading icon that calls an `$on_search` callback. **No consumer today.**
- `search/search_button.jqhtml` — `Search_Button`: an icon-only search button.
  **No consumer today.**

## HOW IT IS USED

**`Sidebar_Nav` renders the primary navigation; the sections it renders are authored in
`rsx/app/frontend/Frontend_Spa_Layout.js` (`on_create()`, `this.state.nav_sections`) —
that is the file to edit to add, rename or reorder a nav entry.** The layout then filters
that array through the gate check before handing it over, so a link this user's `#[Auth]`
gates would deny never appears (nav honesty). `rsx/app/dev/Dev_Spa_Layout.jqhtml` and
`rsx/app/dev/dev_layout.blade.php` mount the same component with their own sections.

`Search_Input` is the filter box of every DataGrid, e.g.
`rsx/app/frontend/clients/list/clients_datagrid.jqhtml` and
`rsx/app/frontend/settings/api_keys/api_keys_datagrid.jqhtml`.

The nav is not in `rsx/resource/conventions/semantic_component_registry.md` — it is layout
chrome, not view vocabulary. The portal has its own nav in `rsx/portal/`.

## HOW TO CUSTOMIZE

- **Add a nav item**: an entry in `Frontend_Spa_Layout.js`'s `nav_sections` with a `route`
  (the action's class name, used for both active detection and the gate check) and an
  `href` from `Rsx.Route(...)`. Never hardcode the URL.
- **Restyle the sidebar**: `sidebar/sidebar_nav.scss`. It is one of the sanctioned places
  for a deliberately dark surface in both themes — if you fix a colour there, say so in a
  comment so the next reader does not "fix" it back to a token.
- Active detection reads the current URL against each item's `route`/`href`; an item with
  neither will never highlight.
- `Search_Bar` and `Search_Button` are unused shapes: adopt or delete them, but do not
  build a third search widget beside them.

## RELATED

`rsx/app/frontend/Frontend_Spa_Layout.{js,jqhtml,scss}` (the shell that mounts the nav) ·
app skill `theme` · skills `rspade:spa` (layouts), `rspade:auth-gates` (`can_access`,
nav honesty) · `../datagrid/CLAUDE.md` (the search box's real consumer)
