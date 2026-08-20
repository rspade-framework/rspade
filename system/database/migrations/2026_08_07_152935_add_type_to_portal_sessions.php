<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give portal sessions the same writer-stamped type as staff sessions.
     *
     * The portal runs a parallel session system with the same lifecycle problem: the
     * rsx:debug harness mints a portal session per run, and without a type those rows
     * are indistinguishable from a client's real login and would sit for the full
     * portal window. Typed, they expire on rsx.sessions.cli_timeout_minutes and the
     * harness can delete its own on exit.
     *
     * Values are Session::TYPE_* (shared vocabulary; TYPE_API has no portal writer).
     *
     * @return void
     */
    public function up()
    {
        DB::statement('ALTER TABLE portal_sessions ADD COLUMN type_id BIGINT NOT NULL DEFAULT 1 AFTER site_id');
        DB::statement('CREATE INDEX idx_portal_sessions_type_last_active ON portal_sessions (type_id, last_active)');
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
