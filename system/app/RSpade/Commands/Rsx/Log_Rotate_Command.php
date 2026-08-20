<?php

namespace App\RSpade\Commands\Rsx;

use App\Console\Commands\FrameworkDeveloperCommand;
use App\RSpade\Core\Debug\Debugger;

/**
 * RSX Log Rotate Command
 *
 * Rotates all development logs (Laravel and nginx).
 * Only available in non-production environments.
 */
class Log_Rotate_Command extends FrameworkDeveloperCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:logrotate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotate all development logs (Laravel and nginx) - development only';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Check environment - throw fatal error in production
        if (app()->environment('production')) {
            throw new \RuntimeException('FATAL: rsx:logrotate command is not available in production environment. This is a development-only debugging tool.');
        }
        
        $this->info('Rotating development logs...');
        
        // Rotate all logs
        Debugger::logrotate();
        
        $this->info('[OK] Logs rotated successfully:');
        $this->info('  - Laravel log');
        $this->info('  - Nginx access log');
        $this->info('  - Nginx error log');
        
        return 0;
    }
}