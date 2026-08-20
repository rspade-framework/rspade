<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Every table carries a signed BIGINT id
     * primary key. All integers are signed; TINYINT(1) is reserved for booleans.
     * Migrations must be self-contained.
     *
     * Creates _api_request_log: one row per external API request (success and failure),
     * with nulls where identity is unknown (pre-auth failures). Pruned by the daily API
     * log cleanup task per config('rsx.api.log_retention_days').
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE _api_request_log (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                api_key_id BIGINT NULL DEFAULT NULL,
                user_id BIGINT NULL DEFAULT NULL,
                site_id BIGINT NULL DEFAULT NULL,
                verb VARCHAR(8) NOT NULL,
                path VARCHAR(2048) NOT NULL,
                handler VARCHAR(255) NULL DEFAULT NULL,
                status BIGINT NOT NULL,
                duration_ms BIGINT NOT NULL,
                ip VARCHAR(45) NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_api_request_log_created_at (created_at),
                INDEX idx_api_request_log_api_key_id (api_key_id),
                INDEX idx_api_request_log_site_id (site_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
