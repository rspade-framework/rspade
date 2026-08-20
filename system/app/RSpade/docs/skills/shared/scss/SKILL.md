---
name: scss
description: SCSS styling architecture in RSX including component scoping, BEM naming, responsive breakpoints, and variables. Use when writing SCSS files, styling components, working with responsive design, or troubleshooting CSS conflicts.
---

# RSX SCSS Architecture

## Component-First Philosophy

Every styled element is a component with scoped SCSS. No CSS spaghetti - no generic classes like `.page-header` scattered across files.

**Pattern vs Unique Decision**:
- If you're copy-pasting markup, extract a component
- Reusable structures → shared component with slots
- One-off structures → page-specific component

---

## Directory Rules

| Location | Purpose | Scoping |
|----------|---------|---------|
| `rsx/app/` | Feature components | Must wrap in component class |
| `rsx/theme/components/` | Shared components | Must wrap in component class |
| `rsx/theme/` (outside components/) | Primitives, variables, Bootstrap overrides | Global |
| `rsx/lib/` | Non-visual utilities | No styles |

---

## Component Scoping (Required)

SCSS in `rsx/app/` and `rsx/theme/components/` **must** wrap in a single component class:

```scss
// dashboard_index_action.scss
.Dashboard_Index_Action {
    padding: 2rem;

    .card {
        margin-bottom: 1rem;
    }

    .stats-grid {
        display: grid;
        gap: 1rem;
    }
}
```

- Wrapper class matches JS class or Blade `@rsx_id`
- Filename must match associated `.js` or `.blade.php` file
- Components auto-render with `class="Component_Name"` on root

---

## BEM Child Classes

Child classes use exact PascalCase component name as prefix:

```scss
.DataGrid_Kanban {
    &__loading { /* .DataGrid_Kanban__loading */ }
    &__board { /* .DataGrid_Kanban__board */ }
    &__column { /* .DataGrid_Kanban__column */ }
}
```

```html
<!-- Correct -->
<div class="DataGrid_Kanban__loading">

<!-- WRONG - kebab-case doesn't match compiled CSS -->
<div class="datagrid-kanban__loading">  <!-- No styles! -->
```

**No kebab-case** in component BEM classes.

---

## Variables

Define in `rsx/theme/variables.scss`. **Check this file before writing new SCSS.**

In bundles, variables.scss must be included before directory includes:

```php
'include' => [
    'rsx/theme/variables.scss',  // First
    'rsx/theme',                 // Then directories
    'rsx/app/frontend',
],
```

Variables can be declared outside the wrapper for sharing:

```scss
// frontend_spa_layout.scss
$sidebar-width: 215px;
$header-height: 57px;

.Frontend_Spa_Layout {
    .sidebar { width: $sidebar-width; }
}
```

---

## Variables-Only Files

Files with only `$var: value;` declarations (no selectors) are valid without wrapper:

```scss
// _variables.scss
$primary-color: #0d6efd;
$border-radius: 0.375rem;
```

---

## Supplemental SCSS Files

Split large SCSS by breakpoint or feature:

```
frontend_spa_layout.scss          # Primary (required)
frontend_spa_layout_mobile.scss   # Supplemental
frontend_spa_layout_print.scss    # Supplemental
```

Supplemental files use the **same wrapper class** as primary:

```scss
// frontend_spa_layout_mobile.scss
.Frontend_Spa_Layout {
    @media (max-width: 768px) {
        .sidebar { width: 100%; }
    }
}
```

---

## Responsive Breakpoints

RSX replaces Bootstrap breakpoints. **Bootstrap's `.col-md-6`, `.d-lg-none` do NOT work.**

### Tier 1 (Simple)

| Name | Range |
|------|-------|
| `mobile` | 0-1023px |
| `desktop` | 1024px+ |

### Tier 2 (Granular)

