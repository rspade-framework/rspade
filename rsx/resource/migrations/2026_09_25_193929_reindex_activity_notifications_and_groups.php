<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Migrations must be self-contained.
     *
     * action_logs: every activity feed is site_id = ? ORDER BY created_at DESC, so
     * (site_id, created_at) replaces (site_id); nothing filters on the actor.
     *
     * notifications: the dropdown reads site_id, user_id ORDER BY created_at DESC and the
     * expiry delete reads site_id = ? AND expires_at < now, so those two composites replace
     * (site_id, user_id) and (expires_at); entity and type are never a predicate.
     *
     * user_groups: the duplicate-name check and the name sort are both per tenant, so
     * (site_id, name) replaces (name) and makes (site_id) its redundant prefix; deleted_at is a
     * soft-delete residual only. user_group_members.user_group_id is the prefix of its UNIQUE
     * (user_group_id, user_id) key, which the foreign key binds to instead.
     *
     * Every table keeps an index LEADING with created_at and one leading with updated_at
     * (migrate:normalize_schema guarantees both, detecting coverage by the leading column).
     * Where a migration-named index duplicated the one normalization added under the column's
     * own name, the named copy is the one dropped: the survivor is then the index
     * normalization re-creates on a database replayed from scratch, so both paths converge.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE action_logs
                DROP INDEX idx_action_logs_created_at,
                DROP INDEX idx_action_logs_site_id,
                DROP INDEX idx_action_logs_actor,
                ADD INDEX idx_action_logs_site_created (site_id, created_at)
        ");

        DB::statement("
            ALTER TABLE notifications
                DROP INDEX idx_notifications_created,
                DROP INDEX idx_notifications_site_user,
                DROP INDEX idx_notifications_expires,
                DROP INDEX idx_notifications_entity,
                DROP INDEX idx_notifications_type,
                ADD INDEX idx_notifications_site_user_created (site_id, user_id, created_at),
                ADD INDEX idx_notifications_site_expires (site_id, expires_at)
        ");

        DB::statement("
            ALTER TABLE user_groups
                DROP INDEX user_groups_name_idx,
                DROP INDEX user_groups_site_id_idx,
                DROP INDEX user_groups_deleted_at_idx,
                ADD INDEX user_groups_site_name_idx (site_id, name)
        ");

        DB::statement("
            ALTER TABLE user_group_members DROP INDEX user_group_members_group_id_idx
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
