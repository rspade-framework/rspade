<?php

namespace App\RSpade\Commands\Restricted;

use App\RSpade\Commands\Restricted\Restricted_Database_Command;

/**
 * Override for migrate:refresh command
 * 
 * This command override prevents the execution of migration refresh operations
 * which would reset and re-run all migrations, causing complete data loss
 * in affected tables.
 */
class Refresh_Command extends Restricted_Database_Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:refresh 
                            {--database= : The database connection to use}
                            {--force : Force the operation to run when in production}
                            {--path=* : The path(s) to the migrations files to be executed}
                            {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                            {--seed : Indicates if the seed task should be re-run}
                            {--seeder= : The class name of the root seeder}
                            {--step= : The number of migrations to be reverted & re-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] Reset and re-run all migrations - This command is disabled in RSpade framework';
}