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
        // Fix flash_alerts table to comply with schema standards:
        // 1. Drop existing foreign key constraint
        DB::statement("ALTER TABLE flash_alerts DROP FOREIGN KEY flash_alerts_ibfk_1");
        
        // 2. Make session_id nullable
        DB::statement("ALTER TABLE flash_alerts MODIFY session_id VARCHAR(255) NULL");
        
        // 3. Add proper foreign key constraint with ON DELETE CASCADE
        DB::statement("ALTER TABLE flash_alerts ADD CONSTRAINT flash_alerts_session_fk 
            FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
