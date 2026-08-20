<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Merge portal_sessions into _sessions. After this migration there is ONE RSpade
     * session table.
     *
     * portal_sessions was ~85% a rename of _sessions: same token/csrf/ip/user-agent/
     * last_active/type_id shape, same lifecycle, a parallel implementation of the same
     * idea. The only structural difference was WHICH identity column it carried. That
     * is a discriminator, not a table: _sessions has carried experience_id since it was
     * created ("support multiple authentication realms"), indexed alongside the token,
     * always 0, with no callers - it was built for exactly this and never used.
     *
     *   experience_id = 0  -> Session::EXPERIENCE_STAFF
     *   experience_id = 1  -> Session::EXPERIENCE_PORTAL
     *
     * Two cookies and two rows per browser REMAIN (rsx / rsx_portal): a dedicated portal
     * domain cannot share a cookie with the staff app. What goes away is the second
     * table, the second model, and the second copy of every retention/cap/termination
     * rule that had to be kept in step with the first.
     *
     * Row ids CHANGE (auto-increment reassigns them). Nothing in the schema or the code
     * persists a portal session id: information_schema shows the only session FK anywhere
     * is _flash_alerts.session_id -> _sessions.id, and the realtime connection registry
     * holds ids only in redis, which is flushed on every maintenance window and re-seeded
     * by the client's reconnect resync. Sessions RESUME BY TOKEN, and the tokens are
     * carried across verbatim, so a live portal login survives this migration.
     *
     * New columns are portal-realm columns; a staff row leaves them NULL and vice versa.
     * impersonator_user_id is deliberately NOT merged into impersonator_login_user_id:
     * they are different id spaces (a portal session's impersonator is a staff users.id,
     * a staff session's is a login_users.id), and collapsing them would make the column
     * unreadable without first knowing the realm.
     *
     * @return void
     */
    public function up()
    {
        // --- Portal-realm columns on the shared table -------------------------------
        DB::statement('ALTER TABLE _sessions ADD COLUMN portal_user_id BIGINT NULL AFTER login_user_id');
        DB::statement('ALTER TABLE _sessions ADD COLUMN impersonator_user_id BIGINT NULL AFTER impersonator_login_user_id');
        DB::statement('ALTER TABLE _sessions ADD COLUMN handoff_token VARCHAR(64) NULL AFTER csrf_token');
        DB::statement('ALTER TABLE _sessions ADD COLUMN handoff_expires_at TIMESTAMP(3) NULL AFTER handoff_token');

        // --- Carry every portal session across --------------------------------------
        // experience_id 1 = Session::EXPERIENCE_PORTAL (literal here: a migration must
        // not depend on application classes). active/version get the staff defaults -
        // portal logic ignores `active` (it ends a session by deleting the row) and
        // version is per-row write bookkeeping that starts at 1 for any new row.
        // last_active crosses timestamp(3) -> datetime(3); the server, both connections
        // and every stored value are UTC, so the conversion is an identity.
        DB::statement(
            'INSERT INTO _sessions ('
            . 'active, site_id, type_id, login_user_id, portal_user_id, '
            . 'impersonator_login_user_id, impersonator_user_id, impersonation_started_at, '
            . 'session_token, csrf_token, handoff_token, handoff_expires_at, '
            . 'ip_address, user_agent, last_active, version, experience_id, '
            . 'created_at, updated_at, created_by_id, created_by_type, updated_by_id, updated_by_type'
            . ') SELECT '
            . '1, site_id, type_id, NULL, portal_user_id, '
            . 'NULL, impersonator_user_id, impersonation_started_at, '
            . 'session_token, csrf_token, handoff_token, handoff_expires_at, '
            . 'ip_address, user_agent, last_active, 1, 1, '
            . 'created_at, updated_at, created_by_id, created_by_type, updated_by_id, updated_by_type '
            . 'FROM portal_sessions'
        );

        // --- Indexes the portal access patterns need on their new home ---------------
        // (portal_user_id, last_active) mirrors idx_portal_sessions_user_recent: the
        // device-session list and the sign-in cap. It also gives the FK below its
        // leftmost index.
        DB::statement('CREATE INDEX idx_sessions_portal_user_recent ON _sessions (portal_user_id, last_active)');

        // Single-use impersonation handoff lookup (mirrors idx_portal_sessions_handoff).
        DB::statement('CREATE INDEX idx_sessions_handoff ON _sessions (handoff_token)');

        // The hourly retention sweep is now realm-aware: every rule narrows by
        // experience_id first, then type, then last_active.
        DB::statement('CREATE INDEX idx_sessions_experience_type_last_active ON _sessions (experience_id, type_id, last_active)');

        // Redundant leftmost prefix of the index just created.
        DB::statement('DROP INDEX idx_experience_id ON _sessions');

        // Deleting a portal user has always taken their sessions with them
        // (portal_sessions_ibfk_2). Preserved here rather than silently dropped.
        // The site_id cascade portal_sessions also had CANNOT be preserved: staff rows
        // legitimately carry site_id 0, which is not a sites.id.
        DB::statement(
            'ALTER TABLE _sessions ADD CONSTRAINT sessions_portal_user_fk '
            . 'FOREIGN KEY (portal_user_id) REFERENCES portal_users (id) ON DELETE CASCADE'
        );

        // --- The second session table is gone ----------------------------------------
        DB::statement('DROP TABLE portal_sessions');
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
