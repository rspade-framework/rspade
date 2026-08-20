<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE users ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER email");
        DB::statement("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name");
        DB::statement("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER last_name");

        DB::statement("ALTER TABLE users ADD INDEX idx_first_name (first_name)");
        DB::statement("ALTER TABLE users ADD INDEX idx_last_name (last_name)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
