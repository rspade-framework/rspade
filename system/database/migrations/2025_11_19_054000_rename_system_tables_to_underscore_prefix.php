<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename system tables to use underscore prefix
 *
 * System tables are internal framework infrastructure that developers
 * should not access directly. The underscore prefix:
 * 1. Signals these are framework-managed tables
 * 2. Exempts them from ORM requirements (DB::table() allowed)
 * 3. Clarifies architectural boundaries
 *
 * See: /system/app/RSpade/man/database_schema_architecture.txt
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
        // Rename system tables to underscore prefix
        // These are purely framework implementation details

        DB::statement("RENAME TABLE file_storage TO _file_storage");
        DB::statement("RENAME TABLE file_attachments TO _file_attachments");
        DB::statement("RENAME TABLE flash_alerts TO _flash_alerts");
        DB::statement("RENAME TABLE search_indexes TO _search_indexes");

        // Note: migrations table will be renamed manually after this migration runs
        // Note: _tasks already has underscore prefix
    }
};
