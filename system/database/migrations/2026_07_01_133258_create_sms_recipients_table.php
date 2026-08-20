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
        // Per-number SMS blocklist + delivery stats (mirrors email_recipients).
        DB::statement("
            CREATE TABLE sms_recipients (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                number VARCHAR(32) NOT NULL,
                is_blocked_notification TINYINT(1) NOT NULL DEFAULT 0,
                is_blocked_marketing TINYINT(1) NOT NULL DEFAULT 0,
                is_blocked_all TINYINT(1) NOT NULL DEFAULT 0,
                total_sent BIGINT NOT NULL DEFAULT 0,
                total_failed BIGINT NOT NULL DEFAULT 0,
                last_sent_at TIMESTAMP(3) NULL DEFAULT NULL,
                unsubscribed_at TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT DEFAULT NULL,
                updated_by BIGINT DEFAULT NULL,

                UNIQUE KEY uk_sms_recipients_site_number (site_id, number),
                KEY idx_sms_recipients_site_id (site_id),
                KEY idx_sms_recipients_number (number),
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
