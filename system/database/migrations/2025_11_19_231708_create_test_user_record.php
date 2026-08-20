<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates a default site and user record for the test user.
     *
     * Assumptions:
     * - Running in fresh/reset database
     * - login_users table has record with id=1 (created by prior migration)
     * - sites table is empty
     * - users table is empty
     *
     * @return void
     */
    public function up()
    {
        // Create default site
        DB::statement("
            INSERT INTO sites (
                id,
                slug,
                name,
                is_enabled,
                created_at,
                updated_at
            ) VALUES (
                1,
                'test',
                'Test Site',
                1,
                NOW(3),
                NOW(3)
            )
        ");

        // The site profile for login_user 1 - but ONLY when that credential exists.
        //
        // Its sibling migration (create_admin_test_user) creates login_user 1 from
        // RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD, and deliberately creates
        // nothing when those are blank: development hands that job to the first-run
        // setup screen, and a test database wants a schema rather than a person.
        // Inserting this row regardless would then violate the foreign key and take
        // the whole migration run down with it.
        //
        // A profile with no credential is not a lesser outcome worth forcing - it is
        // a row nobody can log in as.
        $login_user_exists = DB::table('login_users')->where('id', 1)->exists();

        if (!$login_user_exists) {
            return;
        }

        // Create user record for test user and associate with site
        DB::statement("
            INSERT INTO users (
                id,
                login_user_id,
                site_id,
                email,
                first_name,
                last_name,
                created_at,
                updated_at
            ) VALUES (
                1,
                1,
                1,
                'test@example.com',
                'Test',
                'User',
                NOW(3),
                NOW(3)
            )
        ");
    }
};
