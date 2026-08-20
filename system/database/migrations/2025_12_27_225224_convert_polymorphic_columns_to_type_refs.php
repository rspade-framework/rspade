<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE type_refs ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Helper to drop index only if it exists (MySQL doesn't support DROP INDEX IF EXISTS)
        $dropIndexIfExists = function($table, $index) {
            $exists = DB::select(
                "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                [$table, $index]
            )[0]->cnt > 0;
            if ($exists) {
                DB::statement("ALTER TABLE {$table} DROP INDEX {$index}");
            }
        };

        // Convert polymorphic type columns from VARCHAR to BIGINT
        // These columns will now store type_ref IDs instead of class name strings
        // Existing data is cleared since there's no production data to migrate

        // _file_attachments: Convert fileable_type from VARCHAR to BIGINT
        // First drop the existing index that uses the VARCHAR column
        $dropIndexIfExists('_file_attachments', 'idx_fileable');
        // Clear existing data and modify column type
        DB::statement("UPDATE _file_attachments SET fileable_type = NULL");
        DB::statement("ALTER TABLE _file_attachments MODIFY fileable_type BIGINT NULL");
        // Recreate index with BIGINT column
        DB::statement("ALTER TABLE _file_attachments ADD INDEX idx_fileable (fileable_type, fileable_id)");

        // _search_indexes: Convert indexable_type from VARCHAR to BIGINT
        // First drop the existing indexes that use the VARCHAR column
        $dropIndexIfExists('_search_indexes', 'idx_indexable');
        $dropIndexIfExists('_search_indexes', 'unique_indexable');
        // Clear existing data and modify column type
        DB::statement("TRUNCATE TABLE _search_indexes");
        DB::statement("ALTER TABLE _search_indexes MODIFY indexable_type BIGINT NOT NULL");
        // Recreate indexes with BIGINT column
        DB::statement("ALTER TABLE _search_indexes ADD INDEX idx_indexable (indexable_type, indexable_id)");
        DB::statement("ALTER TABLE _search_indexes ADD UNIQUE KEY unique_indexable (indexable_type, indexable_id)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
