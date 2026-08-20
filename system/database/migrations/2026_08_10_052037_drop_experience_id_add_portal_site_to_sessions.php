<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ONE SESSION PER BROWSER. experience_id is gone; portal_site_id arrives.
     *
     * The previous migration (2026_08_09_075941) merged portal_sessions into _sessions
     * but kept TWO rows and TWO cookies per browser, told apart by an experience_id
     * discriminator. The owner ruled that design wrong (2026-08-10): a session
     * identifies a BROWSER, not an authentication realm. One `rsx` cookie, one
     * _sessions row, shared by the staff app and the client portal alike - a property
     * bag, where the EXPERIENCE is a property of the REQUEST
     * (Rsx_Portal::is_portal_request()) deciding which of the row's properties get
     * consulted. Both identities set on one row at once is normal: the same human, in
     * the same browser, signed into both.
     *
     *   staff  properties: login_user_id, site_id, impersonator_login_user_id
     *   portal properties: portal_user_id, portal_site_id, impersonator_user_id,
     *                      handoff_token, handoff_expires_at, impersonation_started_at
     *
     * site_id is now STAFF-ONLY, which is why portal_site_id exists: a browser signed
     * into both experiences can legitimately be looking at a different tenant in each,
     * and one column cannot answer both questions.
     *
     * The existing portal rows are DELETED rather than folded into their browser's
     * staff row: the two rows were minted under two different cookies and nothing on
     * disk records which staff row (if any) belongs to the same browser as a given
     * portal row - a guess would attach one person's portal identity to another
     * person's session. Portal users sign in again; their flash alerts cascade away
     * with the rows (owner ruling).
     *
     * A dedicated portal domain needs no machinery at all: per-origin cookie jars mean
     * that browser simply holds its own row on that origin, with the staff properties
     * null.
     *
     * @return void
     */
    public function up()
    {
        // --- The portal realm's rows go, not their identity model --------------------
        // experience_id 1 was Session::EXPERIENCE_PORTAL. Literal here: a migration
        // must not depend on application classes, and the constant no longer exists.
        DB::statement('DELETE FROM _sessions WHERE experience_id = 1');

        // --- Indexes that existed only to serve the discriminator ---------------------
        // session_token already carries a UNIQUE index, so the composite was never more
        // than a wider duplicate of it once the realm predicate went away.
        DB::statement('DROP INDEX idx_token_experience ON _sessions');

        // The retention sweep no longer narrows by realm. Its rules are (type_id,
        // last_active) plus an identity-nullness test, which idx_sessions_type_last_active
        // (created 2026_08_07_152553) already serves as the leftmost prefix - nothing to
        // re-create here.
        DB::statement('DROP INDEX idx_sessions_experience_type_last_active ON _sessions');

        DB::statement('ALTER TABLE _sessions DROP COLUMN experience_id');

        // --- The portal's own tenant column -------------------------------------------
        // NULL until a portal identity (or a portal site declaration) touches the row.
        DB::statement('ALTER TABLE _sessions ADD COLUMN portal_site_id BIGINT NULL AFTER portal_user_id');
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
