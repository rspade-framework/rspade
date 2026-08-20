<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement() with raw SQL
     * ❌ Schema::create() with Blueprint
     *
     * REQUIRED: ALL tables MUST have: id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY
     * No exceptions - every table needs this exact ID column (SIGNED for easier migrations)
     *
     * Integer types: Use BIGINT for all integers, TINYINT(1) for booleans only
     * Never use unsigned - all integers should be signed
     *
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // The FIRST user of the application, from .env - never from a default.
        //
        // A framework that invents a credential here is a framework where every
        // install in the world shares one email and one password, published in
        // its own source. So both values are REQUIRED and blank is fatal: the
        // operator chooses the first login before the application has one.
        // See .env.README for how to set them.
        $email = trim((string) env('RSPADE_DEFAULT_EMAIL', ''));
        $password = (string) env('RSPADE_DEFAULT_PASSWORD', '');

        // Two contexts where blank is not an error, and the account is simply not
        // created:
        //
        //   DEVELOPMENT - the first-run setup screen offers to create this account
        //   in the browser instead, which is a far better introduction than a login
        //   form with no way past it.
        //
        //   THE TEST DATABASE - a test run provisions a schema, not a person. It
        //   migrates with RSX_MODE=debug (to skip the datadir snapshot), so the
        //   mode alone does not identify it; the database being migrated does.
        //   Without this the whole suite fails at provisioning the moment
        //   credentials are blank, which is their shipped state.
        //
        // Anywhere else, blank stays fatal: a real install is configured
        // deliberately, and inventing a password is exactly what this refuses to do.
        if ($email === '' || $password === '') {
            $connection = (string) config('database.default');
            $current_database = (string) config('database.connections.' . $connection . '.database');
            $test_database = (string) env('DB_TEST_DATABASE', 'rspade_test');

            $is_development = env('RSX_MODE', 'development') === 'development';
            $is_test_database = $current_database !== '' && $current_database === $test_database;

            if ($is_development || $is_test_database) {
                return;
            }
        }

        if ($email === '' || $password === '') {
            $missing = [];
            if ($email === '') {
                $missing[] = 'RSPADE_DEFAULT_EMAIL';
            }
            if ($password === '') {
                $missing[] = 'RSPADE_DEFAULT_PASSWORD';
            }

            throw new \RuntimeException(
                'Cannot create the first user: ' . implode(' and ', $missing) . ' '
                . (count($missing) === 1 ? 'is' : 'are') . ' not set in .env.'
                . "\n\n"
                . "  These are the credentials of the account you will log in with, and RSpade\n"
                . "  deliberately ships no default for them - a shared, published password is\n"
                . "  not a starting point. Set both in .env and run migrate again:\n\n"
                . "      RSPADE_DEFAULT_EMAIL=you@example.com\n"
                . "      RSPADE_DEFAULT_PASSWORD=<a password you choose>\n\n"
                . '  See .env.README for the full description of every .env value.'
            );
        }

        $hashed_password = Hash::make($password);
        
        // Bound parameters, not interpolation: these values now come from .env,
        // so an apostrophe in an address would otherwise break the migration.
        DB::statement("
            INSERT INTO users (
                email,
                password,
                is_activated,
                is_verified,
                created_at,
                updated_at
            ) VALUES (?, ?, 1, 1, NOW(), NOW())
        ", [$email, $hashed_password]);
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
