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
     * Every clients and contacts query is site-scoped, so a single-column index on anything but
     * site_id serves only a single-tenant install. The access paths that exist get site-first
     * composites; the rest go:
     *
     *   - clients (site_id, name): the client dropdowns ORDER BY name.
     *   - contacts (site_id, email): the duplicate-address question on create.
     *   - contacts (site_id, created_at): the dashboard's recent contacts. Added beside the
     *     created_at index, which stays.
     *   - Dropped: email on clients (LIKE only), owner_user_id and created_by_id (no query),
     *     portal_enabled, is_active, priority and status_id (low cardinality), deleted_at (the
     *     soft-delete residual is not selective), first_name / last_name (grid sorts under the
     *     site filter), and site_id itself, now the prefix of the composites.
     *   - client_departments.name and .site_id serve no query; its client_id index stays for
     *     the foreign key.
     *
     * The indexes the foreign keys bind to (billing_contact_id, client_id,
     * client_department_id, reports_to_contact_id) stay.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE clients
                DROP INDEX idx_email,
                DROP INDEX idx_owner_user_id,
                DROP INDEX idx_created_by_user_id,
                DROP INDEX idx_clients_portal_enabled,
                DROP INDEX idx_deleted_at,
                DROP INDEX idx_priority,
                DROP INDEX idx_status_id,
                DROP INDEX idx_name,
                DROP INDEX idx_site_id,
                ADD INDEX idx_clients_site_name (site_id, name)
        ");

        DB::statement("
            ALTER TABLE contacts
                DROP INDEX idx_owner_user_id,
                DROP INDEX idx_created_by_user_id,
                DROP INDEX idx_is_active,
                DROP INDEX idx_first_name,
                DROP INDEX idx_last_name,
                DROP INDEX idx_deleted_at,
                DROP INDEX idx_priority,
                DROP INDEX idx_email,
                DROP INDEX idx_site_id,
                ADD INDEX idx_contacts_site_email (site_id, email),
                ADD INDEX idx_contacts_site_created (site_id, created_at)
        ");

        DB::statement("
            ALTER TABLE client_departments
                DROP INDEX idx_name,
                DROP INDEX idx_site_id
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
