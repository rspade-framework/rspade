<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add an explicit status to portal invitations.
     *
     * Status becomes the authoritative validity signal (used_at / expires_at stay
     * for timing + audit). 1=PENDING, 2=USED, 3=EXPIRED, 4=REVOKED. Backfill from
     * the existing derived state: used_at set -> USED; else past expiry -> EXPIRED;
     * else PENDING.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE portal_invitations ADD COLUMN status_id BIGINT NOT NULL DEFAULT 1 AFTER invitation_code");
        DB::statement("ALTER TABLE portal_invitations ADD INDEX idx_portal_invitations_status (status_id)");

        // Backfill from the previously-derived state.
        DB::statement("UPDATE portal_invitations SET status_id = 2 WHERE used_at IS NOT NULL");
        DB::statement("UPDATE portal_invitations SET status_id = 3 WHERE used_at IS NULL AND expires_at < NOW()");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
