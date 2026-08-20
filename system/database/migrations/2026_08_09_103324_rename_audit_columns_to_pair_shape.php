<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give the framework audit columns the polymorphic PAIR shape.
     *
     *   created_by  ->  created_by_id  +  created_by_type   (BIGINT type ref)
     *   updated_by  ->  updated_by_id  +  updated_by_type   (BIGINT type ref)
     *
     * The bare `created_by` name was a bare integer of unstated species: some call sites wrote
     * a site-scoped users.id, others a cross-site login_users.id, and nothing recorded which.
     * The pair makes the species explicit and matches the framework-wide polymorphic standard
     * ({relation}_type + {relation}_id, both BIGINT, the type stored as a _type_refs id). With
     * the pair in place Rsx_Model_Abstract::save() can stamp authorship automatically.
     *
     * The table set is DISCOVERED from information_schema rather than listed: every table in
     * the schema carries these columns (the schema-hygiene command puts them there), and a
     * downstream app has its own tables the framework cannot know about.
     *
     * Idempotent by shape: a table already carrying the *_id spelling is skipped, so this runs
     * safely after Migrate_Normalize_Schema_Command has already converged a table (the
     * normalizer runs both before and after the migration set).
     *
     * No index is added on the *_type columns. They are audit metadata, never a query path;
     * an index on the *_id column (where one already existed) survives RENAME COLUMN.
     *
     * @return void
     */
    public function up()
    {
        // Laravel's migration tracker is framework-owned with a fixed schema and never
        // receives audit columns - the same exclusion the schema-hygiene command honors.
        $excluded_tables = ['_migrations'];

        $rows = DB::select(
            "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND COLUMN_NAME IN ('created_by', 'updated_by', 'created_by_id', 'updated_by_id', 'created_by_type', 'updated_by_type')"
        );

        // table => [column => true]
        $present = [];
        foreach ($rows as $row) {
            $present[$row->table_name][$row->column_name] = true;
        }

        foreach ($present as $table => $columns) {
            if (in_array($table, $excluded_tables, true)) {
                continue;
            }

            foreach (['created_by', 'updated_by'] as $base) {
                $old = $base;
                $id_column = $base . '_id';
                $type_column = $base . '_type';

                $has_old = isset($columns[$old]);
                $has_id = isset($columns[$id_column]);

                if ($has_old && $has_id) {
                    // The rename target is already taken by a DIFFERENT column. Refusing is the
                    // only honest move: silently keeping both would leave the auto-stamp writing
                    // into somebody else's column.
                    throw new RuntimeException(
                        "Cannot rename `{$table}`.`{$old}` to `{$id_column}`: `{$id_column}` already exists on that " .
                        "table. The framework audit pair now owns the `{$id_column}`/`{$type_column}` names. Rename " .
                        "the conflicting application column to something else, then re-run the migration."
                    );
                }

                if ($has_old) {
                    DB::statement("ALTER TABLE `{$table}` RENAME COLUMN `{$old}` TO `{$id_column}`");
                    $has_id = true;
                }

                if (!$has_id) {
                    // A table that had neither spelling (nothing in the schema today, but a
                    // downstream app may differ) still gets the pair.
                    DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$id_column}` BIGINT NULL");
                }

                if (!isset($columns[$type_column])) {
                    DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$type_column}` BIGINT NULL AFTER `{$id_column}`");
                }
            }
        }
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
