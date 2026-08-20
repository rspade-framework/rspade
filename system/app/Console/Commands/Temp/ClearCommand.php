<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\Console\Commands\Temp;

use Illuminate\Console\Command;
use App\RSpade\Core\Build_Manager;

class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'temp:clear 
                            {--older-than=0 : Clear files older than N hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear temporary files';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $older_than = (int) $this->option('older-than');
        
        if ($older_than > 0) {
            $count = Build_Manager::clear_temp($older_than);
            $this->info("Cleared $count temporary files older than $older_than hours.");
        } else {
            $count = Build_Manager::clear_temp();
            $this->info("Cleared all $count temporary files.");
        }
        
        return 0;
    }
}