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
     * portal_invitations: every pending-invitation query is site_id, status_id = PENDING,
     * expires_at > now, so one (site_id, status_id, expires_at) index replaces the three
     * single-column ones; email is always paired with site_id (served by (site_id, email)) and
     * used_at is written only.
     *
     * portal_request_documents: the thread's documents are read thread_id = ? ORDER BY
     * created_at, id - the shape its sibling message and event tables already index - so
     * (thread_id, created_at) replaces (thread_id), which still leads for the foreign key.
     * attachment_id is never a predicate.
     *
     * announcements: a client's recent announcements are site_id, client_id ORDER BY
     * created_at DESC, so created_at joins that composite; published_at is display only.
     *
     * Dropped as serving no query: portal_memberships.role_id, the portal_password_resets
     * expiry/used indexes (their only reader, a cleanup method, has no caller), demo_products
     * category/status, shared_items expires_at/shared_by. portal_projects.client_id is the
     * prefix of its UNIQUE (client_id, project_id) key, which the foreign key binds to
     * instead.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE portal_invitations
                DROP INDEX idx_portal_invitations_status,
                DROP INDEX idx_portal_invitations_expires_at,
                DROP INDEX idx_portal_invitations_site_id,
                DROP INDEX idx_portal_invitations_email,
                DROP INDEX idx_portal_invitations_used_at,
                ADD INDEX idx_portal_invitations_site_status_expires (site_id, status_id, expires_at)
        ");

        DB::statement("
            ALTER TABLE portal_request_documents
                DROP INDEX idx_prd_thread,
                DROP INDEX idx_prd_attachment,
                ADD INDEX idx_prd_thread (thread_id, created_at)
        ");

        DB::statement("
            ALTER TABLE announcements
                DROP INDEX idx_announcements_site_client,
                DROP INDEX idx_announcements_published,
                ADD INDEX idx_announcements_site_client (site_id, client_id, created_at)
        ");

        DB::statement("
            ALTER TABLE portal_memberships DROP INDEX idx_portal_memberships_role_id
        ");

        DB::statement("
            ALTER TABLE portal_password_resets
                DROP INDEX idx_portal_password_resets_expires_at,
                DROP INDEX idx_portal_password_resets_used_at
        ");

        DB::statement("
            ALTER TABLE portal_projects DROP INDEX idx_portal_projects_client_id
        ");

        DB::statement("
            ALTER TABLE demo_products
                DROP INDEX idx_category_id,
                DROP INDEX idx_status_id
        ");

        DB::statement("
            ALTER TABLE shared_items
                DROP INDEX idx_shared_items_expires_at,
                DROP INDEX idx_shared_items_shared_by
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
