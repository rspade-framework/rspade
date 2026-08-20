<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE users ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * @return void
     */
    public function up()
    {
        // Step 1: Drop existing foreign key constraints BEFORE renaming
        DB::statement("ALTER TABLE site_users DROP FOREIGN KEY site_users_ibfk_1"); // user_id → users
        DB::statement("ALTER TABLE site_users DROP FOREIGN KEY site_users_ibfk_2"); // site_id → sites

        // Step 2: Drop unique constraint (will recreate with new column name)
        DB::statement("ALTER TABLE site_users DROP INDEX uk_site_users_user_site");

        // Step 3: Rename site_users to users_new (temporary)
        DB::statement("RENAME TABLE site_users TO users_new");

        // Step 4: Rename user_id column to login_user_id
        DB::statement("ALTER TABLE users_new CHANGE COLUMN user_id login_user_id BIGINT NOT NULL");

        // Step 5: Add new columns for site-specific user profile data
        DB::statement("
            ALTER TABLE users_new
            ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER last_name,
            ADD COLUMN user_role_id BIGINT NOT NULL DEFAULT 2 AFTER is_enabled,
            ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER user_role_id
        ");

        // Step 6: Add indexes for new columns
        DB::statement("ALTER TABLE users_new ADD INDEX idx_users_phone (phone)");
        DB::statement("ALTER TABLE users_new ADD INDEX idx_users_user_role_id (user_role_id)");
        DB::statement("ALTER TABLE users_new ADD INDEX idx_users_email (email)");

        // Step 7: Recreate unique constraint with new column name
        DB::statement("ALTER TABLE users_new ADD UNIQUE KEY uk_users_login_user_site (login_user_id, site_id)");

        // Step 8: Add foreign key constraints with new column names
        DB::statement("
            ALTER TABLE users_new
            ADD CONSTRAINT fk_users_login_user_id
            FOREIGN KEY (login_user_id) REFERENCES login_users(id) ON DELETE CASCADE
        ");
        DB::statement("
            ALTER TABLE users_new
            ADD CONSTRAINT fk_users_site_id
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
        ");

        // Step 9: Removed - no longer populating users_new from old users table

        // Step 10: Drop foreign key constraints from other tables that reference users
        DB::statement("ALTER TABLE user_invites DROP FOREIGN KEY user_invites_ibfk_1");
        DB::statement("ALTER TABLE user_profiles DROP FOREIGN KEY fk_user_profiles_user_id");

        // Step 11: Drop the old users table (data now in login_users and users_new)
        DB::statement("DROP TABLE users");

        // Step 12: Rename users_new to users (final name)
        DB::statement("RENAME TABLE users_new TO users");

        // Step 13: Recreate foreign keys for tables that should reference login_users
        // user_invites should reference login_users (invitations are to login identities)
        DB::statement("
            ALTER TABLE user_invites
            ADD CONSTRAINT fk_user_invites_user_id
            FOREIGN KEY (user_id) REFERENCES login_users(id) ON DELETE CASCADE
        ");

        // Note: user_profiles FK will be recreated in the next migration to reference new users table
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
