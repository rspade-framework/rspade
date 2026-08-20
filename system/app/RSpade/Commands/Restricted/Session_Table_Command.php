<?php

namespace App\RSpade\Commands\Restricted;

use App\RSpade\Commands\Restricted\Restricted_Database_Command;

/**
 * Restricted version of session:table command
 * 
 * This command is disabled in the RSpade framework because:
 * - Database structure is managed through migrations
 * - Tables should be created via forward-only migrations
 * - Prevents scaffold commands from altering database structure
 */
class Session_Table_Command extends Restricted_Database_Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'session:table';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command has been restricted in RSX';
    
    /**
     * Get the specific reason this command is restricted
     *
     * @return string
     */
    protected function get_restricted_reason(): string
    {
        return "The session:table command creates database tables outside of the migration system.\n\n" .
               "In the RSpade framework, all database tables must be created through migrations.\n" .
               "If you need database-based sessions, create a migration:\n" .
               "  php artisan make:migration:safe create_sessions_table\n\n" .
               "Then define the table structure in the migration file.";
    }
    
    /**
     * Get the alternative approach message
     *
     * @return string
     */
    protected function get_alternative_approach(): string
    {
        return "To use database sessions:\n" .
               "1. Create a migration: php artisan make:migration:safe create_sessions_table\n" .
               "2. Define the sessions table structure in the migration\n" .
               "3. Run migrations: php artisan migrate\n" .
               "4. Set SESSION_DRIVER=database in your .env file\n\n" .
               "Note: File-based sessions (default) work well for most applications.";
    }
}