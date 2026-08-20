<?php

namespace App\RSpade\Commands\Restricted;

use App\RSpade\Commands\Restricted\Restricted_Database_Command;

/**
 * Restricted version of notifications:table command
 * 
 * This command is disabled in the RSpade framework because:
 * - Database structure is managed through migrations
 * - Tables should be created via forward-only migrations
 * - Prevents scaffold commands from altering database structure
 */
class Notification_Table_Command extends Restricted_Database_Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:table';

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
        return "The notifications:table command creates database tables outside of the migration system.\n\n" .
               "In the RSpade framework, all database tables must be created through migrations.\n" .
               "If you need a notifications table, create a migration:\n" .
               "  php artisan make:migration:safe create_notifications_table\n\n" .
               "Then define the table structure in the migration file.";
    }
    
    /**
     * Get the alternative approach message
     *
     * @return string
     */
    protected function get_alternative_approach(): string
    {
        return "To add notification support:\n" .
               "1. Create a migration: php artisan make:migration:safe create_notifications_table\n" .
               "2. Define the notifications table structure in the migration\n" .
               "3. Run migrations: php artisan migrate\n\n" .
               "This ensures your database structure is version-controlled and reproducible.";
    }
}