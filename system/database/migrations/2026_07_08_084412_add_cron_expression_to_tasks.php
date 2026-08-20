<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE tasks ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Stored cron expression for recurring #[Schedule] tracker rows (rows with
        // next_run_at IS NOT NULL). Lets the scheduler detect a #[Schedule] edit by
        // comparing the manifest expression to this stored value, and lets a worker
        // advance next_run_at from the row itself before executing a due cron task.
        // NULL on all non-cron rows (dispatched / run_background one-shots).
        DB::statement("ALTER TABLE _tasks ADD COLUMN cron_expression VARCHAR(120) NULL COMMENT 'Cron expression for recurring scheduled tracker rows (NULL otherwise)' AFTER next_run_at");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
