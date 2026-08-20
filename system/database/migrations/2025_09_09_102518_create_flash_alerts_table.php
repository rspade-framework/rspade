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
        // Drop existing table if it exists
        DB::statement("DROP TABLE IF EXISTS flash_alerts");
        
        DB::statement("
            CREATE TABLE flash_alerts (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                class_attribute VARCHAR(255) NOT NULL DEFAULT 'alert alert-danger alert-flash',
                created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
                
                INDEX idx_session_id (session_id),
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
