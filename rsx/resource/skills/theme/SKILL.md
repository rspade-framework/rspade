---
name: theme
description: "Styling against this application's own theme - the var(--bs-*) runtime colour tokens and their two exceptions, variables.scss ($spacing-*, $font-size-*, $border-radius-*, $z-index-*, primary-hover-bg()), composition_tokens.scss (--block-gap, --page-pad, --rsx-form-veil), px-only units, the two-tier responsive system (mobile/desktop plus phone/tablet/desktop-sm/md/lg/xl) with its mixins, infixed utility classes and fifth-width columns, Responsive.is_mobile() and friends, badge-outline classification chips, and the vendored Bootstrap build. Use when writing any SCSS rule, picking a colour or a spacing value, making something responsive, adding a variable, theming for dark mode, or when a colour looks wrong on a dark page."
---

# This application's theme

> **Living skill.** This skill ships with the template application and is yours. It describes
> the CURRENT state of `rsx/theme/` (outside `components/`); the directory files
> `rsx/theme/CLAUDE.md` and `rsx/theme/components/CLAUDE.md` are its companions. When this
> feature changes, update this skill and those files in the same pass.

The rules the FRAMEWORK enforces regardless of theme - the single-class wrap
(SCSS-SCOPE-01), BEM child naming, no `<style>` tags, bundle include order - are skill
`rspade:scss-rules`. Everything here is the application's palette, scale and breakpoint
system, and every value in it is yours to change.

## What is here

| File | What it holds |
|---|---|
| `variables.scss` | brand colours, the spacing/typography/radius/z-index scales, `primary-hover-bg()` |
| `composition_tokens.scss` | CSS custom properties for spatial RELATIONSHIPS (`--block-gap`, `--page-pad`) and the theme-flipping `--rsx-form-veil` |
| `responsive.scss` | the two-tier breakpoint system: mixins, generated utility classes, fifth-widths |
| `layout.scss` | `.page-content` width containers |
| `badges.scss` | `.badge-outline-*` classification chips |
| `vendor/` | the vendored Bootstrap 5 source plus `bootstrap_custom.scss` |
| `components/` | every shared component; each group carries its own `CLAUDE.md` |
| `bootstrap5_src_bundle.php` | the asset bundle that compiles the custom Bootstrap build |

## Colours are RUNTIME tokens, never SCSS variables

**Every colour is a Bootstrap custom property, `var(--bs-*)`.** SCSS resolves at compile
time, so a colour written as `$text-color` or `#f8f9fa` is baked into the stylesheet and
cannot follow a theme picked at runtime - it is simply wrong on a dark page, and nothing
breaks to tell you. Bootstrap redefines these properties for dark itself, so **one rule
serves both themes**.

| Token | Use for |
|---|---|
| `--bs-body-bg` | page and card surface (the base surface) |
| `--bs-tertiary-bg` | subtle raised or striped surface - the `#f8f9fa` equivalent |
| `--bs-secondary-bg` | a step stronger - disabled fields, selected rows |
| `--bs-border-color` | every border, divider and rule |
| `--bs-body-color` | primary ink |
| `--bs-secondary-color` | muted ink - captions, hints, placeholders |
| `--bs-emphasis-color` | strongest ink - headings, active values |

`--bs-border-color-translucent` for a border that must let its surface show through. A
**tinted brand colour** uses the channel form, never an SCSS `rgba()`:
`rgba(var(--bs-primary-rgb), 0.1)` (also `--bs-success-rgb`, `--bs-warning-rgb`,
`--bs-danger-rgb`, `--bs-info-rgb`).

```scss
// WRONG - baked at build time; wrong in dark mode, silently
.Invoice_Header { background: $white; color: $text-color; border-bottom: 1px solid $border-color; }

// RIGHT - the colours follow the theme; the spacing does not need to
.Invoice_Header {
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    border-bottom: 1px solid var(--bs-border-color);
    padding: $spacing-md;
}
```

**Two exceptions.** A surface that is dark **on purpose** in both themes (a dark sidebar,
a code block) is a fixed colour with a comment saying so, or the next reader will "fix"
it. And contrast ink on a brand-coloured button or badge is `color: #fff`, because the
surface under it does not flip.

`components/inputs/select/select_input.scss` is the worked example of the hard case: a
prebuilt third-party stylesheet with hardcoded colours, re-pointed rule by rule onto these
tokens.

`variables.scss` deliberately holds **no neutral ramp and no semantic colour alias** - the
brand colours are there because Bootstrap's build needs them as Sass variables, and
everything else is a runtime token.

### Dark mode

The body carries `rsx-dark` server-side before first paint. A token that must FLIP and has
no Bootstrap equivalent is declared twice in `composition_tokens.scss` - the light value on
`:root`, the dark value under `body.rsx-dark`. That is how `--rsx-form-veil` works: framework
core has no palette, so `Rsx_Form` reads the token and a dark page that does not declare it
flashes white. Skill `rspade:dark-mode`.

## Variables - everything that is NOT a colour

**Check `variables.scss` before writing new SCSS.** It holds the values that do not change
with the theme:

