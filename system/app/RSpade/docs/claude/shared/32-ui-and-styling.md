<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of the UI philosophy and z-index bands. -->

## UI & STYLING

**Asset split**: jqhtml = markup | JS files = logic, lifecycle, state | SCSS = component styles, auto-bundled. **No `<style>` or `<script>` tags**, no inline event handlers. **An external script/stylesheet is never hand-injected either** (no appended `<script src=cdn>`, no vendor copy-paste snippet) — declare it in a `*.externals.php` file beside the feature and `await Rsx.load_external('id')`; the CSP whitelist derives from that declaration, so an ad-hoc external is a blocked external. Skill `rspade:external-resources`; `rsx:man external_resources`, `rsx:man csp`.

**UI philosophy**: serious business tools. Hover effects ONLY on interactive elements; no animation on non-actionable elements. **Buttons** use filled styles (`btn-primary`) — the one exception is icon-only button groups, which use outline. **z-index**: Bootstrap defaults, plus 1100 (modal children), 1200 (flash alerts), 9000+ (system).

**Every styled element is a component with scoped SCSS** — if you are copy-pasting markup, extract a component. SCSS in `rsx/app/` and `rsx/theme/components/` **must** wrap in a single class matching its component; `rsx/lib/` is non-visual; `rsx/theme/` outside `components/` holds primitives, variables and Bootstrap overrides. Page/action SCSS should be **near-empty** — a page's look lives in the components it composes.

**BEM child classes use the exact PascalCase component name as prefix** (`.Component_Name { &__element }`). **No kebab-case** — `datagrid-kanban__loading` does not match the compiled CSS and the element silently gets no styles. Shared variables live in `rsx/theme/variables.scss`; **check that file before writing new SCSS**.

**Responsive**: RSX replaces Bootstrap's breakpoints with semantic names — **Bootstrap's `.col-md-6`, `.d-lg-none` etc. do NOT work.** Tier 1 `mobile` (0-1023) / `desktop` (1024+); tier 2 `phone`, `phone-sm`, `phone-lg`, `tablet`, `desktop-sm`, `desktop-md`, `desktop-lg`, `desktop-xl`. Each is an SCSS mixin (`@include mobile { }`); infixed utility classes exist only for the non-zero tiers (`.col-tablet-6`, `.d-desktop-block` — the unprefixed `.col-6` IS the mobile rule), plus visibility helpers (`.mobile-only`, `.hide-phone`). JS asks `Responsive.is_mobile()` and friends.

Skill `rspade:scss`. Details: `rsx:man scss`, `rsx:man semantic_composition`, `rsx:man responsive`, `rsx:man zindex`.
