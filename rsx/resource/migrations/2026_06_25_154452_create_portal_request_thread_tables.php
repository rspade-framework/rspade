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
        // Portal request THREADS - the thread-based replacement for the flat
        // portal_requests feature. A request is a stateful conversation (firm <-> client)
        // for collecting documents / questionnaires / checklists. All site-scoped;
        // status_id / review_status / *_type are BIGINT enums / type-refs.
        DB::statement("
            CREATE TABLE portal_request_threads (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                client_id BIGINT NOT NULL,
                title VARCHAR(255) NOT NULL,
                status_id BIGINT NOT NULL DEFAULT 1,
                created_by BIGINT NULL DEFAULT NULL,
                last_activity_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_prt_site_client (site_id, client_id),
                INDEX idx_prt_status (site_id, status_id),
                CONSTRAINT fk_prt_client_id
                    FOREIGN KEY (client_id) REFERENCES clients (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Append-only timeline posts. author is polymorphic (Login_User_Model staff |
        // Portal_User_Model) via the author_type type-ref BIGINT column.
        DB::statement("
            CREATE TABLE portal_request_messages (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                thread_id BIGINT NOT NULL,
                author_type BIGINT NULL DEFAULT NULL,
                author_id BIGINT NULL DEFAULT NULL,
                body TEXT NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_prm_thread (thread_id, created_at),
                CONSTRAINT fk_prm_thread_id
                    FOREIGN KEY (thread_id) REFERENCES portal_request_threads (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Per-attachment review metadata (the file lives in the File_Attachment
        // subsystem; this row carries review state). requires_review distinguishes
        // client submissions (reviewable) from staff attachments (informational).
        // review_status: 1=PENDING, 2=ACCEPTED, 3=REJECTED.
        DB::statement("
            CREATE TABLE portal_request_documents (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                thread_id BIGINT NOT NULL,
                message_id BIGINT NOT NULL,
                attachment_id BIGINT NOT NULL,
                requires_review TINYINT(1) NOT NULL DEFAULT 1,
                review_status BIGINT NOT NULL DEFAULT 1,
                reject_reason TEXT NULL DEFAULT NULL,
                reviewed_by BIGINT NULL DEFAULT NULL,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_prd_thread (thread_id),
                INDEX idx_prd_message (message_id),
                INDEX idx_prd_attachment (attachment_id),
                CONSTRAINT fk_prd_thread_id
                    FOREIGN KEY (thread_id) REFERENCES portal_request_threads (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_prd_message_id
                    FOREIGN KEY (message_id) REFERENCES portal_request_messages (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Status-change events, rendered as alert cards interleaved in the timeline.
        // actor_type is a type-ref BIGINT column.
        DB::statement("
            CREATE TABLE portal_request_events (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                thread_id BIGINT NOT NULL,
                from_status BIGINT NULL DEFAULT NULL,
                to_status BIGINT NOT NULL,
                actor_type BIGINT NULL DEFAULT NULL,
                actor_id BIGINT NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_pre_thread (thread_id, created_at),
                CONSTRAINT fk_pre_thread_id
                    FOREIGN KEY (thread_id) REFERENCES portal_request_threads (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
