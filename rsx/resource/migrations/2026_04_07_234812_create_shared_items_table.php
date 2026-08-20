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
            CREATE TABLE shared_items (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                shared_by BIGINT NOT NULL,
                contact_id BIGINT NOT NULL,
                item_type BIGINT NOT NULL,
                item_id BIGINT NOT NULL,
                token VARCHAR(64) NOT NULL,
                message TEXT NULL,
                expires_at TIMESTAMP(3) NULL DEFAULT NULL,
                accessed_at TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT DEFAULT NULL,
                updated_by BIGINT DEFAULT NULL,

                UNIQUE KEY uk_shared_items_token (token),
                KEY idx_shared_items_site_id (site_id),
                KEY idx_shared_items_shared_by (shared_by),
                KEY idx_shared_items_contact_id (contact_id),
                KEY idx_shared_items_item_type_id (item_type, item_id),
                KEY idx_shared_items_expires_at (expires_at),
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
