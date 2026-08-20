<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give the DELETION audit column the same polymorphic PAIR shape the authorship columns
     * received in 2026_08_09_103324_rename_audit_columns_to_pair_shape:
     *
     *   deleted_by  ->  deleted_by_id  +  deleted_by_type   (BIGINT type ref)
     *
     * Same reasoning as created_by/updated_by: a bare integer could not say WHICH identity
     * model it referenced (User_Model, Login_User_Model or Portal_User_Model), and the pair
     * is what lets Rsx_Model_Abstract stamp the deleter automatically.
     *
     * SCOPE - THIS PAIR IS NARROWER THAN THE OTHER TWO, ON PURPOSE. created_by/updated_by go
     * on every table because every row is created and updated. A row is only ever DELETED-BY
     * somebody if its table soft-deletes: a hard delete removes the row, leaving nothing to
     * stamp. So the deleted pair follows `deleted_at`, which is exactly the rule the
     * schema-hygiene command has always applied to the bare `deleted_by` column
     * (Migrate_Normalize_Schema_Command: "for all tables with soft deletes, ensure a
     * deleted_by column is present"). Migration, normalizer and stamp therefore agree on one
     * table set.
     *
     * A table carrying a bare `deleted_by` WITHOUT `deleted_at` is still converged rather than
     * left behind - it already owns the name, and the framework now owns its shape.
     *
     * Discovery is information_schema-driven, not a table list: a downstream app has its own
     * soft-deleting tables the framework cannot know about.
     *
     * Idempotent by shape, so it is safe after the normalizer has already converged a table
     * (the normalizer runs both before and after the migration set).
     *
     * No index on deleted_by_type - audit metadata, never a query path.
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
                AND COLUMN_NAME IN ('deleted_at', 'deleted_by', 'deleted_by_id', 'deleted_by_type')"
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

            $has_old = isset($columns['deleted_by']);
            $has_id = isset($columns['deleted_by_id']);

            if ($has_old && $has_id) {
                // The rename target is already taken by a DIFFERENT column. Refusing is the
                // only honest move: silently keeping both would leave the delete-time stamp
                // writing into somebody else's column.
                throw new RuntimeException(
                    "Cannot rename `{$table}`.`deleted_by` to `deleted_by_id`: `deleted_by_id` already exists on " .
                    'that table. The framework audit pair now owns the `deleted_by_id`/`deleted_by_type` names. ' .
                    'Rename the conflicting application column to something else, then re-run the migration.'
                );
            }

            if ($has_old) {
                DB::statement("ALTER TABLE `{$table}` RENAME COLUMN `deleted_by` TO `deleted_by_id`");
                $has_id = true;
            }

            // Out of scope for creation: a table that never soft-deletes and has never carried
            // the column. Nothing could ever be written there.
            $in_scope = $has_id || isset($columns['deleted_at']);
            if (!$in_scope) {
                continue;
            }

            if (!$has_id) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `deleted_by_id` BIGINT NULL");
            } else {
                // An older normalizer created this column as INT(11); the framework standard is
                // BIGINT, and the id it holds is an actor primary key.
                DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `deleted_by_id` BIGINT NULL");
            }

            if (!isset($columns['deleted_by_type'])) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `deleted_by_type` BIGINT NULL AFTER `deleted_by_id`");
            }
        }
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
