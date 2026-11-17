<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * projects.hourly_rate - the rate a new time entry on the project is priced at.
 *
 * Money (DECIMAL(12,2)), NULL when the project has no rate. A time entry copies it when it is
 * created and keeps its own copy, so changing the project's rate never reprices an entry.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE projects ADD COLUMN hourly_rate DECIMAL(12,2) NULL DEFAULT NULL AFTER budget");
    }
};
