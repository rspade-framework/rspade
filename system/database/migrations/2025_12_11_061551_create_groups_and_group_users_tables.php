<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // Create user_groups table
        DB::statement("
            CREATE TABLE user_groups (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                deletion_protection TINYINT(1) NOT NULL DEFAULT 0,
                deleted_at TIMESTAMP(3) NULL,
                deleted_by BIGINT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT NULL,
                updated_by BIGINT NULL,

                INDEX user_groups_site_id_idx (site_id),
                INDEX user_groups_name_idx (name),
                INDEX user_groups_deleted_at_idx (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create user_group_members pivot table (many-to-many)
        DB::statement("
            CREATE TABLE user_group_members (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_group_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                created_by BIGINT NULL,
                updated_by BIGINT NULL,

                INDEX user_group_members_group_id_idx (user_group_id),
                INDEX user_group_members_user_id_idx (user_id),
                UNIQUE INDEX user_group_members_unique (user_group_id, user_id),
                CONSTRAINT user_group_members_group_fk FOREIGN KEY (user_group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
                CONSTRAINT user_group_members_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
