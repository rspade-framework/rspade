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
     * Creates _zip_download_requests: a minted, server-authorized multi-file ZIP download.
     * Server-side app code records the file set (JSON array of {key, name?}) plus an
     * optional zip_name and receives an opaque download_key; the browser then GETs
     * /_download_zip/:key. Rows are NOT consumed on use; they die by expiry (config
     * rsx.attachments.zip_request_retention_hours), enforced at serve time AND pruned by
     * Zip_Download_Cleanup_Service; the created_at index backs both the expiry and the
     * cleanup scan.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE _zip_download_requests (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                download_key VARCHAR(64) NOT NULL,
                files LONGTEXT NOT NULL,
                zip_name VARCHAR(255) NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uniq_zip_download_requests_download_key (download_key),
                INDEX idx_zip_download_requests_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
