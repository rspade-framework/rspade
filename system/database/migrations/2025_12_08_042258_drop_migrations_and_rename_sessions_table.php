<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clean up legacy migrations table and rename sessions to _sessions
 *
 * 1. Drop 'migrations' table if it exists (should only be '_migrations')
 * 2. Rename 'sessions' to '_sessions' for consistency with other system tables
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Drop legacy 'migrations' table if it exists
        // Laravel should only use '_migrations' as configured in database.php
        if (Schema::hasTable('migrations')) {
            DB::statement("DROP TABLE migrations");
        }

        // Rename sessions to _sessions for system table consistency
        // First drop foreign key from _flash_alerts
        if (Schema::hasTable('_flash_alerts')) {
            DB::statement("ALTER TABLE _flash_alerts DROP FOREIGN KEY flash_alerts_session_fk");
        }

        // Rename the table
        DB::statement("RENAME TABLE sessions TO _sessions");

        // Re-add foreign key constraint
        if (Schema::hasTable('_flash_alerts')) {
            DB::statement("ALTER TABLE _flash_alerts ADD CONSTRAINT flash_alerts_session_fk FOREIGN KEY (session_id) REFERENCES _sessions(id) ON DELETE CASCADE");
        }
    }
};
