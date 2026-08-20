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
        // Add status column (1=active, 2=inactive, 3=suspended)
        DB::statement("ALTER TABLE users ADD COLUMN status INT NOT NULL DEFAULT 1 AFTER is_verified");
        DB::statement("ALTER TABLE users ADD INDEX idx_status (status)");

        // Add user_role_id column (1=read_only, 2=standard, 3=admin, 4=billing_admin, 5=root_admin)
        DB::statement("ALTER TABLE users ADD COLUMN user_role_id INT NOT NULL DEFAULT 2 AFTER status");
        DB::statement("ALTER TABLE users ADD INDEX idx_user_role_id (user_role_id)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
