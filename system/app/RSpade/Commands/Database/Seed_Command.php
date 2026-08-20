<?php

namespace App\RSpade\Commands\Database;

use Illuminate\Database\Console\Seeds\SeedCommand as LaravelSeedCommand;

/**
 * Override Laravel's db:seed command to require migration mode
 *
 * Seeders modify database state and should be protected by the same
 * snapshot mechanism as migrations in development environments.
 */
class Seed_Command extends LaravelSeedCommand
{
    protected $description = 'Seed the database with records (requires migration mode in development)';

    protected $flag_file = '/var/www/html/.migrating';

    /**
     * Get the console command options
     */
    protected function getOptions()
    {
        return array_merge(parent::getOptions(), [
            ['production', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Run in production mode, skipping migration mode requirement'],
        ]);
    }

    public function handle()
    {
        // Check if we're in production mode (either via flag or environment)
        $is_production = $this->option('production') || app()->environment('production');

        // Only enforce migration mode in development mode without --production flag
        $require_migration_mode = !$is_production;

        // Check for migration mode if we require it
        if ($require_migration_mode) {
            if (!file_exists($this->flag_file)) {
                $this->error('[ERROR] Migration mode not active!');
                $this->error('');
                $this->line('In development mode, seeders must be run within a migration session.');
                $this->line('This prevents data corruption if seeding fails.');
                $this->info('');
                $this->line('To seed the database, use the --seed flag with migrate:');
                $this->line('   php artisan migrate --seed');
                $this->info('');
                $this->line('Or use the --production flag to skip snapshot protection:');
                $this->line('   php artisan db:seed --production');

                return 1;
            }

            $this->info('[OK] Migration mode active - snapshot available for rollback');
            $this->info('');
        }

        // Call parent handle to run the actual seeding
        return parent::handle();
    }

    /**
     * Get a seeder instance from the container
     * Override to check RSX seeders directory first
     *
     * @return \Illuminate\Database\Seeder
     */
    protected function getSeeder()
    {
        $class = $this->input->getArgument('class') ?? $this->input->getOption('class');

        // If it's a simple class name, try to find it in RSX seeders directory
        if ($class && !str_contains($class, '\\')) {
            $seeder_path = \App\RSpade\Core\Database\SeederPaths::get_default_path() . '/' . $class . '.php';

            if (file_exists($seeder_path)) {
                // Load the seeder file directly
                require_once $seeder_path;

                // Check if class exists now
                if (!class_exists($class)) {
                    throw new \RuntimeException("Seeder file found at {$seeder_path} but class {$class} not defined");
                }

                // Create instance directly since it's not in the container
                $instance = new $class();
                return $instance->setContainer($this->laravel)->setCommand($this);
            }
        }

        // Fall back to parent implementation for namespaced seeders
        return parent::getSeeder();
    }
}
