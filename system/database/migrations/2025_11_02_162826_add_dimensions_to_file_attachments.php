<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add dimension and metadata fields to file_attachments table for storing
     * extracted media properties (width, height, duration, animation detection).
     *
     * These fields are populated automatically during file upload by the
     * File_Attachment_Model::process_file() method using ImageMagick.
     *
     * @return void
     */
    public function up()
    {
        // Image/Video dimensions (using BIGINT as per framework standards)
        // Note: COMMENT must come before AFTER in MySQL syntax
        DB::statement("ALTER TABLE file_attachments ADD COLUMN width BIGINT NULL COMMENT 'Width in pixels for images/videos' AFTER file_type_id");
        DB::statement("ALTER TABLE file_attachments ADD COLUMN height BIGINT NULL COMMENT 'Height in pixels for images/videos' AFTER width");

        // Video/Animation duration in seconds
        DB::statement("ALTER TABLE file_attachments ADD COLUMN duration BIGINT NULL COMMENT 'Duration in seconds for videos/animated images' AFTER height");

        // Animated image detection
        DB::statement("ALTER TABLE file_attachments ADD COLUMN is_animated TINYINT(1) DEFAULT 0 COMMENT 'Whether this is an animated image (GIF, WebP, APNG)' AFTER duration");

        // Frame count for animated images
        DB::statement("ALTER TABLE file_attachments ADD COLUMN frame_count BIGINT NULL COMMENT 'Number of frames in animated images' AFTER is_animated");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
