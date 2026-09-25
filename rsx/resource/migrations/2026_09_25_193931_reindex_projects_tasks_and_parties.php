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
     * projects and tasks are site-scoped; their indexes become site-first composites for the
     * access paths that exist:
     *
     *   - (site_id, created_at): the DataGrid default sort, created_at DESC, 25 rows a page.
     *     Added beside the created_at index, which stays; parties gets the same.
     *   - (site_id, status, due_date): the dashboard's open and overdue lists, status IN
     *     (...) [AND due_date < today] ORDER BY due_date.
     *   - (site_id, name) / (site_id, title): the pickers, ORDER BY name / title LIMIT 20.
     *   - Dropped: status, name, title and site_id (now prefixes or superseded),
     *     client_department_id, owner_user_id, assigned_to_user_id and created_by_id (no
     *     query), priority (low cardinality), deleted_at (not selective).
     *   - project_users.project_id and project_contacts.project_id are the prefixes of their
     *     UNIQUE (project_id, ...) keys, which the foreign keys bind to instead.
     *
     * Every table keeps an index LEADING with created_at and one leading with updated_at
     * (migrate:normalize_schema guarantees both, detecting coverage by the leading column).
     * Where a migration-named index duplicated the one normalization added under the column's
     * own name, the named copy is the one dropped: the survivor is then the index
     * normalization re-creates on a database replayed from scratch, so both paths converge.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE projects
                DROP INDEX idx_created_at,
                DROP INDEX idx_updated_at,
                DROP INDEX idx_client_department_id,
                DROP INDEX idx_owner_user_id,
                DROP INDEX idx_created_by,
                DROP INDEX idx_deleted_at,
                DROP INDEX idx_priority,
                DROP INDEX idx_status,
                DROP INDEX idx_name,
                DROP INDEX idx_site_id,
                ADD INDEX idx_projects_site_created (site_id, created_at),
                ADD INDEX idx_projects_site_status_due (site_id, status, due_date),
                ADD INDEX idx_projects_site_name (site_id, name)
        ");

        DB::statement("
            ALTER TABLE tasks
                DROP INDEX idx_created_at,
                DROP INDEX idx_updated_at,
                DROP INDEX idx_assigned_to_user_id,
                DROP INDEX idx_created_by,
                DROP INDEX idx_deleted_at,
                DROP INDEX idx_priority,
                DROP INDEX idx_status,
                DROP INDEX idx_title,
                DROP INDEX idx_site_id,
                ADD INDEX idx_tasks_site_created (site_id, created_at),
                ADD INDEX idx_tasks_site_status_due (site_id, status, due_date),
                ADD INDEX idx_tasks_site_title (site_id, title)
        ");

        DB::statement("
            ALTER TABLE project_users DROP INDEX idx_project_users_project_id
        ");

        DB::statement("
            ALTER TABLE project_contacts DROP INDEX idx_project_contacts_project_id
        ");

        DB::statement("
            ALTER TABLE parties ADD INDEX idx_parties_site_created (site_id, created_at)
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
