<?php

namespace App\RSpade\Commands\Restricted;

use App\RSpade\Commands\Restricted\Restricted_Database_Command;

/**
 * Override for migrate:fresh command
 * 
 * This command override prevents the execution of fresh migration operations
 * which would drop all tables and recreate the database schema, potentially
 * causing irreversible data loss.
 */
class Fresh_Command extends Restricted_Database_Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:fresh 
                            {--database= : The database connection to use}
                            {--drop-views : Drop all tables and views (Postgres only)}
                            {--drop-types : Drop all tables and types (Postgres only)}
                            {--force : Force the operation to run when in production}
                            {--path=* : The path(s) to the migrations files to be executed}
                            {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                            {--schema-path= : The path to a schema dump file}
                            {--seed : Indicates if the seed task should be re-run}
                            {--seeder= : The class name of the root seeder}
                            {--step : Force the migrations to be run so they can be rolled back individually}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] Drop all tables and re-run all migrations - This command is disabled in RSpade framework';
}