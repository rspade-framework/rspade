<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE enum ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    /**
     * Convert clients.status (varchar) to status_id (BIGINT enum)
     *
     * Mapping:
     *   'active'   => 1 (STATUS_ACTIVE)
     *   'inactive' => 2 (STATUS_INACTIVE)
     *   'prospect' => 3 (STATUS_PROSPECT)
     *   'archived' => 4 (STATUS_ARCHIVED)
     */
    public function up()
    {
        // Add new status_id column
        DB::statement("ALTER TABLE clients ADD COLUMN status_id BIGINT NOT NULL DEFAULT 1 AFTER status");

        // Migrate existing data
        DB::statement("UPDATE clients SET status_id = CASE status
            WHEN 'active' THEN 1
            WHEN 'inactive' THEN 2
            WHEN 'prospect' THEN 3
            WHEN 'archived' THEN 4
            ELSE 1
        END");

        // Add index
        DB::statement("ALTER TABLE clients ADD INDEX idx_status_id (status_id)");

        // Drop old column and index
        DB::statement("ALTER TABLE clients DROP INDEX idx_status");
        DB::statement("ALTER TABLE clients DROP COLUMN status");
    }
};
