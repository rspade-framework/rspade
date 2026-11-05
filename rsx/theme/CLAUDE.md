# rsx/theme — the application's design system

## WHAT IS HERE

- `variables.scss` — the Sass scale: `$spacing-*`, `$font-size-*`, `$font-weight-*`,
  `$border-radius-*`, `$z-index-*`, transition durations, the content max-widths, and the
  Bootstrap variable overrides the custom build compiles against.
- `composition_tokens.scss` — the CSS custom properties that hold the spatial
  RELATIONSHIPS between the semantic primitives (`--block-gap`, `--card-pad-y`,
  `--card-pad-x`, `--col-gutter`, `--page-pad`). One edit here re-spaces every page.
  It also declares `--rsx-form-veil`, the translucent veil `Rsx_Form` draws over itself
  while loading, in a light and a dark value.
- `responsive.scss` — the two-tier breakpoint system and its mixins: `mobile` / `desktop`,
  then `phone`, `phone-sm`, `phone-lg`, `tablet`, `desktop-sm/-md/-lg/-xl`, plus the
  `*-up` and `*-down` forms and the infixed utility classes.
- `layout.scss` — the page-content width modifiers used by the unconverted pages.
- `badges.scss` — the `.badge-outline-*` classification chips (the Rule of Two Chips).
- `tom_select.scss` — Tom Select's control and dropdown re-pointed onto the runtime colour
  tokens; global because every Tom Select in the app shares it.
- `bootstrap5_src_bundle.php` — the asset bundle that compiles the custom Bootstrap build.
- `components/` — the shared component library, one `CLAUDE.md` per group.
- `vendor/` — the vendored upstream Bootstrap source; never edited.

## HOW TO CUSTOMIZE

- **This whole directory is yours.** Change a value in `variables.scss` and every
  component follows; the rules below say which file a given kind of value belongs in.
- Reskinning the app's card look is `components/section/view_section_abstract.scss`;
  re-spacing the page rhythm is `composition_tokens.scss`; recolouring is the runtime
  token table below, not a Sass colour variable.
- **A new GLOBAL stylesheet goes at the top of this directory.** The bundles served to
  visitors who have not signed in (`Login_Bundle`, `Errors_Bundle`, `Portal_Auth_Bundle`)
  take this directory's own stylesheets with `'rsx/theme/*.scss'` and nothing beneath it,
  so a global placed here reaches every page, and one placed in a subdirectory reaches
  the signed-in bundles only - a login page then renders without it, with no error. A
  component under `components/` is likewise absent from those three bundles until its
  files are named in them (skill `rspade:bundles`).
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

**A token is read bare, never with a literal default** - `var(--x, #2563eb)` paints the literal
when `--x` was never declared and hides that from every other theme. `rsx:check` reports it
(`SCSS-PHANTOM-TOKEN-01`); a missing token is declared here instead. A chain,
`var(--x, var(--bs-primary))`, is fine. `vendor/` is not checked.

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

`tom_select.scss` is the worked example of the hard case - a prebuilt third-party stylesheet
with hardcoded colors, re-pointed rule by rule onto these same tokens.

Full treatment: `php artisan rsx:man scss` (COLOURS section) and `php artisan rsx:man dark_mode`.

## Variables, units and mixins

Everything that is not a colour comes from `variables.scss` (spacing, font sizes and
weights, radii, z-index, transitions; check it before writing a value, add a named variable
when one is missing). Units are **px only, never rem**. Breakpoints come from the
`responsive.scss` mixins. The full roster with values, the mixins and the responsive tiers:
app skill `theme`.
