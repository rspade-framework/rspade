<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

class View_Cache_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'view:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] Use optimize:cache instead to cache all Laravel components';

    /**
     * Hide this command from artisan list
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->error('This command has been restricted in RSX.');
        $this->line('');
        $this->info('Please use the following command instead:');
        $this->line('  php artisan optimize:cache');
        $this->line('');
        $this->comment('The optimize:cache command will cache all Laravel components including views.');
        
        return 1;
    }
}