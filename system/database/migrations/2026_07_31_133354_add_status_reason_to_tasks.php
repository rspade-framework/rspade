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
        // Free-text reason for a terminal (or recycled) status transition - notably the
        // required explanation when a task is force-killed via rsx:tasks:kill / kill-all
        // (status KILLED). Parallels the existing `error` column; NULL on ordinary rows.
        DB::statement("ALTER TABLE _tasks ADD COLUMN status_reason TEXT NULL COMMENT 'Human explanation for a terminal/recycled status transition (e.g. KILLED reason)' AFTER error");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
