<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add main-session (staff) impersonation tracking to _sessions.
     *
     * impersonator_login_user_id - the real principal's login_user_id behind an
     *                              in-place identity swap (NULL = a normal, non-
     *                              impersonation session). This single column both
     *                              flags the session as impersonated and records the
     *                              identity to restore on stop_impersonation().
     * impersonation_started_at   - when the impersonation began (audit).
     *
     * Same-cookie, in-place model (no handoff token / throwaway row like the portal).
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE _sessions ADD COLUMN impersonator_login_user_id BIGINT NULL AFTER login_user_id");
        DB::statement("ALTER TABLE _sessions ADD COLUMN impersonation_started_at TIMESTAMP(3) NULL AFTER impersonator_login_user_id");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
