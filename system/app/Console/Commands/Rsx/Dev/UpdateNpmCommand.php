<?php

namespace App\Console\Commands\Rsx\Dev;

use App\Console\Commands\FrameworkDeveloperCommand;
use Symfony\Component\Process\Process;

class UpdateNpmCommand extends FrameworkDeveloperCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:dev:update_npm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update all npm packages and rebuild framework bundles';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $system_dir = base_path();

        // Step 1: Run npm update
        $this->info('Running npm update...');
        $this->newLine();

        $npm_process = new Process(['npm', 'update'], $system_dir, null, null, 300);
        $npm_process->run(function ($type, $buffer) {
            echo $buffer;
        });

        if (!$npm_process->isSuccessful()) {
            $this->error('npm update failed');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('npm update completed successfully');
        $this->newLine();

        // Step 2: Run rsx:clean
        $this->info('Running rsx:clean...');
        $this->newLine();

        // Caches only - a dependency-update tool never touches git state.
        passthru('php artisan rsx:clean --_no-system-reset', $clean_exit);

        if ($clean_exit !== 0) {
            $this->error('rsx:clean failed');
            return self::FAILURE;
        }

        $this->newLine();

        // Step 3: Run rsx:bundle:compile
        $this->info('Running rsx:bundle:compile...');
        $this->newLine();

        passthru('php artisan rsx:bundle:compile', $compile_exit);

        if ($compile_exit !== 0) {
            $this->error('rsx:bundle:compile failed');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All operations completed successfully!');

        return self::SUCCESS;
    }
}
