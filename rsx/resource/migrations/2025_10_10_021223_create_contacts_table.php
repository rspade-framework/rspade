<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::statement("
            CREATE TABLE contacts (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                client_id BIGINT NOT NULL,
                first_name VARCHAR(255) NOT NULL,
                last_name VARCHAR(255) NOT NULL,
                title VARCHAR(255) NULL,
                client_department_id BIGINT NULL,
                email VARCHAR(255) NULL,
                email_secondary VARCHAR(255) NULL,
                phone_work VARCHAR(50) NULL,
                phone_cell VARCHAR(50) NULL,
                phone_other VARCHAR(50) NULL,
                address TEXT NULL,
                city VARCHAR(255) NULL,
                state VARCHAR(255) NULL,
                zip VARCHAR(20) NULL,
                reports_to_contact_id BIGINT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                priority BIGINT NOT NULL DEFAULT 2,
                notes TEXT NULL,
                created_by_user_id BIGINT NULL,
                owner_user_id BIGINT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,

                INDEX idx_site_id (site_id),
                INDEX idx_client_id (client_id),
                INDEX idx_first_name (first_name),
                INDEX idx_last_name (last_name),
                INDEX idx_email (email),
                INDEX idx_client_department_id (client_department_id),
                INDEX idx_reports_to_contact_id (reports_to_contact_id),
                INDEX idx_is_active (is_active),
                INDEX idx_priority (priority),
                INDEX idx_created_by_user_id (created_by_user_id),
                INDEX idx_owner_user_id (owner_user_id),

                CONSTRAINT fk_contacts_client
                    FOREIGN KEY (client_id) REFERENCES clients(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_contacts_department
                    FOREIGN KEY (client_department_id) REFERENCES client_departments(id)
                    ON DELETE SET NULL,

                CONSTRAINT fk_contacts_reports_to
                    FOREIGN KEY (reports_to_contact_id) REFERENCES contacts(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
