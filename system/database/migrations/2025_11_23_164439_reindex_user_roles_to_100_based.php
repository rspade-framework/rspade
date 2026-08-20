<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reindex user role_id values from sequential (1-7) to 100-based spacing.
     * Also set user 1 to ROLE_DEVELOPER (100).
     *
     * Old → New mapping:
     *   1 (ROOT_ADMIN)  → 200
     *   2 (SITE_OWNER)  → 300
     *   3 (SITE_ADMIN)  → 400
     *   4 (MANAGER)     → 500
     *   5 (USER)        → 600
     *   6 (VIEWER)      → 700
     *   7 (DISABLED)    → 800
     *
     * @return void
     */
    public function up()
    {
        // Update existing roles from old values to new 100-based values
        // Process in reverse order to avoid collision (7→800 first, then 6→700, etc.)
        DB::statement("UPDATE users SET role_id = 800 WHERE role_id = 7");
        DB::statement("UPDATE users SET role_id = 700 WHERE role_id = 6");
        DB::statement("UPDATE users SET role_id = 600 WHERE role_id = 5");
        DB::statement("UPDATE users SET role_id = 500 WHERE role_id = 4");
        DB::statement("UPDATE users SET role_id = 400 WHERE role_id = 3");
        DB::statement("UPDATE users SET role_id = 300 WHERE role_id = 2");
        DB::statement("UPDATE users SET role_id = 200 WHERE role_id = 1");

        // Set user 1 to ROLE_DEVELOPER (100)
        DB::statement("UPDATE users SET role_id = 100 WHERE id = 1");
    }
};
