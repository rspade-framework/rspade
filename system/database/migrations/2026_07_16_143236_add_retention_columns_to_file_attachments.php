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
        // File disposal & retention lifecycle: live -> deleted_at (recoverable, retention
        // clock starts) -> destroyed_at (permanent tombstone; the row persists forever as
        // an audit record, only its claim on the blob is released). deleted_at is the
        // SoftDeletes column (File_Attachment_Model uses SoftDeletes); destroyed_at is the
        // disposal task's terminal stamp.
        DB::statement("ALTER TABLE _file_attachments ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
        DB::statement("ALTER TABLE _file_attachments ADD COLUMN destroyed_at TIMESTAMP NULL DEFAULT NULL");

        // The destroy pass scans deleted_at < cutoff AND destroyed_at IS NULL; the
        // blob-release pass groups by file_storage_id filtered on destroyed_at.
        DB::statement("CREATE INDEX idx_file_attachments_deleted_at ON _file_attachments (deleted_at)");
        DB::statement("CREATE INDEX idx_file_attachments_destroyed_at ON _file_attachments (destroyed_at)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
