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
        // Rename user_id to login_user_id in sessions table
        // Sessions track the authentication identity (login_user), not the site-specific user

        // Step 1: Drop existing indexes on user_id
        DB::statement("ALTER TABLE sessions DROP INDEX sessions_user_id_index");
        DB::statement("ALTER TABLE sessions DROP INDEX sessions_user_id_active_index");

        // Step 2: Rename column
        DB::statement("ALTER TABLE sessions CHANGE COLUMN user_id login_user_id BIGINT DEFAULT NULL");

        // Step 3: Recreate indexes with new column name
        DB::statement("CREATE INDEX idx_sessions_login_user_id ON sessions(login_user_id)");
        DB::statement("CREATE INDEX idx_sessions_login_user_id_active ON sessions(login_user_id, active)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
