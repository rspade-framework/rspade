<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE portal_users ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE portal_users ADD COLUMN contact_id BIGINT NULL DEFAULT NULL AFTER site_id");
        DB::statement("ALTER TABLE portal_users ADD INDEX idx_portal_users_contact_id (contact_id)");
        DB::statement("ALTER TABLE portal_users ADD CONSTRAINT fk_portal_users_contact_id FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
