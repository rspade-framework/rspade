<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * Laravel's scheduler is NOT the framework's scheduling story - there is no
 * schedule() override here on purpose. Recurring work is declared with
 * #[Schedule] on a #[Task] method and driven by the rsx:task:process cron.
 */
class Kernel extends ConsoleKernel
{
    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');           // Application commands
        $this->load(__DIR__.'/../RSpade/Commands'); // Framework commands

        require base_path('routes/console.php');
    }

}
