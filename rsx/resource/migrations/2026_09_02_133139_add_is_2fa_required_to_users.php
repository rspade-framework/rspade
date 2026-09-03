<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Administrator-required two-factor authentication.
     *
     * A TEMPLATE-APP flag on a framework table. The framework decides whether an identity
     * HAS a second factor (two_factor_credentials); this column is the application's own
     * policy - whether this site user must have one before they may use the app - and it is
     * read by Rsx\Main::pre_dispatch() and the user-management screens, nowhere in system/.
     *
     * Defaults to 0: an existing install's users are unaffected until an administrator says
     * otherwise.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE users ADD COLUMN is_2fa_required TINYINT(1) NOT NULL DEFAULT 0");
    }
};
