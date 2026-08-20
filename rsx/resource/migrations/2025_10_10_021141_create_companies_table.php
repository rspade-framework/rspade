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
            CREATE TABLE clients (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                name VARCHAR(255) NOT NULL,
                address TEXT NULL,
                city VARCHAR(255) NULL,
                state VARCHAR(255) NULL,
                zip VARCHAR(20) NULL,
                phone VARCHAR(50) NULL,
                phone_secondary VARCHAR(50) NULL,
                website VARCHAR(255) NULL,
                billing_contact_id BIGINT NULL,
                priority BIGINT NOT NULL DEFAULT 2,
                notes TEXT NULL,
                created_by_user_id BIGINT NULL,
                owner_user_id BIGINT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,

                INDEX idx_site_id (site_id),
                INDEX idx_name (name),
                INDEX idx_created_by_user_id (created_by_user_id),
                INDEX idx_owner_user_id (owner_user_id),
                INDEX idx_billing_contact_id (billing_contact_id),
                INDEX idx_priority (priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
