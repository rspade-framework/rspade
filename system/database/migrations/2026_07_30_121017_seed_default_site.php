<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the Default site (id 0).
     *
     * Sessionless code (a #[Task] or CLI invocation) has no session site, so
     * Rsx_Site_Model_Abstract::get_current_site_id() resolves to 0 and site-scoped
     * inserts persist site_id=0. This row gives those inserts a valid FK target and
     * makes the site-0 blocklist scope real (id 0 is not special-cased in queries).
     *
     * The migration runner does NOT enable NO_AUTO_VALUE_ON_ZERO, so an explicit
     * id=0 into an AUTO_INCREMENT PK would silently become 1. We flip that SQL mode
     * on for the INSERT, then restore it - mirroring the raw-INSERT seed precedent in
     * 2025_11_19_231708_create_test_user_record.php. INSERT IGNORE keeps it idempotent.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('SET @rsx_old_sql_mode := @@SESSION.sql_mode');
        DB::statement("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");

        DB::statement("
            INSERT IGNORE INTO sites (
                id,
                slug,
                name,
                is_enabled,
                created_at,
                updated_at
            ) VALUES (
                0,
                'default',
                'Default',
                1,
                NOW(3),
                NOW(3)
            )
        ");

        DB::statement('SET SESSION sql_mode = @rsx_old_sql_mode');
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
