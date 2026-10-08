<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * users.is_2fa_required was this application's per-user "an administrator requires a second
 * factor" flag. The forced-enrollment screen that read it is gone, and a per-user flag is the
 * wrong grain for a policy an organisation turns on for everybody, so the column goes with it.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE users DROP COLUMN is_2fa_required");
    }
};
