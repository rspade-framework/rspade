<?php

namespace App\RSpade\Commands\Restricted;

use App\RSpade\Commands\Restricted\Restricted_Database_Command;

/**
 * Override for db:wipe command
 * 
 * This command override prevents the execution of database wipe operations
 * in the RSpade framework to maintain data integrity and provide safety
 * guardrails for automated systems.
 */
class Wipe_Command extends Restricted_Database_Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:wipe 
                            {--database= : The database connection to use}
                            {--drop-views : Drop all tables and views}
                            {--drop-types : Drop all tables and types (Postgres only)}
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] Drop all tables, views, and types - This command is disabled in RSpade framework';
}