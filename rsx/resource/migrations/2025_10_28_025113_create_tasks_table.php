<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement() with raw SQL
     * ❌ Schema::create() with Blueprint
     * 
     * REQUIRED: ALL tables MUST have: id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY
     * No exceptions - every table needs this exact ID column (SIGNED for easier migrations)
     * 
     * Integer types: Use BIGINT for all integers, TINYINT(1) for booleans only
     * Never use unsigned - all integers should be signed
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE tasks (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description LONGTEXT,
                taskable_type VARCHAR(255) NOT NULL,
                taskable_id BIGINT NOT NULL,
                status BIGINT NOT NULL DEFAULT 1,
                priority BIGINT NOT NULL DEFAULT 2,
                due_date DATE NULL,
                completed_date DATE NULL,
                assigned_to_user_id BIGINT NULL,
                notes LONGTEXT,
                created_by BIGINT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                updated_by BIGINT NULL,

                INDEX idx_site_id (site_id),
                INDEX idx_taskable (taskable_type, taskable_id),
                INDEX idx_status (status),
                INDEX idx_priority (priority),
                INDEX idx_assigned_to_user_id (assigned_to_user_id),
                INDEX idx_created_by (created_by),
                INDEX idx_created_at (created_at),
                INDEX idx_updated_at (updated_at),
                INDEX idx_title (title)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
