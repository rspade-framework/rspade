<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Announcements (AP-C) - an app feature: staff post a notice to a client's portal
 * members (or site-wide); publishing emits one Portal_Notification (T4) per
 * targeted portal user so it lands in each recipient's activity feed (T5).
 *
 * Site-scoped. client_id is NULL for a site-wide announcement (every active portal
 * member of any client on the site). published_at NULL = draft (not yet broadcast).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE announcements (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                client_id BIGINT NULL DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                published_at TIMESTAMP NULL DEFAULT NULL,
                created_by BIGINT NULL DEFAULT NULL,
                updated_by BIGINT NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_announcements_site_client (site_id, client_id),
                INDEX idx_announcements_published (site_id, published_at),
                CONSTRAINT fk_announcements_client_id
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
