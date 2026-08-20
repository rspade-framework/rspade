<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE sessions ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Add experience_id column to sessions table
        // Experience ID represents the authentication realm (default=0, staff=1, customer=2, etc.)
        // Allows users to be logged into multiple experiences simultaneously
        DB::statement("ALTER TABLE sessions ADD COLUMN experience_id INT DEFAULT 0 NOT NULL");

        // Add index for experience_id lookups
        DB::statement("ALTER TABLE sessions ADD INDEX idx_experience_id (experience_id)");

        // Add composite index for common query pattern (token + experience lookup)
        DB::statement("ALTER TABLE sessions ADD INDEX idx_token_experience (session_token, experience_id)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
