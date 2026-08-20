<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task Management + Project Hierarchy schema (feature Batch 1).
 *
 * Raw SQL per house convention (BIGINT ids, TIMESTAMP(3) audit, no down(), self-contained).
 *
 * Deltas:
 *  - tasks: ADD hour_estimate DECIMAL(8,2), ADD project_id BIGINT (the DERIVED column - see
 *    docs.dev/BACKLOG.md B-1; the derive+cascade logic lives in Task_Model / tasks_controller).
 *    taskable_{type,id} are also relaxed to NULL so a task may have NO polymorphic parent
 *    (a top-level task whose project_id is user-set directly).
 *  - projects: ADD parent_project_id BIGINT (self-reference, plain indexed column - the
 *    projects table carries NO foreign keys, so this matches its convention; cycle prevention
 *    is enforced application-side).
 *  - project_contacts / project_users pivots: user_group_members shape (surrogate id, UNIQUE
 *    pair, audit cols, FK CASCADE) PLUS site_id, matching the APP pivot convention
 *    (portal_memberships / shared_items both carry site_id + FK sites CASCADE; the parents
 *    projects/contacts/users are all site-scoped).
 *  - projects.contact_id (the single primary contact) is MIGRATED into project_contacts then
 *    DROPPED (owner-confirmed - contacts become 1-to-many via the pivot).
 */
return new class extends Migration
{
    public function up()
    {
        // --- tasks: derived project_id + hour_estimate, and nullable taskable parent ---
        DB::statement("
            ALTER TABLE tasks
                ADD COLUMN hour_estimate DECIMAL(8,2) NULL AFTER notes,
                ADD COLUMN project_id BIGINT NULL AFTER taskable_id,
                MODIFY taskable_type BIGINT NULL,
                MODIFY taskable_id BIGINT NULL,
                ADD INDEX idx_tasks_project_id (project_id)
        ");

        // --- projects: self-referencing parent (plain indexed column, no FK - projects has none) ---
        DB::statement("
            ALTER TABLE projects
                ADD COLUMN parent_project_id BIGINT NULL AFTER client_id,
                ADD INDEX idx_projects_parent_project_id (parent_project_id)
        ");

        // --- project_contacts pivot (1-to-many contacts per project) ---
        DB::statement("
            CREATE TABLE project_contacts (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                project_id BIGINT NOT NULL,
                contact_id BIGINT NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT NULL,
                updated_by BIGINT NULL,

                UNIQUE KEY uk_project_contacts (project_id, contact_id),
                KEY idx_project_contacts_site_id (site_id),
                KEY idx_project_contacts_project_id (project_id),
                KEY idx_project_contacts_contact_id (contact_id),
                CONSTRAINT fk_project_contacts_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_project_contacts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_project_contacts_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // --- project_users pivot (1-to-many assigned users per project) ---
        DB::statement("
            CREATE TABLE project_users (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                project_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT NULL,
                updated_by BIGINT NULL,

                UNIQUE KEY uk_project_users (project_id, user_id),
                KEY idx_project_users_site_id (site_id),
                KEY idx_project_users_project_id (project_id),
                KEY idx_project_users_user_id (user_id),
                CONSTRAINT fk_project_users_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_project_users_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_project_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // --- migrate the existing single contact_id into the pivot, then drop the column ---
        // Guard on a real contact row so the FK cannot reject (soft-deleted/orphan contact_ids).
        DB::statement("
            INSERT INTO project_contacts (site_id, project_id, contact_id, created_at, updated_at)
            SELECT p.site_id, p.id, p.contact_id, NOW(3), NOW(3)
            FROM projects p
            INNER JOIN contacts c ON c.id = p.contact_id
            WHERE p.contact_id IS NOT NULL
        ");

        DB::statement("ALTER TABLE projects DROP COLUMN contact_id");
    }

    /**
     * down() method is prohibited in RSpade framework - forward-only migrations.
     */
};
