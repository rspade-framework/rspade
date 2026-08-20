<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

/**
 * Override Laravel's key:generate to prevent accidental key regeneration
 *
 * This command replaces the default key:generate behavior with a safer version
 * that requires explicit --force flag when a key already exists.
 */
class KeyGenerateOverride extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'key:generate
                            {--show : Display the key instead of modifying files}
                            {--force : Force the operation even if key exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the application key (requires --force if key exists)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $current_key = config('app.key');

        // Check if key already exists and --force not provided
        if ($current_key && !$this->option('force')) {
            $this->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->error('  APPLICATION KEY ALREADY EXISTS');
            $this->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->newLine();
            $this->line('Current key: <fg=yellow>' . substr($current_key, 0, 20) . '...</>');
            $this->newLine();
            $this->warn('⚠️  Regenerating the application key will cause:');
            $this->warn('   • All existing encrypted data becomes unreadable');
            $this->warn('   • All user sessions invalidated (everyone logged out)');
            $this->warn('   • All password reset tokens invalidated');
            $this->warn('   • All remember-me cookies invalidated');
            $this->newLine();
            $this->error('If you really need to regenerate (THIS IS DESTRUCTIVE):');
            $this->line('   <fg=red>php artisan key:generate --force</>');
            $this->newLine();
            $this->info('For first-time setup (when key is empty):');
            $this->line('   <fg=green>php artisan key:generate</>');
            $this->newLine();
            $this->error('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

            return 1;
        }

        // If --force provided with existing key, show additional warning
        if ($current_key && $this->option('force')) {
            $this->warn('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->warn('  DESTRUCTIVE OPERATION - REGENERATING APPLICATION KEY');
            $this->warn('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->newLine();
            $this->error('This will DESTROY all encrypted data and invalidate all sessions!');
            $this->newLine();

            if (!$this->confirm('Are you absolutely sure you want to continue?', false)) {
                $this->info('Operation cancelled.');
                return 0;
            }

            $this->newLine();
        }

        // Generate new key
        $key = $this->generate_random_key();

        if ($this->option('show')) {
            $this->line('<comment>' . $key . '</comment>');
            return 0;
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
