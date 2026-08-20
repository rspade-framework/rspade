---
name: migrations
description: RSX database migrations - make:migration:safe, raw SQL enforcement (the Schema builder is prohibited), the forward-only no-rollback philosophy, automatic schema normalization and audit columns, and the development-mode database snapshot that auto-rolls-back a failed run. Use when creating or altering a table, adding a column or index, writing or running a migration, troubleshooting a Schema-builder violation or a failed migrate, or wondering whether it is safe to run migrate.
---

# RSX Database Migrations

## Philosophy

RSX enforces a forward-only migration strategy with raw SQL:

1. **Forward-only** - No rollbacks, no `down()` methods
2. **Raw SQL only** - Direct MySQL statements, no Schema builder
3. **Fail loud** - Migrations must succeed or fail with clear errors
4. **Snapshot safety** - Development requires database snapshots

---

## Schema Builder is Prohibited

All migrations **must** use `DB::statement()` with raw SQL:

```php
// [OK] CORRECT
DB::statement("CREATE TABLE products (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00
)");

// [NO] WRONG - Schema builder prohibited
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name');
});
```

**Prohibited**: `Schema::create()`, `Schema::table()`, `Schema::drop()`, `Blueprint`, `$table->` chains

---

## Development Workflow

```bash
# 1. Create migration
php artisan make:migration:safe create_products_table

# 2. Write migration with raw SQL

# 3. Run migrations (auto-snapshot in development)
php artisan migrate
```

In development mode, `migrate` automatically:
- Creates database snapshot before running
- Commits on success (regenerates constants, recompiles bundles)
- Auto-rollbacks on failure (database restored to pre-migration state)

**Run `migrate` with its FULL output** - never pipe it through `| tail`/`| head` or
otherwise truncate: the snapshot/rollback narrative IS the diagnostic, and a truncated
run hides which step failed.

**A migrate cancelled mid-run** (Ctrl-C, killed process) leaves the migration flag and
the snapshot behind with nobody to restore them. `php artisan migrate:restore` performs
the exact restore a failed run performs automatically - and reports "No migration is in
progress - nothing to restore" when there is no flag. A FAILED restore keeps the flag
and the snapshot in place (the snapshot is the only copy of the pre-migration data).

**Never put a timeout anywhere on the snapshot/rollback path.** A 60-second cap on `run_privileged_command()` once failed a MySQL datadir snapshot on a large database, and the same cap sat unnoticed on the ROLLBACK path - where firing would have abandoned a half-copied datadir and destroyed the very state the snapshot existed to protect. A datadir copy takes as long as the data requires; slowness here is normal and is never evidence of a hang.

---

## Automatic Normalization

The system auto-normalizes types after migration. You can write simpler SQL:

| You Write | System Converts To |
|-----------|-------------------|
| `INT` | `BIGINT` |
| `TEXT` | `LONGTEXT` |
| `FLOAT` | `DOUBLE` |
| `TINYINT(1)` | Preserved (boolean) |

**Auto-added columns - NEVER declare these yourself.** The schema-hygiene pass adds `created_at`/`updated_at TIMESTAMP(3)` plus the polymorphic authorship PAIRS (`created_by_id` + `created_by_type`, `updated_by_id` + `updated_by_type`, and `deleted_by_id` + `deleted_by_type` wherever the table has `deleted_at`), and converges any older spelling by rename.

**Never reference them positionally (`AFTER updated_by`) or by a pre-pair name** - the rename would leave your `ALTER` pointing at a column that no longer exists.

---

## No Defensive Coding

A migration runs against a state you already know. Write the exact transformation.

```php
// [NO] WRONG - guessing at the current state
if (Schema::hasColumn('products', 'sku')) { ... }
DB::statement("ALTER TABLE products ADD COLUMN IF NOT EXISTS sku VARCHAR(50) NULL");
$exists = DB::select("SELECT * FROM information_schema.columns WHERE ...");

// [OK] CORRECT - know the state, state the transformation
DB::statement("ALTER TABLE products ADD COLUMN sku VARCHAR(50) NULL AFTER name");
```

No `IF EXISTS`, no `information_schema` queries, no fallbacks. **Failures fail loud** - in development the automatic snapshot rollback is what handles recovery, so a migration that guesses is hiding a defect for no benefit.

---

## Detail Tables

A 1:1 detail table for class-table inheritance is NOT hand-written DDL - use `Rsx_Detail_Table::create($detail_table, $parent_table, $columns)`, which emits the surrogate id, the UNIQUE FK, the cascade and the audit columns. See the `rspade:detail-tables` skill.

---

## Migration Examples

### Simple Table (Recommended)

```php
public function up()
{
    DB::statement("
        CREATE TABLE products (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_quantity INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            category_id INT NULL,
            INDEX idx_category (category_id),
            INDEX idx_active (is_active)
        )
    ");
}
```

**Notes**:
- `INT` becomes `BIGINT` automatically
- `TEXT` becomes `LONGTEXT` automatically
- `created_at`/`updated_at` added automatically
- `TINYINT(1)` preserved for booleans

### Adding Columns

```php
public function up()
{
    DB::statement("
        ALTER TABLE products
        ADD COLUMN sku VARCHAR(50) NULL AFTER name,
        ADD COLUMN weight DECIMAL(8,2) NULL,
        ADD INDEX idx_sku (sku)
    ");
}
```

### Foreign Keys

```php
public function up()
{
    DB::statement("
        ALTER TABLE orders
        ADD CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE CASCADE
    ");
}
```

---

## Required Table Structure

**Every table MUST have**:

```sql
id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY
```

This is non-negotiable. Use SIGNED (not UNSIGNED) for easier future migrations.

---

## Foreign Key Columns

Foreign key columns **must match** the referenced column type exactly:

```sql
-- If users.id is BIGINT, then:
user_id BIGINT NULL  -- [OK] Matches

-- Column names ending in _id are assumed to be foreign keys
```

---

## Debug/Production Workflow

```bash
# In debug or production mode (RSX_MODE=debug or production)
php artisan migrate
```

In debug/production mode:
- No snapshot protection (source code is read-only)
- Schema normalization still runs
- Constants and bundles NOT regenerated

Ensure migrations are thoroughly tested in development first.

---

## Validation

The migration validator automatically checks for:
- Schema builder usage
- `down()` methods (auto-removed)
- Proper SQL syntax

Violations show clear error messages with remediation advice.

---

## Troubleshooting

| Error | Solution |
|-------|----------|
| "Found forbidden Schema builder usage" | Replace with `DB::statement()` |
| "Validation failed" | Check migration for prohibited patterns |
| Foreign key constraint fails | Ensure column types match exactly |

## More Information

Where a migration FILE lives (framework-core vs template-app directory) is a monorepo concern and does not apply to an application - an app's migrations go in its own migrations directory.

Details: `php artisan rsx:man migrations`, `php artisan rsx:man database_schema_architecture`
