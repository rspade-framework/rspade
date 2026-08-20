# Semantic Composition

Distilled from `php artisan rsx:man semantic_composition`, which is the authoritative and much fuller treatment. Read the man page before converting or building any view page.

## The success test

A converted page's template **reads like a well-engineered program's main routine** - a sequence of named concepts - and its page-level SCSS file is near-empty (ideally a comment explaining why nothing is there). A page template that weaves markup, styling hooks and business logic together across hundreds of lines is a symptom of skipping this discipline, not a natural outcome of a complex page.

The permanent why: **simplicity leads to cohesion.** Components are the HOW. The four outcomes are consistently reusable tags (one concept renders through one component everywhere), separation of concerns by layer, consistent unit types (one component per data shape, so the app has a countable vocabulary), and reskinning-readiness (change one component's SCSS, every page follows).

**Never create components for the sake of components.**

## The four layers

Every converted view page delegates responsibility identically:

1. **Page scaffold** - `Page_Scaffold`, ONE component app-wide. Owns page max-width and centering, the main/sidebar column split (slots `main` and `sidebar`), the gutter, and the responsive column stacking. Pages never hand-assemble a content wrapper + row + `col-*` columns.
2. **Section / card chrome** - one abstract base (`View_Section_Abstract`) with a few concretes (`Section`, `Card_Widget`, `Detail_Sidebar`, `Section_Columns`). Background, border, radius, rhythm gap and body padding live in exactly one file.
3. **Spacing ownership (R1-R3)** - the enforcement mechanism for "pages author zero spacing":
   - **R1**: blocks never carry outer margins. A component styles its interior only; containers own the gaps between children. No negative-margin clawbacks.
   - **R2**: containers pad, content never pushes. Full-bleed content inside a padded section is a `$flush` ARG on the section, never CSS cancelling the parent's padding from inside.
   - **R3**: one relationship token per gap type (`--block-gap`, `--card-pad-y`, `--card-pad-x`, `--col-gutter`, `--page-pad`), defined once in the theme.
4. **Content vocabulary** - one component per data shape: `Entity_Header`, `Status_Badge`, `Count_Pill`, `View_Field`/`View_Fields`, `Kpi_Cell` in `Sidebar_Kpi_Group`, `Stat_Row`, `Callout`, `Author_Meta_Row`, `Feed_Row`, `People_List`, `Record_Table`, `Empty_State`, `Empty_Value`, `Entity_Link`, `Tab_Bar`/`Tab_Panels`/`Tab_Panel`, `Action_Menu`.

## Extract, lever, or promote

The single most common mistake is building a new component when an existing one should have been used or extended.

| Situation | Do this |
|---|---|
| An existing component fits | Use it. |
| An existing component ALMOST fits | Add a small, **additive lever** (an arg): `$removable`, `$avatar`, `$inline`, `$divided`. **A lever's default rendering must be pixel-identical to before**, and you carry the regression duty - screenshot every existing consumer to prove nothing shifted. |
| Two or more sites hand-roll the same shape | Extract ONE component and converge the sites onto it. |
| A needed widget RESEMBLES an existing one but has different domain semantics | Do NOT merge domains. Extract the shared display PRIMITIVE both can use, or an abstract base with two concrete variants. Converge the SHAPE they share, not the widgets. |

**The evidence bar**: extraction is evidence-driven, never speculative - **two or more LIVE call sites with the same shape**. Do not build a component in anticipation of a second consumer that does not exist yet.

**Promotion over time**: when a look repeats, promote it from page-local markup to a named component; when two components share a display shape, promote that shape to a shared primitive or an abstract base. The direction is always toward fewer, better-named, more-reused concepts.

**Preserving a bespoke look is not a reason to skip componentizing.** When the owner wants a distinctive look kept, the correct output is a named, self-contained, reusable component with that look - never page-local markup plus CSS.

## Argument rules

- **Displayed content is innerHTML** - `content()` or named `<Slot:name>` - **never an attribute.** Args carry DATA the component formats itself (`$user_id`, `$status_id`) and behavioral flags (`$variant`, `$size`, `$alert`). `$label="Open Tasks"` is exactly as wrong as `$title="Dashboard"`: both trap authored content as dead text. **Even a single word today** may need markup tomorrow.
- **Dual-channel chrome**: structural wrappers (`Page_Scaffold`, `Section`, `Card_Widget`, `Form_Field`) accept a plain-string `$title`/`$label` arg for convenience AND a matching `<Slot:title>`/`<Slot:label>` that WINS when present. Plain text -> the arg is fine. ANY markup, icon, nested component or emphasis -> the slot, always. **HTML inside an arg string is ALWAYS a defect** (`$label="<i class='bi bi-star'></i> Rating"` - the icon belongs in `<Slot:label>`).
- **Complete look, own file.** The SCSS file is wrapped in exactly the component's class; never style ANOTHER component's class from your file (the linter rejects it). Tokens only - no hardcoded hex, and no `var(--foo, #123456)` fallbacks that hide an undefined token. Child elements inside get simple unprefixed classes (`.avatar`, `.toolbar`) nested under the wrapper - the wrapper IS the namespace; BEM `Parent__child` is reserved for elements that must survive a `.component()` re-init. The look may split across files (`Data_Grid.scss` + `Data_Grid_mobile.scss`) provided every file wraps the same component class.
- **Variants as args, validated loudly.** A component throws unless `$variant` is one of the known values - a fail-loud error beats a silently unstyled banner.
- **Slots judiciously**: `content()` for a single-body wrapper; named `<Slot:x>` when there are two or more content regions. The moment a template uses ANY named slot, ALL caller content must be in slots.
- **Docblock contract**: the first line of a component's jqhtml header comment is `Component_Name - <summary, 12 words or fewer>`, then its args, slots and a usage snippet. The glossary generator reads these.

## Rules that settle recurring disputes

- **The Rule of Two Chips**: a FILLED pastel pill is a STATUS (a workflow state from a model enum -> `Status_Badge`); an OUTLINED chip is a CLASSIFICATION (type/role/category - a fact, not a state); a COUNT is a third, visually distinct pill (`Count_Pill`). Filling a classification chip is a defect. This resolves roughly ninety percent of chip questions.
- **Provisional-money honesty**: estimated or live-computed dollar values render amber and/or italic (a `$provisional` flag); committed facts render plain. Presenting a 100%-estimated total under a plain "Billed" label is dishonest UI.
- **KPIs are sidebar telemetry**: no strip of large KPI tiles across the top of a page. Compact bordered `Kpi_Cell`s inside `Sidebar_Kpi_Group` at the TOP of the entity sidebar.
- **The empty-state mandate**: every zero-child region renders `Empty_State` (icon + title + reason + optional CTA), never bare "no results" text and never a lying count. A single empty cell renders `Empty_Value` (the one muted em-dash). Every count must agree with what its tab or list actually renders.
- **The parent-chain idiom**: a record belonging to a parent shows "Part of: `<Entity_Link ...>`" in the entity header's meta row, the same way on every page.

## The page conversion procedure

The unit of work is ONE page, end-to-end. Never convert half a page.

1. **Inventory** - read the page's template, JS and SCSS completely. Map every region to the vocabulary: which existing component covers it, which needs a lever, what is genuinely new. List every hand-rolled idiom (raw badge spans, bespoke tab-toggle JS, box-in-box wrappers, inline styles, `hr` dividers, page media queries, sprinkled spacing utilities).
2. **Content re-evaluation** - before converting markup, walk the page as its real personas and ask whether it answers what its user came for. This surfaces query-scoping bugs, missing parent links, dead placebo buttons and competing surfaces. File everything found.
3. **Direction gate** - for a whole-app refactor, batch all findings into ONE consolidated proposal and get the owner's decisions once. Low-impact preference calls: make the sensible choice, record it, batch the disclosure.
4. **Convert** - rebuild top-down: scaffold -> sections/tab panels -> content vocabulary. Delete hand-rolled tab-toggle JS, migrate badges per the Rule of Two Chips, add the sidebar KPI block where the data exists, kill inline `style=` and page `@media` queries entirely, and reduce page SCSS to justified survivors with a comment saying why. **Where a seam tempts you to hand-author markup, give the owning component a lever instead.**
5. **Verify (non-negotiable, per page)** - BEFORE screenshots captured before any edit; AFTER screenshots at desktop (1920) AND mobile; drive the real interactions headlessly with `rsx:debug --eval=`; exercise sparse data so empty regions prove they render `Empty_State`; regression-screenshot every OTHER consumer of any shared component you levered; zero console errors.
6. **Record** - update the living registry (`rsx/resource/conventions/semantic_component_registry.md`) as part of the page's definition of done, then checkpoint-commit.

## Pitfalls

- **P1 - Slot-only inheritance breaks parent `$sid` resolution.** A child defined by slot-only inheritance re-scopes the content passed into it, so the PARENT's `this.sid('x')` on elements inside that content returns null. Use body-preserving `extends=` on the `<Define:>` tag.
- **P2 - `$flush` vs. widgets with their own header.** A section's padding-strip arg combined with a self-headed widget produces a misaligned orphan header at the card edge. Wrap self-headed widgets in a PLAIN section.
- **P3 - `$sid` discipline.** `$sid` targets are defined in the template and rendered unconditionally (toggle visibility, do not gate the element behind an `if`). Never create `data-sid` nodes from JS, and never SELECT by `data-sid`/`data-name`/`data-cid` - those are debug attributes, stripped in production.
- **P4 - `on_render` re-fires.** Namespace every DOM bind; guard every DOM injection against duplicate appends.
- **P5 - Hand-authored seams are a smell** pointing at a missing component lever. If several pages "need" the same `hr` in the same place, the adjacent component needs a `$divided` arg.
- **P6 - Phantom tokens.** `var(--foo, #123456)` fallbacks hide undefined tokens. Define the token once and strip the inline fallback.
- **P8 - Responsive stacking must not hide functionality.** When the sidebar stacks under the main column on mobile, no affordance may become unreachable.
- **P9 - Sparse-data honesty.** Every zero-child region renders `Empty_State`; every count agrees with what its tab or list actually renders. A disagreement is usually a real bug the conversion surfaced.
- **P10 - Naming residue.** When you delete a component, rename anything named after it. No-backwards-compatibility applies to names too.
- **P11 - Iterate with the page renderer, not the full lint suite.** Render pages headlessly after each change (`rsx:debug`); run `rsx:check` ONCE at the end of the epic.
