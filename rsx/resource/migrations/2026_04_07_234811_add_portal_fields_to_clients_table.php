<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE clients ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE clients ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER newsletter_opt_in");
        DB::statement("ALTER TABLE clients ADD COLUMN portal_last_activity_at TIMESTAMP(3) NULL DEFAULT NULL AFTER portal_enabled");
        DB::statement("ALTER TABLE clients ADD INDEX idx_clients_portal_enabled (portal_enabled)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
