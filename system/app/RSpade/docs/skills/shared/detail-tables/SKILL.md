---
name: detail-tables
description: Building a class-table-inheritance entity in RSpade - a base model plus 1:1 detail tables selected by a discriminator, declared with $detail_tables and created with Rsx_Detail_Table::create(). Use when a record's fields differ by type instead of cramming every type's columns into one wide nullable table, when writing a detail model (Rsx_Detail_Model_Abstract, $parent_model), when saving a base + detail pair, when a detail accessor throws a wrong-type error, when a list of base records is issuing one query per detail (preload_details), or when responding to a DETAIL-01 finding.
---

# Detail Tables (Class-Table Inheritance)

A base model spans a base table PLUS one or more 1:1 **detail** tables, selected by a discriminator column. Type-specific fields live on the detail; universal fields stay on the base. Scope becomes a SCHEMA FACT: the table a field lives in is the constraint, and the access path (`party.party_person.first_name`) is the documentation.

The template's worked example is the `Party` entity: `/rsx/models/party_model.php` plus the staff module under `/rsx/app/frontend/party/`.

## 1. Declare the map on the base

```php
class Party_Model extends Rsx_Site_Model_Abstract
{
    public static $detail_tables = [
        'type_id' => [
            self::TYPE_PERSON  => Party_Person_Detail_Model::class,
            self::TYPE_COMPANY => Party_Company_Detail_Model::class,
            // a value absent from the map => that type has no detail
        ],
    ];
}
```

**One `$detail_tables` map, and it is the single source of truth** for "what detail a type has". Exactly one discriminator column. A discriminator value absent from the map means that type has no detail at all - all its fields are universal. Several values MAY map to the same detail model (they share one table and one accessor).

## 2. The detail model

```php
class Party_Person_Detail_Model extends Rsx_Detail_Model_Abstract
{
    protected $table = 'party_person_details';
    protected static $parent_model = Party_Model::class;
}
```

It is a **STANDARD model** - default `id` primary key, no custom `$primaryKey`/`$incrementing`. The FK column is derived from the parent basename (`Party_Model` -> `party_id`); override `$parent_key` only for a non-conventional name. The accessor name is derived from the detail class basename: `Party_Person_Detail_Model` -> `party_person`.

The detail **inherits the base's authorization**. It has no `fetch()`, no Ajax endpoint of its own - it is reachable only through the base accessor and embed.

## 3. The migration

```php
use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Table;

Rsx_Detail_Table::create('party_person_details', 'parties', [
    ['name' => 'first_name',    'type' => 'VARCHAR(255)'],
    ['name' => 'last_name',     'type' => 'VARCHAR(255)'],
    ['name' => 'date_of_birth', 'type' => 'DATE'],
]);
```

Signature: `create($detail_table, $parent_table, array $columns, $parent_key = null, $soft_deletes = false)`. The helper emits, as raw DDL:

- the framework-standard surrogate `id BIGINT AUTO_INCREMENT PRIMARY KEY`,
- `{parent}_id BIGINT NOT NULL UNIQUE` + `FOREIGN KEY ... ON DELETE CASCADE`,
- the audit columns (`created_at`/`updated_at` + the authorship pairs; the deleted pair when `$soft_deletes`),
- your columns, which **default to NULL** (declare `'nullable' => false` for NOT NULL).

**It is NOT a literal shared primary key.** The 1:1 is enforced by the UNIQUE constraint and the lifecycle by CASCADE, so every table still honors the universal id-PK rule. The base carries ZERO detail-FK columns - the discriminator selects the table.

## 4. Read - accessor, vivify, wrong-type

```php
$party->name                        // universal (base)
echo $party->party_person->first_name;   // person-only (detail)
$party->party_company;              // THROWS - wrong type for a Person
```

The accessor for the record's ACTIVE type returns the persisted detail row, or - if none exists yet - a **new unsaved detail with the parent FK already set**, cached per request so you can fill it and save it. Wrong-type access throws a clear, LOCAL error (the discriminator is on the record; no query is issued) - in PHP **and** in JS, before any `await`. Check the type first; that is the caller's job.

`toArray()` eager-embeds the active PERSISTED detail under a `__details` blob (never under the accessor key, so it cannot collide with the JS accessor method). A vivified-but-unsaved detail is never serialized.

```javascript
const p      = await Party_Model.fetch(id);  // the detail rides inside this one request
const person = await p.party_person();       // instant - resolved from the embed, NO network
await p.party_company();                     // THROWS for a Person
```

**Sets - avoid N+1.** Serializing many base records lazy-loads each detail one at a time. Batch them first:

```php
$parties = Party_Model::where(...)->get();
Party_Model::preload_details($parties);   // one query per detail table
// now each ->toArray() embeds its detail with no extra query
```

## 5. Write - save order is yours

**`save()` does NO detail magic.** The FK lives on the detail, so the detail can only be created AFTER the base has an id. Write both in the SAME transaction - this one pattern covers create and edit, every type:

```php
DB::transaction(function () use ($party, $params) {
    $party->type_id = Party_Model::TYPE_COMPANY;   // only ever set on CREATE
    $party->name    = $params['legal_name'];        // composed base column, set before save
    $party->save();

    $detail = $party->party_company;   // existing row, or new with party_id pre-set
    $detail->legal_name = $params['legal_name'];
    $detail->save();
});
```

- **Composed base columns** (a person's `name` from first + last) are ordinary endpoint code: compute and assign before `$party->save()`. There is no framework save hook.
- **Required detail fields are validated at the endpoint, NOT by the ORM** (plus the DB's own NOT NULL). The accessor never inserts a row on its own.
- **The discriminator is immutable.** Changing it on an existing record would orphan the detail, so it throws on save. Set the type only on create.
- **Delete cascades**: deleting the base deletes the detail (`ON DELETE CASCADE`).

## 6. Codegen spans both tables

The manifest merges detail columns into the base model's column map, so the base behaves as one logical model:

- `Model.field_length('first_name')` resolves detail columns - a form binds `$max_length=Party_Model.field_length('first_name')` even though the column lives on a detail.
- `rsx:constants:regenerate` documents detail columns in the base model's `@property` docblock, each annotated `(detail: <table>)`.
- The JS stub bakes the discriminator plus the value->accessor map and emits the embed-resolving accessors.
- `$enums` on a detail column work like any column - the detail model is a real model and casts its own columns.

## 7. DETAIL-01

The rule validates each `$detail_tables` declaration: exactly one discriminator column; every mapped class is an `Rsx_Detail_Model_Abstract` whose `$parent_model` points back at the base; no two distinct detail models derive the same accessor name.

**Not in v1** (do not invent it): query sugar / datagrid auto-join by a detail column (use `whereHas`), a declarative required-by-subtype validation, a guarded `change_type()`, multi-detail or nested CTI.

Details: `php artisan rsx:man detail_tables`.
