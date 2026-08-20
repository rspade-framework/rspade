<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;

/**
 * Rsx_Migration_Notice_Provider - Shows notice after migration creation
 *
 * REGISTERED IN: config/app.php line ~214 in 'providers' array
 *
 * WHAT IT DOES:
 * Listens for CommandFinished event and when make:migration completes successfully,
 * outputs a notice reminding developers that:
 * - RSpade framework does not use down() migration methods
 * - Migrations should only move forward, never backward
 *
 * PURPOSE:
 * Educates developers about RSX's forward-only migration philosophy.
 * Prevents confusion about why down() methods aren't used.
 *
 * PHILOSOPHY:
 * - Production databases should never be rolled back
 * - If a migration is bad, write a new migration to fix it
 * - In development mode, `php artisan migrate` auto-creates snapshots
 * - Failed migrations automatically rollback to pre-migration state
 */
#[Instantiatable]
class Rsx_Migration_Notice_Provider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Listen for when migration commands finish
        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            // Check if this was a make:migration command
            if ($event->command === 'make:migration' && $event->exitCode === 0) {
                // Output the RSpade framework notice
                $event->output->writeln('');
                $event->output->writeln('  <bg=yellow;fg=black> NOTICE </> Please note: RSpade framework does not use down() migration methods - migrations should only move forward, never backward');
                $event->output->writeln('');
            }
        });
    }
}