| Name | Range |
|------|-------|
| `phone` | 0-799px |
| `phone-sm` | 0-399px |
| `phone-lg` | 400-799px |
| `tablet` | 800-1023px |
| `desktop-sm` | 1024-1199px |
| `desktop-md` | 1200-1639px |
| `desktop-lg` | 1640-2199px |
| `desktop-xl` | 2200px+ |

### SCSS Mixins

```scss
.Component {
    padding: 2rem;

    @include mobile {
        padding: 1rem;
    }

    @include phone {
        padding: 0.5rem;
    }

    @include desktop-xl {
        max-width: 1800px;
    }
}
```

### Utility Classes

```html
<div class="col-12 col-desktop-6">...</div>
<div class="col-12 col-phone-lg-6">...</div>
<div class="desktop-only">Hidden on mobile</div>
<div class="mobile-only">Only visible on mobile</div>
<div class="hide-tablet">Hidden on tablet only</div>
```

Infixed classes exist only for the non-zero tiers (`phone-lg`, `tablet`, `desktop*`) and are MIN-WIDTH ("and up"). The zero-width tiers (`mobile`, `phone`, `phone-sm`) generate no infix at all - the unprefixed `.col-12` / `.d-none` IS the mobile rule, and `.col-mobile-6` / `.d-mobile-none` do not exist. Full detail: `rsx:man responsive`.

### Fifth-Width Columns

Five-across layouts have no Bootstrap equivalent. Eight variants exist:

`.col-5ths` (all widths) plus `.col-mobile-5ths`, `.col-tablet-5ths`, `.col-desktop-5ths`, `.col-desktop-sm-5ths`, `.col-desktop-md-5ths`, `.col-desktop-lg-5ths`, `.col-desktop-xl-5ths`.

There are no `phone`/`phone-sm`/`phone-lg` fifth-width variants - use `.col-mobile-5ths` there.

### JavaScript Detection

Tier 1: `Responsive.is_mobile()`, `Responsive.is_desktop()`.
Tier 2: `Responsive.is_phone()`, `is_tablet()`, `is_desktop_sm()`, `is_desktop_md()`, `is_desktop_lg()`, `is_desktop_xl()`.

```javascript
if (Responsive.is_mobile()) {
    // Mobile behavior
}

if (Responsive.is_desktop_xl()) {
    // Extra large desktop
}
```

**There is no `Responsive.is_phone_sm()` / `is_phone_lg()`** - `phone-sm` and `phone-lg` exist as SCSS mixins and utility classes only. Handle that split in CSS, or branch on `is_phone()` plus your own width check.

---

## Slot-Based Composition

Use slots to separate structure from content:

```jqhtml
<Define:Datagrid_Card>
    <div class="card">
        <div class="card-header"><%= content('toolbar') %></div>
        <div class="card-body"><%= content('body') %></div>
    </div>
</Define:Datagrid_Card>

<!-- Usage -->
<Datagrid_Card>
    <Slot:toolbar><button>Add</button></Slot:toolbar>
    <Slot:body><My_Datagrid /></Slot:body>
</Datagrid_Card>
```

Component owns layout/styling; pages provide content via slots.

---

## What Remains Shared (Unscoped)

Only primitives should be unscoped:
- Buttons (`.btn-primary`, `.btn-secondary`)
- Spacing utilities (`.mb-3`, `.p-2`)
- Typography (`.text-muted`, `.fw-bold`)
- Bootstrap overrides

Everything else → component-scoped SCSS.

---

## No Exemptions

There are **no exemptions** to scoping rules for files in `rsx/app/` or `rsx/theme/components/`. If a file can't be associated with a component, it likely belongs in:
- `rsx/theme/base/` for global utilities
- A dedicated partial imported via `@use`

## More Information

Details: `php artisan rsx:man scss` (file organization, component scoping, and the breakpoint system). Page-level composition and the "page SCSS is near-empty" discipline: `php artisan rsx:man semantic_composition`. Layering rules: `php artisan rsx:man zindex`.
