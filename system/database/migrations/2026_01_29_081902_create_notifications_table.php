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
            CREATE TABLE notifications (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                type_id BIGINT NOT NULL,
                entity_type BIGINT NULL,
                entity_id BIGINT NULL,
                metadata JSON NULL,
                read_at TIMESTAMP(3) NULL,
                expires_at TIMESTAMP(3) NOT NULL,
                created_by BIGINT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                updated_by BIGINT NULL,

                INDEX idx_notifications_site_user (site_id, user_id),
                INDEX idx_notifications_user_unread (site_id, user_id, read_at),
                INDEX idx_notifications_entity (entity_type, entity_id),
                INDEX idx_notifications_expires (expires_at),
                INDEX idx_notifications_type (type_id),
                INDEX idx_notifications_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
