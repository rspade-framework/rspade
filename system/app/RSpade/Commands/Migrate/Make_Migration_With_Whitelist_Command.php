<?php

namespace App\RSpade\Commands\Migrate;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Database\MigrationPaths;

class Make_Migration_With_Whitelist_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:migration:safe {name : The name of the migration}
        {--create= : The table to be created}
        {--table= : The table to migrate}
        {--path= : The location where the migration file should be created}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--fullpath : Output the full path of the migration (Deprecated)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new migration and add it to the whitelist';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Determine migration path based on framework developer flag
        $is_framework_dev = config('rsx.code_quality.is_framework_developer', false);
        $migration_path = $is_framework_dev
            ? database_path('migrations')
            : base_path('rsx/resource/migrations');

        // Ensure target directory exists
        if (!is_dir($migration_path)) {
            mkdir($migration_path, 0755, true);
        }

        // Build arguments for the real make:migration command
        $arguments = ['name' => $this->argument('name')];

        $options = [];
        if ($this->option('create')) $options['--create'] = $this->option('create');
        if ($this->option('table')) $options['--table'] = $this->option('table');

        // Override path unless explicitly provided
        if ($this->option('path')) {
            $options['--path'] = $this->option('path');
        } else {
            // Use relative path from base_path() for Laravel's make:migration
            $options['--path'] = str_replace(base_path() . '/', '', $migration_path);
        }

        if ($this->option('realpath')) $options['--realpath'] = true;
        if ($this->option('fullpath')) $options['--fullpath'] = true;

        // Show where migration will be created
        $location_type = $is_framework_dev ? 'framework' : 'application';
        $this->info("Creating {$location_type} migration in: {$migration_path}");

        // Call the real make:migration command
        $result = Artisan::call('make:migration', array_merge($arguments, $options), $this->output);
        
        if ($result === 0) {
            // Get the migration file that was just created
            $name = trim($this->argument('name'));
            $migrationPath = $this->getMigrationPath();
            
            // Find the newly created migration file
            $files = glob($migrationPath . '/*_' . Str::snake($name) . '.php');
            
            if (!empty($files)) {
                $migrationFile = basename(end($files));
                $this->addToWhitelist($migrationFile);
                $this->info("[OK] Migration added to whitelist: {$migrationFile}");
            }
        }
        
        return $result;
    }
    
    /**
     * Add a migration to the whitelist
     */
    protected function addToWhitelist(string $filename): void
    {
        // Get correct whitelist path based on framework developer flag
        $is_framework_dev = config('rsx.code_quality.is_framework_developer', false);
        $whitelistPath = $is_framework_dev
            ? database_path('migrations/.migration_whitelist')
            : base_path('rsx/resource/migrations/.migration_whitelist');

        // Ensure whitelist file exists
        if (!file_exists($whitelistPath)) {
            file_put_contents_safe($whitelistPath, json_encode([
                'description' => 'This file tracks migrations created via php artisan make:migration',
                'purpose' => 'Prevents manually created migrations from running to avoid timestamp conflicts',
                'migrations' => []
            ], JSON_PRETTY_PRINT));
        }

        // Load existing whitelist
        $whitelist = json_decode(file_get_contents($whitelistPath), true);

        // Add new migration with metadata
        $whitelist['migrations'][$filename] = [
            'created_at' => now()->toIso8601String(),
            'created_by' => get_current_user(),
            'command' => 'php artisan make:migration:safe ' . $this->argument('name')
        ];

        // Save updated whitelist
        file_put_contents_safe($whitelistPath, json_encode($whitelist, JSON_PRETTY_PRINT));
    }
    
    /**
     * Get migration path
     */
    protected function getMigrationPath()
    {
        if ($this->option('path')) {
            return base_path($this->option('path'));
        }

        // Return path based on framework developer flag
        $is_framework_dev = config('rsx.code_quality.is_framework_developer', false);
        return $is_framework_dev
            ? database_path('migrations')
            : base_path('rsx/resource/migrations');
    }
}