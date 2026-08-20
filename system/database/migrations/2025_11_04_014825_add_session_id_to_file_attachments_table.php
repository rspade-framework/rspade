<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add session_id column to file_attachments table for attachment assignment security.
     * Users can only assign attachments that were uploaded in their current session.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE file_attachments ADD COLUMN session_id VARCHAR(255) NULL AFTER site_id");
        DB::statement("ALTER TABLE file_attachments ADD INDEX idx_session_id (session_id)");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
