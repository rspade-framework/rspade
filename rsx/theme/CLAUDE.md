# RSX Theme SCSS Guidelines

## Variables First

Always check `variables.scss` before writing new SCSS rules. Use existing variables for:

- **Spacing**: `$spacing-xs` (4px), `$spacing-sm` (8px), `$spacing-md` (16px), `$spacing-lg` (24px), `$spacing-xl` (32px)
- **Font sizes**: `$font-size-xs` (12px), `$font-size-sm` (14px), `$font-size-base` (16px), `$font-size-lg` (18px), `$font-size-xl` (20px)
- **Font weights**: `$font-weight-normal`, `$font-weight-medium`, `$font-weight-semibold`, `$font-weight-bold`
- **Colors**: `$primary-color`, `$text-color`, `$text-muted`, `$border-color`, `$background-light`
- **Grays**: `$gray-100` through `$gray-900` (aligned with Bootstrap)
- **Border radius**: `$border-radius-sm` (4px), `$border-radius-md` (6px), `$border-radius-lg` (8px)
- **Z-index**: `$z-index-dropdown` (1000), `$z-index-modal` (1050), `$z-index-modal-content` (1100)
- **Transitions**: `$transition-duration-fast` (0.15s), `$transition-duration-base` (0.2s)

If a variable doesn't exist for a value you need, add it to `variables.scss` with a descriptive name.

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
- `$text-muted` is clearly de-emphasized text
- `$border-radius-md` is clearly medium rounding

Avoid magic numbers. If you see a hardcoded value, replace it with a variable.
