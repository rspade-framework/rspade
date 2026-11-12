<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * login_user_password_resets - the staff "Forgot your password?" links.
 *
 * One row per link minted for a login identity. NOT site-scoped: a login identity spans every
 * site it belongs to, and so does its password. token_hash is the SHA-256 of the secret the
 * emailed link carries - the secret itself is never stored, so a copy of this table opens no
 * account. A row is used up by used_at (set once, by an atomic claim, or when a newer link,
 * a completed reset or an administrator's reset invalidates it) and dies at expires_at.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE login_user_password_resets (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                login_user_id BIGINT NOT NULL,
                token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                expires_at TIMESTAMP(3) NOT NULL,
                used_at TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

                UNIQUE KEY uk_login_user_password_resets_token_hash (token_hash),
                KEY idx_login_user_password_resets_login_user_id (login_user_id, used_at),
                FOREIGN KEY (login_user_id) REFERENCES login_users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
