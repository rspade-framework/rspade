# RSX Theme SCSS Guidelines

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

- **Spacing**: `$spacing-xs` (4px), `$spacing-sm` (8px), `$spacing-md` (16px), `$spacing-lg` (24px), `$spacing-xl` (32px)
- **Font sizes**: `$font-size-xs` (12px), `$font-size-sm` (14px), `$font-size-base` (16px), `$font-size-lg` (18px), `$font-size-xl` (20px)
- **Font weights**: `$font-weight-normal`, `$font-weight-medium`, `$font-weight-semibold`, `$font-weight-bold`
- **Border radius**: `$border-radius-sm` (4px), `$border-radius-md` (6px), `$border-radius-lg` (8px)
- **Z-index**: `$z-index-dropdown` (1000), `$z-index-modal` (1050), `$z-index-modal-content` (1100)
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

Use responsive mixins from `responsive.scss`:
- `@include mobile` - Tablet and below
- `@include phone` - Phone only

## Self-Documenting Code

The theme SCSS should be self-documenting through consistent variable usage. When someone reads the SCSS, variable names should immediately convey meaning:
- `$spacing-lg` is clearly larger spacing
- `$border-radius-md` is clearly medium rounding
- `var(--bs-secondary-color)` is clearly de-emphasized text

Avoid magic numbers. If you see a hardcoded value, replace it with a variable - or, if it is a
color, with the runtime token for the role it plays.
