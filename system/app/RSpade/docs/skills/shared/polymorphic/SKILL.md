---
name: polymorphic
description: RSpade polymorphic relationships built on type references - a BIGINT {relation}_type + {relation}_id pair declared in $type_ref_columns, transparent to stock Eloquent morph relations. Use when implementing morphTo/morphOne/morphMany, adding a polymorphic column to a migration, handling polymorphic form fields, deciding what to do about morphToMany, or responding to a POLY-01 manifest-build failure or a stale _type_refs / half-set-pair error.
---

# RSpade Polymorphic Relationships

## The shape - always a pair

**A polymorphic reference is ALWAYS the pair `{relation}_type` + `{relation}_id`, both BIGINT.** The `_type` column stores a type-ref integer id, **never a VARCHAR class name**, and it is declared in the owning model's `$type_ref_columns`.

```php
$activity->eventable_type = 'Contact_Model';  // stored as an integer
echo $activity->eventable_type;               // reads back "Contact_Model"
$activity->eventable;                         // the Contact_Model row
```

**Forbidden shapes:**

- **Two parallel nullable FKs** (`contact_id` + `project_id`, one of them set) instead of a pair. A reference that can point at exactly one kind of thing is a plain FK, not a pair - do not turn it into one either.
- **A VARCHAR `*_type`** repeating a class name on every row. That is Laravel's stock string morph: outside the type-ref registry, invisible to the query-builder conversion and to `joinMorph()`. It half-works, which is the problem - it reads fine and silently matches nothing the moment anything else treats the column as a type ref.
- **A `type_id` ENUM column is a different concept entirely - not a type ref.** Enum discriminators are untouched by any of this.

Audit authorship uses the same shape (`created_by_type` + `created_by_id`), which is why it is polymorphic at all: RSpade has three identity models, so a bare integer could not say which one it meant.

---

## Defining Type Reference Columns

Declare which columns are type references in your model - that declaration is the ENTIRE setup:

```php
class Activity_Model extends Rsx_Model_Abstract
{
    protected static $type_ref_columns = ['eventable_type'];

    #[Relationship]
    public function eventable()
    {
        return $this->morphTo();   // stock Eloquent
    }
}
```

The cast is automatically applied - no manual `$casts` needed.

**Type refs are TRANSPARENT to Eloquent.** `Type_Ref_Registry::register_morph_map()` registers each type ref under TWO morph-map aliases - the class name AND the integer id - so `morphTo()` resolving the raw integer attribute lands on the right class, while a write goes out through `getMorphClass()` as the class-name alias and the cast converts it back. `morphTo`/`morphOne`/`morphMany`/`associate`/`dissociate`/`whereMorphedTo`/`whereHasMorph` all work unchanged. Read a relation as a **property** (`$activity->eventable`); call the method only when you want the relation object (`withTrashed()` and friends).

---

## Boundaries and failure modes

- **Eager loading is forbidden framework-wide**: `->with()` throws. Load the relation when you need it.
- **A half-set pair fails loud.** `_type` null with `_id` set (or the reverse) is an error, not a partial record - **write both or neither**.
- **A stale `_type_refs` row** (the model was renamed or deleted) throws on read, and makes `whereHasMorph($rel, '*')` fatal. Fix the registry row or the class name; do not code around it.
- **Pivot morphs are UNSUPPORTED.** `morphToMany()` / `morphedByMany()` put the `_type` column on a PIVOT TABLE that no model owns, so no `$type_ref_columns` declaration can ever apply to it. **Remedy: model the join table as a real model with its own `{relation}_type` / `{relation}_id` pair.**

## POLY-01

A manifest-build FATAL (AST, cross-file) rejecting the Laravel string-morph pattern. It flags:

1. `morphTo()` whose `_type` column is not declared in the DECLARING model's `$type_ref_columns`;
2. `morphOne()` / `morphMany()` whose `_type` column is not declared on the RELATED model;
3. any `morphToMany()` / `morphedByMany()` - always, see above;
4. a morph type column whose name does not end in `_type`.

Deliberately NOT detected: a morph verb whose owning model cannot be resolved statically (the rule is fatal, so an unprovable case is skipped rather than guessed at), and migrations (a `VARCHAR *_type` is caught at the model that relates over it, not at the migration that created it). Suppressed by `@POLY-01-EXCEPTION` for a genuinely external, non-RSpade table whose discriminator is not ours to change.

