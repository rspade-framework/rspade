<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE core_tables ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Note: clients table already has soft delete columns from previous migration

        // Add soft delete columns to contacts table
        DB::statement("ALTER TABLE contacts ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
        DB::statement("ALTER TABLE contacts ADD COLUMN deleted_by BIGINT NULL DEFAULT NULL AFTER deleted_at");
        DB::statement("ALTER TABLE contacts ADD INDEX idx_deleted_at (deleted_at)");

        // Add soft delete columns to projects table
        DB::statement("ALTER TABLE projects ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
        DB::statement("ALTER TABLE projects ADD COLUMN deleted_by BIGINT NULL DEFAULT NULL AFTER deleted_at");
        DB::statement("ALTER TABLE projects ADD INDEX idx_deleted_at (deleted_at)");

        // Add soft delete columns to tasks table
        DB::statement("ALTER TABLE tasks ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
        DB::statement("ALTER TABLE tasks ADD COLUMN deleted_by BIGINT NULL DEFAULT NULL AFTER deleted_at");
        DB::statement("ALTER TABLE tasks ADD INDEX idx_deleted_at (deleted_at)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
