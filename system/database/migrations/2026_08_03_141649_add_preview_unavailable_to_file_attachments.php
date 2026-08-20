<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Degrade unparseable image uploads to generic files (#8) - schema change.
 *
 * Adds preview_unavailable: a stored flag set when an uploaded image's BYTES cannot be
 * parsed by ImageMagick (content-parse failure, distinct from a MISSING binary which still
 * fatals). When set, process_file() also downgrades file_type_id to OTHER, and
 * pipeline_mime() returns application/octet-stream so the viewer/thumbnail/text pipelines
 * treat the file as a plain, non-previewable download. The raw served mime_type column is
 * deliberately NOT touched (it is the serve-time Content-Type security boundary).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _file_attachments ADD COLUMN preview_unavailable TINYINT(1) NOT NULL DEFAULT 0 AFTER frame_count");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
