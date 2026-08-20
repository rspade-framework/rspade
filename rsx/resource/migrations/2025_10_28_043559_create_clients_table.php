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
        // Add new columns to existing clients table
        DB::statement("
            ALTER TABLE clients
                ADD COLUMN email VARCHAR(255) NULL AFTER website,
                ADD COLUMN fax VARCHAR(50) NULL AFTER phone,
                ADD COLUMN address_street VARCHAR(255) NULL COMMENT 'renamed from address',
                ADD COLUMN address_country VARCHAR(100) NULL DEFAULT 'USA',
                ADD COLUMN industry VARCHAR(100) NULL,
                ADD COLUMN company_size VARCHAR(20) NULL COMMENT '1-10, 11-50, 51-200, 201-500, 501-1000, 1000+',
                ADD COLUMN established_year BIGINT NULL,
                ADD COLUMN revenue_range VARCHAR(50) NULL,
                ADD COLUMN facebook_url VARCHAR(255) NULL,
                ADD COLUMN twitter_handle VARCHAR(100) NULL,
                ADD COLUMN linkedin_url VARCHAR(255) NULL,
                ADD COLUMN instagram_handle VARCHAR(100) NULL,
                ADD COLUMN tags JSON NULL,
                ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active, inactive, prospect, archived',
                ADD COLUMN preferred_contact_method VARCHAR(20) NULL DEFAULT 'email' COMMENT 'email, phone, text, any',
                ADD COLUMN newsletter_opt_in TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL,
                ADD INDEX idx_email (email),
                ADD INDEX idx_status (status),
                ADD INDEX idx_deleted_at (deleted_at)
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
