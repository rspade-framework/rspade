<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE status_id ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Rename status column to status_id to match enum naming convention
        DB::statement("ALTER TABLE users CHANGE COLUMN status status_id INT NOT NULL DEFAULT 1");

        // Recreate the index with the new column name
        DB::statement("ALTER TABLE users DROP INDEX idx_status");
        DB::statement("ALTER TABLE users ADD INDEX idx_status_id (status_id)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
