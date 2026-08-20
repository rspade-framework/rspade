<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

class KeyGenerateSafe extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'key:generate:safe
                            {--show : Display the key instead of modifying files}
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate application key only if one does not exist (prevents accidental regeneration)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $current_key = config('app.key');

        // Check if key already exists
        if ($current_key) {
            $this->error('Application key already exists!');
            $this->newLine();
            $this->line('Current key: ' . $current_key);
            $this->newLine();
            $this->warn('Regenerating the application key will:');
            $this->warn('  - Invalidate all existing sessions');
            $this->warn('  - Break all encrypted data');
            $this->warn('  - Require all users to log in again');
            $this->newLine();
            $this->error('If you really need to regenerate, use: php artisan key:generate --force');
            $this->newLine();

            return 1;
        }

        // Generate new key
        $key = $this->generate_random_key();

        if ($this->option('show')) {
            $this->line('<comment>' . $key . '</comment>');
            return 0;
        }

        // Check if .env file exists
        $env_file = $this->laravel->environmentFilePath();
        if (!file_exists($env_file)) {
            $this->error('.env file not found at: ' . $env_file);
            return 1;
        }

        // Set key in .env file
        if (!$this->set_key_in_environment_file($key)) {
            $this->error('Failed to write key to .env file');
            return 1;
        }

        $this->laravel['config']['app.key'] = $key;

        $this->info('Application key set successfully.');

        return 0;
    }

    /**
     * Generate a random key for the application.
     */
    protected function generate_random_key(): string
    {
        return 'base64:' . base64_encode(
            Encrypter::generateKey($this->laravel['config']['app.cipher'])
        );
    }

    /**
     * Set the application key in the environment file.
     */
    protected function set_key_in_environment_file(string $key): bool
    {
        $env_file = $this->laravel->environmentFilePath();
        $current_key = $this->laravel['config']['app.key'];

        if (strlen($current_key) !== 0) {
            // Replace existing key
            $replaced = preg_replace(
                $this->key_replacement_pattern(),
                'APP_KEY=' . $key,
                $input = file_get_contents($env_file)
            );

            if ($replaced === $input || $replaced === null) {
                $this->error('Unable to set application key. Manual setting required.');
                return false;
            }
        } else {
            // Add new key
            $replaced = file_get_contents($env_file) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;
        }

        file_put_contents($env_file, $replaced);

        return true;
    }

    /**
     * Get a regex pattern that will match APP_KEY with any random key.
     */
    protected function key_replacement_pattern(): string
    {
        $escaped = preg_quote('=' . $this->laravel['config']['app.key'], '/');

        return "/^APP_KEY{$escaped}/m";
    }
}
