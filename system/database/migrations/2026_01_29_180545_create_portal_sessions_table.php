<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Portal sessions are completely separate from internal sessions.
     * This ensures:
     * - Different cookie names (no session bleeding)
     * - Independent session management
     * - Simpler structure (no experience_id, no multi-site)
     *
     * Portal sessions are site-scoped - a portal user can only
     * be logged into one site at a time with a given session.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE portal_sessions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                portal_user_id BIGINT NULL,
                session_token VARCHAR(64) NOT NULL,
                csrf_token VARCHAR(64) NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NULL,
                last_active TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

                UNIQUE KEY uk_portal_sessions_token (session_token),
                KEY idx_portal_sessions_site_id (site_id),
                KEY idx_portal_sessions_portal_user_id (portal_user_id),
                KEY idx_portal_sessions_last_active (last_active),
                KEY idx_portal_sessions_site_user (site_id, portal_user_id),
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
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
