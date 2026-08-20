<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */


namespace App\Console\Commands;

use Illuminate\Console\Command;

class GetEnvironment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'env';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the current application environment';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->line(app()->environment());
        return 0;
    }
}