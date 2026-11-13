<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * tasks.direct_project_id - the project a user chose for a task, beside the derived
 * tasks.project_id.
 *
 * project_id is computed by the model: the project the task's parent chain (task -> task
 * -> ... -> project, through the taskable_type / taskable_id pair) reaches, otherwise
 * direct_project_id. This migration adds the column and fills it from project_id for every
 * task whose chain reaches no project - there project_id is the value somebody chose. A task
 * under a project chain gets NULL: its project_id was the chain's.
 *
 * The chain walk below is the SQL form of the model's chain resolver: same-site parent
 * lookups, a soft-deleted parent task still counts, at most 25 hops, and a cycle reaches no
 * project. The fill holds updated_at where it is; nobody edited these tasks.
 *
 * Finally the table is queued for re-derivation, so the closing step of this migrate run
 * recomputes project_id for every task with the model's own derive method.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE tasks
                ADD COLUMN direct_project_id BIGINT NULL DEFAULT NULL AFTER taskable_id,
                ADD INDEX idx_tasks_direct_project_id (direct_project_id)
        ");

        // Every task whose parent chain reaches a project. A temporary table, because MySQL
        // refuses an UPDATE whose own target appears in a subquery of it.
        DB::statement("
            CREATE TEMPORARY TABLE tmp_tasks_reaching_project (task_id BIGINT NOT NULL PRIMARY KEY)
            WITH RECURSIVE chain (task_id, site_id, cur_type, cur_id, depth, path) AS (
                SELECT t.id, t.site_id, t.taskable_type, t.taskable_id, 1,
                       CAST(CONCAT(',', t.id, ',') AS CHAR(2000))
                  FROM tasks t
                UNION ALL
                SELECT c.task_id, c.site_id, p.taskable_type, p.taskable_id, c.depth + 1,
                       CONCAT(c.path, p.id, ',')
                  FROM chain c
                  JOIN tasks p ON p.id = c.cur_id AND p.site_id = c.site_id
                 WHERE c.cur_type = (SELECT id FROM _type_refs WHERE class_name = 'Task_Model')
                   AND c.depth < 25
                   AND c.path NOT LIKE CONCAT('%,', p.id, ',%')
            )
            SELECT DISTINCT task_id
              FROM chain
             WHERE cur_type = (SELECT id FROM _type_refs WHERE class_name = 'Project_Model')
               AND cur_id IS NOT NULL
               AND cur_id <> 0
        ");

        DB::statement("
            UPDATE tasks t
              LEFT JOIN tmp_tasks_reaching_project r ON r.task_id = t.id
               SET t.direct_project_id = t.project_id,
                   t.updated_at = t.updated_at
             WHERE r.task_id IS NULL
               AND t.project_id IS NOT NULL
        ");

        DB::statement("DROP TEMPORARY TABLE tmp_tasks_reaching_project");

        \App\RSpade\Core\Database\Rsx_Rederive::enqueue('tasks');
    }
};
