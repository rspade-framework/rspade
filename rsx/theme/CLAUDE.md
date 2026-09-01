# rsx/theme — the application's design system

## WHAT IS HERE

- `variables.scss` — the Sass scale: `$spacing-*`, `$font-size-*`, `$font-weight-*`,
  `$border-radius-*`, `$z-index-*`, transition durations, the content max-widths, and the
  Bootstrap variable overrides the custom build compiles against.
- `composition_tokens.scss` — the CSS custom properties that hold the spatial
  RELATIONSHIPS between the semantic primitives (`--block-gap`, `--card-pad-y`,
  `--card-pad-x`, `--col-gutter`, `--page-pad`). One edit here re-spaces every page.
- `responsive.scss` — the two-tier breakpoint system and its mixins: `mobile` / `desktop`,
  then `phone`, `phone-sm`, `phone-lg`, `tablet`, `desktop-sm/-md/-lg/-xl`, plus the
  `*-up` and `*-down` forms and the infixed utility classes.
- `layout.scss` — the page-content width modifiers used by the unconverted pages.
- `badges.scss` — the `.badge-outline-*` classification chips (the Rule of Two Chips).
- `bootstrap5_src_bundle.php` — the asset bundle that compiles the custom Bootstrap build.
- `components/` — the shared component library, one `CLAUDE.md` per group.
- `vendor/` — the vendored upstream Bootstrap source; never edited.

## HOW TO CUSTOMIZE

- **This whole directory is yours.** Change a value in `variables.scss` and every
  component follows; the rules below say which file a given kind of value belongs in.
- Reskinning the app's card look is `components/section/view_section_abstract.scss`;
  re-spacing the page rhythm is `composition_tokens.scss`; recolouring is the runtime
  token table below, not a Sass colour variable.
- Add a breakpoint or a mixin in `responsive.scss` only — Bootstrap's own `-md-`/`-lg-`
  infixes do not exist in this app.
- The framework's own SCSS rules (the single-class wrap, BEM prefixes, no `<style>` tags,
  bundle include order) are not yours to change: skill `rspade:scss-rules`.
- Depth and the full token roster: app skill `theme`. Component composition: app skill
  `semantic-components` and `components/CLAUDE.md`.


## Colors Are Runtime Tokens, Never SCSS Variables

**Every color is a Bootstrap custom property: `var(--bs-...)`.** SCSS resolves at compile time,
so a color written as `$text-color` or `#f8f9fa` is baked into the stylesheet and cannot follow a
theme the user picks at runtime - it is simply wrong on a dark page, and nothing breaks to tell
you. The custom properties are redefined for dark by Bootstrap itself, so a rule that reads one is
correct in both themes and needs no dark-mode rule of its own.

| Token | Use for |
|---|---|
| `--bs-body-bg` | page and card surface (the base surface) |
| `--bs-tertiary-bg` | subtle raised or striped surface - the `#f8f9fa` equivalent |
| `--bs-secondary-bg` | a step stronger - the `#e9ecef` equivalent; disabled fields, selected rows |
| `--bs-border-color` | every border, divider and rule |
| `--bs-body-color` | primary ink |
| `--bs-secondary-color` | muted ink - captions, hints, placeholders |
| `--bs-emphasis-color` | strongest ink - headings, active values |

`--bs-border-color-translucent` is the border for a surface whose own color must show through.
A **tinted brand color** uses the channel form, never an SCSS `rgba()`:
`background: rgba(var(--bs-primary-rgb), 0.1)` (also `--bs-success-rgb`, `--bs-warning-rgb`,
`--bs-danger-rgb`, `--bs-info-rgb`).

```scss
// WRONG - baked at build time; wrong in dark mode, silently
.Invoice_Header {
    background: $white;
    color: $text-color;
    border-bottom: 1px solid $border-color;
    padding: $spacing-md;

    .meta { color: $text-muted; }
}

// RIGHT - the colors follow the theme; the spacing does not need to
.Invoice_Header {
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    border-bottom: 1px solid var(--bs-border-color);
    padding: $spacing-md;

    .meta { color: var(--bs-secondary-color); }
}
```

**Two exceptions.** A surface that is dark **on purpose** in both themes - a dark sidebar, a code
block - is a fixed color, and says so in a comment so the next reader does not "fix" it. And
contrast ink on a brand-colored button or badge is `color: #fff`, not a token: the surface beneath
it is the brand color in both themes, so the text must stay white in both.

`select_input.scss` is the worked example of the hard case - a prebuilt third-party stylesheet
with hardcoded colors, re-pointed rule by rule onto these same tokens.

Full treatment: `php artisan rsx:man scss` (COLOURS section) and `php artisan rsx:man dark_mode`.

## Variables First - For Everything That Is Not A Color

Always check `variables.scss` before writing new SCSS rules. It holds the values that do **not**
change with the theme:

- **Spacing**: `$spacing-xs` (4px), `$spacing-sm` (8px), `$spacing-md` (16px), `$spacing-lg` (24px), `$spacing-xl` (32px), plus `$spacing-2xl`/`$spacing-3xl` and the numeric `$spacing-1`..`$spacing-6` scale
- **Font sizes**: `$font-size-xs` (12px), `$font-size-sm` (14px), `$font-size-base` (16px), `$font-size-lg` (18px), `$font-size-xl` (20px), plus `$font-size-2xl`/`$font-size-3xl`
- **Font weights**: `$font-weight-normal`, `$font-weight-medium`, `$font-weight-semibold`, `$font-weight-bold`
- **Border radius**: `$border-radius-sm` (4px), `$border-radius-md` (6px), `$border-radius-lg` (8px)
- **Z-index**: `$z-index-dropdown` (1000) through `$z-index-tooltip` (1070), plus `$z-index-modal-content` (1100) for dropdowns inside modals
- **Transitions**: `$transition-duration-fast` (150ms), `$transition-duration-base` (200ms)

If a variable doesn't exist for a value you need, add it to `variables.scss` with a descriptive
name - unless the value is a color, which belongs in the table above instead.

## Units: px Only

**Never use rem**. Always use px for all measurements:
- Spacing and padding
- Font sizes
- Border radius
- Widths and heights
- Margins

This ensures consistent, predictable sizing across all components.

## Mixins

Use `@include primary-hover-bg($alpha)` for primary color hover backgrounds:
```scss
&:hover {
    @include primary-hover-bg(0.1);
    color: $primary-color;
}
```

Use the responsive mixins from `responsive.scss`. Tier 1 is `@include mobile` (below the
desktop breakpoint) and `@include desktop`; tier 2 names a device - `phone`, `phone-sm`,
`phone-lg`, `tablet`, `desktop-sm`, `desktop-md`, `desktop-lg`, `desktop-xl` - each also
available in a `*-up` and `*-down` form. Bootstrap's `-md-`/`-lg-` infixes do NOT exist
here; the app's infixed utility classes (`.col-tablet-6`, `.d-desktop-block`) and the
visibility helpers (`.mobile-only`, `.hide-phone`) follow the same names. The full roster
is the app skill `theme`.

## Self-Documenting Code

The theme SCSS should be self-documenting through consistent variable usage. When someone reads the SCSS, variable names should immediately convey meaning:
- `$spacing-lg` is clearly larger spacing
- `$border-radius-md` is clearly medium rounding
- `var(--bs-secondary-color)` is clearly de-emphasized text

Avoid magic numbers. If you see a hardcoded value, replace it with a variable - or, if it is a
color, with the runtime token for the role it plays.
