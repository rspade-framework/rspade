<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop foreign key constraint from flash_alerts table first
        DB::statement('ALTER TABLE flash_alerts DROP FOREIGN KEY flash_alerts_session_fk');

        // Delete all flash_alerts records since session IDs will be incompatible
        DB::statement('DELETE FROM flash_alerts');

        // Convert flash_alerts.session_id from varchar to bigint
        DB::statement('ALTER TABLE flash_alerts MODIFY session_id BIGINT NULL');

        // Drop existing sessions table
        DB::statement('DROP TABLE IF EXISTS sessions');

        // Create new session table based on RS3 specification with security enhancements
        DB::statement("
            CREATE TABLE sessions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                active TINYINT(1) NOT NULL DEFAULT 1,
                site_id BIGINT NULL DEFAULT 0,
                user_id BIGINT NULL,
                session_token VARCHAR(64) NOT NULL,
                csrf_token VARCHAR(64) NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NULL,
                last_active DATETIME NOT NULL,
                version INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,

                UNIQUE KEY sessions_session_token_unique (session_token),
                INDEX sessions_active_index (active),
                INDEX sessions_user_id_index (user_id),
                INDEX sessions_last_active_index (last_active),
                INDEX sessions_active_last_active_index (active, last_active),
                INDEX sessions_user_id_active_index (user_id, active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Re-add foreign key constraint from flash_alerts table
        DB::statement('ALTER TABLE flash_alerts ADD CONSTRAINT flash_alerts_session_fk FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE');
    }
};
