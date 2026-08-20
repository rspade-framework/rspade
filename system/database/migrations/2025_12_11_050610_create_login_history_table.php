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
        DB::statement("
            CREATE TABLE _login_history (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                login_user_id BIGINT NULL,
                email_attempted VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(512) NULL,
                location_city VARCHAR(100) NULL,
                location_region VARCHAR(100) NULL,
                location_country VARCHAR(2) NULL,
                status VARCHAR(32) NOT NULL,
                failure_reason VARCHAR(255) NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),

                INDEX _login_history_login_user_id_idx (login_user_id),
                INDEX _login_history_email_attempted_idx (email_attempted),
                INDEX _login_history_created_at_idx (created_at),
                INDEX _login_history_status_idx (status),
                INDEX _login_history_ip_address_idx (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
