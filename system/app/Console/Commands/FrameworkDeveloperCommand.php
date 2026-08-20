<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Base class for framework developer commands
 *
 * Commands extending this class will be hidden from the artisan command list
 * unless IS_FRAMEWORK_DEVELOPER=true is set in the .env file.
 *
 * These commands can still be called directly, but are hidden to avoid
 * confusion for end users who shouldn't need to use them.
 */
abstract class FrameworkDeveloperCommand extends Command
{
    /**
     * Hide command from list unless framework developer mode is enabled
     *
     * @var bool
     */
    protected $hidden = false;
    
    /**
     * Create a new command instance
     */
    public function __construct()
    {
        parent::__construct();
        
        // Hide command unless IS_FRAMEWORK_DEVELOPER flag is set
        $this->hidden = !env('IS_FRAMEWORK_DEVELOPER', false);
    }
    
    /**
     * Get the console command description with framework developer indicator
     *
     * @return string
     */
    public function getDescription(): string
    {
        $description = parent::getDescription();

        // Add prefix if in framework developer mode
        if (env('IS_FRAMEWORK_DEVELOPER', false)) {
            return "[Framework Dev] {$description}";
        }

        return $description;
    }
}