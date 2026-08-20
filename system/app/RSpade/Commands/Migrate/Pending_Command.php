<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Migrate;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\MigrationPaths;

/**
 * Show pending migrations that have not been run yet
 * 
 * This command displays a simple list of migration files that are present
 * in the migrations directory but have not yet been executed against the database.
 */
class Pending_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show pending migrations that have not been run';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get the migrator instance
        $migrator = app('migrator');

        // Get migration names from all migration directories (recursive, covers
        // nested subdirectories such as database/migrations/rspade/). Laravel keys
        // migrations by basename without the .php extension.
        $migration_names = [];
        foreach (MigrationPaths::get_all_migration_files() as $file) {
            $migration_names[] = basename($file, '.php');
        }

        // Get migrations that have already been run
        $ran_migrations = $migrator->getRepository()->getRan();

        // Find pending migrations (files not in the ran list)
        $pending_migrations = array_diff($migration_names, $ran_migrations);

        // Sort them to maintain chronological order
        sort($pending_migrations);
        
        if (empty($pending_migrations)) {
            $this->info('No pending migrations.');
            return 0;
        }
        
        $this->line('Migrations Pending:');
        foreach ($pending_migrations as $migration) {
            $this->line('- ' . $migration);
        }
        
        return 0;
    }
}