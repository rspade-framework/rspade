<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Migrations must be self-contained.
     *
     * billable_entries: a task's logged hours read site_id = ? AND task_id = ?, and no index
     * led with task_id under the site, so every task view scanned the site's entries.
     * (site_id, task_id) mirrors the existing (site_id, project_id).
     *
     * portal_invitations: the hourly expiry sweep reads every tenant at once
     * (status_id = pending AND expires_at < now, no site predicate), which the per-site
     * (site_id, status_id, expires_at) cannot serve; (status_id, expires_at) lets it read only
     * the due rows instead of every invitation ever sent.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE billable_entries ADD INDEX idx_billable_entries_site_task (site_id, task_id)");
        DB::statement("ALTER TABLE portal_invitations ADD INDEX idx_portal_invitations_status_expires (status_id, expires_at)");
    }
};