---

## Database Schema

Type reference columns must be **BIGINT**, not VARCHAR:

```sql
CREATE TABLE activities (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    eventable_type BIGINT NULL,
    eventable_id BIGINT NULL,
    action VARCHAR(50) NOT NULL,
    INDEX idx_eventable (eventable_type, eventable_id)
);
```

---

## Usage

### Setting Values

```php
$activity = new Activity_Model();
$activity->eventable_type = 'Contact_Model';  // Use class name
$activity->eventable_id = 123;
$activity->save();
```

### Reading Values

```php
echo $activity->eventable_type;  // "Contact_Model" (string)
$related = $activity->eventable; // Returns Contact_Model instance
```

### Querying

Class names are automatically converted to IDs in WHERE clauses:

```php
// All work - class names auto-converted
Activity_Model::where('eventable_type', 'Contact_Model')->get();
Activity_Model::whereIn('eventable_type', ['Contact_Model', 'Project_Model'])->get();
```

---

## Polymorphic Join Helpers

Join tables with polymorphic columns:

```php
// INNER JOIN - contacts that have attachments
Contact_Model::query()
    ->joinMorph('file_attachments', 'fileable')
    ->select('contacts.*', 'file_attachments.filename')
    ->get();

// LEFT JOIN - all contacts, with attachments if they exist
Contact_Model::query()
    ->leftJoinMorph('file_attachments', 'fileable')
    ->get();

// RIGHT JOIN
Contact_Model::query()
    ->rightJoinMorph('file_attachments', 'fileable')
    ->get();
```

**Parameters**:
- `$table` - Table with polymorphic columns (e.g., 'file_attachments')
- `$morphName` - Column prefix (e.g., 'fileable' for fileable_type/fileable_id)
- `$morphClass` - Optional explicit class (defaults to current model)

---

## Form Handling

### Client-Side Format

Polymorphic fields submit as JSON:

```javascript
eventable={"model":"Contact_Model","id":123}
```

### Server-Side Parsing

```php
use App\RSpade\Core\Polymorphic_Field_Helper;

#[Ajax_Endpoint]
public static function save(Request $request, array $params = [])
{
    $eventable = Polymorphic_Field_Helper::parse($params['eventable'], [
        Contact_Model::class,
        Project_Model::class,
    ]);

    // Validate
    if ($error = $eventable->validate('Please select an entity')) {
        return response_error(Ajax::ERROR_VALIDATION, ['eventable' => $error]);
    }

    // Use
    $activity = new Activity_Model();
    $activity->eventable_type = $eventable->model;  // "Contact_Model"
    $activity->eventable_id = $eventable->id;       // 123
    $activity->save();
}
```

**Important**: Always use `Model::class` for the whitelist.

---

## Auto-Discovery

When storing a new class name that isn't in `_type_refs` yet:

```php
$attachment->fileable_type = 'Custom_Model';
$attachment->save();
```

RSX will:
1. Verify `Custom_Model` exists and extends `Rsx_Model_Abstract`
2. Create a new `_type_refs` entry with next available ID
3. Store that ID in the column

Any model can be used without pre-registration.

---

## Common Patterns

### File Attachments to Multiple Models

```php
class File_Attachment_Model extends Rsx_Model_Abstract
{
    protected static $type_ref_columns = ['fileable_type'];

    public function fileable()
    {
        return $this->morphTo();
    }
}

// Attach to contact
$attachment->fileable_type = 'Contact_Model';
$attachment->fileable_id = $contact->id;

// Attach to project
$attachment->fileable_type = 'Project_Model';
$attachment->fileable_id = $project->id;
```

### Activity Log

```php
class Activity_Model extends Rsx_Model_Abstract
{
    protected static $type_ref_columns = ['subject_type'];

    public function subject()
    {
        return $this->morphTo();
    }
}

// Log activity for any model
Activity_Model::log('updated', $contact);  // subject_type = 'Contact_Model'
Activity_Model::log('created', $project);  // subject_type = 'Project_Model'
```

---

## Simple Names Only

Always use simple class names (basename), never FQCNs:

```php
// [OK] CORRECT - class_basename($model)
$activity->eventable_type = 'Contact_Model';

// [NO] WRONG - fully qualified (get_class($model))
$activity->eventable_type = 'App\\Models\\Contact_Model';
```

## More Information

Details: `php artisan rsx:man polymorphic`
