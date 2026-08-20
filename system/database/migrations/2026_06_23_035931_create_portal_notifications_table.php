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
        // Per-recipient portal notification / activity record.
        //
        // Framework-core primitive (T4): the bus that portal features
        // (announcements, portal requests, activity feed) emit into. One row per
        // recipient portal user - emitting to N users writes N rows. This is the
        // right shape for a per-user "what's new" feed at SMB scale.
        //
        // App-agnostic: no FK to application tables. The portal user is core
        // (portal_users), so site_id + portal_user_id FKs are fine. App-specific
        // subjects are referenced POLYMORPHICALLY via the type-ref system
        // (subject_type is a BIGINT type-ref id, subject_id the row id) - never a
        // hard FK to clients/projects/etc.
        DB::statement("
            CREATE TABLE portal_notifications (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                portal_user_id BIGINT NOT NULL,
                type VARCHAR(64) NOT NULL,
                subject_type BIGINT NULL,
                subject_id BIGINT NULL,
                payload JSON NULL,
                read_at TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT NULL,
                updated_by BIGINT NULL,

                INDEX idx_portal_notifications_site_id (site_id),
                INDEX idx_portal_notifications_recipient (site_id, portal_user_id, created_at),
                INDEX idx_portal_notifications_unread (site_id, portal_user_id, read_at),
                INDEX idx_portal_notifications_type (type),
                INDEX idx_portal_notifications_subject (subject_type, subject_id),

                CONSTRAINT fk_portal_notifications_portal_user
                    FOREIGN KEY (portal_user_id) REFERENCES portal_users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
