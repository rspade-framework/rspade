<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * user_groups and user_group_members held this application's user groups. Nothing
 * granted, notified or assigned through a group - permissions are the role and the
 * per-user ACL - so the concept is removed: the editor, the model, and these two
 * tables. The pivot goes first, since its foreign key references user_groups.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("DROP TABLE user_group_members");
        DB::statement("DROP TABLE user_groups");
    }
};
