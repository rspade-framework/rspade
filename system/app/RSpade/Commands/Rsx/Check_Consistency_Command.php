<?php

namespace App\RSpade\Commands\Rsx;

use App\Console\Commands\FrameworkDeveloperCommand;
use App\RSpade\Core\Database\ModelHelper;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class Check_Consistency_Command extends FrameworkDeveloperCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:migrate:check_consistency';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check manifest consistency with production database schema';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check if in production mode
        if (app()->environment() !== 'production') {
            $this->info('Manifest Consistency Check');
            $this->info('==============================');
            $this->info('');
            $this->warn('This command is designed for production environments.');
            $this->info('');
            $this->line('Purpose: Verifies that the deployed manifest matches the production database schema.');
            $this->line('');
            $this->line('In development mode:');
            $this->line('  - The manifest is automatically regenerated after migrations');
            $this->line('  - It will always be in sync with the current database state');
            $this->line('  - Running this check is unnecessary');
            $this->line('');
            $this->line('In production mode:');
            $this->line('  - The manifest is deployed as part of the application');
            $this->line('  - It should match the production database schema exactly');
            $this->line('  - This check ensures deployment consistency');
            $this->info('');
            $this->info('Current environment: ' . app()->environment());
            return 0;
        }
        
        $this->info('Checking manifest consistency with production database...');
        
        $error_count = $this->check_manifest_database_consistency();
        
        if ($error_count > 0) {
            $this->error("\n[ERROR] Found {$error_count} manifest/database inconsistencies");
            $this->error("The deployed manifest is not consistent with the production database schema.");
            return 1;
        }
        
        $this->info("[OK] Manifest is consistent with production database");
        return 0;
    }
    
    /**
     * Check manifest consistency with database in production mode
     * Returns the number of inconsistencies found
     */
    protected function check_manifest_database_consistency(): int
    {
        $error_count = 0;
        
        try {
            // Get all tables from manifest
            $manifest_tables = ModelHelper::get_all_table_names();
            
            if (empty($manifest_tables)) {
                $this->warn("[WARNING]  No tables found in manifest");
                return 0;
            }
            
            foreach ($manifest_tables as $table_name) {
                // Check if table exists in database
                if (!Schema::hasTable($table_name)) {
                    $this->error("[ERROR] Table '{$table_name}' exists in manifest but not in production database");
                    $error_count++;
                    continue;
                }
                
                // Get columns from manifest
                try {
                    $manifest_columns = ModelHelper::get_columns_by_table($table_name);
                } catch (\Exception $e) {
                    $this->error("[ERROR] Could not get manifest columns for table '{$table_name}': " . $e->getMessage());
                    $error_count++;
                    continue;
                }
                
                // Get column names from database
                $db_column_names = [];
                $column_results = DB::select("SHOW COLUMNS FROM `{$table_name}`");
                foreach ($column_results as $column) {
                    $db_column_names[] = $column->Field;
                }
                
                // Compare column names only
                $manifest_column_names = array_keys($manifest_columns);
                
                $only_in_manifest = array_diff($manifest_column_names, $db_column_names);
                $only_in_db = array_diff($db_column_names, $manifest_column_names);
                
                if (!empty($only_in_manifest) || !empty($only_in_db)) {
                    $this->error("[ERROR] Column mismatch for table '{$table_name}':");
                    
                    if (!empty($only_in_manifest)) {
                        $this->error("   Columns only in manifest: " . implode(', ', $only_in_manifest));
                    }
                    
                    if (!empty($only_in_db)) {
                        $this->error("   Columns only in database: " . implode(', ', $only_in_db));
                    }
                    
                    $error_count++;
                }
            }
            
        } catch (\Exception $e) {
            $this->error("[ERROR] Error checking manifest consistency: " . $e->getMessage());
            return 1;
        }
        
        return $error_count;
    }
}