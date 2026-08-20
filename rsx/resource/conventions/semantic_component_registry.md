# Semantic Component Registry

The living memory of the semantic view-page refactor. Update it as part of EACH
page's definition of done - new component rows, lever notes, "used on" columns,
decremented legacy-pattern counts, page-tracker status.

Authoritative design guidance: `php artisan rsx:man semantic_composition`.
Method source: `docs.dev/external_requests/2026_07_12_semantic_view_page_refactor_guide.md`.
Glossary generator: `php artisan rsx:jqhtml:glossary` (run `--missing` before building any new UI element; keep it at zero for the vocabulary we own).

---

## THE GOAL (owner-stated, verbatim intent)

> A reusable, organized widget library, and UIs revised to a consistent flow
> built from those reusable components - making the UIs consistent and
> reskinning straightforward later.

Components are the HOW, not the WHY. The why is **simplicity -> cohesion**. Four
outcomes: consistently reusable tags; separation of concerns by layer; consistent
unit types (one component per data shape); reskinning-readiness. The success test:
a converted page's template reads like a program's main routine, and its page
SCSS file is near-empty.

---

## Token survey (Batch B, 2026-07-12)

As-built spacing surveyed across `Frontend_Spa_Layout` and the entity view pages.
Where the app disagreed, ONE standard was chosen (recorded here). The four
relationship tokens are CSS custom properties in `rsx/theme/composition_tokens.scss`,
bound to Sass values in `rsx/theme/variables.scss`.

| Token | Standard | As-built evidence & choice |
|---|---|---|
| `--block-gap` | 24px (`$spacing-lg`) | `mb-4` between every card on every view page = 24px. |
| `--card-pad-y` | 16px (`$spacing-md`) | Real cards render Bootstrap `.card-body` = 16px. The `$card-padding` token (32px) was never applied on view pages; standardized on the as-built 16px. |
| `--card-pad-x` | 20px (`$spacing-5`) | As-built horizontal was 16px; stepped one notch to 20px for the header anatomy a Section now carries (low-impact preference call). |
| `--col-gutter` | 24px (`$spacing-lg`) | Default Bootstrap `row` gutter on the `col-desktop-8/4` shells = 24px. |
| `--page-pad` | 32px (`$spacing-xl`), 16px mobile | `Frontend_Spa_Layout__page-content` horizontal padding = `$spacing-xl`; mobile drops to 16px, matching the layout. |
| `$content-max-width` | **2300px** | D1: was 1600 (wide 1800). Raised to 2300, centered, Etsy-style margins beyond. Both `$content-max-width` and `-wide` now = 2300. |

### Layout reconciliation (Batch C, RESOLVED)

`Frontend_Spa_Layout__page-content` still owns its padding (30/32px) and the
`--constrained` / `--constrained-wider` max-width caps (1200/1600) for the ~40
UNCONVERTED pages. A page that adopts `Page_Scaffold` must not inherit either,
or the scaffold's 2300px cap sits under the 1200px cap and the scaffold's
`--page-pad` doubles the layout padding.

**Mechanism** (chosen for zero blast radius): an action that composes with
`Page_Scaffold` declares `scaffolded = true` (a class field, exactly like the
existing `full_width` / `constrained_wider` opt-ins). `Frontend_Spa_Layout.on_action`
clears all three width modifiers then applies the one the action requests; for a
`scaffolded` action it stamps `Frontend_Spa_Layout__page-content--scaffolded`,
which sets `max-width:none; margin:0; padding:0` so the scaffold owns all
width/padding. The persistent content element is reset on every navigation, so
navigating from a scaffolded page to an unconverted one restores the old class.
Unconverted pages never set `scaffolded`, so their `full_width` / `constrained`
rendering is byte-identical (regression-verified on Clients_Index (full_width),
Dashboard (full_width), and Contacts_View (constrained): classes + padding
unchanged).

### Settings_Layout reconciliation (Batch G, RESOLVED)

