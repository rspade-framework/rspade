<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete columns for the actor tables.
 *
 * The actor model layer (Rsx_Actor_Model_Abstract / Rsx_Site_Actor_Model_Abstract)
 * MANDATES SoftDeletes: an identity that has signed in or stamped authorship must never
 * be erasable by an ordinary delete(), because every audit column pointing at it would
 * become a dangling reference and get_printed_name() could no longer answer.
 *
 * `users` already carried deleted_at. `login_users` and `portal_users` did not - this
 * migration converges them so the trait the abstract installs has a column to write.
 *
 * The matching deleted_by column is added by the schema-hygiene pass
 * (Migrate_Normalize_Schema_Command), which adds it to any table that has deleted_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['login_users', 'portal_users'] as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` ADD COLUMN deleted_at TIMESTAMP(3) NULL DEFAULT NULL");
            DB::statement("ALTER TABLE `{$table}` ADD KEY idx_{$table}_deleted_at (deleted_at)");
        }
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
