<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Portal users are external users (customers, clients, vendors) who access
     * a site through the client portal. They are completely separate from
     * internal system users (login_users).
     *
     * Key differences from login_users:
     * - Site-scoped (no multi-tenant access)
     * - Simpler auth model (no multi-site switching)
     * - Invite-only registration
     * - No remember_token (portal sessions are simpler)
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE portal_users (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                email VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                is_verified TINYINT(1) NOT NULL DEFAULT 0,
                status_id BIGINT NOT NULL DEFAULT 1,
                metadata JSON NULL,
                last_login TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT DEFAULT NULL,
                updated_by BIGINT DEFAULT NULL,

                UNIQUE KEY uk_portal_users_site_email (site_id, email),
                KEY idx_portal_users_site_id (site_id),
                KEY idx_portal_users_email (email),
                KEY idx_portal_users_status_id (status_id),
                KEY idx_portal_users_is_verified (is_verified),
                KEY idx_portal_users_created_at (created_at),
                KEY idx_portal_users_last_login (last_login),
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
