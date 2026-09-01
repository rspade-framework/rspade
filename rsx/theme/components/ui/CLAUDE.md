# rsx/theme/components/ui — small interface primitives

## WHAT IS HERE

- `status_badge/` — `Status_Badge`: ONE filled pill for a model enum workflow status.
  `$model` + `$status_id` read the enum's `label`/`badge` through the generated stub;
  `$field` picks a different enum column; `$label` + `$badge` override directly for a row
  payload that already shipped them.
- `action_menu/` — `Action_Menu`: the overflow "..." dropdown (`$label`, `$align`) whose
  items are authored `.dropdown-item` buttons in content. Destructive actions live INSIDE
  it, never as a peer red button beside the primary action.
- `count_pill/` — `Count_Pill`: a tiny neutral pill showing a count; renders `0` honestly.
- `external_link/` — `External_Link`: an outbound link with a globe icon that promotes a
  bare `domain.com` to a full URL.
- `breadcrumb/` — `Breadcrumb` + `Breadcrumb_Item`: the authored breadcrumb list (as
  opposed to `../page/breadcrumb_nav.jqhtml`, which the layout drives from data).

## HOW IT IS USED

`Status_Badge` is the most-used component in the app (19 templates), e.g.
`rsx/app/frontend/clients/view/Clients_View_Action.jqhtml` and
`rsx/app/frontend/dashboard/Dashboard_Index_Action.jqhtml`. `Action_Menu` sits in an
entity header's action cluster (`.../clients/view/Clients_View_Action.jqhtml`,
`rsx/app/frontend/tasks/view/Tasks_View_Action.jqhtml`).

`Count_Pill` has no direct consumer in `rsx/app`: it is composed INSIDE `Section` headers
and `Tab_Bar` tabs, which is how counts reach the screen. The authored `Breadcrumb` pair
survives only in `rsx/app/ssr_test/components/SSR_Test_Page.jqhtml` — the staff shell
renders breadcrumbs through `Breadcrumb_Nav` instead.

**The Rule of Two Chips**: a filled `Status_Badge` carries workflow STATUS; a
CLASSIFICATION (type, role, priority, category) is an outline chip, `.badge-outline-*`
from `rsx/theme/badges.scss`, not a second filled badge. Registry rows: Layer 4 of
`rsx/resource/conventions/semantic_component_registry.md`.

## HOW TO CUSTOMIZE

- Badge colours come from the model's own `$enums` `badge` key — restyle the pill in
  `status_badge.scss`, but change WHICH colour a status is in the model's enum table.
- `$editable` (a click-to-change-status popover) is deliberately not built: a clickable
  status chip implies a transition endpoint. Add it in the same change that adds one.
- `Action_Menu` items are authored by the caller and wired in the owning action's
  `on_ready()` via their `$sid` — the component itself knows nothing about them, so a new
  kind of menu item needs no change here.
- Keep the two breadcrumb implementations apart: `Breadcrumb_Nav` (`../page/`) is the
  layout's data-driven chain and is the one to change for the staff shell.

## RELATED

App skill `semantic-components` · app skill `theme` (badge tokens, `badges.scss`) ·
`rsx/resource/conventions/semantic_component_registry.md` · `../view/CLAUDE.md` ·
`rsx:man semantic_composition` · skill `rspade:model-enums`
