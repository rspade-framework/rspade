<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE file_attachments ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Add metadata fields to file_attachments table
        DB::statement("ALTER TABLE file_attachments ADD COLUMN fileable_type_meta VARCHAR(255) NULL AFTER fileable_category");
        DB::statement("ALTER TABLE file_attachments ADD COLUMN fileable_meta TEXT NULL AFTER fileable_order");

        // Add index for fileable_type_meta for efficient querying
        DB::statement("CREATE INDEX idx_file_attachments_type_meta ON file_attachments(fileable_type_meta)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
