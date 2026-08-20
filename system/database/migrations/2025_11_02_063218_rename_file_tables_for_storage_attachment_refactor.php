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
        // Rename file_hashes table to file_storage
        DB::statement("RENAME TABLE file_hashes TO file_storage");

        // Drop unnecessary columns from file_storage
        DB::statement("ALTER TABLE file_storage DROP COLUMN mime_type");

        // Rename files table to file_attachments
        DB::statement("RENAME TABLE files TO file_attachments");

        // Rename file_hash_id to file_storage_id in file_attachments
        DB::statement("ALTER TABLE file_attachments CHANGE COLUMN file_hash_id file_storage_id BIGINT NOT NULL");

        // Drop file_size column from file_attachments (size is stored in file_storage)
        DB::statement("ALTER TABLE file_attachments DROP COLUMN file_size");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