Batch E deferred all settings-sublayout views: an action there mounts in
`Settings_Layout`'s `.settings-content` pane (beside the 220px settings nav, in a
`220px 1fr` grid with its own `$spacing-lg` padding), which the Batch-C
`scaffolded` flag does NOT neutralize (that flag only touches
`Frontend_Spa_Layout`'s outer page-content). A raw `Page_Scaffold` there would add
its own `--page-pad` on top of the grid's padding (double-padding).

**Mechanism** (mirrors Batch C, both layers reconcile): a settings action that
composes with `Page_Scaffold` still declares `scaffolded = true`. BOTH layouts in
the chain react: `Frontend_Spa_Layout.on_action` stamps
`Frontend_Spa_Layout__page-content--scaffolded` (outer padding/constraint off, as
before), AND `Settings_Layout.on_action` stamps `settings-content--scaffolded` on
its content pane. The settings modifier does exactly ONE thing:
`{ --page-pad: 0; }` — so the nested `Page_Scaffold`'s `padding: var(--page-pad)`
collapses to 0 and the scaffold fills the pane cleanly; the `.settings-layout`
grid still frames it (its own `$spacing-lg`/mobile `$spacing-md` padding + the nav
gutter), and the scaffold's 2300px max-width never binds inside the narrower
content track. NO cross-component styling — the modifier only overrides a CSS
custom property in its own scope, which cascades to the child scaffold. Reset per
navigation via `toggleClass(..., !!this.action.scaffolded)`, so an unconverted
settings page restores the default. Regression-verified: unconverted
`Settings_User_Settings` and `Settings_Password_Security` render byte-identical
(standard `.card` chrome, no scaffold modifier, no double-padding), desktop + mobile.

The mechanism generalizes to any sublayout (the pending Portal_Spa_Layout /
portal-sublayout views take the same one-line `--page-pad: 0` treatment on their
content pane).

### Portal layout reconciliation (Batch J, RESOLVED - the 4th + 5th layers)

The portal is a SECOND design surface (own layout, own session) and reconciles in TWO
layers, mirroring the staff Frontend_Spa_Layout + Settings/System sublayout pattern:

- **`Portal_Layout` (OUTER, like Frontend_Spa_Layout).** The content wrapper `.container-fluid
  .py-4` became `<div class="Portal_Layout__page" $sid="content">`. `Portal_Layout.on_action`
  stamps `Portal_Layout__page--scaffolded` (SCSS: `padding: 0`) so a scaffolded action's nested
  `Page_Scaffold` owns page width/padding + its own `--page-pad` (32px). Default (non-scaffolded)
  padding is `$spacing-lg`. Every portal SPA page is scaffolded after Batch J, so the default is
  effectively dead but the mechanism stays per-action (reset per navigation via `toggleClass`).
- **`Portal_Workspace_Layout` (SUBLAYOUT, like Settings_Layout).** Its `$content()` pane
  (`.Portal_Workspace_Layout__content`, inside the `.container.py-4 > .row > col-9` chrome) gets
  `Portal_Workspace_Layout__content--scaffolded` → `{ --page-pad: 0 }` so the nested scaffold
  fills the pane (the workspace `.container`/`.row` already frames it; the scaffold's 2300px
  max-width never binds in the narrow track). NO cross-component styling — only a CSS-var override
  cascading to the child scaffold.

For a workspace sub-action BOTH react (Portal_Layout zeroes outer padding, Portal_Workspace_Layout
zeroes `--page-pad`), exactly as a Settings entity view drives both Frontend_Spa_Layout +
Settings_Layout. Both layouts keep all their layout-chrome SCSS. Also added:
`Portal_Request_Thread_Action → 'requests'` to the workspace NAV_CONFIG so the Requests pill
stays active on a thread (was un-highlighted before).

### System_Layout reconciliation (Batch H, RESOLVED)

`System_Layout` (the system-admin sublayout, `220px 1fr` grid + own `$spacing-lg`
padding, same shape as `Settings_Layout`) took the identical one-line treatment:
`System_Layout.on_action` stamps `system-content--scaffolded` on its content pane;
the SCSS modifier is exactly `{ --page-pad: 0; }`. A system action composing with
`Page_Scaffold` declares `scaffolded = true`; BOTH `Frontend_Spa_Layout` (outer
constraint off) and `System_Layout` (`--page-pad: 0`) react. Reset per navigation
via `toggleClass(..., !!this.action.scaffolded)`. This is the third layer to adopt
the pattern (Frontend_Spa_Layout Batch C, Settings_Layout Batch G, System_Layout
Batch H); Portal_Spa_Layout remains the one pending sublayout.

---

## Layer 1 - Page scaffold

| Component | Usage | Replaces | Considerations / gotchas |
|---|---|---|---|
| `Page_Scaffold` | View-page shell: 2300px centered, `<Slot:main>` + optional `<Slot:sidebar>`, `$ratio` (8/4 default; 9/3, 7/5, 6/6). Mobile: sidebar stacks under main. | Hand-assembled `page-content` + `row` + `col-desktop-8/4` shells | Ratio stamped as a modifier class in JS (curated set, not arbitrary). No-sidebar collapses to full width (JS detects empty slot in `on_render`). No display-mode signal (D1). Sits inside the layout's page-content today - reconcile that padding/constraint in Batch C. |

## Layer 2 - Section / card chrome

| Component | Usage | Replaces | Considerations / gotchas |
|---|---|---|---|
| `View_Section_Abstract` | Chrome base (bg/border/radius/`$shadow-sm`). Concrete children re-parent it via `extends=`; the base class is stamped on the shared root. Never a tag. | Duplicated `.card` chrome | No `overflow:hidden` (dropdowns/popovers inside must escape). Restyle the card look here, once. |
| `Section` | The workhorse: header anatomy (`$icon` / `$title` + `<Slot:title>` / `$count` -> `Count_Pill` / `<Slot:actions>`) + padded body. `$flush` strips body padding (R2). | `card card-body` + hand `<h5>` headers + `<hr>` dividers | **Slot rule:** if you use `<Slot:title>` or `<Slot:actions>`, the body MUST move to `<Slot:body>` (the jqhtml parser forbids loose content beside named slots; `default` is reserved). No named slots -> body loose. P2: `$flush` + a self-headed widget = misaligned header; wrap self-headed widgets in a plain Section. |
| `Card_Widget` | Standalone page-level card (chrome + padded body, no header anatomy). | `card card-body` page containers | Body is a flex-column with `--block-gap` rhythm. Padded - if a full-bleed tab bar is wanted flush to the edge, revisit in Batch C. |
| `Detail_Sidebar` | Entity sidebar wrapper (chrome + stacking rhythm) for KPIs -> fields -> actions. | Ad-hoc sidebar card stacks | Body flex-column with `--block-gap`. KPI cells (`Sidebar_Kpi_Group`, Batch C) live at the TOP. |
| `Section_Columns` | Nested 2:1 split inside a section (`<Slot:primary>` / `<Slot:secondary>`); own collapse + gutter. | Nested `row`/`col` inside a card | Not a card (no chrome). Named slots only (no mixing). Collapses to stacked on mobile. **Zero live consumers** - built as part of the plan-mandated Layer-2 chrome set, but no page has yet needed a nested split. ORCHESTRATOR DECISION: keep, marked "reserved - the first nested-split consumer adopts it, or it is deleted at the next residue sweep." |

## Layer 4 - Content vocabulary (seeded in Batch B; the rest land in later batches)

| Component | Usage | Replaces | Considerations / gotchas |
|---|---|---|---|
| `Count_Pill` | Tiny neutral pill showing a count; `$count` (data). | `(<%= x.length %>)` text suffixes | Renders `0` honestly. Used inside `Section` headers and `Tab_Bar` tabs. |
| `Entity_Header` | Entity identity header: `<Slot:title>` H1 + `<Slot:chips>` + `<Slot:subtitle>` + `<Slot:meta>`. Slot-first (no title arg). | Hand-rolled `.view-header` H1 + hand-typed badge spans | All content authored. `__top` flexes title + chips; `__meta` is the muted id/date/parent-chain row. |
| `Status_Badge` | ONE filled status pill from a model enum. `$model`+`$status_id` reads `Model.status_id__enum(v)`; `$field` overrides the enum field; `$label`+`$badge` override directly (precomputed row payloads). | `<span class="badge <%= x.status_badge %>">` (the Clients_View bug: wrong `status_badge`/`status_label` keys) | Filled `.bg-*` reused from the enum's `badge` (not "pastel" - D1 keeps as-built colors). `$editable`/popover deliberately NOT built (no client status-transition endpoint; would imply a fake action). |
| outline chip (`.badge-outline-*`) | CLASSIFICATION chip (type/role/category/priority) - Rule of Two Chips. Theme primitive, `rsx/theme/badges.scss`. | Filled `.badge .bg-*` used for a classification (priority) | Pair with Bootstrap `.badge`. Map a filled `bg-X` to outline: `x.replace('bg-','badge-outline-')`. Colors: primary/secondary/success/info/warning/danger/dark. |
| `View_Field` / `View_Fields` | Label/value facts. `View_Fields` = responsive auto-fit grid (1 col in a narrow container). `View_Field`: `$label`, `$icon` (label icon), `$inline` (label-left/value-right); value is loose content(). | `.row > .col-md-6 > label.text-muted + div` fact grids | Value via loose content() (arg label + loose body = allowed). Add `<Slot:label>` only when a label needs markup beyond an icon (none yet). |
| `Sidebar_Kpi_Group` / `Kpi_Cell` | "At a Glance" telemetry at the TOP of a `Detail_Sidebar` (and, levered, a dashboard `Stat_Group`). Group = 2-up grid; `Kpi_Cell` (slot-first: `<Slot:label>`/`<Slot:value>`/`<Slot:sub>`) with `$alert`, `$clickable`+`$tab`, `$tooltip`. | (new - 0 of the app's view pages had a KPI sidebar) | **RENAMED `Sidebar_Kpi_Row` -> `Kpi_Cell` in Batch K** (P10 naming debt - the cell is consumed by both `Sidebar_Kpi_Group` AND the dashboard `Stat_Group`, so the `Sidebar_` prefix under-described it; the GROUP keeps its `Sidebar_` name, it IS sidebar-specific). Files moved to `rsx/theme/components/view/kpi_cell/`. `$clickable` exposes `data-kpi-tab`; the owning action delegates `.Kpi_Cell--clickable` clicks to `this.sid('<tab-bar>').activate(tab)`. `$provisional` (amber estimate) deferred until a page needs it. |
| `Empty_State` | Empty LIST region: `$icon` + `<Slot:title>` + `<Slot:body>` + `<Slot:cta>`. | bare `<p class="text-muted">No ...</p>` | Centered; own padding, so it sits fine inside a `$flush` section. Single empty CELL -> `Empty_Value`. |
| `Empty_Value` | The one muted em-dash for a single empty cell/value. | `<span class="text-muted">-</span>`, `\|\| '-'` | Template-only. |
| `Entity_Link` | Fetched entity reference: type icon + name (+ link). `$model`+`$id`, `$no_link` (inside clickable rows), `$small`, `$icon`, `$placeholder`. | `Client_Label` / `Client_Label_Link` (DELETED) | **Store a PLAIN object in `this.data`, NEVER the hydrated model instance** - a model instance defeats the post-load re-render (the framework serializes `this.data` to detect the change), so the name never paints inside DataGrid rows. Per-model icon/route maps (`ICONS`/`ROUTES`); routes to the SPA `*_View_Action`. |
| `Action_Menu` | Overflow "..." dropdown for secondary/destructive actions (Bootstrap dropdown). `$label`, `$align`. Items authored as `.dropdown-item` in content(). | a peer red Delete button next to the primary action | Destructive items live INSIDE, never as a peer red button. Give items `$sid`; wire in the action's on_ready. |
| `Record_Table` | Compact record list table; authored `<thead>`/`<tbody>`. `<tr data-href>` = whole-row nav (skips a/button/input/select/label/`.no-row-nav`). | `table table-hover` + a page-local `.__clickable_row` class + per-page `Spa.dispatch` JS | Place inside a `<Section $flush=true>` (R2 - section owns the flush). Row-nav is idempotent-delegated in on_render. |
| `Tab_Bar` | Neutral tab strip; `$tabs` array of `{key,label,icon?,count?}`, `$hash` URL persistence, `$divided` border-top seam. Fires `tab_change`. | Hand-rolled `nav-tabs`; the copy-pasted `<hr>` seam (P5 lever). | Match tab buttons by attribute filter, NOT `escape_jq_selector` (it prepends `#`). Idempotent delegated click in `on_render`. |
| `Tab_Panels` / `Tab_Panel` | Semantic panels bound to a sibling `Tab_Bar`; `Tab_Panel $key` matches a tab. | Hand-rolled show/hide JS | `Tab_Panels` locates its sibling `Tab_Bar` and follows `tab_change` (event replay covers timing). `Tab_Panel` self-registers and starts hidden. |
| `Feed_Row` | One activity/timeline entry: icon tile + "actor verb object" summary (`<Slot:body>`) + relative time (`<Slot:time>`). `$icon`, `$variant` (primary/success/info/warning/danger/secondary tints the icon). | Dashboard "Recent Activity" table rows (Action/Entity/User/Time) | Built Batch D (Dashboard). DISTINCT from Author_Meta_Row (authored-post byline w/ avatar) - Feed_Row is a single-line event. Self-dividing: place a stack inside `<Section $flush=true>`; each row owns padding + a hairline (last-child none), no container styling. Fed by `Action_Log_Model::render()` (already emits the linked summary). Evidence at build = 1 live site (dashboard); Action_Logs_View + portal feed were the imminent 2nd/3rd - mission-directed build. **Used on 6 pages as of Task-mgmt Batch 2:** Dashboard (recent activity) + all four entity-view Activity tabs (Contacts / Projects / Party / Clients) + Tasks_View Activity tab (decorated via the same `Activity_Feed.decorate(type_id)`; task range 30-39 already in the map, no addition needed). Single-consumer debt cleared. The entity Activity tabs decorate the raw `{id,html,created_at,type_id}` payload with icon/variant via the shared JS `Activity_Feed.decorate(type_id)` (`rsx/lib/action_log/activity_feed.js`) - the client-side twin of the dashboard's server-side `_activity_icon()`, so every page renders the feed identically. |
| `Stat_Group` | Dashboard headline-KPI strip: a responsive grid (4-up desktop / 2-up tablet / 1-up phone) of KPI cells. Contains `Kpi_Cell` cells (levered, not duplicated). | Dashboard "Quick Stats Bar" (`col-6 col-md-3 border-end` cells w/ literal numbers) | Built Batch D. A dashboard is the ONE legit home for a headline stat strip (guide 6.2); an entity view keeps telemetry in the sidebar (`Sidebar_Kpi_Group`). Owns only layout; the cell look is `Kpi_Cell`'s (this dual dashboard+sidebar consumption is exactly why `Sidebar_Kpi_Row` was renamed `Kpi_Cell` in Batch K). `minmax(0,1fr)` tracks so a wide value can't blow the grid out. |
| `Widget_Grid` | Overview layout container: a 2-up (desktop) / 1-up (mobile) grid of independent widget cards, owning the inter-widget gap. | Bootstrap `row` + `col-12 col-lg-6` shells wrapping each dashboard card (box-in-box) | Built Batch D. `minmax(0,1fr)` tracks + `align-items:start` so a wide record table shrinks to its track and scrolls inside its own card (see Section `--flush` overflow) instead of forcing the page wider. Portal_Dashboard is the imminent 2nd consumer. |
| `Stat_Row` | One money / numeric stat LINE: `<Slot:label>` + monospaced right-aligned `<Slot:value>`; `$strong_label` (totals: top divider + bold label + primary value), `$alert` (red value). | Hand-rolled invoice `Subtotal / Tax / Total` `<table class="table table-sm">` rows | Built Batch F (invoices). Distinct from `Kpi_Cell` (a big telemetry CELL) - Stat_Row is a single label:value line, `$font-family-mono` + `tabular-nums`. Self-stacking (each owns its row padding; `$strong_label` draws the divider above the total). `$provisional` (amber estimate) is RESERVED, not built - no page yet shows a computed estimate. **RESERVED-WITH-RATIONALE as of the tab-alignment epic Batch 1:** both live consumers (Invoices_View totals block + Invoices_Add live totals) were REMOVED when the invoices pages were deleted per owner decision, so Stat_Row now has ZERO live consumers. The money-line VOCABULARY SHAPE is kept intact (like `Section_Columns`) - the first real money/billing page re-adopts it; delete at the next residue sweep if none appears. Its docblock invoice example is retained as the canonical money-line illustration. |
| `Callout` | Inline alert banner: `$variant="danger"\|"warning"` (validated loudly) + `$icon`; `<Slot:title>` + `<Slot:body>` + `<Slot:actions>`. | The Clients_View deleted-client `.alert alert-danger`; an overdue-invoice warning box | Built Batch F (invoices). Reached the 2-live-consumer extraction bar (Invoices_View overdue warning + the retrofitted Clients_View deleted-client banner). **Tab-alignment Batch 1:** the Invoices_View consumer was removed with the invoices pages; the **Clients_View deleted-client banner remains a live consumer**, so Callout stays a live (not reserved) component (1 live consumer, still justified - it was extracted at 2). Its docblock overdue-invoice example is retained as an illustration. Colored left accent + colored icon/title on a neutral surface (serious-tool restraint, not a loud Bootstrap alert); no new color tokens. Distinct from `Feed_Row` (event line) and `Empty_State` (empty region). |
| `Placeholder_Card` | Coming-soon / not-yet-built FEATURE panel: `$icon` + `$variant="dashed"\|"muted"` (default dashed, validated loudly) + `<Slot:title>` + `<Slot:body>` + `<Slot:cta>`. | Ad-hoc `text-center py-5` + `display-1` "coming soon" placeholders (System_Status/Tasks, Reports body) | Built Batch H. 3 live consumers at build (System_Status, System_Tasks, Reports body) - well past the evidence bar. Centered content like `Empty_State` but a full-width card BLOCK standing in for a whole unbuilt feature (not a zero-child list region inside a populated feature - that stays `Empty_State`). `dashed` = unbuilt scaffolding; `muted` = calmer filled panel. Tokens only (`$gray-400` dashed border / `$border-color`+`$background-light` muted). |

| `Realtime_Status_Badge` | Header "connection lost" pill: observes `Rsx_Realtime.on_state_change()` and fades in a warning pill ONLY during a genuine realtime outage; hidden by default. Subscribes to NO topic. `rsx/theme/components/realtime/`. | (new - no prior realtime connection indicator) | State-observer, not content vocabulary. Arms a 5s grace timer on `connecting`/`reconnecting` only; hides on `connected` or a TERMINAL `disconnected` (a bare `disconnected` = idle/lazy/disabled, never warns; a transient reconnect `disconnected` is distinguished by a deferred `_last_state` check). Wired into BOTH the staff (`Frontend_Spa_Layout`) and portal (`Portal_Layout`) headers beside the notification/user chrome. Registers the listener in `on_create` (stores unsubscribe), tears down in `on_stop`; visibility kept in `this.state` and re-applied in `on_render` so a layout re-render never loses it. |
| `Person_Avatar` | A person's face: a profile image or a deterministic initials disc. `$name` (seeds alt/initials/colour), `$avatar_url`, `$initials` (override), `$size` (`sm` 28 / `md` 36 / `lg` 72). Display-only. | The per-page `Request_Participant_Avatar` (frontend) + `Portal_Participant_Avatar` (portal) - BOTH DELETED | Built Batch I. Promoted from the identical per-page participant avatars. Initials derive from `$name` when `$initials` absent (so it works for a thread message byline that ships only an author name); the disc hue is a deterministic function of the resolved initials, so participant discs (which pass explicit initials) render byte-identical to before. Consumed by `Author_Meta_Row`, `People_List`, and the two thread contact-card modals (staff + portal, both `$size="lg"`). Inline `background-color` (a per-person hue) is the one non-token value, by necessity. Batch J migrated the portal twin's consumers (thread + participant-card modal body) onto it and DELETED `Portal_Participant_Avatar`. |
| `Author_Meta_Row` | The shared "who + when" byline: `Person_Avatar` + `<Slot:author>` + `<Slot:time>` (+ `<Slot:actions>`). `$name`/`$avatar_url`/`$initials` feed the avatar; author/time/actions are authored slots. | Hand-rolled `.message-head` bylines (staff request-thread post; portal thread post) | Built Batch I (guide 6.5: converge the SHARED byline, keep the domain wrappers). 2 live sites: the staff thread + (Batch J) the portal thread (byte-identical shape). DISTINCT from `Feed_Row` (an icon-tile "actor did thing" event line, no avatar). The author line's emphasis/links/inline-email are authored in the slot (a `<strong>` name + muted email). Wraps the timestamp below the author line on narrow widths. |
| `People_List` | A calm vertical list of people: stacked `Person_Avatar` + name rows. `$people` (array of `{name, avatar_url?, initials?, subtitle?}`), `$clickable` (rows react + fire `person_click`), `$removable` (per-row remove button + `person_remove`), `$size`, `$empty_text`. Fires `person_click`/`person_remove` with `{person, index}`. | The hand-rolled `__group`/`__people`/`__media-row` participant pills in BOTH request threads (staff + portal) | Built Batch J. **Used on 3 live sites as of Task-mgmt Batch 3:** the staff (`Clients_Request_Thread`) + portal (`Portal_Request_Thread`) participant sidebars (both retrofitted at build) + **Projects_View Overview** (Assigned Users list `$clickable=false`; Contacts list `$clickable=true` -> `Contacts_View_Action`, one `on_ready` binds every `.People_List` and routes on `person.type`). The person object carries `{id,type,name,subtitle}` and is handed straight back on click (no `_find_*`). NEVER a wrapping pill cloud - a scannable stacked list (guide Layer 4). Domain-free: it hands the exact person object back on click, so the owning action drops its `_find_participant` lookup (`this.$.find('.People_List').each(el => el.component().on('person_click', ...))` - both member/staff groups share one handler; child components are recreated per render so the bind stays fresh). `$removable` is the opt-in write affordance (reserved - no live editor consumer yet). The doc buckets in the threads are NOT people (thumbnail, not avatar), so they stay domain `__media-row` - People_List converged only the PERSON shape (guide 6.5). |

**Vocabulary COMPLETE as of Batch J.** People_List (built Batch J) was the last unbuilt
vocabulary component. (Author_Meta_Row + Person_Avatar built in Batch I; Stat_Row + Callout in
Batch F; Placeholder_Card in Batch H; Feed_Row in Batch D — see their rows.) `People_List`'s 2nd
live consumer arrived exactly as predicted (the portal participants sidebar), so it was built in
Batch J and BOTH thread participant sidebars (staff + portal) were retrofitted onto it in the
same batch (guide's evidence bar + retrofit-in-same-batch discipline).

`Status_Badge $editable` (change-status popover) is deferred, not missing: it is
only warranted where an entity has a real inline status-transition endpoint.

**Nothing is MISSING as of Batch K; the vocabulary is complete and every reserved
element is deferred WITH a reason:**
- `Qty_Rate_Sentence` - reserved, never built: no page shows a "qty x rate" sentence
  (invoice line items are a 4-column `Record_Table`, not prose). Build when an evidence
  site appears.
- `Profile_Hero_Header` / an `$avatar` lever on `Entity_Header` - deferred: fails the
  2-live-consumer bar (only `Settings_Profile_Display` is a person-profile view; the
  portal user-profile view is the awaited 2nd consumer). Restore the profile avatar there.
- `Stat_Row` (the whole money-line component) - RESERVED as of the tab-alignment epic
  Batch 1: its two consumers were the deleted invoices pages, so it now has 0 live
  consumers. Shape kept intact for the first real money/billing page (see its Layer-4 row).
- `$provisional` (amber/italic estimate) on `Stat_Row` / `Kpi_Cell` - reserved additive
  arg: no page renders an `is_estimated`-style figure yet.
- `$removable` on `People_List` - reserved: no live editor consumer yet.
- `Status_Badge $editable` - see above.
- `Feed_Row` 2nd/3rd consumer - the portal "What's New" feed was judged a look-alike and
  kept as a domain shape (Batch J); Feed_Row stays earmarked for a true audit feed.

**Batch K renamed `Sidebar_Kpi_Row` -> `Kpi_Cell`** (P10 naming debt first flagged in
Batch D): the cell is consumed by both `Sidebar_Kpi_Group` (sidebar telemetry) AND the
dashboard `Stat_Group`, so the `Sidebar_` prefix under-described it. The GROUP keeps its
`Sidebar_Kpi_Group` name (genuinely sidebar-specific). Files moved to
`rsx/theme/components/view/kpi_cell/`; class, SCSS class (`.Kpi_Cell`, `--alert`,
`--clickable`), and all 14 consumer call sites + the `Clients_View_Action` delegation
selector (`.Kpi_Cell--clickable`) updated. `data-kpi-tab` (not named after the component)
was left as-is. Only residue: the sanctioned "formerly Sidebar_Kpi_Row" doc-comment inside
`kpi_cell.jqhtml` (intentional - records what the component replaced). The docs have since
been migrated: grep confirms ZERO `Sidebar_Kpi_Row` in `CLAUDE.md`, `CLAUDE.dist.md`, or any
man page - the whole rename is settled in both code and docs.

## Levered existing components

| Component | Lever added | Notes |
|---|---|---|
| `Form_Field` | `<Slot:label>` (wins over `$label`); arg now rendered escaped (`<%=`). | Dual-channel D2. 104 arg-based consumers unaffected. With `<Slot:label>`, input moves to `<Slot:body>`. The escape switch surfaced 5 HTML-in-`$label` defects in Clients_Edit (4 social icons + 1 `&nbsp;` spacer) - all converted to `<Slot:label>`. |
| `Section` | `--flush .Section__body { overflow-x: auto; }` (Batch D). | A too-wide edge-to-edge table on a narrow viewport now scrolls inside its card instead of forcing the page wider. Additive, flush-only (padded sections unchanged); flush bodies hold tables/empty states, never popovers, so a scroll container is safe. Regression-verified on Clients_View (desktop + mobile) - identical. |
| `Entity_Link` | Task-mgmt Batch 2: `Task_Model` + `User_Model` added to `ICONS`; `Task_Model` added to `ROUTES` (-> `Tasks_View_Action`); `User_Model` deliberately absent from `ROUTES` (no staff view page -> renders icon + name, no anchor). Display-name resolution now falls back `name` -> `title` (task) -> `first+last`/`get_full_name`/`email` (user). | Lets the one reference component serve the task parent-chain idiom ("Part of: <Entity_Link>") for ALL taskable types (Project/Client link; Task links to its view; User plain-text) and the Tasks_View Assignee/Project fields. Existing Client/Project/Contact consumers unaffected (`.name` still resolves first). |
| `Kpi_Cell` (was `Sidebar_Kpi_Row`) | No code change - REUSED on the dashboard inside `Stat_Group` (Batch D). | The KPI cell is a generic display shape (uppercase label + big value + `$alert`), levered onto the dashboard rather than duplicated into a new `Stat_Tile`. **RESOLVED (Batch K): renamed `Sidebar_Kpi_Row` -> `Kpi_Cell`** - the deferred P10 rename this dual sidebar+dashboard reuse first flagged in Batch D. Files, class, SCSS class, and all 14 consumer call sites updated; `Sidebar_Kpi_Group` kept its name (genuinely sidebar-specific). |

## Deleted

| Component | Reason |
|---|---|
| `Data_Table` (`rsx/theme/components/_data_table/`) | Self-flagged obsolete, 0 live uses (only archived research docs reference it). P10: no live naming residue. |
| `Client_Label` / `Client_Label_Link` (`rsx/theme/components/business/`) | Generalized into `Entity_Link` (Batch C). All 4 live call sites (Contacts_Edit, contacts_datagrid, Contacts_View, Projects_Edit) converted. P10: only doc-comment references remain (in entity_link.jqhtml/.js, describing what it replaced). Old link variant pointed at `Frontend_Clients_Controller::view`; Entity_Link routes to the correct SPA `Clients_View_Action`. |
| `Portal_Participant_Avatar` (`rsx/portal/workspaces/requests/thread/`) | DELETED Batch J - the portal twin of the frontend `Request_Participant_Avatar` (killed Batch I). Both consumers (the portal thread participant sidebar + the participant-card modal body) migrated to the theme `Person_Avatar`; the `.jqhtml` + `.scss` (39 SCSS lines) removed. P10: no live-code residue (grep clean); only a historical doc-comment in `person_avatar.jqhtml` describing what it replaced + the registry rows here. |
| `Form_Hidden_Field` (`rsx/theme/components/forms/`) | DELETED Batch H - a proven footgun. It extends `Form_Field_Abstract`, NOT `Form_Input_Abstract`, so `Rsx_Form.vals()` (`shallowFind('.Form_Input_Abstract')`) never reads it: any lookup-key value it carried was silently dropped on submit (edit -> `find("")` -> duplicate INSERT). Batch G named 2 remaining consumers; Batch H found 2 MORE (the signup + accept_invite auth blades, carrying `invite_code` through `Rsx_Form` - the SAME bug in live auth flows). ALL 4 migrated to `<Hidden_Input>` (a real `Form_Input_Abstract`, Party_Edit's proven pattern): edit_user + edit_group modals, signup_index.blade, create_account.blade. Verified: both modal round-trips send `id` (`vals()={"id":"1"...}`), UPDATE not INSERT (row count unchanged), values persist. Residue: 3 archive blades (`rsx/resource/archive/`, dead snapshots - left as historical) + 3 man pages (crud/form_conventions/forms_and_widgets - flagged, NOT auto-edited per CLAUDE.md "never update man pages autonomously"). |

## Kept independent (not merged into new primitives)

| Component | Decision | Rationale |
|---|---|---|
| `Rsx_Tabs` / `Rsx_Tab` | Kept independent for FORMS; the new `Tab_Bar`/`Tab_Panels` serve view pages. | 0 live page consumers (only `Rsx_Form`'s optional error-integration hook). Form-validation coupling (error badges, jump-to-errored-tab) is a distinct domain; a wrapper-of-panels model incompatible with the separated bar/panels composition. Guide §6.5: resembles-but-different-domain -> keep separate. Zero regression risk. |

---

## Batch D - Dashboard D4 content decisions (every figure is now DB-truth)

The dashboard was 100% invented literals (stats `5/87/12/2`; hand-typed table
rows "Website Redesign", "John Smith"). D4 mandate: real query where a model
exists; seeded-demo-through-the-DB where a concept exists but has no rows;
REMOVE pure fiction with no backing model. All served by one new endpoint
`Frontend_Dashboard_Controller::dashboard_data()` (site-scoped, real queries).

| Region | Decision | Backing |
|---|---|---|
| KPI "Active Projects" | REAL query | `Project_Model` status=ACTIVE count |
| KPI "Total Contacts" | REAL query | `Contact_Model` count |
| KPI "Open Tasks" | REAL query | `Task_Model` status in (PENDING,IN_PROGRESS) |
| KPI "Overdue Tasks" ($alert) | REAL query | open tasks with due_date < today |
| Recent Activity table | REAL -> `Feed_Row` | `Action_Log_Model` (render() emits the linked summary); icon/variant by type range |
| Active Projects table | REAL -> `Record_Table` | `Project_Model` status=ACTIVE, +client name |
| Recent Contacts table | REAL -> `Record_Table` | `Contact_Model` newest, +client name |
| Today's Tasks table | REAL -> `Record_Table` | open tasks due today-or-earlier (overdue shown red); row nav -> `/tasks/view/:id` (repointed from parent project in Task-mgmt Batch 2, now that task pages exist) |
| **Upcoming Events table** | **REMOVED** | No event/calendar model exists (Calendar is a placeholder page). Pure fiction with no backing model -> deleted, not faked. |

**Seeder (D4 demo data):** `Seeder_Service` gained `seed_projects` (2-3 per
client that has none) + `seed_tasks` (3-5 per project that has none), both
additive + idempotent, wired into `seed_all`. Tasks had NO model rows at all
(and NO main-app UI creates them yet), so without seeding the task KPIs + list
would be permanently empty. RAN the two additive seeders on this dev DB (50
projects, 193 tasks created; non-destructive). **Existing dev DBs should run
`rsx:task:run Seeder_Service seed_projects` + `seed_tasks` (or a fresh
`seed_all`) to populate the dashboard.** `Action_Log` rows (6) are a real audit
trail - NOT fabricated; the activity feed shows the genuine 6.

## Batch E - sibling entity-view sweep (Contacts_View, Projects_View, Party_View)

Three leaf entity views converted (originally trimmed copies of the archetype's
BEFORE state). **Zero new components, zero new levers** - the batch's purpose was
proving the Batch-B/C vocabulary generalizes to siblings, and it did: all three
compose entirely from Page_Scaffold / Card_Widget / Entity_Header / Status_Badge /
`badge-outline-*` / Section / View_Fields / Empty_Value / Empty_State / Entity_Link /
Detail_Sidebar / Action_Menu.

**Tabs-or-scroll decision: all three single-scroll (no tabs).** The archetype
(Clients_View) has tabs ONLY because a Client owns child COLLECTIONS (contacts,
projects). Contact / Project / Party are leaf entities with no naturally-loaded
child collections, so a faithful "apply the archetype AFTER shape" makes them
single-scroll field pages (Card_Widget + Entity_Header + Sections + Detail_Sidebar).

**Sidebar KPI ("At a Glance"): omitted on all three - no honest count telemetry.**
The only candidate (a Project task count) sits on a broken relationship (see finding
below); fabricating a KPI would violate the epic's sparse-data honesty rule. KPI
group remains a Clients/Dashboard feature (entities that own counts).

**Content re-evaluation findings:**
- **[FIXED] Badge bug (Batch C lesson 7), Contacts + Projects:** templates read
  single-underscore enum keys (`priority_badge`/`status_badge`/`status_label`) but
  `Model::fetch()` ships BEM double-underscore (`priority__badge`/`status__badge`/
  `status__label`). Result: Contacts priority chip rendered BLANK; Projects status
  AND priority both rendered BLANK (confirmed in BEFORE screenshots). Fixed by routing
  through `Status_Badge` ($label/$badge channel) + `badge-outline-*` with the correct
  `__` keys. Party already used correct `type_id__label`/`__badge`.
- **[FIXED] Projects_View had NO link to its parent Client** (domain mandates
  `client_id`). Added the parent-chain idiom to Entity_Header meta:
  `Client: <Entity_Link $model="Client_Model" $id=_project.client_id $small=true />`.
  (Contacts already linked its Client via a Company field - moved into the header meta.)
- **[FIXED] Dead placebo buttons removed:** Contacts "Send Email"/"Call", Projects
  "Add Team Member"/"Add Task" (all unwired, no handler, copy-paste chrome).
- **[FIXED] Projects description is a WYSIWYG HTML field** rendered via `<%!= %>`;
  project 1's value is `<p><br></p>` (effectively empty) and showed a blank Description
  card. Now renders `safe_html()` and the Section is guarded on visible-text
  (`$('<div>').html(desc).text().trim()`), so an empty-HTML description hides the
  section instead of showing an empty one.
- **[ENHANCED] Party discriminator surfaced properly:** the detail-table `type_id`
  (Person/Company/Group) is a CLASSIFICATION, not a workflow status - migrated from a
  FILLED badge to an outline chip (Rule of Two Chips). The type-specific detail fills a
  single "`<Type>` Details" section; a **Group** (no detail table) renders an
  `Empty_State` explaining its fields are all universal (the batch's live Empty_State
  consumer). Added an `Action_Menu` with a real **Delete Party** (wired to the existing
  `Frontend_Party_Controller::delete`; destructive-inside-menu, verified opening the
  confirm modal without confirming).
- **[ESCALATED - data-layer bug, out of cosmetic scope] `Project_Model::tasks()`
  morphMany returns 0** despite 193 task rows: `taskable_type` is a polymorphic
  type-ref column storing the SIMPLE class name (`"Project_Model"`), but Laravel's
  `morphMany` queries the FQCN morph class, so the relationship never matches (mirrors
  the CLAUDE.md morphTo/type-ref warning; Batch D lesson 3 territory). A correct count
  needs a manual `Task_Model::where('taskable_type','Project_Model')->where('taskable_id',$id)`.
  This is why Projects_View has no task KPI/list. Recommend fixing via a proper morph-map
  or a manual-count accessor before any page surfaces project tasks.
  **[SUPERSEDED 2026-08-09]** The diagnosis was right for its time and the morph-map fix is
  the one that landed: `Type_Ref_Registry::register_morph_map()` now registers each type-ref
  integer id as a morph-map alias, and the query builder converts the table-qualified type
  column a morph relation emits. `morphMany` over a type-ref column WORKS. Do not read this
  entry as a standing rule - see `rsx:man polymorphic`.
- **[ESCALATED - noted, not fixed] Soft-deleted records not surfaced:** all three
  `fetch()` use `withTrashed()` but the views have no deleted banner (the archetype
  does, via a plain `.alert`). Contacts/Projects have no restore endpoint and Party has
  delete-but-no-restore, so a deleted banner would be half a feature; deferred with the
  Callout decision below.

**Callout: still NOT built (deferred again).** Evidence bar remains 1 live consumer
(the Clients_View deleted-client `.alert`). None of the three Batch E pages has an
inline-alert shape today - they don't surface soft-deleted state (see finding above),
and adding one is blocked on missing restore endpoints. No 2nd consumer -> no build.
Retrofit of the Clients_View banner stays pending the batch that reaches 2 consumers.

**Settings admin views (User_Management_View 255ln, Group_Management_View 116ln):
REPORT-AND-DEFERRED.** Content maps cleanly to the vocabulary (Section + View_Fields +
Count_Pill for the member badge + Record_Table/Empty_State for the member list +
Detail_Sidebar + Action_Menu for the destructive Delete). BUT they render inside the
`Settings_Layout` SUBLAYOUT (`@layout('Frontend_Spa_Layout')` + `@layout('Settings_Layout')`):
the action mounts in `Settings_Layout`'s `.settings-content` pane, which sits beside the
settings nav sidebar and owns its OWN padding/card context. The `scaffolded` reconciliation
neutralizes only `Frontend_Spa_Layout`'s outer page-content (Batch C) - it does NOT touch
`Settings_Layout`. Adopting `Page_Scaffold` there would nest a 2300px two-column scaffold
(with its own sidebar) inside the settings content pane, colliding with the settings nav
and double-padding. That is unbuilt LAYOUT INFRASTRUCTURE (the same class of task as the
registry's pending "Portal layout will need the same treatment"), not a cheap
no-new-component conversion. Defer until `Settings_Layout` gets a scaffolded-reconciliation
pass (owner-gate: settings entity views may want a settings-specific scaffold shape, since a
two-column Page_Scaffold sidebar duplicates the settings nav).

## Batch F - Invoices trio (Invoices_Index, Invoices_View, Invoices_Add)

The heaviest hand-rolled cluster (hr + inline styles + raw grids together, and the
only cluster with NO shared components). All three converted; **two new components
built** (`Stat_Row`, `Callout`) plus the consolidated demo source `Invoices_Demo`.

**Money vocabulary decisions:**
- **`Stat_Row` BUILT** (money-line shape). Evidence = 2 live sites: Invoices_View's
  Subtotal/Tax/Total block and Invoices_Add's live totals. Monospaced tabular value;
  `$strong_label` totals variant; `$alert` red value (amount due / overdue).
- **Provisional-money rule: RESERVED, not built.** These pages display no
  estimated/computed-vs-committed figures (no `is_estimated`-style flag exists - the
  invoices feature has no data layer at all). `$provisional` documented on `Stat_Row`
  and `Sidebar_Kpi_Row` as a reserved additive arg for the first page that shows an
  estimate. NOT built speculatively.
- **`Qty_Rate_Sentence`: not built.** Line items render as a 4-column table
  (Description/Qty/Rate/Amount), not qty x rate prose - no evidence for the sentence
  formatter. Line-item tables use `Record_Table`.

**Callout decision: BUILT (2nd consumer reached).** The Invoices_View overdue-invoice
warning is a genuine inline-alert shape - the long-pending 2nd consumer the earlier
batches never surfaced. Built `Callout` ($variant danger|warning, icon + title/body/
actions slots, loud variant validation) and **retrofitted the Clients_View deleted-client
banner** (was a plain `.alert alert-danger`) onto it. Regression-verified Clients_View
in both the non-deleted (banner absent, layout identical) and deleted (danger Callout
renders) states.

**Invoices_Index architecture call: Record_Table + Stat_Group, NOT a real
DataGrid_Abstract.** A DataGrid needs a model/table/`datagrid_fetch` endpoint; the
invoices feature has none. Converged the hand-rolled summary strip -> `Stat_Group`
(dashboard lesson D2 - a list/overview is a sanctioned home for a headline strip) and
the hand-rolled table -> `Section` + `Record_Table` (row-nav to the view). The
non-functional filter bar and fake pagination ("Showing 1 to 3 of 87 entries", page
2 of 29) were REMOVED (they implied a backend that does not exist). Summary figures are
DERIVED from the same demo list, so the strip always agrees with the rows (P9).

**D4 content re-evaluation - the trio is 100% demo fiction (escalated):** there is NO
`Invoice_Model`, no `invoices` table, and the three invoice controllers expose NO Ajax
endpoints. The pages were hardcoded HTML (Index: literal `$45,234` / `87 entries` /
3 sample rows; View: a static mock object; Add: a raw `<form action="#">` whose
"Add Item"/remove/tax-calc buttons were wired to NOTHING). Building a real invoice
backend (model + 2 tables + datagrid + endpoints + seeder) is feature-dev, not a UI
refactor (guide 5 Step 2: fix-if-small-else-escalate; a whole data layer is not small).
**Resolution (per D4 "seeder-backed demo where not"):** one clearly-labelled demo source,
`rsx/app/frontend/invoices/invoices_demo.js` (`Invoices_Demo`), drives all three screens
with self-consistent figures. It references REAL `client_id`s so the client link
(`Entity_Link`) resolves genuine clients; totals are DERIVED from line items (never
stored). The Add form loads the REAL client list. **ESCALATED:** the invoices feature
needs a dedicated data-layer epic (Invoice_Model + invoice_line_items + status enum +
CRUD endpoints + seeder). Until then Save is an honest demo notice (Modal), and no
figure is presented as a database read.

**Per-page content fixes:**
- **[FIXED] Dead placebo controls removed** across all three: Index's non-working
  search/status/client/date filters + fake pagination + `href="#"` view/edit/delete/
  send/download/duplicate row actions (23 dead `href="#"`); View's unwired Send Invoice/
  Download PDF/Edit/Record Payment/Duplicate/Delete header + sidebar buttons (6 dead
  `href="#"`); Add's dead Preview/Preview-PDF and the fake project-name options.
- **[FIXED] Invoices_View now links to its Client** (was a dead `<a href="#">`): the
  parent-chain idiom in the Entity_Header meta + a real `View Client` sidebar button, both
  via `Entity_Link` / `Rsx.Route('Clients_View_Action', client_id)`.
- **[FIXED] Add line-item repeater was DEAD** (no JS bound the Add Item / remove / tax
  buttons). Wired client-side: add/remove rows + live Subtotal/Tax/Total recompute; the
  client dropdown now loads REAL clients (`Frontend_Clients_Controller.datagrid_fetch`).
- **[FIXED] Add's custom sidebar layout was broken** (referenced `.invoice-sidebar`
  classes with no SCSS file, so it stacked full-width at the top). Replaced by
  `Page_Scaffold` main + `Detail_Sidebar` (Save/Reset/Cancel).
- **Empty_State** now covers a line-item-less invoice (a demo empty Draft, INV-2025-0229)
  and a zero-invoice index.

**Zero page SCSS:** all three `*_action.scss` are the empty-with-comment marker; the
Add form uses standard Bootstrap form controls (their own system) with no `.row/.col`
grid, `hr`, `card`, or inline styles.

## Batch G - edit forms + list wrappers + settings sweep

Four edit forms, five list wrappers, Action_Logs_View, and the two settings
profile surfaces converted. **Zero new components, zero new levers** - the batch's
purpose was proving the vocabulary covers form shells, thin datagrid wrappers, and
the settings sublayout (via the new Settings_Layout reconciliation above). One page
SCSS survivor added (Action_Logs_View metadata `<pre>`, justified with a comment).

**Edit forms (Clients / Contacts / Projects / Party):** shell-only conversion.
Each is now `Page_Scaffold > Slot:main > Rsx_Form > Card_Widget > Section(s) + submit
row`; the three-state (loading/error/content) wraps each state in `Page_Scaffold`.
The hand-rolled `<h5 class="border-bottom"><i>...</i> Title</h5>` group headers
became `<Section $title="..." $icon="bi ...">` (the icon rides the `$icon` DATA arg
- allowed on chrome per the Title/Label Channel Rule); the `col-md-*` field grids
(non-functional under RSX breakpoints - CLAUDE.md) were dropped, fields stack as
they already rendered. Rsx_Form internals (Form_Field / input components) were NOT
restructured. **No sidebar** - these pages had no sidebar actions, so per the
mission rule the form keeps its own submit row (inside the Card_Widget); the
scaffold collapses to full-width main.

**Form field discovery through Section/Card_Widget: PROVEN SAFE.** Rsx_Form finds
inputs via `shallowFind('.Form_Input_Abstract')`, which only stops descending at
elements matching the SEARCHED class (verified against the impl) - intermediate
`.Section` / `.Card_Widget` / `.Component` wrappers are traversed through. All four
forms save a real round-trip through the nesting (verified in DB, restored).

**[FIXED - pre-existing data bug] `Form_Hidden_Field` for `id` never populated ->
edit saves silently CREATE duplicates.** `Form_Hidden_Field` extends
`Form_Field_Abstract`, NOT `Form_Input_Abstract`, so `Rsx_Form.vals()` (which sets
values via `shallowFind('.Form_Input_Abstract')`) never populates its value - it
stays `""`. On submit `id=""` -> the controller's `find($id)` misses -> a NEW record
is created every "edit". Reproduced on the UNMODIFIED Contacts_Edit (created contact
197) BEFORE any change, so it is pre-existing, not a Batch-G regression. Clients /
Contacts / Projects edit all used `Form_Hidden_Field $name="id"`; **Party edit
already used `Hidden_Input` (a real `Form_Input_Abstract`) and worked.** Fix (small,
per guide 5 Step 2): swap `<Form_Hidden_Field $name="id" />` -> `<Hidden_Input
$name="id" />` on the three broken forms, matching Party's proven pattern. After the
swap all four update in place (id sent, no dup). **RECOMMEND:** audit every remaining
`Form_Hidden_Field` consumer app-wide - any edit form using it for a lookup key has
the same duplicate-on-save bug.

**[FIXED] Clients_Edit 12 `Text_Input requires $max_length` console errors** (the
KNOWN debt) - added `$max_length=Client_Model.field_length('col')` (name, website,
email, address_street, city, zip, facebook_url, twitter_handle, linkedin_url,
instagram_handle) / `-1` (established_year number, notes textarea). Phone/fax use
`Phone_Text_Input`, which supplies its own default (no error). Contacts_Edit (10) and
Projects_Edit and profile_edit (7) carried the SAME class of error - all cleared the
same way (zero console errors is required on every converted page, not just Clients).

**[NEW LESSON] `$sid` resolves through a `Section`'s loose `content()` body** - the
Clients_Edit Tags repeater keeps `this.$sid('add-tag')` / `this.$sid('tags-container')`
inside a title-only `<Section>` and Add-Tag works (before=1 -> after=2 rows). Extends
Batch-F lesson 3 (proven for `Detail_Sidebar` default content) to the `Section`
default/loose body (same `<%= content() %>` mechanism). NAMED slots of Section remain
unproven - keep using `.js-*` class hooks there.

**[NEW LESSON] A chrome `$title` arg is re-escaped by `<%=`** - writing
`$title="Status &amp; Preferences"` renders "Status &amp;amp; Preferences" (double
escape). Pass the raw character: `$title="Status & Preferences"`.

**List wrappers (Clients / Contacts / Projects / Party / Action_Logs Index):** the
five thin `<div class="container-fluid py-4"><X_DataGrid /></div>` wrappers became
`<Page_Scaffold><Slot:main><X_DataGrid /></Slot:main></Page_Scaffold>` with the JS
flag `full_width = true` -> `scaffolded = true`. The DataGrid (a full `card` with its
own toolbar/pagination) is NOT rebuilt (out of scope) - it re-parents into the
scaffold, which now owns page padding + max-width. All five render, paginate (click
page 2 -> active), and sort a column. Consistent with Invoices_Index (also a
scaffolded list). A datagrid list is a full-width single-column page - the scaffold's
empty-sidebar collapse gives it that.

**Action_Logs_View (entity-view conversion, single-scroll leaf):** `Page_Scaffold`
main = `Card_Widget > Entity_Header` (title = the linked `render` "actor verb object"
summary as authored HTML in `<Slot:title>`; `<Slot:chips>` = the action TYPE as an
outline classification chip - Rule of Two Chips, `type_id__label` is a category not a
workflow state; `<Slot:meta>` = datetime + relative) + `Section "Details"` (View_Fields
Initiator/Target, Target -> "(deleted)" when `subject_display` is null) + a guarded
`Section "Additional Data"` for the metadata `<pre>`. Sidebar = `Detail_Sidebar` with
Record Info (Log ID / Site ID, inline View_Fields). Payload keys verified via tinker
(`render`, `type_id__label`, `actor_display`, `subject_display`, `metadata`) - no
metadata rows exist on this dev DB, so that section is guard-only in practice. The one
page-SCSS survivor: `.Action_Logs_View_Action__json { white-space: pre-wrap;
word-break: break-word }` (raw dev-facing JSON dump; single consumer, below the
extraction bar - the killed inline style had nowhere else to go).

**Settings profile surfaces (display + edit):** first pages to use the new
Settings_Layout reconciliation. profile_display (view) = `Card_Widget > Entity_Header`
(name / "Active" status chip + role outline chip / email+title subtitle) + `Section
"Profile Information"` (View_Fields) + Edit button; sidebar = `Detail_Sidebar` with
account-status View_Fields + action buttons. profile_edit (form shell) = same form
pattern as the other edit forms; sidebar keeps the Profile Tips / Privacy help as two
`<Section>`s in the scaffold sidebar column. Both save/round-trip (profile_edit saves
the session user - no `id` field, no dup risk). Group headings ("Account Status" /
"Quick Actions") were DROPPED to match the archetype sidebar (Clients_View has none;
View_Fields has no `$title` lever built - the registry's "prefer a `$title` lever"
note remains a recommendation, not built, below the evidence bar here).

**[OBSERVATION -> owner] profile display + edit are TWO surfaces over ONE record.**
The mission kept them distinct this batch. They are a display view and an edit form of
the same user profile, reached by adjacent nav items ("Profile" / "Edit Profile") -
the same fork the guide 6.7 retired for the "legacy model" pages. **RECOMMEND** the
owner decide whether to (a) keep two surfaces (current), (b) collapse to one
view-with-inline-edit, or (c) make display the canonical `/profile` with an Edit
action. Not merged here (out of a LIGHT batch's remit); recorded for the direction gate.

**[OBSERVATION -> owner] the person-profile hero (avatar banner) was DROPPED.**
profile_display's bespoke 128px photo + name/badge hero has no home in the current
vocabulary: `Entity_Header` has no avatar slot, and there is no standalone avatar
DISPLAY component (only `Profile_Photo_Input`, a form widget). Building
`Profile_Hero_Header` (guide 6.6) or an `$avatar` lever on `Entity_Header` fails the
2-live-consumer evidence bar today (profile_display is the only person-profile view;
the portal user profile is the likely 2nd). So the identity now renders through the
standard `Entity_Header` (name + status/role chips + email), photo omitted.
**RECOMMEND** building `Profile_Hero_Header` (or the `$avatar` lever) when the portal
user-profile view lands as the 2nd consumer, and restoring the avatar there.

**[OBSERVATION - pre-existing, not fixed] settings pages show "(title not set)".**
The settings profile actions define no `page_title()`/`breadcrumb_*()`, so the layout
header falls back to "(title not set)" (visible BEFORE and AFTER; also on unconverted
`user_settings`). Out of Batch-G scope (not a markup/composition issue); flagged for a
settings-wide page-title pass.

## Batch H - settings + system sweep

Sixteen pages converted across the Settings sub-app, the System sub-app, and
Reports, plus the mandatory `Form_Hidden_Field` footgun deletion. **One new
component** (`Placeholder_Card`), **one new sublayout reconciliation**
(`System_Layout`, mirrors Settings_Layout), **one new endpoint**
(`Frontend_Reports_Controller::report_stats`, real site-scoped counts). Two page
SCSS files touched: one new justified survivor (`System_Email_View_Action__preview-frame`
iframe box, mirrors Action_Logs_View's `<pre>`), Placeholder_Card's own SCSS.

**Form_Hidden_Field DELETED (mandatory bug fix + footgun removal).** See the
Deleted table above. The Batch-G audit recommendation is now closed: all four live
consumers migrated to `<Hidden_Input>`, component deleted, glossary confirms zero
references. **Discovered:** the signup + accept_invite auth blades carried
`invite_code` through the broken component inside an `Rsx_Form` - the SAME
duplicate/empty-value bug in LIVE auth onboarding flows. Migrating them (a D7
"drive-by idiom fix" on auth blades) fixes that latent bug. Round-trip proof: both
edit modals now send `id` in `vals()` and UPDATE in place (row counts unchanged).
Pre-existing `$max_length` console errors remain on the two edit MODAL forms (not
converted pages - out of the swap's scope; flagged for their eventual conversion).

**Placeholder_Card BUILT** (guide 6, vocabulary "Placeholder/coming-soon"). 3 live
consumers at build (System_Status, System_Tasks, Reports body) - past the evidence
bar immediately. Distinct from `Empty_State`: a whole-FEATURE stand-in, not a
zero-child region inside a populated feature.

**System sub-app (6 pages):** the 2 stub placeholders -> `Placeholder_Card`; the 2
list pages (email_queue, email_recipients) -> `Section` + `Record_Table` (queue rows
carry `data-href` whole-row nav to the email view; resend/toggle controls skip
row-nav); email_config -> 2-col `Page_Scaffold` with `Sidebar_Kpi_Group` queue-stats
telemetry (Failed cell `$alert` when > 0) + `Detail_Sidebar` quick links; email_view
-> archetype `Entity_Header` (subject + `Status_Badge` + category outline chip) +
`Callout` for the delivery error + a `$flush` "Message Preview" Section wrapping the
render iframe. All email statuses route through `Status_Badge`; categories
(Transactional/Notification/Marketing) are outline classification chips (Rule of Two
Chips). System_Email_View's redundant `page_actions()` Back button dropped (sidebar
Back + breadcrumb parent cover it).

**Settings admin entity views (Batch E's deferred pair, now unblocked by the
Settings_Layout reconciliation):** User_Management_View + Group_Management_View ->
full archetype (`Card_Widget` + `Entity_Header` + Sections + `Detail_Sidebar`).
User view: is_enabled -> `Status_Badge`; site/system role -> outline chips; invite
status -> `Status_Badge`; recent sessions -> `Record_Table`/`Empty_State`; person
avatar DROPPED (Batch G lesson 8 - no avatar vocabulary home yet). **Dead placebo
removed:** Reset Password / Disable / Enable / Delete User (no endpoints exist on
the controller) + the dead row-level Edit/Delete in `users_datagrid`. Group view:
member count -> `Sidebar_Kpi_Row`, members -> `Record_Table`/`Empty_State`; the
stale "Member management coming soon" note removed (member editing IS available via
the Edit Group modal's multiselect - the note was misleading).

**Settings LIGHT pages:** user_settings (254ln) + password_security (148ln) are
static demo fiction (no persistence) - shell-converted to `Page_Scaffold` +
`Section`s + `Detail`-less help Sections in the sidebar column (Batch G's help-sidebar
precedent), Bootstrap form controls kept (their own system), `<hr>` dividers replaced
by section chrome / spacing, dead `href="#"` links removed. The `col-md-*` field
grids were non-functional under RSX breakpoints (rendered stacked + the whole page
mis-laid-out with a huge left gap) - `Page_Scaffold` fixes the layout materially.
site_settings: shell + real (if stubbed) `update` endpoint; api_keys: real DataGrid
kept, help cards -> sidebar Sections, dev-TODO note removed. All four scaffolded via
the Settings_Layout reconciliation. **Escalated:** user_settings /
password_security / site_settings have NO persistence layer (site_settings' `update`
is a no-op stub, its `description` field has no column, the template referenced a
nonexistent `save_settings`); the Save/2FA/Revoke/session controls are demo-only.

**Settings list wrappers (3):** user_management / group_management / portal_users
-> `Page_Scaffold` scaffolded single-column (matching Batch G's datagrid wrappers);
`full_width = true` -> `scaffolded = true`.

**Reports (D4/D8 honesty):** was 4 hardcoded stat cards + a `display-1` empty
placeholder. Revenue ($124,500) and Hours Tracked (1,284) have NO backing model ->
REMOVED (not faked). Active Clients / Active Projects / Total Contacts -> REAL
site-scoped queries via the new `report_stats` endpoint, rendered through
`Stat_Group` + `Sidebar_Kpi_Row` (dashboard-class strip). Body ->
`Placeholder_Card`. Dead header `page_actions()` (New Report / Export) removed.

## Levered existing components (Batch H additions)

| Component | Lever / reuse | Notes |
|---|---|---|
| `Kpi_Cell` (was `Sidebar_Kpi_Row`) | No code change - REUSED in `Stat_Group` on Reports AND as a `Sidebar_Kpi_Group` cell on System_Email_Config queue-stats. | Extends the Batch-D dashboard reuse; the KPI cell is a generic count-telemetry shape. This further reuse is what tipped the P10 rename `Sidebar_Kpi_Row` -> `Kpi_Cell` (done Batch K). |
| `Stat_Group` | No code change - 2nd non-dashboard consumer (Reports). | A reports overview is a sanctioned home for a headline stat strip (guide 6.2 / dashboard lesson D2). |

## Batch I - Clients_Portal fold-in (D3) + request-thread convergence (D5)

The epic's most surgical batch: two SPA pages, real portal-management functionality,
**two new shared primitives** (`Person_Avatar`, `Author_Meta_Row`), one component deletion
(`Request_Participant_Avatar`), one page RETIRED, one route migrated.

**D3 - Clients_Portal folded into a Clients_View "Portal" tab; `/clients/portal/:id`
retired.** The 390-line standalone portal-management page (the app's ONLY Bootstrap
`nav nav-tabs` page) became a new **`Clients_Portal_Panel`** complex Component consumed by a
conditional Portal `Tab_Panel` on Clients_View (present only when `portal_enabled`). The
panel loads its own data (`$client_id`) and drives every flow: add/disable member, resend/
cancel invite, change role, upload/share/delete documents, post announcements, create
requests, toggle project visibility. Its 5 former Bootstrap tabs (Members/Documents/Requests/
Announcements/Projects) became **stacked `Section`s** (Record_Table + Empty_State + Status_Badge
per the vocabulary) - no nested tabs (robust + avoids tab-in-tab UX). The Portal KPI cell on the
Clients_View sidebar is now `$clickable $tab="portal"`; the redundant "Manage Portal" sidebar
button was removed. **Route retirement is a clean 404** (NO-BACKWARDS-COMPAT: no redirect shim
for a deliberately-deleted route; zero external users). All 3 external `Clients_Portal_Action`
links repointed to `Clients_View_Action` (Contacts_View + portal_users_datagrid land on
`...#tab=portal`). Deleted the 127-line `clients_portal_action.scss`; the panel's own SCSS is
~55 justified lines (block rhythm + de-emphasised pending-invite row + doc thumb + announcement
list).

**D5 - request-thread display primitives converged (guide 6.5).** `Clients_Request_Thread`
converted to the vocabulary and its route MIGRATED `/clients/portal/:id/request/:thread_id` ->
`/clients/view/:id/request/:thread_id` (route NAME unchanged, so all `Rsx::Route(...)` callers -
the `request_thread_create` redirect, the portal-side staff `view_url` - auto-resolve). Built
`Person_Avatar` (theme, promoted from `Request_Participant_Avatar` which was DELETED; the portal
twin `Portal_Participant_Avatar` is LEFT for Batch J) and `Author_Meta_Row` (theme, the shared
byline). The thread is now `Page_Scaffold` -> `Card_Widget` -> `Entity_Header` (title + status
chip + parent-chain `Entity_Link` to the client) -> `Section "Messages" $flush` (a self-dividing
timeline of `Author_Meta_Row` bylines + post body + doc chips, plus centered status-event pills)
-> `Section "Reply"` (composer kept working); sidebar = `Detail_Sidebar` with a
`Sidebar_Kpi_Group` (Needs Review `$alert` / Accepted) + participant/doc media-row groups.
The chat-bubble left/right message cards were dropped in favour of the guide's sanctioned
Author_Meta_Row divided-list-of-posts (a look change, not a function change). The 198-line
`Clients_Request_Thread_Action.scss` shrank to ~135 lines of justified THREAD DOMAIN survivors
(timeline entry/event pill, message post-body, attachment chips, composer staged chips, sidebar
media rows) - the byline + avatar converged onto the shared primitives; the rest is the domain
wrapper the guide 6.5 sanctions.

**People_List: deliberately NOT built** (see the vocabulary "Still MISSING" note) - the members
region is an editable Record_Table, and the thread participants are the only live person-list
consumer until Batch J's portal twin.

**Verification:** BOTH pages 0 console errors, both viewports. Driven: Portal tab switch (tab +
clickable KPI); portal enable/disable round-trip via the UI (tab disappears/reappears; DB
restored to `portal_enabled=1`); invitation create+cancel via the real endpoints
(`portal_bulk_invite` contact 6 -> PENDING id 14 -> `portal_revoke_invite` -> REVOKED ->
cleaned up); thread reply round-trip through the composer (3->4 messages, body persisted, test
message deleted); participant + document modals open (Person_Avatar renders in the lg contact
card). Regressions clean (Contacts_View edited link, Dashboard, Party_View, Clients_View other
tabs, Portal_Dashboard + the untouched portal thread twin all 200 / 0 errors). glossary
`--missing`: Person_Avatar + Author_Meta_Row both carry parsable summaries (not listed);
Clients_Portal_Panel + the thread action are page-level (expected, per Batch H lesson 9).

## Legacy-pattern counts (initial, Batch B baseline; frontend + portal templates)

Scope-qualify every future "extinct" claim. Counts are occurrences unless noted.

| Pattern | Initial count | Now | Notes |
|---|---|---|---|
| raw `class="badge` | 126 (in 40 files) | ~74 | Migrate per the Rule of Two Chips (Status_Badge vs outline chip). **Batch H: ~20 migrated** (email_queue/email_view status -> `Status_Badge`, category -> outline chip; email_config dev-site Yes/No/suppressed -> `Status_Badge`; user_management_view is_enabled/invite/session-active -> `Status_Badge`, site/system role -> outline chips; password_security "Not Enabled"/"Current" -> `Status_Badge`; group_management_view member badge -> `Sidebar_Kpi_Row`). Each converted page keeps only sanctioned outline classification chips. Clients_View: extinct (7 removed). Dashboard: extinct (4 removed). **Batch E: extinct on Contacts/Projects/Party_View (5 removed** - Contacts status+priority, Projects status+priority, Party filled `type_id` badge -> outline classification chip; each page keeps exactly 1 sanctioned `badge-outline-*` classification chip). **Batch F: extinct on the invoices trio (7 removed** - all invoice status badges now route through `Status_Badge`; the overdue "Overdue" badge in the header is the status chip). **Batch G: ~5 migrated** (profile_display Active/Verified/2FA -> `Status_Badge`, role -> outline chip; Action_Logs_View action-type -> outline chip); profile_display + Action_Logs_View each keep 1 sanctioned classification chip. Edit forms + list wrappers had none. **Batch J (portal): ~10 migrated** - portal thread/request/dashboard-thread statuses + settings account status + overview client status (via a new `status_badge` vitals payload) -> `Status_Badge`; workspace role -> `badge-outline-*` (Rule of Two Chips). The session "Current" green badge + the doc review-state badge in the thread are kept (genuine chips). |
| raw `col-desktop-*` grid shells | 30 | 15 | 8/4 shells -> `Page_Scaffold`; nested -> `Section_Columns`. Clients_View: extinct (2 removed). Dashboard: n/a (Bootstrap cols). **Batch E: extinct on all 3 sibling views (6 removed** - each was one `col-desktop-8` + `col-desktop-4`). **Batch F: unchanged** - the invoices trio used Bootstrap `col-md-*`/`col-lg-*` (20, non-functional under RSX breakpoints), not `col-desktop-*`; those 20 were removed (noted on the `class="row` row) but don't touch this count. **Batch G: 5 removed** (Contacts/Projects/Party edit 1 each `col-desktop-8`; Action_Logs_View `col-desktop-8`+`col-desktop-4`). **Batch H: 4 removed** (System_Email_View + System_Email_Config each `col-desktop-8`+`col-desktop-4` -> `Page_Scaffold`). **Batch I: 2 removed** (Clients_Request_Thread `col-desktop-8`+`col-desktop-4` -> `Page_Scaffold`). **Batch J: 6 removed** (portal dashboard + settings + thread each `col-desktop-8`+`col-desktop-4` -> `Page_Scaffold`; the workspace-layout `.row`/`col-desktop-3/9` is layout chrome, kept). |
| raw `class="row` shells | 145 | ~87 | Layout scaffolding to be absorbed by scaffold/section columns. Dashboard: extinct (3 removed). **Batch E: extinct on all 3 sibling views (~10 removed** - outer + nested `.row` fact grids -> `Page_Scaffold` + `View_Fields`). **Batch F: extinct on the invoices trio (15 removed** - Index 2, View 9 fact/total rows, Add 4). The trio also carried 20 non-functional Bootstrap `col-md-*`/`col-lg-*` shells (they don't work under RSX breakpoints - CLAUDE.md), all removed; these are distinct from the `col-desktop-*` count below. **Batch G: ~30 removed** across the 4 edit forms (outer + `col-md-*` field-grid `.row`s), Action_Logs_View (3), profile_display (~10 `.row mb-3` fact rows), profile_edit (3); the 5 list wrappers dropped their `container-fluid py-4` wrapper (not a `.row`). **Batch H: ~40 removed** across all 16 pages (settings LIGHT pages' `col-md-*` field-grid rows, um/gm view fact `.row`s, the 3 list wrappers' `row`/`col-12` shells, email pages' `col-desktop` rows). |
| `<hr>` dividers in templates | 21 | ~5 | -> `Tab_Bar $divided` / section chrome (P5). Clients_View / Dashboard / Batch E siblings had none. **Batch F: 5 removed on the invoices trio** (Index 3 dropdown-dividers, View 1, Add 1) - section chrome / `Stat_Row --strong` divider replace them. **Batch G: 2 removed** (profile_display's 2 identity/section dividers -> `Entity_Header` + `Section` chrome). **Batch H: ~9 removed** (user_settings notification/privacy dividers, password_security 2FA/session dividers, user_management_view profile dividers -> section chrome). |
| inline `style="` | 100 | ~59 | Kill on converted pages. Clients_View had none. Dashboard: extinct (15 removed). Batch E siblings had none. **Batch F: 11 removed on the invoices trio** (Index 2 th-width, View 4, Add 5 col-width/table-width). **Batch G: ~3 removed** (profile_display 128px icon sizing; Action_Logs_View metadata `<pre>` white-space -> the one justified page-SCSS survivor). **Batch H: ~12 removed** (email_queue/recipients toolbar widths -> `w-auto`/natural sizing; email_view iframe width/height/border -> the `System_Email_View_Action__preview-frame` SCSS survivor; user_management_view 96px avatar sizing - avatar dropped). |
| dead `href="#"` links | (new) | (dashboard 0) | Dashboard had 11 - all removed/repointed. **Batch E: 4 dead placebo BUTTONS removed** (Contacts "Send Email"/"Call"; Projects "Add Team Member"/"Add Task" - all unwired, no handler). Party had none. **Batch F: 29 removed on the invoices trio** (Index 23: filter/pagination + view/edit/delete/send/download/duplicate row actions; View 6: header + sidebar action buttons) - the trio's actions were entirely placebo (no endpoints). **Batch H: ~6 removed** (user_settings "Download My Data"/"View Documentation"; user_management_view had dead Reset Password/Disable/Enable/Delete BUTTONS - no endpoints - removed; `users_datagrid` dead row Edit `href="#"` + Delete removed). One placeholder `href="#"` intentionally kept: api_keys "View API Docs" (docs-portal placeholder, not a functional control - flagged). |
| `(N)` count text (`.length` interp) | 8 | ~4 | -> `Count_Pill`. **Batch H: ~2 removed** (group_management_view `member_count members` -> `Sidebar_Kpi_Row`; user/group/email Section counts -> `$count` Count_Pill). Clients_View: extinct (2 removed). |
| bare-text empty states (`text-muted ...No `) | 23 | ~15 | -> `Empty_State` (frontend). **Batch H: ~4 removed** (user_management_view "No recent activity", group_management_view "No members", email_queue "No emails in queue", email_recipients "No recipients tracked" -> `Empty_State`). Clients_View: extinct (2 removed). Dashboard added 4. **Batch E: Party group "no type-specific detail" note -> `Empty_State` (1 removed).** Also Party's 6 `\|\| '-'` cell idioms -> `Empty_Value`. **Batch F: View's "No payments recorded yet." bare text removed (1)** (payment-history section dropped - no payments data); the trio gained `Empty_State` consumers for empty line items + a zero-invoice index. |
| portal empty-cards (`text-center` files) | 16 files | ~9 files | -> `Empty_State` (portal). **Batch J: 7 portal-SPA empty cards -> `Empty_State`** (dashboard no-access + caught-up + empty-workspaces; documents empty; requests empty; thread empty-messages; settings empty-sessions). Remaining ~9 are in the D7-out-of-scope auth blades (server-rendered) - scope-qualified. |
| `card` / `card-body` boxes | 431 | ~305 | -> `Section` / `Card_Widget`. Clients_View: extinct (13 removed). Dashboard: extinct (7 removed). **Batch E: extinct on all 3 sibling views (~16 removed** - Contacts 7, Projects 5, Party ~4). **Batch F: extinct on the invoices trio (~36 removed** - Index ~14, View ~11, Add ~11 card/card-body/card-header/card-footer boxes). **Batch G: ~54 removed** across the 4 edit forms (Clients ~3, Contacts ~14, Projects ~11, Party ~8 card/card-header/card-body/card-footer), Action_Logs_View (8), profile_display (~8), profile_edit (~8) - all -> `Card_Widget`/`Section`. **Batch H: ~50 removed** across all 16 pages (settings LIGHT pages ~9, um/gm view ~10, email pages ~15, list wrappers + reports the rest) -> `Card_Widget`/`Section`/`Detail_Sidebar`. **Batch J: ~18 removed** across the 6 portal SPA pages (dashboard ~4, settings 3, overview 1, documents grid-cards, requests 1, thread ~6) -> `Card_Widget`/`Section`/`Detail_Sidebar`. |
| `container-fluid` page wrappers | (new) | -13 | **Batch H: 1 removed** (Reports `container-fluid py-4`). **Batch G: 12 removed** - the 5 datagrid list wrappers + the 4 edit forms + Action_Logs_View + 2 profile pages each dropped a hand-rolled `<div class="container-fluid py-4">` (list wrappers) / `<div class="container-fluid py-4">` (forms/views); page padding now belongs to `Page_Scaffold`. |
| hand-rolled tab-toggle JS | 0 | 0 | **Batch I: the LAST raw tab UI is gone** - Clients_Portal's Bootstrap `nav nav-tabs` + its hand-rolled `_restore_tab_from_hash()` hash-toggle JS died when the page was folded into the Clients_View "Portal" tab (stacked `Section`s under the semantic `Tab_Bar`). No raw/Bootstrap tab UI remains on any view page. |

### Batch K - final residue sweep (authoritative current counts; every count is 0 on converted surfaces or explicitly qualified)

Counts are live greps over `rsx/app` + `rsx/portal` templates (excl. `resource/archive`, `resource/research`). "Converted-page defects" is the number of stragglers found on a converted view page and NOT fixed - the target is 0 for every row.

| Pattern | Current total | Converted-page defects (post-fix) | Qualification of the remainder |
|---|---|---|---|
| raw `class="badge` | 58 (app+portal jqhtml) | **0** | 23 are `badge-outline-*` classification chips (16 literal + 7 computed via `.replace('bg-','badge-outline-')` on `_pri`/`_ppri`/`_type`/`_cat` - Rule-of-Two-sanctioned, they just don't spell "badge-outline" in the template). 10 are genuine domain filled chips with no Status_Badge home, kept per registry precedent: the thread doc review-state `doc.badge` (x4, incl. 2 detail modals), the Clients_Portal_Panel "Needs Review (N)" alert-count, the portal "Current" session chip, and the portal member-picker modal Member/Invited/Accepted chips. 30 are DataGrid cell-template internals (6 list grids); 2 are ssr_test dev pages. **Batch K fixed the LAST Rule-of-Two violation on a converted surface: the `Portal_Workspace_Layout` role badge `bg-secondary` -> `badge-outline-secondary`** (a role is a classification; Batch J's "role everywhere is outline" claim was true everywhere except this layout instance). |
| raw `col-desktop-*` grid shells | 5 | **0** | `Portal_Workspace_Layout` x2 (the workspace nav/content split - layout chrome, kept per Batch J) + `ssr_test` x3 (dev surface). No converted page authors a `col-desktop` shell. |
| raw `class="row` shells | ~9 files | **0** | user_management add_user/edit_user MODAL forms (form-field grids, below view-page scope) + `Portal_Workspace_Layout` (layout chrome) + dev/ssr_test. Zero on converted view pages. |
| `<hr>` dividers in templates | 1 | **0** | The single remaining `<hr>` is `<hr class="dropdown-divider">` in `Frontend_Spa_Layout`'s user menu - the sanctioned Bootstrap dropdown-menu idiom, NOT a hand-authored section seam. No page section `<hr>` survives. |
| inline `style="` | (converted 0) | **0** | On converted pages: `Portal_Settings_Action` x3 `style="display:none;"` are FUNCTIONAL JS-toggle initial-state paired with jQuery `.show()` - Bootstrap's `.d-none` uses `!important` which `.show()` cannot override, so `display:none` is the CORRECT idiom here, not a decorative style. Others: DataGrid internals, the invite_success modal icon-size (modal helper), dev/ssr. Zero decorative inline styles on converted pages. |
| dead `href="#"` links | (converted 0) | **0** | **Batch K removed 6** (the dead placebo Edit `<a href="#">` + Delete `<button>` row-actions on clients/contacts/projects datagrids - matching Batch H's users_datagrid cleanup; the working View link stays) and **repointed the `Frontend_Spa_Layout` "My Profile" dead link** to `Rsx.Route('Settings_Profile_Display_Action')`. Remaining qualified: api_keys "View API Docs" (registry-known docs-portal placeholder), `Portal_Workspace_Layout` nav pills (hrefs populated by `on_action`, not dead), dev/ssr. |
| `(N)` count text (`.length` interp) | 1 (was 2; -1 tab-alignment Batch 1) | **0** | One legit remainder, not a count-pill candidate: Clients_Portal_Panel "Needs Review (`<%= count %>`)" (the count is bound inside an alert badge label, inseparable). The former 2nd remainder - Invoices_View "Tax (`<%= rate %>`%)" - was deleted with the invoices pages (tab-alignment Batch 1). |
| bare-text empty states (`text-muted ...No `) | 4 | **0** | The request-thread (staff + portal twins) Detail_Sidebar sub-group notes "No participants" / "No accepted documents" / "Nothing to review." - compact one-line notes under a small sidebar label where a padded, centered `Empty_State` card is the WRONG shape at sidebar scale. `People_List` handles its own list-empty via `$empty_text`; the doc buckets stay domain `__media-row` (Batch J). No list REGION renders bare text. |
| portal empty-cards (`text-center` files) | 1 file | **0** | The only remaining `text-center` in `rsx/portal` is `Portal_Layout`'s impersonation banner (layout chrome), not an empty-feature card. All 6 portal SPA pages' empty cards became `Empty_State` in Batch J. |
| `card` / `card-body` boxes | 0 (converted) | **0** | Extinct on every converted frontend + portal view page (grep of `class="card` over converted pages, excl. DataGrid/ssr_test, returns 0). Card chrome lives only in `Section`/`Card_Widget`/`Detail_Sidebar`. |
| `container-fluid` page wrappers | 0 (converted) | **0** | Remaining `container-fluid` are layout navbar chrome (`Frontend_Spa_Layout`, `Portal_Layout`) + dev pages. Batch K's Calendar/Tasks placeholders dropped their `container-fluid py-4` wrappers (-> `Page_Scaffold`). |
| hand-rolled tab-toggle JS | 0 | **0** | Extinct since Batch I. |

**Sweep verdict:** every legacy-pattern count on a CONVERTED surface is 0; every non-zero remainder is a sanctioned outline classification chip, a genuine domain chip, a functional (non-decorative) idiom, a Bootstrap component idiom, layout chrome, a DataGrid/modal internal, or a D7 dev/ssr surface - each qualified above.

---

## Page tracker

Status: `-` not started, `R` researched, `G` gated, `C` converted.
(Research + gate for the whole app were done once up front - see the plan's D-table.)

### Frontend SPA - core product

| Page | Research | Gate | Converted |
|---|---|---|---|
| Dashboard_Index | R | G | C |
| Calendar_Index (placeholder) | R | G | C (Batch K - Page_Scaffold + Placeholder_Card; dead New Event / view-toggle header removed) |
| Action_Logs_Index (list) | R | G | C (Batch G) |
| Action_Logs_View | R | G | C (Batch G) |
| Clients_Index (list) | R | G | C (Batch G) |
| Clients_View (archetype) | R | G | C (tab-alignment Batch 2 - Activity tab appended after Projects/before Portal + Activity KPI cell; client_activity) |
| Clients_Edit | R | G | C (Batch G - shell + 12 `$max_length` + `id` hidden-field fix) |
| Clients_Portal (retire per D3) | R | G | RETIRED (Batch I - folded into Clients_View "Portal" tab; route `/clients/portal/:id` deleted -> 404) |
| Clients_Request_Thread | R | G | C (Batch I - route migrated to `/clients/view/:id/request/:thread_id`) |
| Contacts_Index (list) | R | G | C (Batch G) |
| Contacts_View | R | G | C (tab-alignment Batch 2 - retabbed Overview/Projects/Activity + KPI sidebar; contact_projects/contact_activity) |
| Contacts_Edit | R | G | C (Batch G) |
| Projects_Index (list) | R | G | C (Batch G) |
| Projects_View | R | G | C (Task-mgmt Batch 3 - added Subprojects(n) tab (Record_Table, navigable) + Subprojects KPI cell + "Part of:" parent Entity_Link in header meta + Overview Assigned Users/Contacts People_List sections; project_subprojects/project_people. Prior: tab-alignment Batch 2 Overview/Tasks/Activity + Open Tasks/Overdue KPI) |
| Projects_Edit | R | G | C (Task-mgmt Batch 3 - added parent-project Ajax_Entity_Select_Input (self+descendant cycle guard) + Team & Contacts section (assigned-users + client-scoped contacts Checkbox_Multiselect); project_form_options. Prior: Batch G shell) |
| Party_Index (list) | R | G | C (Batch G) |
| Party_View | R | G | C (tab-alignment Batch 2 - retabbed Overview/Activity + Activity KPI; party_activity) |
| Party_Edit | R | G | C (Batch G) |
| Tasks_Index | R | G | C (Task-mgmt Batch 2 - placeholder REPLACED with the thin Tasks_DataGrid wrapper + New Task; columns title/parent/project/status/priority/assignee/due/est.hours; row-nav -> /tasks/view/:id) |
| Tasks_View | R | G | C (Task-mgmt Batch 2 - full archetype: Entity_Header (status/priority chips + "Part of:" parent-chain via Entity_Link) + Overview/Subtasks/Activity tabs + KPI sidebar (Subtasks/Est.Hours/Activity + Overdue $alert) + Action_Menu delete; task_subtasks/task_activity) |
| Tasks_Edit | R | G | C (Task-mgmt Batch 2 - dual-route add/edit; Parent_Selector_Input + derived-vs-editable Project field via live resolve_parent_project) |
| Invoices_Index | R | G | RETIRED (tab-alignment Batch 1 - deleted per owner decision; `/invoices` 404s. Demo fiction: no model/table/endpoints) |
| Invoices_View | R | G | RETIRED (tab-alignment Batch 1 - deleted per owner decision; `/invoices/:id` 404s) |
| Invoices_Add | R | G | RETIRED (tab-alignment Batch 1 - deleted per owner decision; `/invoices/add` 404s) |
| Reports_Index | R | G | C (Batch H - real stat strip + Placeholder_Card; added `report_stats` endpoint) |

### Frontend Settings sub-app

| Page | Research | Gate | Converted |
|---|---|---|---|
| Settings_General (redirect) | R | G | n/a |
| Settings_Profile_Display | R | G | C (Batch G - via Settings_Layout reconciliation) |
| Settings_Profile_Edit | R | G | C (Batch G - via Settings_Layout reconciliation) |
| Settings_User_Settings | R | G | C (Batch H - shell; static demo, persistence escalated) |
| Settings_Password_Security | R | G | C (Batch H - shell; static mock, escalated) |
| Settings_Api_Keys (list) | R | G | C (Batch H - real DataGrid + help sidebar) |
| User_Management_Index (list) | R | G | C (Batch H - scaffolded list + dead row Edit/Delete removed) |
| User_Management_View | R | G | C (Batch H - full archetype; dead Reset/Disable/Delete removed) |
| Group_Management_Index (list) | R | G | C (Batch H) |
| Group_Management_View | R | G | C (Batch H - full archetype; stale "coming soon" note removed) |
| Portal_Users_Index (list) | R | G | C (Batch H) |
| Site_Settings | R | G | C (Batch H - shell + `id`-less form; persistence escalated) |

### Frontend System sub-app

| Page | Research | Gate | Converted |
|---|---|---|---|
| System_Status | R | G | C (Batch H - Placeholder_Card) |
| System_Tasks | R | G | C (Batch H - Placeholder_Card) |
| System_Email_Queue (list) | R | G | C (Batch H - Section + Record_Table row-nav) |
| System_Email_View | R | G | C (Batch H - archetype + Callout error + iframe scss survivor) |
| System_Email_Config | R | G | C (Batch H - 2-col + Sidebar_Kpi_Group stats) |
| System_Email_Recipients (list) | R | G | C (Batch H - Section + Record_Table) |

### Portal SPA (converted after frontend, D6)

| Page | Research | Gate | Converted |
|---|---|---|---|
| Portal_Dashboard | R | G | C (Batch J - dashboard-class; notification feed kept as domain look-alike) |
| Portal_Settings | R | G | C (Batch J - Section + Record_Table sessions + Detail_Sidebar account) |
| Portal_Workspace_Overview | R | G | C (Batch J - Section + View_Fields; status filled / role outline) |
| Portal_Workspace_Documents (list) | R | G | C (Batch J - Section + Record_Table) |
| Portal_Workspace_Requests (list) | R | G | C (Batch J - Active/Closed Sections + Record_Table row-nav) |
| Portal_Request_Thread | R | G | C (Batch J - twin of Clients_Request_Thread; People_List built + twin deleted) |

Out of scope (D7): 19 auth blades (drive-by idiom fixes only), root/dev/backend/ssr_test surfaces.

## Batch J - portal surface sweep (D6: the whole portal SPA + People_List + twin deletion)

All 6 portal SPA pages converted onto the SAME vocabulary as the staff app (proving generality
across a second design surface), the two-layer **Portal layout reconciliation** landed (see the
reconciliation section above), **one new component** (`People_List`) built with BOTH thread
participant sidebars retrofitted onto it, and the last per-page avatar twin
(`Portal_Participant_Avatar`) deleted. Every page: 0 console errors, both viewports.

**`People_List` BUILT (the last unbuilt vocabulary component).** 2 live consumers at build (staff
`Clients_Request_Thread` + portal `Portal_Request_Thread` participant sidebars) - both retrofitted
in this batch. A calm stacked avatar+name list; fires `person_click`/`person_remove` with the exact
person object so the owning action drops its `_find_participant`. `$removable` reserved (no editor
consumer yet). Doc buckets (thumbnail, not avatar) stayed domain `__media-row` - only the PERSON
shape converged (guide 6.5). The app-side retrofit was regression-shot (Clients_Request_Thread +
Clients_View both 200/0 errors; participant rows render byte-identical to the old `__media-row`).

**Portal thread = the D5 payoff (twin convergence).** `Portal_Request_Thread` converted to MATCH
`Clients_Request_Thread`'s converted shape line-for-line (Page_Scaffold → Card_Widget →
Entity_Header + Status_Badge → `Section "Messages" $flush` with `Author_Meta_Row` bylines + a
self-dividing timeline → `Section "Reply"` composer → Detail_Sidebar with Sidebar_Kpi_Group +
People_List participants + doc `__media-row` buckets). The old chat-bubble left/right message cards
were dropped for the guide's divided-list-of-posts (a look change, function intact - reply
round-trip verified: 1→2 messages, persisted, test message + status-event + role change all
restored). Domain wrappers kept separate (portal keeps its own action/endpoints/permissions; only
DISPLAY primitives - Person_Avatar / Author_Meta_Row / People_List - are shared). Portal thread SCSS
212 → 145 justified DOMAIN lines (matches the staff twin's 143; the shared shapes converged).

**Portal_Dashboard content decision - the notification feed is a LOOK-ALIKE kept distinct (judgment
call).** The registry earmarked the portal feed as Feed_Row's imminent 2nd consumer, but on
inspection the "What's New" notifications are unread-aware items with a title + body + optional View
CTA - a genuinely different shape from `Feed_Row` (a single-line "actor did thing" audit event with
an icon tile, no unread state, no title/body split). Forcing them into Feed_Row would lose the
unread tint/dot/bold-title and flatten title+body - degrading the feature's core value. Per guide
6.5 ("know your look-alikes"; resembles-but-different-domain → keep separate), the feed stays a lean
domain shape (self-dividing rows in a flush Section). Feed_Row remains earmarked for a true audit
feed. Everything ELSE on the dashboard is vocabulary: Section + Record_Table (Action needed / My
Workspaces, both `data-href` row-nav), Status_Badge (thread status), `badge-outline-*` (workspace
role classification), Empty_State (no-access / caught-up / empty workspaces). Dashboard SCSS
195 → 120 (invite rows + the notification feed - the justified domain survivor).

**Small honest enrichments:** added `status_badge` to the workspace `get()` vitals payload so the
Overview status renders through a proper `Status_Badge` (filled) instead of a hardcoded green badge;
role everywhere is a `badge-outline-*` classification chip (Rule of Two Chips).

**SCSS ledger (page-scoped, before → after):** dashboard 195→120, settings 0→0, overview 30→8,
documents 62→47, requests 46→16, thread 212→145, Portal_Participant_Avatar 39→DELETED = **584 → 336
page-scoped lines (−248)**. Survivors are all justified DOMAIN shapes (thread timeline/chips/media
rows matching the staff twin; the notification feed; doc-cell identity; a couple of table-cell
truncations) or empty-with-comment markers (overview). The two LAYOUTS keep + slightly grew their
layout-chrome SCSS for the reconciliation (portal_layout 45→60, Portal_Workspace_Layout 51→62). New
shared theme SCSS: people_list 66 (not page-scoped). NOTE: the mission's "~505 dying" estimate
assumed the thread + feed would converge further; they did not, because both are genuine domain
shapes the guide sanctions as survivors (the staff twin kept 143 thread lines for the same reason).

## Batch K - placeholders (D8) + P10 Kpi_Cell rename + final residue sweep (the LAST conversion batch)

The closing batch: the two remaining placeholder pages converted, the last deferred P10
naming debt paid, and a whole-app residue sweep that drove every legacy-pattern count to
"0 on converted surfaces or explicitly qualified". **Zero new vocabulary components**
(the vocabulary was complete at Batch J); one component RENAMED.

**K1 - placeholder pages (D8).** `Calendar_Index` (17 ln) and `Tasks_Index` (12 ln) - the
last two unconverted frontend pages, both hand-rolled `container-fluid > card > text-center
display-1` "coming soon" stubs - became `Page_Scaffold` (scaffolded, no sidebar -> full
width) + `Placeholder_Card` (dashed variant, built Batch H). Calendar's `page_actions()`
(a dead "New Event" button + Month/Week/Day view-toggle group, no calendar backend) was
REMOVED (D4 honesty) and `full_width = true` swapped for `scaffolded = true`. Tasks gained
`scaffolded = true`. Both: 0 console errors, desktop + mobile (412px), scaffold owns width.

**K2 - `Sidebar_Kpi_Row` -> `Kpi_Cell` rename (P10 naming debt, first flagged Batch D).**
The KPI cell had been consumed by both `Sidebar_Kpi_Group` (entity-sidebar telemetry) AND
the dashboard `Stat_Group` since Batch D, so the `Sidebar_` prefix under-described it. Renamed
the component (files -> new `rsx/theme/components/view/kpi_cell/` dir; JS class
`Sidebar_Kpi_Row` -> `Kpi_Cell`; SCSS `.Sidebar_Kpi_Row`/`--alert`/`--clickable` ->
`.Kpi_Cell*`) and all 14 consumer call sites (Dashboard, Clients_View + its `.js` delegation
selector, Reports, System_Email_Config, group_management_view, invoices index/view + scss,
staff + portal request threads, Stat_Group/Stat_Row docblocks). `Sidebar_Kpi_Group` KEPT
its name (it IS sidebar-specific). `data-kpi-tab` kept (not named after the component). Verified
every consumer renders `Kpi_Cell` with 0 console errors, and the Clients_View clickable-KPI
delegation still activates its tab (clicked `.Kpi_Cell--clickable` -> Portal tab active). Grep:
zero `Sidebar_Kpi_Row` in live code bar the sanctioned "formerly" doc-comment in `kpi_cell.jqhtml`.
The docs have since been migrated too: grep confirms ZERO `Sidebar_Kpi_Row` in `CLAUDE.md`,
`CLAUDE.dist.md`, or any man page - the rename is fully settled in code and docs.

**K3 - legacy-pattern residue sweep.** See the "Batch K - final residue sweep" table above for
every pattern's current count + qualification. Fixes landed on converted/live surfaces:
- `Portal_Workspace_Layout` role badge `bg-secondary` -> `badge-outline-secondary` (the last
  Rule-of-Two violation; a role is a classification - Batch J's "role everywhere is outline"
  claim was true everywhere except this one layout instance, which is live UI shown when a
  workspace has a role).
- Removed 6 dead placebo row-actions (Edit `href="#"` + unwired Delete `<button>`) from the
  clients / contacts / projects list DataGrids - matching Batch H's users_datagrid cleanup;
  the working View link stays. All 3 lists re-render with 15 View buttons, 0 errors.
- Repointed the `Frontend_Spa_Layout` user-menu "My Profile" dead `href="#"` to
  `Rsx.Route('Settings_Profile_Display_Action')` (the real profile page the header avatar
  already links to).
Everything else qualified (sanctioned outline/computed classification chips; genuine domain
chips - doc review-state, "Needs Review" alert, "Current" session, picker-modal states;
functional `display:none` idioms; Bootstrap dropdown-divider; layout chrome; DataGrid/modal
internals; D7 dev/ssr). **card/card-body, container-fluid, `<hr>` section dividers,
col-desktop shells, and raw tab-toggle JS are extinct on every converted page.**

**K4 - P10 naming residue global check.** Grepped all 7 deleted components (`Data_Table`,
`Client_Label`, `Client_Label_Link`, `Form_Hidden_Field`, `Portal_Participant_Avatar`,
`Clients_Portal_Action`, `Request_Participant_Avatar`) across live code: ZERO live references.
The only hits are the sanctioned "describes what it replaced" doc-comments inside the
replacement components (`entity_link.*` for Client_Label*; `person_avatar.jqhtml` for the
avatar twins) - exactly what the Deleted table documents.

**K6 - glossary.** `--missing` is clean for the whole owned vocabulary: `Kpi_Cell` carries a
parsable summary and is NOT listed; `Sidebar_Kpi_Row` is gone. The remaining 123-of-171 missing
are page Actions, DataGrids, error pages, and legacy `page/`+`card/` primitives (expected /
pre-existing per Batch H lesson 9; the bar is "no VOCABULARY component missing a summary", met).
**Final component count: 171 total, 48 with parsable summaries.**

**Epic status: every page in the research inventory now has a terminal status** (C / RETIRED /
already-compliant / out-of-scope-D7). The demo/template app composes end-to-end from the semantic
vocabulary; placeholder pages included.

## Batch L - adversarial QA passes + fixes

Two adversarial QA passes (L1 render-honesty audit, L2 registry audit) ran over the finished
epic; a third (L3) fixed the confirmed findings. What each surfaced and how it was resolved:

**L1 - render/data-honesty audit (2 findings, both fixed):**
- *Invoices_Index reconciliation gap (P9).* The Outstanding KPI ($16,858.13) sums each issued
  invoice's `amount_due` (net of a hidden $1,000 deposit on INV-2025-0230), while the Amount
  column rendered the GROSS `total` - so the strip could not be derived from the visible rows.
  FIX: the table column is now **Balance** and renders `amount_due`; when an invoice is partially
  paid (`amount_paid > 0`) a muted "of $X" (the gross total) sits under it. Summing the Balance
  column for issued rows now equals the Outstanding KPI - fully table-honest. KPI left as-is
  (correct accounting).
- *"(title not set)" on 7 settings pages (pre-existing).* `Spa_Action.page_title()` defaults to
  `(title not set)`; seven settings actions carried only a `@title(...)` browser-tab decorator and
  never overrode the method, so their in-page H1/breadcrumb read "(title not set)". FIX: copied the
  working sibling pattern (`settings_group_management_view_action.js`) - added `page_title()` +
  `breadcrumb_label_active()` (and, for the two nested pages, `breadcrumb_parent()`/`breadcrumb_label()`)
  to profile_display, profile_edit, user_settings, password_security, api_keys, site_settings, and
  user_management_view.

**L2 - registry-accuracy audit (2 findings, both fixed):**
- *Stale doc-follow-up claim.* Batch K's notes said `CLAUDE.md` + `semantic_composition`/`jqhtml`
  man pages still used `Sidebar_Kpi_Row` and wanted a manual follow-up. Grep proves those docs
  were already migrated (zero `Sidebar_Kpi_Row` in `CLAUDE.md`, `CLAUDE.dist.md`, or any man page);
  the only remaining reference is the sanctioned "formerly" doc-comment in `kpi_cell.jqhtml`. The
  stale claim (Levered-components note ~L201 + K2 ~L870) is corrected to "fully settled in code and docs".
- *`Section_Columns` unqualified.* The Layer-2 row didn't note it has ZERO live consumers. Qualified:
  built as part of the plan-mandated Layer-2 chrome set; ORCHESTRATOR DECISION - keep, marked
  "reserved - the first nested-split consumer adopts it or it is deleted at the next residue sweep."

**L3 - epic-introduced `rsx:check` violations (7, all fixed):** one PHP-CLASS-01 (defensive
`class_exists()` wrap in `frontend_dashboard_controller.php` removed - the type-ref accessor yields
a valid simple class name and `::find()` fails loud), one JS-FALLBACK-01 (the word "fallback" reworded
out of an `Invoices_Add_Action.js` comment), and five JS-NATIVE-01 `String()` -> `str()` swaps
(`page_scaffold.js`, `tab_bar.js` x2, `tab_panels.js`, `invoices_demo.js`). `rsx:check` is clean
(0 violations; the 29 convention warnings are pre-existing).

---

# Entity-View Tab-Alignment epic (follow-on)

Goal: format Contacts_View / Projects_View / Party_View with the same tabbed design as the
Clients_View archetype, add an Activity tab to all four entity views, and REMOVE the invoices
demo (not a core CMS feature). Same vocabulary, conventions, and lessons file as the parent
refactor epic.

## Tab-alignment Batch 1 - backend enablers + invoices removal

Pure backend/plumbing batch (NO page markup changed yet - that is Batch 2). Delivered:

**1. Invoices DELETED entirely** (owner decision - demo fiction: no `Invoice_Model`, no table,
no real endpoints). Removed all 13 files under `rsx/app/frontend/invoices/` (3 actions x
jqhtml/js/scss, 3 stub controllers, the `Invoices_Demo` source) + the Invoices nav entry in
`Frontend_Spa_Layout.js` (the Financial section keeps ONLY Reports). `/invoices`, `/invoices/add`,
`/invoices/:id` all 404 (verified); the sidebar renders with no Invoices entry (verified via an
authenticated `rsx:debug` render - Financial -> Reports only, 0 console errors, layout bundle
compiles). Live-code residue grep (`Invoices_|/invoices`) is zero except: the auto-regenerated
`system/storage/rsx-build/manifest_data.php` build artifact (rebuilds JIT), the SANCTIONED
money-line/alert doc-comment examples in `stat_row.jqhtml`/`callout.jqhtml` (kept - they illustrate
the component CONCEPT, not the deleted pages; rewording would blunt the canonical example), and
`ViewErrors.php`'s unrelated `invoice.print.blade.php` filename-convention example. Registry:
3 pages -> RETIRED (page tracker); `Stat_Row` -> RESERVED-WITH-RATIONALE (both consumers were the
invoice pages; shape kept for the first real money page - like `Section_Columns`); `Callout` stays
live (its Clients_View deleted-client banner remains); `(N)`-count legacy remainder recounted 2 -> 1.

**2. `Project_Model::tasks()` FIXED (the Batch-E-escalated data-layer bug).** Deleted the broken
`morphMany(Task_Model, 'taskable')` (type-ref column stores the SIMPLE class name but morphMany
queries the FQCN morph class -> always 0 rows) and its `#[Relationship]`/`#[Ajax_Endpoint_Model_Fetch]`
attributes. Replaced with the type-ref-safe manual query
`Task_Model::where('taskable_type','Project_Model')->where('taskable_id',$this->id)->orderBy('due_date')->get()`
(the dashboard/seeder pattern). NO dual implementation - the broken one is gone; no callers existed
(grep clean). Verified via tinker: project 1 returns 3 tasks = `SELECT count(*)` DB truth.
**[SUPERSEDED 2026-08-09]** The parenthetical reason no longer holds - morph relations now work
over type-ref columns. `tasks()` stays a manual ordered query because it returns the Collection
callers iterate, not because morphMany is broken.

**3. Party action logging ADDED.** Registered `TYPE_PARTY_CREATED/UPDATED/DELETED` (40/41/42,
following the Client 1-9 / Contact 10-19 / Project 20-29 / Task 30-39 numbering) as enum entries on
`Action_Log_Model` (constants regenerated via `rsx:constants:regenerate`), added `party_created/
updated/deleted` renderer methods to `Action_Log_Renderer` (linking to `Party_View_Action`, mirroring
the project renderers), and wired `Action_Log::record(...)` into `party_controller.php`'s save (create
vs update) + delete paths - exactly as contacts_controller does. Proven end-to-end: a real edit through
the `save` endpoint (`rsx:ajax Frontend_Party_Controller save --user=1`) created action_log row 13
(type 41), confirmed via `Action_Log::get_for_entity($party)` in tinker.

**4. Six new read `#[Ajax_Endpoint]`s** (all auth-gated by their controller's existing
`pre_dispatch` `Session::is_logged_in()` - verified: a call with no `--user` returns `unauthorized`).
`Frontend_Contacts_Controller::contact_projects` (projects where `contact_id` = id; row shape mirrors
`client_projects`) + `contact_activity`; `Frontend_Projects_Controller::project_tasks` (via the fixed
query; per row: id/title/status__label/status__badge/due_date/is_overdue where is_overdue = an OPEN
task past due) + `project_activity`; `Frontend_Party_Controller::party_activity`;
`Frontend_Clients_Controller::client_activity`. The four Activity endpoints share ONE lean payload
shape (Dashboard's recent-activity items): `{id, html (Action_Log_Model::render() linked summary),
created_at, type_id}`, wrapping `Action_Log::get_for_entity($entity, 50)`. Every endpoint verified via
`rsx:ajax <Controller> <method> --args='{"id":1}' --user=1` returning real rows.

**Files touched:** deleted `rsx/app/frontend/invoices/` (13 files); edited
`rsx/app/frontend/Frontend_Spa_Layout.js`, `rsx/models/project_model.php`,
`rsx/models/action_log_model.php`, `rsx/lib/action_log/action_log_renderer.php`,
`rsx/app/frontend/party/party_controller.php`, `rsx/app/frontend/contacts/contacts_controller.php`,
`rsx/app/frontend/projects/projects_controller.php`, `rsx/app/frontend/clients/clients_controller.php`
(+ `rsx:constants:regenerate` regenerated the model docblocks/JS stubs).

**Judgment calls / notes:**
- The `Action_Log_Model` renderer enum strings use the namespace segment `Rsx\Lib\Action_Log`
  (underscore) even though the renderer file declares `namespace Rsx\Lib\ActionLog` (no underscore).
  This mismatch is PRE-EXISTING and demonstrably works (Client/Contact/Project render on the
  dashboard); the new Party entries mirror the existing pattern EXACTLY for consistency, not
  "correctness" - do not "fix" it in isolation.
- `Party_Model::save` validates `type_id` BEFORE loading the stored party, so an edit MUST still
  send `type_id` (the edit form does; a bare `{id}` edit fails "Unknown party type"). Pre-existing
  quirk - noted for the Batch 2 Party_View author.

## Tab-alignment Batch 2 - page alignment (the visible deliverable)

The three sibling entity views (Contacts / Projects / Party), converted single-scroll in Batch E,
were retabbed to match the Clients_View archetype (Entity_Header + Tab_Bar/Tab_Panels + KPI
`Detail_Sidebar`); Clients_View gained an Activity tab. **Reverses the Batch E "leaf entities are
single-scroll, no tabs" decision** - that call was correct GIVEN the leaves loaded no child
collections, but the owner directed adding Activity (all four entity views share the concept) plus
Contacts' Projects and Projects' Tasks child lists, which supplies the collections a tabbed shape
needs. Zero new vocabulary COMPONENTS (pure vocabulary application, as predicted). ONE shared JS
helper added.

**Per-page shape (all identical structure - cross-page consistency IS the acceptance test):**
- **Contacts_View**: Overview / Projects(n) / Activity(n). Projects tab = `Record_Table` (name /
  Status_Badge / priority outline chip / due date; `data-href` -> `Projects_View_Action`). KPI
  sidebar: Projects + Activity, both `$clickable`+`$tab`. `Promise.all(Contact_Model.fetch,
  contact_projects, contact_activity)`.
- **Projects_View**: Overview / Tasks(n) / Activity(n). Tasks tab = `Record_Table` (title / filled
  Status_Badge via `$label`/`$badge` / due date, overdue in `text-danger` via `is_overdue`; rows now
  navigable -> `/tasks/view/:id` since Task-mgmt Batch 2 built the task pages). KPI: Open Tasks + Overdue (`$alert=_overdue>0`), both `$clickable`
  `$tab="tasks"`. `Promise.all(Project_Model.fetch, project_tasks, project_activity)`.
- **Party_View**: Overview (universal + CTI `<Type> Details` moved inside the Overview panel) /
  Activity(n). KPI: Activity. Kept the `Action_Menu` Delete + type outline chip. `Promise.all(
  Party_Model.fetch, party_activity)` then the CTI detail resolves off the fetched party's embed.
- **Clients_View**: Activity tab appended AFTER Projects, BEFORE the conditional Portal tab (a plain
  `<Tab_Panel>` between the projects panel and the `<% if portal_enabled %>` portal panel). Added an
  Activity `Kpi_Cell` to the sidebar KPI group (now Contacts/Projects/Activity[+Portal] - a clean
  2-up grid; verified no crowding at mobile, tabs wrap).

**Judgment calls:**
- **Clients Activity-KPI cell: ADDED (the mission left this to the writer's call).** Consistency won -
  the other three pages all carry an Activity KPI, so Clients matching them makes the four sidebars
  read identically. With portal enabled it's a 2x2 grid (Contacts/Projects/Activity/Portal); portal
  off, a 2+1. Verified at 390px mobile: no crowding, KPI grid stays 2-up.
- **Shared activity decorator = `Activity_Feed.decorate(type_id)`** (`rsx/lib/action_log/
  activity_feed.js`, a globally-callable static class in the frontend-bundled `rsx/lib`). The four
  Activity endpoints return the raw `{id,html,created_at,type_id}`; the pages map each entry to add
  `{icon,variant}` in `on_load` (`.map(a => ({...a, ...Activity_Feed.decorate(a.type_id)}))`), then
  `<Feed_Row $icon=a.icon $variant=a.variant>`. This is the client-side twin of the dashboard's
  server-side `_activity_icon()` - kept in ONE place so all five feeds render identically (DRY over 4
  consumers; the alternative was a 6-line map duplicated per page). Party range 40-49 added to the map
  (`bi bi-diagram-3` / secondary) - the dashboard's PHP match had no case-4 (party logging is newer).
- **One additive backend field:** `project_tasks` now also returns the raw `status` (id) per row so
  Projects_View can compute an honest "Open Tasks" KPI (status in PENDING/IN_PROGRESS) - the endpoint
  previously shipped only `status__label`/`__badge` (no machine-readable status). One-line, mirrors
  `contact_projects` which already returns raw `status`. Lessons/Batch-1 payload note updated.

**Feed_Row single-consumer debt CLEARED** - now 5 live consumers (dashboard + 4 entity Activity tabs).

**Verification (all four pages, both viewports, zero console errors):** every tab switch driven via
`.Tab_Bar__tab[data-tab-key]` click -> the matching `Tab_Panel` becomes visible (`overview=>overview
; projects=>projects ; activity=>activity`, etc. on each page). One KPI `$clickable` jump per page ->
target panel activates. Contacts Projects-tab row `data-href=/projects/view/1` -> click navigates
(empty eval return = SPA nav destroyed the context = success, per Batch H lesson 8). Projects Tasks
tab shows 5 REAL rows (= `project_tasks` count for project 2; DB truth) with the overdue row's date in
red; Open Tasks 5 / Overdue 1. Party 1 Activity shows the seeded logged row (diagram-3 feed icon);
Party 2 (Company) Activity shows Empty_State (honest - no history). Regressions clean: Dashboard,
portal dashboard (portal-user 1), and Contacts_Edit all render with 0 console errors (the shared
`rsx/lib` helper didn't disturb any bundle). Glossary `--missing`: only page ACTIONS + pre-existing
`Page_Section` (Batch H lesson 9 - no vocabulary component missing a summary).

**Lessons appended to `docs.dev/research/2026_07_12_semantic_refactor_lessons.md` (Tab-alignment
Batch 2 block).**


## Task-mgmt Batch 2 - Task Management UI (Tasks_Index/View/Edit + parent picker)

Built the full `/tasks` CRUD surface on the shared vocabulary. **Zero new theme
vocabulary** - the three pages compose entirely from Page_Scaffold / Card_Widget /
Entity_Header / Status_Badge / `badge-outline-*` / Tab_Bar / Tab_Panels / Section /
View_Fields / Empty_Value / Empty_State / Entity_Link / Record_Table / Feed_Row /
Sidebar_Kpi_Group / Kpi_Cell / Detail_Sidebar / Action_Menu, exactly like the other
four entity views. Cross-checked Tasks_View against Contacts_View side-by-side: identical
archetype (Entity_Header title/chips/meta -> Tab_Bar -> Tab_Panels; Detail_Sidebar =
Sidebar_Kpi_Group + View_Fields + edit button + Action_Menu delete).

**Two NEW app-level form inputs** (co-located `rsx/app/frontend/tasks/edit/form/`, NOT theme
vocabulary - they are task-domain widgets over the tasks controller):

| Component | Contract | Notes |
|---|---|---|
| `Ajax_Entity_Select_Input` | `extends Select_Input`. A single-type **remote-search** TomSelect over `Frontend_Tasks_Controller.search_parents({type, filter, exclude_id})`. `$parent_type` (runtime-swappable via `set_type()`), `$exclude_id`, `$placeholder`. `val()` = a scalar entity id, timing-indifferent (base buffers pre-ready). On set of an id not yet an option, resolves its label via `search_parents({type, id})` (RESOLVE-ONE mode) so a preselected value shows a name. `set_disabled()` locks it. | Reused 4x: Tasks_Edit assignee field, editable project field, the entity half of `Parent_Selector_Input`, AND (Task-mgmt Batch 3) the **Projects_Edit parent-project picker** (`$parent_type="Project_Model"`, `$exclude_id=<own id>`). Remote typeahead (not full-list) because Task spans ~200 rows + needs `exclude_id`. Cross-module reuse is fine - a projects form drives a picker whose `search_parents` endpoint lives in the tasks controller (manifest resolves the class by name). |
| `Parent_Selector_Input` | `extends Form_Input_Abstract` (composite). A type `<select>` (None/Task/Project/Client/User) drives a nested `Ajax_Entity_Select_Input`. `val()` get/set = `{type: 'X_Model'\|null, id: int\|null}`, timing-indifferent; fires `input` (user changes) + `val` (all). `$exclude_id` (edit passes the task's own id -> excludes self+subtree). | **Flat submission via mirror hidden inputs** (`name="taskable_type"`/`"taskable_id"`): `Rsx_Form.vals()` collects `input[hidden][name]` directly, so the controller reads the SAME flat params with ZERO controller change. Its own `{type,id}` val rides under `$name="taskable"` (ignored by the controller). The nested entity input is private - `Rsx_Form.shallowFind('.Form_Input_Abstract')` stops at the outer composite and never descends. |

**Derived-project UX (the heart, application case study for BACKLOG B-1):** the Project field is
ONE always-present `Ajax_Entity_Select_Input`; the action listens to `Parent_Selector_Input`'s
`input` event and calls `Frontend_Tasks_Controller.resolve_parent_project` -> when the chain
reaches a project the field is `set_disabled(true)` + a "Derived from parent chain: <name>" note
(`.form-text`, `d-none` toggled); otherwise editable. A disabled input still submits its value
(CLAUDE.md), and the controller overrides it from the chain when derived, so the two states are
both correct on save. Initial state established by calling the same `_refresh_project` with the
loaded parent in `on_ready`.

**Server additions (small, in-scope):** `search_parents` gained `id` (resolve-one, for the
picker's preselect label) + `exclude_id` (Task-type: excludes the task + its whole subtree via a
new `__task_and_descendant_ids` walk, mirroring the save() cycle guard); new `task_subtasks`
endpoint (mirrors `project_tasks`); `save()` redirect repointed `Tasks_Index -> Tasks_View`.

**Entity_Link levered** (see Levered table): `Task_Model` + `User_Model` added so the parent-chain
idiom + Assignee/Project fields resolve through the one reference component.

**Row-nav enablement:** Dashboard "Today's Tasks" rows repointed from parent-project to
`/tasks/view/:id`; Projects_View Tasks-tab rows gained `data-href` to the task view (both were
non-navigable "no task page" placeholders - task pages now exist).

**Verification (both viewports, zero console errors on all 3 pages + regressions):** index datagrid
renders 223 rows, parent icon+name, Status_Badge, priority outline chip, overdue-red due dates,
row-nav. View archetype matches Contacts_View (parent chain "Part of: Update documentation" ->
task view; Overdue $alert cell; subtasks tab count; project Entity_Link). Edit: full derived flow
DRIVEN - task-parent -> project disabled+"Derived from parent chain: Global Inc"; switch to Client
parent -> project editable. Save round-trips via the real controller: create w/ task-parent ->
`project_id` DERIVED = chain project (DB truth); edit to Client parent + manual project -> user
value honored (DB truth); `exclude_id` drops self+descendant; Action_Menu delete opens the confirm
modal. Regressions clean: Dashboard, Projects_View, Clients_View, Contacts_View, Projects_Edit
(Entity_Link change is additive - Client/Project/Contact still resolve `.name` first).

**Feed_Row single-consumer debt: now 6 live consumers** (added Tasks_View Activity tab).


## Task-mgmt Batch 3 - Projects hierarchy UI (subprojects tab + parent picker + contacts/users pivots)

The final writer batch of the Task-mgmt epic. Surfaced the Batch-1 project-hierarchy schema
(parent_project_id + project_contacts/project_users pivots) in the Projects view/edit UI.
**Zero new vocabulary or app components** - reused `People_List` (theme), `Ajax_Entity_Select_Input`
(tasks-domain input), and `Checkbox_Multiselect_Input` (theme input).

**Projects_View (added to the existing Overview/Tasks/Activity archetype):**
- **Header meta:** `Part of: <Entity_Link $model="Project_Model" $id=parent_project_id $small=true>`
  when the project has a parent (guarded inside the always-present `<Slot:meta>`).
- **NEW Subprojects(n) tab** (between Tasks and Activity): `Record_Table` (Name / filled Status_Badge /
  priority outline chip / due date; `data-href` -> `Projects_View_Action`), Empty_State when none.
  Fed by the existing `project_subprojects` endpoint.
- **Overview gained two `People_List` sections:** Assigned Users (`$clickable=false`) + Contacts
  (`$clickable=true` -> `Contacts_View_Action`). Fed by ONE new `project_people` endpoint returning
  `{contacts:[{id,type,name,subtitle}], users:[...]}` (contacts via `contacts()`, users via
  `assigned_users()`). One `on_ready` binds every `.People_List` and routes on `person.type`.
- **Sidebar KPI:** added a Subprojects `Kpi_Cell` (`$clickable $tab="subprojects"`) alongside Open
  Tasks / Overdue - the honest direct-child count.

**Projects_Edit (added to the Batch-G shell):**
- **Parent-project picker:** reused `Ajax_Entity_Select_Input` (`$parent_type="Project_Model"`); edit
  mode passes `$exclude_id=<own id>` so the project + its subtree are excluded (cycle guard).
- **Team & Contacts section:** `Checkbox_Multiselect_Input` for assigned users (all site users) +
  contacts (scoped to the project's client). Prefilled from the current pivots. Contacts shown only
  when the client is known at load (edit or add-from-client); plain-add omits it (client chosen via
  dropdown - assign contacts after the project exists).

**Backend (small, in-scope):**
- `Project_Model::self_and_descendant_ids($id)` - the shared cycle-guard helper (BFS over
  parent_project_id, depth cap 50). Used by BOTH `search_parents` (Project branch now honors
  `exclude_id`) AND `projects_controller::save()` (rejects a self/descendant parent - the old
  self-only guard was widened).
- New `project_people` (view) + `project_form_options` (edit options + current selections) endpoints.

**Judgment calls (reported):**
- **Multiselect over the checkbox-table modal precedent:** `Checkbox_Multiselect_Input` is the
  simplest in-theme fit and already the group-member pattern; a modal would be heavier for an inline
  form field.
- **Assigned-users People_List is `$clickable=false` (no-op).** The settings user-management view
  route EXISTS, but navigating from a project's team member to an admin settings screen is a jarring
  context switch; the calm display list reads better. Contacts ARE clickable (a natural cross-link).
- **UNION current selections into the client-scoped contact options** (see lesson 1): a linked
  contact whose client differs (migrated single-contact data) would otherwise fail to prefill and be
  silently dropped on save. `project_form_options` UNIONs any selected id not in the scoped set.
- **`DB::table` on the pivots** (project_contacts/project_users) mirrors the committed Batch-1 pattern
  (no pivot model); PHP-DB-01 flags it as a pre-existing class - not a new pivot model.

**Contacts_View Projects tab (D3 verify):** renders correctly post-pivot (contact 1's `contact_projects`
shows "Global Inc Website Redesign" via the `project_contacts` join) - no change needed.

**Verification (both viewports, zero console errors):** Projects_View 1 (subprojects tab Record_Table,
Overview Assigned Users/Contacts, Subprojects KPI) + Projects_View 53 (child: "Part of: Global Inc
Website Redesign" header). Projects_Edit 1 prefill DRIVEN via `component().val()`:
`{parent:"", contacts:[1], users:[1]}` (contact 1 is the off-client union case); Projects_Edit 53:
`{parent:"1", contacts:[195], users:[2,1]}`. Cycle guard proven via rsx:ajax: `search_parents`
exclude_id=1 drops 1+53; save parent=53 (descendant) and parent=1 (self) both rejected. Pivot
round-trip: save contacts=[1,6]/users=[1,2] -> DB confirms -> restored to [1]/[1]. Create-subproject
round-trip: save with parent=2 -> appears in project 2's Subprojects + child parent_project_id=2 (test
project cleaned up). 13 Task tests green. Regressions clean (Tasks_View/Clients_View/Dashboard 0 JS
errors).
