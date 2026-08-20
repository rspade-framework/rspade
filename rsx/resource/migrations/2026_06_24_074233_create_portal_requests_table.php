<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Portal requests (AP-D, "Requests") - an app feature: the firm asks a client for a
 * document ("please send us X"); the client uploads files (File_Attachments under the
 * 'submission' category) and submits; the firm reviews and approves (promote files to
 * the client document library) or requests changes (with feedback).
 *
 * Site-scoped. status_id is a BIGINT enum (1=OPEN, 2=SUBMITTED, 3=CHANGES_REQUESTED,
 * 4=APPROVED). feedback holds the firm's "request changes" note. due_date is optional.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE portal_requests (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                client_id BIGINT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                status_id BIGINT NOT NULL DEFAULT 1,
                feedback TEXT NULL DEFAULT NULL,
                due_date DATE NULL DEFAULT NULL,
                created_by BIGINT NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_portal_requests_site_client (site_id, client_id),
                INDEX idx_portal_requests_status (site_id, status_id),
                CONSTRAINT fk_portal_requests_client_id
                    FOREIGN KEY (client_id) REFERENCES clients (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
