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
        // Countries table - ISO 3166-1 data
        DB::statement("
            CREATE TABLE countries (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                alpha2 CHAR(2) NOT NULL UNIQUE COMMENT 'ISO 3166-1 alpha-2 code (US, CA, GB)',
                alpha3 CHAR(3) NOT NULL COMMENT 'ISO 3166-1 alpha-3 code (USA, CAN, GBR)',
                `numeric` CHAR(3) NOT NULL COMMENT 'ISO 3166-1 numeric code (840, 124, 826)',
                name VARCHAR(255) NOT NULL COMMENT 'Official country name',
                common_name VARCHAR(255) NULL COMMENT 'Common name if different from official',
                enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status - disable instead of delete to preserve FKs',
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_alpha2 (alpha2),
                INDEX idx_alpha3 (alpha3),
                INDEX idx_enabled (enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Regions table - ISO 3166-2 subdivisions (states, provinces, territories, etc.)
        DB::statement("
            CREATE TABLE regions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(10) NOT NULL COMMENT 'ISO 3166-2 code (US-CA, CA-ON, GB-ENG)',
                country_alpha2 CHAR(2) NOT NULL COMMENT 'Foreign key to countries.alpha2',
                name VARCHAR(255) NOT NULL COMMENT 'Subdivision name (California, Ontario, England)',
                type VARCHAR(50) NULL COMMENT 'Type: state, province, territory, country, etc.',
                enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status - disable instead of delete',
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_country (country_alpha2),
                INDEX idx_code (code),
                INDEX idx_enabled (enabled),
                UNIQUE KEY unique_country_code (country_alpha2, code),
                CONSTRAINT fk_regions_country
                    FOREIGN KEY (country_alpha2)
                    REFERENCES countries(alpha2)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Uncomment after verifying seeder works:
        // \Illuminate\Support\Facades\Artisan::call('rsx:seed:geographic-data');
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
