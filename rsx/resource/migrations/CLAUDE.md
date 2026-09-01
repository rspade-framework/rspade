# Migration Guidelines

## A migration never references application code

A migration is a **forward-only historical record that must replay cleanly from scratch
forever**. A model class is **current code** that gets renamed and deleted. The moment a
migration names one, a from-scratch replay of the whole chain hard-fails at a file nobody
has touched in a year.

So a migration contains **no model class, no `Type_Ref_Registry`, no service** - only raw
SQL and plain PHP. `rsx:check` enforces it as **MIGRATION-MODEL-01**.

## Polymorphic type references

`Type_Ref_Registry::class_to_id('Foo_Model')` is the usual way this rule gets broken. It
validates the name against the LIVE MANIFEST, so the day `Foo_Model` is retired every
replay dies with `Cannot create type ref for 'Foo_Model': Class not found in manifest.`

Resolve the id with a **get-or-create against `_type_refs`**, using the class-name STRING
and a hardcoded table name:

```php
$type_ref_id = function (string $class_name, string $table_name): int {
    $existing = DB::select("SELECT id FROM _type_refs WHERE class_name = ?", [$class_name]);
    if (!empty($existing)) {
        return (int) $existing[0]->id;
    }
    DB::statement(
        "INSERT INTO _type_refs (class_name, table_name, created_at, updated_at) VALUES (?, ?, NOW(3), NOW(3))",
        [$class_name, $table_name]
    );
    return (int) DB::getPdo()->lastInsertId();
};

$contact_id = $type_ref_id('Contact_Model', 'contacts');
DB::statement("UPDATE activities SET eventable_type = {$contact_id} WHERE eventable_type = 'Contact_Model'");
```

A class-name **string literal is data**, not a symbol: it needs no class to exist, and the
rule never flags one. Ids are still not hardcoded - the closure resolves or creates the
row at replay time, so it is correct on a fresh install and in every environment.

**A table rename is followed into `_type_refs` automatically** - write the plain
`RENAME TABLE a TO b` (or `ALTER TABLE a RENAME TO b`) and nothing else; the migrate
pipeline updates `_type_refs.table_name` in the same run and prints the ref it moved.

## The one exception

Data seeding that genuinely needs model BEHAVIOUR raw SQL cannot reproduce - a file
pipeline, an encryption cast, a factory with side effects. That migration is knowingly
coupled to code that may be deleted, and says so in its docblock:

```php
/**
 * @MIGRATION-MODEL-01-EXCEPTION <why raw SQL cannot do this>
 */
```

The rationale is required; a bare marker is itself a violation. Schema work and type-ref
lookups are never the exception - convert those.

Details: `php artisan rsx:man migrations`, `php artisan rsx:man polymorphic`.
