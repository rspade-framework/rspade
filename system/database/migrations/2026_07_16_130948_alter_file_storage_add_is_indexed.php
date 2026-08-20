<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Document text extraction - is_indexed flag on _file_storage (the extraction work queue).
 *
 * The background extraction cron drives off this flag: it processes one _file_storage row
 * with is_indexed=0 (ordered by id) per iteration, ALWAYS sets is_indexed=1 after an attempt
 * (regardless of outcome), so work never repeats and the loop terminates. New blobs default
 * to 0 and enter the queue automatically; a prompt kick is fired on find_or_create().
 *
 * Existing rows backfill naturally to 0 (DEFAULT), so every pre-existing blob is queued for a
 * one-time backfill extraction on first cron pass.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _file_storage ADD COLUMN is_indexed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = awaiting extraction cron' AFTER size");
        DB::statement("CREATE INDEX idx_is_indexed ON _file_storage(is_indexed)");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
