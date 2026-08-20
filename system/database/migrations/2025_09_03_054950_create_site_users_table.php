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
            CREATE TABLE site_users (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                site_id BIGINT NOT NULL,
                first_name VARCHAR(100) NULL DEFAULT NULL,
                last_name VARCHAR(100) NULL DEFAULT NULL,
                role_id BIGINT NULL DEFAULT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uk_site_users_user_site (user_id, site_id),
                INDEX idx_site_users_site_id (site_id),
                INDEX idx_site_users_role_id (role_id),
                INDEX idx_site_users_is_enabled (is_enabled),
                INDEX idx_site_users_deleted_at (deleted_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
