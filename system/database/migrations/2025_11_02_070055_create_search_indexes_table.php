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
        DB::statement("
            CREATE TABLE search_indexes (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                indexable_type VARCHAR(255) NOT NULL,
                indexable_id BIGINT NOT NULL,
                content LONGTEXT,
                metadata JSON,
                indexed_at TIMESTAMP NULL,
                extraction_method VARCHAR(100),
                language VARCHAR(10),
                site_id BIGINT NOT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                created_by BIGINT NULL,
                updated_by BIGINT NULL,
                FULLTEXT INDEX ft_content (content),
                INDEX idx_indexable (indexable_type, indexable_id),
                INDEX idx_site (site_id),
                INDEX idx_language (language),
                INDEX idx_indexed_at (indexed_at),
                UNIQUE KEY unique_indexable (indexable_type, indexable_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
