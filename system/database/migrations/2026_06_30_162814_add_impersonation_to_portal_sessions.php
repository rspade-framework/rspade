<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add staff impersonation tracking to portal sessions.
     *
     * impersonator_user_id     - the staff User_Model id who initiated the impersonation
     *                            (NULL = a normal portal login). This single column both
     *                            flags the session as impersonated and records provenance.
     * impersonation_started_at - audit / optional auto-expiry.
     * handoff_token            - single-use, short-TTL token consumed by the portal claim
     *                            route to set the rsx_portal cookie (we never put the real
     *                            session_token in a URL).
     * handoff_expires_at       - claim window for the handoff token.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE portal_sessions ADD COLUMN impersonator_user_id BIGINT NULL AFTER portal_user_id");
        DB::statement("ALTER TABLE portal_sessions ADD COLUMN impersonation_started_at TIMESTAMP(3) NULL AFTER impersonator_user_id");
        DB::statement("ALTER TABLE portal_sessions ADD COLUMN handoff_token VARCHAR(64) NULL AFTER impersonation_started_at");
        DB::statement("ALTER TABLE portal_sessions ADD COLUMN handoff_expires_at TIMESTAMP(3) NULL AFTER handoff_token");
        DB::statement("CREATE INDEX idx_portal_sessions_handoff ON portal_sessions(handoff_token)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
