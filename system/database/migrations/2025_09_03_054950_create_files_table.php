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
            CREATE TABLE files (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `key` VARCHAR(128) NOT NULL,
                file_hash_id BIGINT NOT NULL,
                file_name VARCHAR(255) NULL DEFAULT NULL,
                file_extension VARCHAR(20) NULL DEFAULT NULL,
                file_type_id BIGINT NULL DEFAULT NULL,
                file_size BIGINT NOT NULL,
                fileable_type VARCHAR(100) NULL DEFAULT NULL,
                fileable_id BIGINT NULL DEFAULT NULL,
                fileable_category VARCHAR(50) NULL DEFAULT NULL,
                fileable_order BIGINT NULL DEFAULT NULL,
                site_id BIGINT NOT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uk_files_key (`key`),
                INDEX idx_files_file_hash_id (file_hash_id),
                INDEX idx_files_site_id (site_id),
                INDEX idx_files_fileable (fileable_type, fileable_id),
                INDEX idx_files_file_type_id (file_type_id),
                FOREIGN KEY (file_hash_id) REFERENCES file_hashes(id) ON DELETE RESTRICT,
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
