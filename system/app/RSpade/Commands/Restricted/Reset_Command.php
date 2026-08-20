<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Controlled override for migrate:reset command
 *
 * Completely resets the database by dropping and recreating it.
 *
 * Conditions:
 * - Always allowed if IS_FRAMEWORK_DEVELOPER=true
 * - In development mode: allowed for manual execution, blocked for Claude Code
 * - In production mode: always blocked
 * - Must be in migration mode unless IS_FRAMEWORK_DEVELOPER=true
 */
class Reset_Command extends Command
{
    protected $signature = 'migrate:reset';

    protected $description = 'Reset database by dropping and recreating it (restricted: see help for conditions)';

    protected $flag_file = '/var/www/html/.migrating';

    public function handle()
    {
        $is_framework_developer = getenv('IS_FRAMEWORK_DEVELOPER') === 'true';
        $is_production = app()->environment('production');
        $is_claude_code = getenv('CLAUDECODE') === '1';
        $in_migration_mode = file_exists($this->flag_file);

        // Always allow if framework developer
        if ($is_framework_developer) {
            return $this->executeReset();
        }

        // Block in production
        if ($is_production) {
            $this->error('[ERROR] migrate:reset is not allowed in production environments.');
            $this->newLine();
            $this->warn('Forward-only migration policy:');
            $this->line('- Production databases must maintain complete migration history');
            $this->line('- Schema modifications require new forward migrations');
            $this->line('- Database resets would cause irreversible data loss');
            $this->newLine();
            return 1;
        }

        // Block Claude Code in development
        if ($is_claude_code) {
            $this->error('[ERROR] migrate:reset is not allowed from the Claude Code environment.');
            $this->newLine();
            $this->warn('AI Safety Guardrail:');
            $this->line('This command performs destructive database operations and is restricted');
            $this->line('when executed through automated systems to prevent unintended data loss.');
            $this->newLine();
            $this->info('To reset the database:');
            $this->line('- Run this command manually in your terminal');
            $this->line('- Command: php artisan migrate:reset');
            $this->newLine();
            $this->line('This ensures you have explicit control over destructive operations.');
            $this->newLine();
            return 1;
        }

        // Require migration mode for non-framework developers
        if (!$in_migration_mode) {
            $this->error('[ERROR] migrate:reset requires migration mode to be active.');
            $this->newLine();
            $this->warn('Database reset protection:');
            $this->line('This command can only be run within a migration session to ensure');
            $this->line('you have a snapshot to restore if something goes wrong.');
            $this->newLine();
            $this->info('Note:');
            $this->line('In development mode, "php artisan migrate" automatically creates');
            $this->line('snapshots and manages rollback. This command is only needed for');
            $this->line('full database drops, which is rarely necessary.');
            $this->newLine();
            return 1;
        }

        // Development mode, manual execution, in migration mode - allowed
        return $this->executeReset();
    }

    /**
     * Execute database reset by dropping and recreating the database
     *
     * @return int
     */
    protected function executeReset()
    {
        $this->warn('[WARNING]  WARNING: This will DROP and RECREATE the entire database!');
        $this->newLine();

        // Get database name from config
        $database = config('database.connections.mysql.database');

        if (!$database) {
            $this->error('[ERROR] Unable to determine database name from configuration.');
            return 1;
        }

        try {
            // Connect to MySQL without selecting a database
            $pdo = new \PDO(
                'mysql:host=' . config('database.connections.mysql.host'),
                config('database.connections.mysql.username'),
                config('database.connections.mysql.password')
            );

            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Drop database
            $this->info("Dropping database: {$database}");
            $pdo->query("DROP DATABASE IF EXISTS `{$database}`");

            // Create database
            $this->info("Creating database: {$database}");
            $pdo->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $this->newLine();
            $this->info('[OK] Database reset complete. Database is pristine.');
            $this->newLine();

            return 0;
        } catch (\PDOException $e) {
            $this->error('[ERROR] Database reset failed: ' . $e->getMessage());
            return 1;
        }
    }
}
