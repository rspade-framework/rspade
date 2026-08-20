<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Portal password reset tokens allow users to reset their password.
     * Tokens are single-use and expire after a configurable time period.
     *
     * Flow:
     * 1. User requests password reset with their email
     * 2. System creates token and sends email with reset link
     * 3. User clicks link, lands on password reset page
     * 4. User sets new password
     * 5. Token marked as used (used_at set)
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE portal_password_resets (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                portal_user_id BIGINT NOT NULL,
                token VARCHAR(128) NOT NULL,
                expires_at TIMESTAMP(3) NOT NULL,
                used_at TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

                UNIQUE KEY uk_portal_password_resets_token (token),
                KEY idx_portal_password_resets_site_id (site_id),
                KEY idx_portal_password_resets_portal_user_id (portal_user_id),
                KEY idx_portal_password_resets_expires_at (expires_at),
                KEY idx_portal_password_resets_used_at (used_at),
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                FOREIGN KEY (portal_user_id) REFERENCES portal_users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
