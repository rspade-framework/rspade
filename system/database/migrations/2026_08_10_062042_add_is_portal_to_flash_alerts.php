<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Flash alerts become EXPERIENCE-scoped (owner ruling 2026-08-10).
 *
 * One browser has ONE session (one `rsx` cookie, one _sessions row) shared by the staff
 * app and the client portal, so session_id alone cannot say WHICH experience queued an
 * alert - and an alert queued by a portal page was being delivered to whichever page of
 * that browser rendered next, staff or portal.
 *
 * is_portal records the experience of the REQUEST that queued the alert
 * (Rsx_Portal::is_portal_request()). The reader filters on it, so a staff page reads only
 * staff-queued alerts and a portal page only portal-queued ones, and the per-session cap
 * becomes per (session_id, is_portal) - one experience's runaway queue can no longer evict
 * the other's messages.
 *
 * Existing rows default to 0 (staff). They live at most a minute, so backfill is moot.
 *
 * No new index: idx_session_id already narrows to one session, whose row count is bounded
 * by rsx.flash.max_alerts_per_session (default 50) per experience. A second index over
 * that handful of short-lived rows would cost more than it saves.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE _flash_alerts ADD COLUMN is_portal TINYINT(1) NOT NULL DEFAULT 0 AFTER session_id');
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
