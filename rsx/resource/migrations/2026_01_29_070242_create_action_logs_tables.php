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
        // Main action log table
        DB::statement("
            CREATE TABLE action_logs (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                type_id BIGINT NOT NULL,
                actor_type BIGINT NULL,
                actor_id BIGINT NULL,
                subject_type BIGINT NOT NULL,
                subject_id BIGINT NOT NULL,
                metadata JSON NULL,
                created_by BIGINT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                updated_by BIGINT NULL,

                INDEX idx_action_logs_site_id (site_id),
                INDEX idx_action_logs_type_id (type_id),
                INDEX idx_action_logs_actor (actor_type, actor_id),
                INDEX idx_action_logs_subject (subject_type, subject_id),
                INDEX idx_action_logs_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Related entities table (one-to-many from action_logs)
        DB::statement("
            CREATE TABLE action_log_related (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                action_log_id BIGINT NOT NULL,
                role_id BIGINT NOT NULL,
                related_type BIGINT NOT NULL,
                related_id BIGINT NOT NULL,

                INDEX idx_alr_action_log_id (action_log_id),
                INDEX idx_alr_related (related_type, related_id),
                FOREIGN KEY (action_log_id) REFERENCES action_logs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
