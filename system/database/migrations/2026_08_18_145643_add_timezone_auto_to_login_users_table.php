<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     *
     * @return void
     */
    public function up()
    {
        // Whether the browser's detected timezone may auto-set login_users.timezone
        // on sign-in. Defaults ON: a user who never picked a zone gets the right one
        // automatically. Manually choosing a zone in settings turns it OFF.
        DB::statement("
            ALTER TABLE login_users
            ADD COLUMN timezone_auto TINYINT(1) NOT NULL DEFAULT 1
            AFTER timezone
        ");
    }
};
