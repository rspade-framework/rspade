<?php

namespace App\RSpade\Core\Database\DetailTables;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rsx_Detail_Table - schema helper for Class-Table Inheritance (CTI) detail tables.
 *
 * A "detail table" holds the type-specific field set for one discriminator value of a
 * base model (see man detail_tables). It joins 1:1 to the base on a UNIQUE foreign key
 * and cascade-deletes with it. This helper emits the CREATE TABLE as raw SQL via
 * DB::statement (the sanctioned migration path - Schema::create/Blueprint are forbidden),
 * so it composes automatically with SqlQueryTransformer (type normalization + utf8mb4).
 *
 * Physical shape (decision: surrogate id + UNIQUE FK, NOT a literal shared PK - honors
 * the framework's universal "every table has id AUTO_INCREMENT PK" convention):
 *
 *   CREATE TABLE {detail_table} (
 *       id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 *       {parent}_id BIGINT NOT NULL,           -- UNIQUE: enforces the 1:1
 *       ...caller columns (nullable by default so auto-create shells succeed)...,
 *       created_at / updated_at / created_by_id / created_by_type / updated_by_id / updated_by_type,
 *       [deleted_at / deleted_by_id / deleted_by_type when $soft_deletes],
 *       UNIQUE KEY uk_{detail_table}_{parent}_id ({parent}_id),
 *       FOREIGN KEY ({parent}_id) REFERENCES {parent_table}(id) ON DELETE CASCADE
 *   )
 *
 * Usage (from a migration):
 *   Rsx_Detail_Table::create('party_person_details', 'parties', [
 *       ['name' => 'first_name', 'type' => 'VARCHAR(255)'],
 *       ['name' => 'last_name',  'type' => 'VARCHAR(255)'],
 *       ['name' => 'date_of_birth', 'type' => 'DATE'],
 *   ]);
 */
class Rsx_Detail_Table
{
    /**
     * Create a CTI detail table.
     *
     * @param string      $detail_table The detail table name (e.g. 'party_person_details')
     * @param string      $parent_table The base table name (e.g. 'parties')
     * @param array       $columns      Type-specific columns: each ['name'=>, 'type'=>, 'nullable'?=>bool, 'default'?=>string]
     * @param string|null $parent_key   FK column name; defaults to Str::singular($parent_table) . '_id'
     * @param bool        $soft_deletes Add deleted_at + the deleted_by pair (mirror the parent's delete semantics)
     */
    public static function create(string $detail_table, string $parent_table, array $columns, ?string $parent_key = null, bool $soft_deletes = false): void
    {
        DB::statement(static::build_sql($detail_table, $parent_table, $columns, $parent_key, $soft_deletes));
    }

    /**
     * Derive the FK column name for a detail table joining $parent_table.
     * 'parties' -> 'party_id', 'companies' -> 'company_id'.
     */
    public static function parent_key_for(string $parent_table): string
    {
        return Str::singular($parent_table) . '_id';
    }

    /**
     * Build the CREATE TABLE SQL (pure - no I/O). Exposed for testing.
     */
    public static function build_sql(string $detail_table, string $parent_table, array $columns, ?string $parent_key = null, bool $soft_deletes = false): string
    {
        $fk = $parent_key ?: static::parent_key_for($parent_table);

        $lines = [];
        $lines[] = "id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY";
        $lines[] = "{$fk} BIGINT NOT NULL";

        foreach ($columns as $column) {
            $lines[] = static::__build_column_line($column);
        }

        // Framework audit columns (every table carries these - no exclusion).
        $lines[] = "created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3)";
        $lines[] = "updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)";
        $lines[] = "created_by_id BIGINT DEFAULT NULL";
        $lines[] = "created_by_type BIGINT DEFAULT NULL";
        $lines[] = "updated_by_id BIGINT DEFAULT NULL";
        $lines[] = "updated_by_type BIGINT DEFAULT NULL";

        if ($soft_deletes) {
            $lines[] = "deleted_at TIMESTAMP(3) NULL DEFAULT NULL";
            $lines[] = "deleted_by_id BIGINT DEFAULT NULL";
            $lines[] = "deleted_by_type BIGINT DEFAULT NULL";
        }

        // UNIQUE FK enforces the 1:1; CASCADE ties the detail's lifecycle to the base.
        $lines[] = "UNIQUE KEY uk_{$detail_table}_{$fk} ({$fk})";
        $lines[] = "FOREIGN KEY ({$fk}) REFERENCES {$parent_table}(id) ON DELETE CASCADE";

        $body = implode(",\n                ", $lines);

        return "CREATE TABLE {$detail_table} (\n                {$body}\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /**
     * Build a single column definition line. Detail columns default to NULL so that a
     * default shell row can be auto-created on save (see the auto-create-shell contract).
     */
    private static function __build_column_line(array $column): string
    {
        if (!isset($column['name']) || !isset($column['type'])) {
            shouldnt_happen("Rsx_Detail_Table column spec requires 'name' and 'type': " . json_encode($column));
        }

        $nullable = $column['nullable'] ?? true;
        $line = "{$column['name']} {$column['type']}" . ($nullable ? " NULL" : " NOT NULL");

        if (array_key_exists('default', $column)) {
            $line .= " DEFAULT {$column['default']}";
        }

        return $line;
    }
}
