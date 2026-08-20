<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class Cache_Clear_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:clear 
                            {store? : The name of the store you would like to clear}
                            {--tags=* : The cache tags you would like to clear}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush the application cache and RSX caches';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Run the original Laravel cache:clear command by directly invoking it
        $cache_manager = app('cache');
        $store = $this->argument('store') ?: $cache_manager->getDefaultDriver();
        $tags = $this->option('tags');
        
        $cache = $cache_manager->store($store);
        
        if ($tags && !method_exists($cache, 'tags')) {
            $this->error('This cache store does not support tagging.');
            return 1;
        }
        
        $successful = $tags ? $cache->tags($tags)->flush() : $cache->flush();
        
        if (!$successful) {
            $this->error('Failed to clear cache.');
            return 1;
        }
        
        $this->info("Application cache cleared for store [{$store}]!");
        
        // Also clear RSX caches (caches only - a cache-clear command never touches git state).
        // --_no-system-reset is a framework-internal flag stripped from argv pre-boot, so an
        // in-process Artisan::call cannot pass it as an option: declare it for this process.
        $this->info('Clearing RSX caches...');
        \App\RSpade\Core\Console\Rsx_Internal_Flags::set(\App\RSpade\Commands\Rsx\Clean_Command::FLAG_NO_SYSTEM_RESET);
        Artisan::call('rsx:clean', [], $this->output);
        
        return 0;
    }
}