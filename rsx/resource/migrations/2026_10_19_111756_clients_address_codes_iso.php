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
        // A client's address codes are ISO, the vocabulary the country and state pickers
        // and Client_Model::country_name()/region_name() read: address_country an ISO
        // 3166-1 alpha-2 code ('US'), state an ISO 3166-2 subdivision code ('US-WA').
        DB::statement("ALTER TABLE clients ALTER COLUMN address_country SET DEFAULT 'US'");

        DB::statement("UPDATE clients SET address_country = 'US' WHERE address_country = 'USA'");

        // A bare state ('WA') becomes its country's subdivision code ('US-WA') where that
        // subdivision exists; anything else is left as it was.
        DB::statement("
            UPDATE clients c
            JOIN regions r ON r.country_alpha2 = c.address_country AND r.code = CONCAT(c.address_country, '-', c.state)
            SET c.state = r.code
            WHERE c.state NOT LIKE '%-%'
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
