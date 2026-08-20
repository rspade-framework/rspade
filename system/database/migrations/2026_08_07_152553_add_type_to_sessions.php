<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give sessions a TYPE, purge the historical idle backlog, and index the two
     * access patterns that have to stay bounded.
     *
     * type_id (Session::TYPE_WEB / TYPE_CLI / TYPE_API) is stamped by the writer at
     * creation - never inferred from the user-agent afterwards - and is what the
     * hourly cleanup sweep expires against (rsx.sessions.*_timeout_minutes). Every
     * pre-existing row is a browser session, so the backfill is a flat TYPE_WEB.
     *
     * The one-time purge (idle > 14 days) clears the bloat that accumulated while
     * nothing expired machine sessions: one observed box held 68,712 rows, 62,617 of
     * them for a single login user, which was enough to exhaust a 128MB PHP process
     * the moment anything read a user's session list. Deleted in bounded chunks so a
     * months-deep backlog never becomes one lock-holding statement.
     *
     * Indexes:
     * - idx_sessions_type_last_active     - the cleanup sweep's per-type range scan.
     * - idx_sessions_user_active_recent   - login_user_id + active + last_active, so
     *   "this user's N most recent sessions" is an index-ordered LIMIT rather than a
     *   filesort over the user's lifetime session count. It fully covers (and so
     *   replaces) the two prefix indexes that existed before it.
     *
     * @return void
     */
    public function up()
    {
        // One-time fleet-wide purge of the idle backlog, in bounded batches. Runs
        // BEFORE the ALTER so the column addition rewrites only the surviving rows.
        $cutoff = now()->subDays(14);
        while (true) {
            $deleted = DB::table('_sessions')
                ->where('last_active', '<', $cutoff)
                ->limit(10000)
                ->delete();

            if ($deleted < 10000) {
                break;
            }
        }

        // NOT NULL DEFAULT 1 backfills every surviving row as TYPE_WEB: they all
        // predate classification, and they are all browser sessions.
        DB::statement('ALTER TABLE _sessions ADD COLUMN type_id BIGINT NOT NULL DEFAULT 1 AFTER site_id');

        DB::statement('CREATE INDEX idx_sessions_type_last_active ON _sessions (type_id, last_active)');
        DB::statement('CREATE INDEX idx_sessions_user_active_recent ON _sessions (login_user_id, active, last_active)');

        // Redundant prefixes of idx_sessions_user_active_recent.
        DB::statement('DROP INDEX idx_sessions_login_user_id_active ON _sessions');
        DB::statement('DROP INDEX idx_sessions_login_user_id ON _sessions');

        // Same treatment for the portal's own session table, whose "N most recent
        // for this user" list has the identical shape. Portal sessions carry no type
        // (a portal session is a browser session by definition) - they expire on
        // rsx.sessions.portal_timeout_minutes.
        DB::statement('CREATE INDEX idx_portal_sessions_user_recent ON portal_sessions (portal_user_id, last_active)');
        DB::statement('DROP INDEX idx_portal_sessions_portal_user_id ON portal_sessions');
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
