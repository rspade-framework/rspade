<?php

namespace App\RSpade\Commands\Migrate;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Migrate_Status_Command extends Command
{
    protected $signature = 'migrate:status';
    protected $description = 'Show the status of the current migration session';
    
    protected $flag_file = '/var/www/html/.migrating';
    protected $backup_dir = '/var/lib/mysql_backup';
    
    public function handle()
    {
        // Check environment
        $environment = app()->environment();
        $this->info("Environment: " . $environment);
        $this->info("");
        
        // Check if in migration mode
        if (!file_exists($this->flag_file)) {
            $this->info('[OK] No migration session in progress');
            $this->info('');
            
            $this->line('To run migrations:');
            $this->line('   php artisan migrate');
            if ($environment !== 'production') {
                $this->line('');
                $this->line('In development mode, snapshots are created automatically.');
            }
            
            return 0;
        }
        
        // Migration session is active
        $session_info = json_decode(file_get_contents($this->flag_file), true);
        $started_at = $session_info['started_at'] ?? 'unknown';
        $started_by = $session_info['started_by'] ?? 'unknown';
        
        $this->warn(' Migration session is ACTIVE');
        $this->info('');
        $this->line('Session Details:');
        $this->line('   Started at: ' . $started_at);
        $this->line('   Started by: ' . $started_by);
        
        // Check backup status
        if (is_dir($this->backup_dir)) {
            $backup_size = $this->get_directory_size($this->backup_dir);
            $this->line('   Backup size: ' . $this->format_bytes($backup_size));
            $this->info('   Backup status: [OK] Available');
        } else {
            $this->error('   Backup status: [ERROR] Missing!');
        }
        
        $this->info('');
        $this->line('Migration Mode:');
        $this->line('   This indicates the migrate command is running with snapshot protection.');
        $this->line('   The snapshot will be auto-committed on success or auto-rolled back on failure.');
        
        // Check database connection
        $this->info('');
        $this->line('Database Status:');
        try {
            DB::select('SELECT 1');
            $this->info('   Connection: [OK] Active');
            
            // Show pending migrations count
            $pending = $this->get_pending_migrations_count();
            if ($pending > 0) {
                $this->warn("   Pending migrations: $pending");
            } else {
                $this->info('   Pending migrations: 0');
            }
            
        } catch (\Exception $e) {
            $this->error('   Connection: [ERROR] Failed');
            $this->error('   Error: ' . $e->getMessage());
        }
        
        $this->info('');
        $this->warn('[WARNING]  Web UI is disabled during migration mode (development only)');
        
        return 0;
    }
    
    /**
     * Get directory size in bytes
     */
    protected function get_directory_size($path): int
    {
        $size = 0;
        foreach (glob(rtrim($path, '/').'/*', GLOB_NOSORT) as $each) {
            $size += is_file($each) ? filesize($each) : $this->get_directory_size($each);
        }
        return $size;
    }
    
    /**
     * Format bytes to human readable
     */
    protected function format_bytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Get count of pending migrations
     */
    protected function get_pending_migrations_count(): int
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles($migrator->paths());
            $ran = $migrator->getRepository()->getRan();
            return count(array_diff(array_keys($files), $ran));
        } catch (\Exception $e) {
            return -1;
        }
    }
}