<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the dead users.user_role_id column.
 *
 * user_role_id is the PRE-ACL role enum (1=read_only, 2=standard, 3=admin,
 * 4=billing_admin, 5=root_admin), added by 2025_09_16_074547 and carried across the
 * table rebuild in 2025_11_04_051828. The November 2025 ACL rewrite replaced it with
 * role_id (the 100-800 enum) plus the user_permissions GRANT/DENY layer, and deleted
 * its $enums block from User_Model at the same time - leaving the column with no
 * definition, no reader, and no writer. Every row still holds the never-written
 * DEFAULT 2, so there is no value to migrate anywhere.
 *
 * The only surviving couplings were in the template app: a $sortable_columns entry
 * (ORDER BY by name) and phantom user_role_id__label reads, which resolved to nothing
 * because the BEM magic getter only answers for enum columns. Both are repointed at
 * role_id alongside this migration.
 *
 * MySQL drops the idx_users_user_role_id index with its only column, so no separate
 * DROP INDEX is needed. Forward-only.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE users DROP COLUMN user_role_id");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
