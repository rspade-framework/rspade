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
            CREATE TABLE email_queue (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                to_address VARCHAR(255) NOT NULL,
                to_name VARCHAR(255) NULL DEFAULT NULL,
                subject VARCHAR(500) NOT NULL,
                template VARCHAR(255) NOT NULL,
                template_data JSON NULL,
                category_id BIGINT NOT NULL DEFAULT 2,
                rendered_html LONGTEXT NULL,
                status_id BIGINT NOT NULL DEFAULT 1,
                error TEXT NULL,
                retry_count BIGINT NOT NULL DEFAULT 0,
                retry_at TIMESTAMP(3) NULL DEFAULT NULL,
                dev_original_to VARCHAR(255) NULL DEFAULT NULL,
                sent_at TIMESTAMP(3) NULL DEFAULT NULL,
                opened_at TIMESTAMP(3) NULL DEFAULT NULL,
                related_type BIGINT NULL DEFAULT NULL,
                related_id BIGINT NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT DEFAULT NULL,
                updated_by BIGINT DEFAULT NULL,

                KEY idx_email_queue_site_id (site_id),
                KEY idx_email_queue_status_id (status_id),
                KEY idx_email_queue_category_id (category_id),
                KEY idx_email_queue_to_address (to_address),
                KEY idx_email_queue_created_at (created_at),
                KEY idx_email_queue_retry_at (retry_at),
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
