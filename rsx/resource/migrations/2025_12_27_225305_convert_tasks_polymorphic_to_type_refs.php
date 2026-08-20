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
        // Convert tasks.taskable_type from VARCHAR to BIGINT for type_refs system
        // Existing data is cleared since there's no production data to migrate

        // Drop existing index if it exists (MySQL doesn't support IF EXISTS in ALTER TABLE)
        $index_exists = DB::select("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND INDEX_NAME = 'idx_taskable'
        ")[0]->cnt > 0;

        if ($index_exists) {
            DB::statement("ALTER TABLE tasks DROP INDEX idx_taskable");
        }

        // Clear existing data and modify column type
        DB::statement("TRUNCATE TABLE tasks");
        DB::statement("ALTER TABLE tasks MODIFY taskable_type BIGINT NOT NULL");
        // Recreate index with BIGINT column
        DB::statement("ALTER TABLE tasks ADD INDEX idx_taskable (taskable_type, taskable_id)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
