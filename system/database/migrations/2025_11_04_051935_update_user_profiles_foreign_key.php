<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE users ADD COLUMN age BIGINT")
     * ❌ Schema::table() with Blueprint
     * 
     * REQUIRED: ALL tables MUST have: id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY
     * No exceptions - every table needs this exact ID column (SIGNED for easier migrations)
     * 
     * Integer types: Use BIGINT for all integers, TINYINT(1) for booleans only
     * Never use unsigned - all integers should be signed
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Update user_profiles foreign key to reference new users table (site-specific)
        // Previously: user_id → old users table (login identity)
        // Now: user_id → new users table (site-specific profile)
        //
        // Note: The FK was already dropped in the previous migration (refactor_site_users_to_users_table)

        // Step 1: Update existing user_profiles records to reference new users.id
        // The old users.id becomes login_users.id
        // We need to find the corresponding site-specific users.id
        // For single-site setup (site_id=1), there's a 1:1 mapping
        DB::statement("
            UPDATE user_profiles up
            INNER JOIN users u ON u.login_user_id = up.user_id AND u.site_id = 1
            SET up.user_id = u.id
        ");

        // Step 2: Add new foreign key constraint
        DB::statement("
            ALTER TABLE user_profiles
            ADD CONSTRAINT fk_user_profiles_user_id
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
