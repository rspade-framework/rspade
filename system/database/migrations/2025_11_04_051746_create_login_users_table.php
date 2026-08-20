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
        // Create login_users table (authentication identity)
        // This holds email, password, verification status - the login credentials
        // Profile data (first_name, last_name, phone) will be on site-specific users table
        DB::statement("
            CREATE TABLE login_users (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                is_activated TINYINT(1) NOT NULL DEFAULT 0,
                is_verified TINYINT(1) NOT NULL DEFAULT 0,
                status_id BIGINT NOT NULL DEFAULT 1,
                remember_token VARCHAR(100) DEFAULT NULL,
                last_login TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by_id BIGINT DEFAULT NULL,
                created_by_type BIGINT DEFAULT NULL,
                updated_by_id BIGINT DEFAULT NULL,
                updated_by_type BIGINT DEFAULT NULL,

                UNIQUE KEY uk_login_users_email (email),
                KEY idx_login_users_is_activated (is_activated),
                KEY idx_login_users_is_verified (is_verified),
                KEY idx_login_users_status_id (status_id),
                KEY idx_login_users_created_at (created_at),
                KEY idx_login_users_updated_at (updated_at),
                KEY idx_login_users_last_login (last_login)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Migrate data from users table to login_users table
        // Copy: id, email, password, activation/verification, status, tokens, timestamps
        // EXCLUDE: first_name, last_name, phone, user_role_id (these move to site-specific users table)
        DB::statement("
            INSERT INTO login_users (
                id, email, password, is_activated, is_verified, status_id,
                remember_token, last_login, created_at, updated_at,
                created_by_id, created_by_type, updated_by_id, updated_by_type
            )
            SELECT
                id, email, password, is_activated, is_verified, status_id,
                remember_token, last_login, created_at, updated_at,
                created_by_id, created_by_type, updated_by_id, updated_by_type
            FROM users
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
