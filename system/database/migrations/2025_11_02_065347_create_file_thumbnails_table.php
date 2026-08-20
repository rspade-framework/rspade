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
            CREATE TABLE file_thumbnails (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                source_storage_id BIGINT NOT NULL,
                thumbnail_storage_id BIGINT NOT NULL,
                params TEXT NOT NULL,
                detected_mime_type VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                created_by BIGINT NULL,
                updated_by BIGINT NULL,
                INDEX idx_source_storage (source_storage_id),
                INDEX idx_thumbnail_storage (thumbnail_storage_id),
                INDEX idx_source_params (source_storage_id, detected_mime_type(50)),
                FOREIGN KEY (source_storage_id) REFERENCES file_storage(id) ON DELETE CASCADE,
                FOREIGN KEY (thumbnail_storage_id) REFERENCES file_storage(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