- **Spacing**: `$spacing-xs` 4, `$spacing-sm` 8, `$spacing-md` 16, `$spacing-lg` 24, `$spacing-xl` 32
- **Font sizes**: `$font-size-xs` 12 … `$font-size-3xl` 30; weights `$font-weight-normal|medium|semibold|bold`
- **Radius**: `$border-radius-sm` 4, `-md` 6, `-lg` 8
- **Z-index**: `$z-index-dropdown` 1000, `$z-index-modal` 1050, `$z-index-modal-content` 1100 (`rsx:man zindex`)
- **Transitions**: `$transition-duration-fast` 150ms, `$transition-duration-base` 200ms
- **Mixin**: `@include primary-hover-bg($alpha)` for a primary-tinted hover surface

Missing a value? Add it to `variables.scss` with a descriptive name - unless it is a
colour, which belongs in the token table above instead. **Avoid magic numbers.**

**Composition tokens** (`composition_tokens.scss`) are CSS custom properties on purpose:
`--block-gap` (gap between sibling blocks in a column) and `--page-pad` (which tightens on
mobile) re-space every composed page from one edit, because no page hardcodes a gap of its
own. App skill `semantic-components`.

## Units: px only

**Never `rem`.** Spacing, padding, font sizes, radii, widths, heights and margins are all
px, so sizing stays predictable across the app.

## The responsive system

RSX replaces Bootstrap's breakpoints. **Bootstrap's `.col-md-6` and `.d-lg-none` do NOT
exist here.** Breakpoints: 400, 800, 1024, 1200, 1640, 2200.

**Tier 1** - `mobile` 0-1023, `desktop` 1024+.
**Tier 2** - `phone` 0-799, `phone-sm` 0-399, `phone-lg` 400-799, `tablet` 800-1023,
`desktop-sm` 1024-1199, `desktop-md` 1200-1639, `desktop-lg` 1640-2199, `desktop-xl` 2200+.

Each is a mixin:

```scss
.Component {
    padding: 32px;
    @include mobile     { padding: 16px; }
    @include phone      { padding: 8px; }
    @include desktop-xl { max-width: 1800px; }
}
```

**Utility classes** are generated for the non-zero tiers only and are MIN-WIDTH ("and
up"): `.col-tablet-6`, `.col-desktop-4`, `.d-desktop-block`, `.p-desktop-4`, `.m-tablet-3`.
The zero-width tiers (`mobile`, `phone`, `phone-sm`) emit **no infix at all** - the
unprefixed `.col-6` / `.d-none` / `.p-2` IS the mobile rule, and `.col-mobile-6` /
`.d-mobile-none` do not exist. Visibility helpers: `.mobile-only`, `.desktop-only`,
`.phone-only`, `.tablet-only`, `.hide-mobile`, `.hide-desktop`, `.hide-phone`,
`.hide-tablet`.

**Fifth-width columns** have no Bootstrap equivalent: `.col-5ths` plus
`.col-mobile-5ths`, `.col-tablet-5ths`, `.col-desktop-5ths`, `.col-desktop-sm-5ths`,
`.col-desktop-md-5ths`, `.col-desktop-lg-5ths`, `.col-desktop-xl-5ths`. There are no
`phone*` fifth-width variants - use `.col-mobile-5ths`.

**From JavaScript** (`Responsive`, framework core): `is_mobile()`, `is_desktop()`,
`is_phone()`, `is_tablet()`, `is_desktop_sm()`, `is_desktop_md()`, `is_desktop_lg()`,
`is_desktop_xl()`. **There is no `is_phone_sm()` / `is_phone_lg()`** - those two exist as
mixins and utility classes only; branch on `is_phone()` plus your own width check, or
handle the split in CSS. Details: `php artisan rsx:man responsive` (and the app's own
`rsx/resource/man/responsive.txt`).

## Badges: the rule of two chips

A FILLED pill is a STATUS (a workflow state, rendered by the `Status_Badge` component from
a model enum). An OUTLINED chip is a CLASSIFICATION - a type, role, category or priority,
a fact rather than a state. `badges.scss` supplies the outlined variant; pair it with
Bootstrap's `.badge`:

```html
<span class="badge badge-outline-secondary">High</span>
```

## Bootstrap

`vendor/bootstrap5/` is **vendored upstream source - never edit a file in it.** Overrides
happen by declaring Sass variables in `variables.scss`, which `vendor/bootstrap_custom.scss`
imports BEFORE Bootstrap's own defaults; `Bootstrap5_Src_Bundle` compiles that file and
ships Bootstrap's JS bundle with it. Upgrading means replacing the vendored directory
wholesale, which is exactly why nothing in it may be hand-edited.

Unscoped rules are for primitives only: buttons, spacing and typography utilities,
Bootstrap overrides. Everything else is component-scoped SCSS.

Related: `rspade:scss-rules` (the enforced rules), `rspade:dark-mode`, `rspade:bundles`,
app skill `semantic-components`. Contracts: `rsx:man scss`, `rsx:man responsive`,
`rsx:man zindex`, `rsx:man semantic_composition`.
