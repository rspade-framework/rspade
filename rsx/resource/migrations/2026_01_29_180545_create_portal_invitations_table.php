<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Portal invitations are single-use codes that allow external users
     * to register for portal access. The invitation flow:
     *
     * 1. Admin creates invitation with email + optional metadata
     * 2. System sends email with unique invitation code
     * 3. User clicks link, lands on portal registration page
     * 4. User sets password, account created with email verified
     * 5. Invitation marked as used (used_at set)
     *
     * Metadata allows linking portal user to business entities
     * (e.g., contact_id, client_id) - application-specific.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE portal_invitations (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                email VARCHAR(255) NOT NULL,
                invitation_code VARCHAR(64) NOT NULL,
                metadata JSON NULL,
                expires_at TIMESTAMP(3) NOT NULL,
                used_at TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT DEFAULT NULL,
                updated_by BIGINT DEFAULT NULL,

                UNIQUE KEY uk_portal_invitations_code (invitation_code),
                KEY idx_portal_invitations_site_id (site_id),
                KEY idx_portal_invitations_email (email),
                KEY idx_portal_invitations_site_email (site_id, email),
                KEY idx_portal_invitations_expires_at (expires_at),
                KEY idx_portal_invitations_used_at (used_at),
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
