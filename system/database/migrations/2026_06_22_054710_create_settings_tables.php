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
        // Setting definitions: developer-authored metadata for each application
        // setting (the "what exists"). Seeded via Rsx_Settings::define() from
        // application migrations. Underscore-prefixed system table - accessed by
        // the Rsx_Settings facade via the query builder, no Eloquent model.
        DB::statement("
            CREATE TABLE _settings (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,

                setting_key VARCHAR(191) NOT NULL COMMENT 'Machine identifier, e.g. billing_dow_cutoff',
                label VARCHAR(255) NOT NULL COMMENT 'Human-readable name',
                description TEXT NULL,

                scope_id BIGINT NOT NULL DEFAULT 1 COMMENT '1=global, 2=site',
                type_id BIGINT NOT NULL DEFAULT 1 COMMENT '1=text 2=integer 3=decimal 4=float 5=boolean 6=date 7=datetime 8=json',

                default_value LONGTEXT NULL COMMENT 'Serialized default value',
                meta JSON NULL COMMENT 'Type-specific config (e.g. decimal scale)',

                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                setting_group VARCHAR(191) NULL COMMENT 'Optional UI grouping',
                sort_order BIGINT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,

                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,

                UNIQUE KEY uniq_setting_key (setting_key),
                INDEX idx_setting_group (setting_group)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Setting values: stored overrides (the actual configured values). One
        // row per (setting, site). site_id 0 holds a global setting's value.
        DB::statement("
            CREATE TABLE _setting_values (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,

                setting_id BIGINT NOT NULL,
                site_id BIGINT NOT NULL DEFAULT 0 COMMENT '0 = global value',
                value LONGTEXT NULL,

                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,

                UNIQUE KEY uniq_setting_site (setting_id, site_id),
                INDEX idx_setting_id (setting_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
