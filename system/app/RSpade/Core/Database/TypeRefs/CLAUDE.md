# Type References System

## Overview

Polymorphic type references map class names to integer IDs so a polymorphic discriminator
can be stored as a BIGINT rather than a repeated VARCHAR class name, while developers keep
working with human-readable simple class names.

**The pair standard**: a polymorphic reference is ALWAYS `{relation}_type` (BIGINT, declared
in the owning model's `$type_ref_columns`) + `{relation}_id` (BIGINT). Two parallel nullable
FKs is the forbidden shape. A `type_id` enum column is NOT a type ref - different concept,
different suffix.

## How It Works

1. **Database Storage**: Polymorphic `*_type` columns store BIGINT integers
2. **PHP Interface**: Developers work with class name strings (`'Contact_Model'`)
3. **Automatic Mapping**: `Rsx_Type_Ref_Cast` converts between the two
4. **Auto-Registration**: New class names are registered on first use
5. **Stock Eloquent**: morph relations work over the integer column unchanged

## Components

### Type_Ref_Registry

Static registry that manages the class name → ID mapping:

```php
// Get ID for class (auto-creates if new)
$id = Type_Ref_Registry::class_to_id('Contact_Model');

// Get class name for ID
$class = Type_Ref_Registry::id_to_class($id);

// Check existence
Type_Ref_Registry::has_class('Contact_Model');
Type_Ref_Registry::has_id(42);

// Refresh cache after manual DB changes
Type_Ref_Registry::refresh();

// The CLEANUP-MIGRATION seam: the id for a class that may no longer exist.
// No auto-create, no manifest validation, null when the registry never heard of it.
Type_Ref_Registry::find_id_by_class_name('Old_Model');
```

`class_to_id()` / `id_to_class()` both REFUSE a class that no longer exists in the
codebase (see RETIRED TYPE REFS below), which makes them unusable in a migration that
cleans up after a retired model - that is exactly what `find_id_by_class_name()` is for.
Never query `_type_refs` directly instead.

### Rsx_Type_Ref_Cast

Eloquent cast that handles transparent conversion:

```php
// In model, declare type_ref_columns
protected static $type_ref_columns = ['fileable_type'];

// Then use normally - cast handles conversion
$attachment->fileable_type = 'Contact_Model';  // Stored as integer
echo $attachment->fileable_type;                // Reads as 'Contact_Model'
```

### Type_Ref_Model

Simple Eloquent model for the `_type_refs` table. Rarely used directly.

## Database Schema

```sql
_type_refs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(255) UNIQUE,
    table_name VARCHAR(255) NULL,
    created_at TIMESTAMP(3),
    updated_at TIMESTAMP(3)
)
```

## Caching Strategy

1. **Redis Cache**: Build-scoped, invalidates on manifest rebuild
2. **Memory Cache**: Static properties survive within a request
3. **Database**: Source of truth, queried on cache miss

## Laravel Integration - the dual-alias morph map

`Type_Ref_Registry::register_morph_map()` runs at framework boot
(`Rsx_Framework_Provider`, right after `Manifest::init()`) and registers EVERY type ref
under **two** aliases pointing at the same FQCN:

```php
'Contact_Model' => Rsx\Models\Contact_Model::class   // the simple class name
'4'             => Rsx\Models\Contact_Model::class   // the type-ref integer id
```

The integer alias is the entire mechanism that makes stock polymorphism work here.
`HasRelationships::morphTo()` reads the **raw** attribute (`getAttributeFromArray`), which
bypasses the cast and yields the integer, then resolves it through
`Model::getActualClassNameForMorph()` → `Relation::getMorphedModel()` - plain morph-map
lookups. Registering the id AS an alias turns that raw integer into the right class with no
framework workaround. PHP coerces a numeric-string array key to an int, so a raw `4` and a
raw `"4"` both hit the same entry.

### ALIAS ORDER IS LOAD-BEARING

`Model::getMorphClass()` answers with `array_search(static::class, $morphMap, true)` - the
**first** key that maps to the class. That value is what a WRITE puts into the type column:
`morphTo()->associate()`, `morphMany()`'s own `where(type, morphClass)` constraint,
`whereMorphedTo()`. It must be the CLASS-NAME alias, so `Rsx_Type_Ref_Cast::set()` can
convert it to the integer. If the integer alias came first, `getMorphClass()` would answer
`"4"` and the cast would try to register a model class literally named `4`.

So `register_morph_map()` inserts every class-name alias BEFORE any integer alias, and
`_create_type_ref()` (incremental registration) passes `[$class_name => $fqcn, (string)$id => $fqcn]`
in that order. `Relation::morphMap($map, merge: true)` computes `$map + $existing`, which
preserves the new array's order first. Do not reorder either.

Pinned by `tests/polymorphic/php/Polymorphic_Morph_Relations_Test.php`.

### What works

```php
$attachment->fileable;                              // morphTo, lazy read (needs #[Relationship])
$attachment->fileable()->withTrashed()->first();    // the relation object
$attachment->fileable()->associate($contact);       // write
$attachment->fileable()->dissociate();              // clear the pair
$contact->morphMany(File_Attachment_Model::class, 'fileable');
$contact->morphOne(File_Attachment_Model::class, 'fileable');
Activity_Model::whereMorphedTo('eventable', $contact);
Activity_Model::whereHasMorph('eventable', [Contact_Model::class]);
```

Property access (`$attachment->fileable`) requires `#[Relationship]` on the method - RSX
overrides `isRelation()` to consider only attributed methods. Calling the method returns the
relation object, which is what you want for `withTrashed()`.

### Boundaries

- **Eager loading is forbidden framework-wide** (`->with()` throws). Not a type-ref limit.
- **A half-set pair fails loud**: `_type` NULL with `_id` set makes Eloquent build a
  degenerate query and throw. Write both columns together or neither.
- **A retired `_type_refs` row does not self-heal, but it is never silent**: see RETIRED
  TYPE REFS below. Reading throws (relation, cast), writing is refused, and
  `whereHasMorph($rel, '*')` produces the same named error. Prefer explicit type lists.
- **Pivot morphs (`morphToMany`/`morphedByMany`) are unsupported**: the discriminator lives
  on a pivot table no model owns, so no `$type_ref_columns` declaration can cover it. Model
  the join table as a real model with its own pair. POLY-01 flags them.

## Retired type refs (a model class that no longer exists)

A deleted or renamed model leaves its `_type_refs` row behind. That row used to keep
behaving like a valid type ref everywhere except the one path that resolves a relation,
where it produced `Class name must be a valid object or a string` - naming no table, no
column, no row and no class. Every path now fails loud instead:

| Path | Behavior |
|---|---|
| `register_morph_map()` | registers the retired id under a POISON alias (`Retired_Type_Ref`) and logs one `Log::warning` naming every retired `(id, class_name)` pair. It NEVER throws at boot - a retired ref nothing references must not brick the app. |
| `morphTo()` / `whereHasMorph($rel, '*')` | `new $class` hits the poison class, whose constructor throws `Type ref 3 ("Event_Model") names a model class that no longer exists in the codebase...` |
| `Rsx_Type_Ref_Cast::get()` -> `id_to_class()` | throws the same message instead of returning a phantom class name |
| `Rsx_Type_Ref_Cast::set()` -> `class_to_id()` | REFUSES, on the cached path too, so a retired model cannot accrue NEW references |
| `find_id_by_class_name()` | the ONE lookup that still answers - no validation, no auto-create (cleanup migrations) |

**The poison mechanism**: Laravel's morph map maps alias => class-name string and does
`new $class`, which gives the constructed class no way to learn which alias produced it.
Naming the id therefore needs one class per retired ref, and there is no file to autoload
(the ids are data), so `Retired_Type_Ref::poison_class_for()` declares
`...\TypeRefs\Poison\Retired_Type_Ref_{id}` with a single `eval()` over a fully
controlled template - and only ever runs when a retired type ref actually exists.

**Auditing and pruning**:

```bash
php artisan rsx:health            # WARN per retired row + referencing table.column (count)
php artisan rsx:type_refs:prune   # drop retired rows nothing references; REFUSE the rest
```

`Type_Ref_Audit` is the shared implementation (`unresolvable_type_refs()`,
`reference_counts()`, `type_ref_columns_by_table()`); the reference sweep reads every
model's `_type_ref_columns()` - the model's own declaration UNIONED with the framework
audit pairs, which are type refs on every table. It runs only when something is already
retired. Retirement checklist: `rsx:man polymorphic`, BOUNDARIES.

## Query Builder Integration

`RestrictedEloquentBuilder` converts type_ref columns in WHERE clauses:

```php
// All of these work transparently - class names auto-converted to IDs
File_Attachment_Model::where('fileable_type', 'Contact_Model')->get();
File_Attachment_Model::where('fileable_type', '=', 'Contact_Model')->get();
File_Attachment_Model::where(['fileable_type' => 'Contact_Model'])->get();

// whereIn also works
File_Attachment_Model::whereIn('fileable_type', ['Contact_Model', 'Project_Model'])->get();

// Integer IDs still work (pass-through)
File_Attachment_Model::where('fileable_type', 42)->get();
```

Supported methods: `where()`, `orWhere()`, `whereNot()`, `orWhereNot()`, `whereIn()`,
`orWhereIn()`, `whereNotIn()`, `orWhereNotIn()` - the two-argument short form included
(the siblings delegate to `where()` with a fixed arity, so each converts its own short
form before that arity is lost). A closure/nested `where()` receives another
`RestrictedEloquentBuilder`, so clauses inside it convert too.

**Qualified column names are accepted** (`portal_notifications.subject_type`) when the
qualifier names the builder's OWN table. This is required, not cosmetic: Eloquent's morph
relations qualify the morph type column themselves (`HasRelationships::morphMany()` passes
`$table.'.'.$type`, `whereHasMorph()` calls `qualifyColumn()`), so without it the relation's
own constraint would compare an unconverted class-name string against a BIGINT column -
which MySQL coerces to 0, silently matching nothing. A qualifier naming a DIFFERENT table is
deliberately not accepted: that column belongs to a joined table whose declaration is not
this builder's to read.

**An unresolvable value THROWS.** On a declared type-ref column (bare or self-qualified) a
value that is neither null nor an integer is resolved through `Type_Ref_Registry`, and a
failed resolution raises a `RuntimeException` naming the model, the column and the value -
it is never bound as a string. A class-name-shaped string reaching a BIGINT comparison is
never intentional, and binding it would produce the silent empty result set this seam
exists to prevent.

## Polymorphic Join Helpers

Join tables with polymorphic columns using dedicated helpers:

```php
// Get contacts that have attachments (INNER JOIN)
Contact_Model::query()
    ->joinMorph('file_attachments', 'fileable')
    ->select('contacts.*', 'file_attachments.filename')
    ->get();

// Get all contacts, with attachments if they exist (LEFT JOIN)
Contact_Model::query()
    ->leftJoinMorph('file_attachments', 'fileable')
    ->get();

// Explicit class (when querying from a different model)
SomeModel::query()
    ->leftJoinMorph('file_attachments', 'fileable', Contact_Model::class)
    ->get();
```

Available methods: `joinMorph()`, `leftJoinMorph()`, `rightJoinMorph()`

Parameters:
- `$table` - Table with polymorphic columns (e.g., `'file_attachments'`)
- `$morphName` - Column prefix (e.g., `'fileable'` for `fileable_type`/`fileable_id`)
- `$morphClass` - Optional class name (defaults to current model)

## Adding New Polymorphic Models

1. Migration: `{relation}_type BIGINT NULL`, `{relation}_id BIGINT NULL`, indexed as a pair.
2. Declare the column:
   ```php
   protected static $type_ref_columns = ['attachable_type'];
   ```
3. Relate with stock Eloquent:
   ```php
   #[Relationship]
   public function attachable() { return $this->morphTo(); }
   ```
4. Write simple class names (`$model->attachable_type = class_basename($related);`) or use
   `attachable()->associate($related)`.

Converting an EXISTING VARCHAR column (seed the registry, map the data, widen the column,
decide explicitly about unmappable rows): `php artisan rsx:man polymorphic`, CONVERTING
EXISTING COLUMNS.

## Enforcement

**POLY-01** (`CodeQuality/Rules/Manifest/MorphStringPattern_CodeQualityRule.php`) is
manifest-build FATAL, AST-based and cross-file. It flags Laravel's string-morph pattern: a
`morphTo()` whose type column is not in the DECLARING model's `$type_ref_columns`, a
`morphOne()`/`morphMany()` whose type column is not in the RELATED model's, any
`morphToMany()`/`morphedByMany()`, and any morph type column not ending in `_type`. It skips
calls whose owning model cannot be resolved statically (the rule is fatal; it never guesses).
Migrations are not covered - the manifest does not scan them - so a VARCHAR `*_type` column
is caught at the model that relates over it. Escape hatch: `@POLY-01-EXCEPTION - <rationale>`.

## Important Notes

- **Simple Names Only**: Always use simple class names (`Contact_Model`), never FQCNs
- **Auto-Registration**: New classes are auto-registered when first used
- **Transparent**: After setup, code works identically to VARCHAR storage
- **Query Logs**: Show integer IDs, not class names (expected behavior)

## Reference

- `php artisan rsx:man polymorphic` - Full documentation
- `/system/app/RSpade/upstream_changes/type_refs_12_27.txt` - Original type-ref migration
- `/system/app/RSpade/upstream_changes/polymorphic_morph_transparency_08_09.txt` - the
  string-morph conversion downstream apps must perform
- `/system/app/RSpade/tests/polymorphic/` - behavior tests + catalog
