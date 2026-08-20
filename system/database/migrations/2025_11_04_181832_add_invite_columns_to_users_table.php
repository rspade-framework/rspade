<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE users ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Add invite-related columns to users table
        // These support the Slack-style invitation workflow where invitations
        // are represented as users records in a pending state

        // invite_code: Unique code used in acceptance URL (/accept-invite/{code})
        DB::statement("
            ALTER TABLE users
            ADD COLUMN invite_code VARCHAR(100) DEFAULT NULL,
            ADD UNIQUE KEY uk_users_invite_code (invite_code)
        ");

        // invite_accepted_at: NULL until user accepts invite
        // When user accepts, this is set and login_user_id is populated
        DB::statement("
            ALTER TABLE users
            ADD COLUMN invite_accepted_at TIMESTAMP(3) NULL DEFAULT NULL,
            ADD INDEX idx_users_invite_accepted_at (invite_accepted_at)
        ");

        // invite_expires_at: Default 7 days from creation (configurable)
        // Invites cannot be accepted after expiration
        DB::statement("
            ALTER TABLE users
            ADD COLUMN invite_expires_at TIMESTAMP(3) NULL DEFAULT NULL,
            ADD INDEX idx_users_invite_expires_at (invite_expires_at)
        ");

        // For existing users (already accepted), set accepted_at to created_at
        // This marks them as accepted vs pending invites
        DB::statement("
            UPDATE users
            SET invite_accepted_at = created_at
            WHERE login_user_id IS NOT NULL
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
