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
            CREATE TABLE _tasks (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,

                -- Task identification
                class VARCHAR(255) NOT NULL COMMENT 'Fully qualified class name',
                method VARCHAR(255) NOT NULL COMMENT 'Static method name to execute',
                queue VARCHAR(255) NOT NULL DEFAULT 'default' COMMENT 'Queue name for concurrency control',

                -- Status tracking
                status VARCHAR(50) NOT NULL DEFAULT 'pending' COMMENT 'pending, running, completed, failed, stuck',

                -- Data storage
                params JSON NULL COMMENT 'Serialized task parameters',
                result JSON NULL COMMENT 'Task result data',
                logs TEXT NULL COMMENT 'Accumulated log messages',
                error TEXT NULL COMMENT 'Error message if task failed',

                -- Scheduling
                scheduled_for DATETIME NULL COMMENT 'When task should execute (NULL = ASAP)',
                next_run_at DATETIME NULL COMMENT 'Next run time for recurring scheduled tasks',

                -- Execution tracking
                started_at DATETIME NULL COMMENT 'When task execution began',
                completed_at DATETIME NULL COMMENT 'When task finished (success or failure)',
                last_heartbeat_at DATETIME NULL COMMENT 'Last heartbeat from worker process',

                -- Worker management
                timeout BIGINT NULL COMMENT 'Maximum execution time in seconds (NULL = use default)',
                worker_pid BIGINT NULL COMMENT 'Process ID of worker executing this task',
                lock_key VARCHAR(255) NULL COMMENT 'MySQL advisory lock key for this task',

                -- Timestamps
                created_at DATETIME NULL DEFAULT NULL,
                updated_at DATETIME NULL DEFAULT NULL,

                -- Indexes for performance
                INDEX idx_status (status),
                INDEX idx_queue (queue),
                INDEX idx_scheduled_for (scheduled_for),
                INDEX idx_next_run_at (next_run_at),
                INDEX idx_worker_pid (worker_pid),
                INDEX idx_status_queue (status, queue)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     * You may remove this comment as soon as you see it and understand.
     */
};
