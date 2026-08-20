---
name: model-enums
description: Implementing model enums in RSX with integer-backed values, constants, labels, and custom properties. Use when adding enum fields to models, working with status_id or type_id columns, accessing enum labels/properties via BEM-style syntax, or populating dropdowns with enum values.
---

# RSX Model Enums

Integer-backed enums with model-level mapping to constants, labels, and custom properties. Uses BEM-style double underscore naming for magic properties.

## Defining Enums

```php
class Project_Model extends Rsx_Model_Abstract {
    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active', 'badge' => 'bg-success', 'order' => 1],
            2 => ['constant' => 'STATUS_ON_HOLD', 'label' => 'On Hold', 'badge' => 'bg-warning', 'order' => 2],
            3 => ['constant' => 'STATUS_ARCHIVED', 'label' => 'Archived', 'selectable' => false, 'order' => 99],
        ],
        'priority_id' => [
            1 => ['constant' => 'PRIORITY_LOW', 'label' => 'Low', 'color' => '#999', 'days' => 30],
            2 => ['constant' => 'PRIORITY_MEDIUM', 'label' => 'Medium', 'color' => '#f90', 'days' => 14],
            3 => ['constant' => 'PRIORITY_HIGH', 'label' => 'High', 'color' => '#f00', 'days' => 7],
        ],
    ];
}
```

## Required Properties

| Property | Purpose |
|----------|---------|
| `constant` | Static constant name (generates `Model::STATUS_ACTIVE`) |
| `label` | Human-readable display text |

## Special Properties

| Property | Default | Purpose |
|----------|---------|---------|
| `order` | 0 | Sort position in dropdowns (lower first) |
| `selectable` | true | Include in dropdown options |

Non-selectable items are excluded from `field__enum_select()` but still display correctly when they're the current value.

## Custom Properties

Add any properties for business logic - they become accessible via BEM-style syntax:

```php
'status_id' => [
    1 => [
        'constant' => 'STATUS_ACTIVE',
        'label' => 'Active',
        'badge' => 'bg-success',           // CSS class
        'icon' => 'fa-check',              // Icon class
        'can_edit' => true,                // Business rule
        'permissions' => ['edit', 'view'], // Permission list
    ],
],
```

---

## PHP Usage

```php
// Set using constant
$project->status_id = Project_Model::STATUS_ACTIVE;

// BEM-style property access (field__property)
echo $project->status_id__label;       // "Active"
echo $project->status_id__badge;       // "bg-success"
echo $project->status_id__icon;        // "fa-check"

// Business logic flags
if ($project->status_id__can_edit) {
    // Allow editing
}
```

---

## JavaScript Usage

### Static Constants
```javascript
project.status_id = Project_Model.STATUS_ACTIVE;
```

### Instance Property Access
```javascript
const project = await Project_Model.fetch(1);
console.log(project.status_id__label);  // "Active"
console.log(project.status_id__badge);  // "bg-success"
```

### Static Enum Methods

All use BEM-style double underscore (`field__method()`):

```javascript
// Get all enum data
Project_Model.status_id__enum()
// Returns: {1: {label: 'Active', badge: 'bg-success', ...}, 2: {...}, ...}

// Get specific enum's metadata by ID
Project_Model.status_id__enum(Project_Model.STATUS_ACTIVE)
// Returns: {label: 'Active', badge: 'bg-success', order: 1, ...}

Project_Model.status_id__enum(2).selectable  // true or false

// For dropdown population (respects 'selectable' and 'order')
Project_Model.status_id__enum_select()
// Returns: [{value: 1, label: 'Active'}, {value: 2, label: 'On Hold'}]
// Note: Archived excluded because selectable: false

// Simple id => label map
Project_Model.status_id__enum_labels()
// Returns: {1: 'Active', 2: 'On Hold', 3: 'Archived'}

// Array of valid IDs
Project_Model.status_id__enum_ids()
// Returns: [1, 2, 3]
```

---

## Template Usage

```jqhtml
<span class="badge <%= this.args.project.status_id__badge %>">
    <%= this.args.project.status_id__label %>
</span>

<Select_Input
    $name="status_id"
    $options="<%= JSON.stringify(Project_Model.status_id__enum_select()) %>"
/>
```

---

## Boolean Fields

For boolean fields, use 0/1 as keys:

```php
'is_verified' => [
    0 => ['constant' => 'NOT_VERIFIED', 'label' => 'Not Verified', 'icon' => 'fa-times'],
    1 => ['constant' => 'VERIFIED', 'label' => 'Verified', 'icon' => 'fa-check'],
]
```

---

## Context-Specific Labels

Define different labels for different contexts:

```php
1 => [
    'constant' => 'STATUS_NEW',
    'label' => 'New Listing',           // Backend/default
    'label_frontend' => 'Coming Soon',  // Public-facing
    'label_short' => 'New',             // Abbreviated
]
```

Access: `$item->status__label_frontend`

---

## Permission Systems

Use custom properties for complex permission logic:

```php
'role_id' => [
    1 => [
        'constant' => 'ROLE_ADMIN',
        'label' => 'Administrator',
        'permissions' => ['users.create', 'users.delete', 'settings.edit'],
        'can_admin_roles' => [2, 3, 4],  // Can manage these role IDs
    ],
    2 => [
        'constant' => 'ROLE_MANAGER',
        'label' => 'Manager',
        'permissions' => ['users.view', 'reports.view'],
        'can_admin_roles' => [3, 4],
    ],
]
```

```php
// Check permissions
if (in_array('users.create', $user->role_id__permissions)) {
    // User can create users
}
```

---

## Anti-Aliasing Policy

**NEVER alias an enum property when serializing** (`$data['type_label'] = $record->type_id__label;` is wrong) - the BEM-style naming exists so one string works in every layer and grep finds all of it. The rule and its worked examples live in the `rspade:model-fetch` skill.

---

## Database Migration

**Enum columns are BIGINT** - never VARCHAR (wastes space), never the MySQL `ENUM` type (inflexible), never TINYINT (too small for future expansion). Migrations are raw SQL:

```php
DB::statement("ALTER TABLE projects ADD COLUMN status_id BIGINT NOT NULL DEFAULT 1");
```

---

## After Adding or Changing Enums

```bash
php artisan rsx:constants:regenerate
```

This regenerates the model's constants and its documentation comments from `$enums`.

## More Information

Details: `php artisan rsx:man enum`
