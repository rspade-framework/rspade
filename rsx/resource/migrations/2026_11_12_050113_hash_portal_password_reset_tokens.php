<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * portal_password_resets stores the SHA-256 of a link's secret, never the secret.
 *
 * The column becomes token_hash, and every existing row is converted in place by hashing the
 * token it held - so a link already sitting in somebody's mailbox still works, and from here
 * on a copy of the table opens no account. Shared with login_user_password_resets
 * (Rsx\Lib\PasswordReset\Password_Reset_Token).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE portal_password_resets
                ADD COLUMN token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER portal_user_id
        ");

        DB::statement("UPDATE portal_password_resets SET token_hash = SHA2(token, 256)");

        DB::statement("
            ALTER TABLE portal_password_resets
                DROP INDEX uk_portal_password_resets_token,
                DROP COLUMN token,
                MODIFY COLUMN token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                ADD UNIQUE KEY uk_portal_password_resets_token_hash (token_hash)
        ");
    }
};
