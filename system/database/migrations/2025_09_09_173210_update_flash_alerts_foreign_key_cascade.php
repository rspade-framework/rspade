<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement() with raw SQL
     * ❌ Schema::create() with Blueprint
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
        // Drop the existing foreign key constraint
        DB::statement("ALTER TABLE flash_alerts DROP FOREIGN KEY flash_alerts_ibfk_1");
        
        // Recreate the foreign key with ON DELETE CASCADE
        DB::statement("
            ALTER TABLE flash_alerts 
            ADD CONSTRAINT flash_alerts_ibfk_1 
            FOREIGN KEY (session_id) 
            REFERENCES sessions(id) 
            ON DELETE CASCADE
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};