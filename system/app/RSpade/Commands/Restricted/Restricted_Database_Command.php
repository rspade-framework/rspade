<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

/**
 * Base class for restricted database commands in the RSpade framework
 * 
 * This class provides a standardized response for database operations that are
 * intentionally restricted to maintain data integrity and provide guardrails
 * for automated systems and AI agents.
 */
abstract class Restricted_Database_Command extends Command
{
    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] This command has been restricted in RSX';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->error('This command has been restricted in RSX.');
        $this->newLine();
        $this->warn('Database Migration Policy:');
        $this->info('RSX enforces a forward-only migration strategy to ensure data integrity and system stability.');
        $this->newLine();
        $this->line('- Only forward migrations are permitted (php artisan migrate)');
        $this->line('- Rollback operations are disabled to prevent data loss');
        $this->line('- Database resets and refresh operations are not allowed');
        $this->line('- Schema modifications must be handled through new migrations');
        $this->newLine();
        $this->info('This policy provides essential guardrails for:');
        $this->line('- Production environment safety');
        $this->line('- Automated deployment systems');
        $this->line('- AI-assisted development workflows');
        $this->line('- Multi-tenant data integrity');
        $this->newLine();
        $this->warn('Alternative approaches:');
        $this->line('- To modify schema: Create a new migration file');
        $this->line('- To fix issues: Create corrective migrations');
        $this->line('- For development: Use database snapshots or dumps');
        $this->newLine();
        
        return 1; // Return error code
    }
}