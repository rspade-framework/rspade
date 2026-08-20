<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Retire the upload-ownership session stamp on _file_attachments and replace it with an
     * audit-only client IP.
     *
     * session_id was a bare VARCHAR (no FK, nothing cascading) written at upload time and
     * compared against the CURRENT session in can_user_assign_this_file(). It asked the wrong
     * question: it was read through the STAFF session facade unconditionally, so a portal
     * upload matched only by accident, and it made "may this key be claimed" a property of a
     * browser session rather than of the attachment. The claim guard keeps the checks that
     * actually mean something (not already attached + site match); WHO may claim is the app's
     * decision, made through the file.*.authorize gates.
     *
     * created_by_ip_address is NOT a replacement guard - it is the only meaningful datum an
     * anonymous upload carries, recorded for audit. VARCHAR(45) matches _sessions.ip_address
     * (IPv6 maximum). NULL means no request context (CLI, tasks, programmatic creation).
     *
     * @return void
     */
    public function up()
    {
        DB::statement('ALTER TABLE _file_attachments DROP INDEX idx_session_id');
        DB::statement('ALTER TABLE _file_attachments DROP COLUMN session_id');
        DB::statement('ALTER TABLE _file_attachments ADD COLUMN created_by_ip_address VARCHAR(45) NULL AFTER site_id');
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
