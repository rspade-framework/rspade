<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     *
     * Failure visibility for recurring #[Schedule] tracker rows (CR 2026-08-08,
     * "a failed cron tracker is never revived").
     *
     * A tracker is NEVER permanently terminal: when its task throws, the row is
     * recycled straight back to PENDING so the schedule fires again at its next
     * cadence. That recycle used to erase every trace of the failure - the row
     * looked exactly like a healthy pending tracker, so a schedule could fail
     * every night for weeks with nothing to see. These two columns are the
     * record of that:
     *
     *   consecutive_failures - runs failed in a row; reset to 0 on any success.
     *                          A nonzero value on a tracker is the signal that a
     *                          schedule is broken while still looking scheduled.
     *   last_error_at        - when the most recent failure was recorded (the
     *                          `error` column already carries the text, but a
     *                          recycled tracker has no completed_at to date it).
     *
     * Both apply to on-demand rows too, where consecutive_failures is simply
     * 0 or 1 (an on-demand row runs once and stays terminal).
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE _tasks ADD COLUMN consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Runs failed in a row; reset to 0 on success. Nonzero on a cron tracker = a schedule that keeps failing.' AFTER status_reason");
        DB::statement("ALTER TABLE _tasks ADD COLUMN last_error_at TIMESTAMP(3) NULL DEFAULT NULL COMMENT 'When the most recent failure was recorded (a recycled cron tracker has no completed_at to date it).' AFTER completed_at");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
