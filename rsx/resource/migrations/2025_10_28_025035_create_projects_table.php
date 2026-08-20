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
            CREATE TABLE projects (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                name VARCHAR(255) NOT NULL,
                description LONGTEXT,
                client_id BIGINT NOT NULL,
                client_department_id BIGINT NULL,
                contact_id BIGINT NULL,
                status BIGINT NOT NULL DEFAULT 1,
                priority BIGINT NOT NULL DEFAULT 2,
                start_date DATE NULL,
                due_date DATE NULL,
                completed_date DATE NULL,
                budget DECIMAL(15, 2) NULL,
                notes LONGTEXT,
                created_by BIGINT NULL,
                owner_user_id BIGINT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                updated_by BIGINT NULL,

                INDEX idx_site_id (site_id),
                INDEX idx_client_id (client_id),
                INDEX idx_client_department_id (client_department_id),
                INDEX idx_contact_id (contact_id),
                INDEX idx_status (status),
                INDEX idx_priority (priority),
                INDEX idx_created_by (created_by),
                INDEX idx_owner_user_id (owner_user_id),
                INDEX idx_created_at (created_at),
                INDEX idx_updated_at (updated_at),
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
