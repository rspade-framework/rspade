<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Prod\Rsx_Prod_Env;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;
use Illuminate\Console\Command;
use App\RSpade\Core\Console\Rsx_Artisan;

/**
 * Leave prod mode and return to development.
 *
 * Removes the seal FIRST (so the subsequent cache clears are not blocked by the
 * immutability guard), switches RSX_MODE back to development, clears caches, and
 * pre-warms the dev bundle JIT. Idempotent: safe to run when not sealed.
 */
class Prod_Disable_Command extends Command
{
    protected $signature = 'rsx:prod:disable';

    protected $description = 'Leave sealed prod mode and return to development';

    public function handle(): int
    {
        // Heal FIRST: ensure system/.env is a symlink to the authoritative root
        // .env before the RSX_MODE write below, so the mode lands in one file.
        $env_report = \App\RSpade\Core\Prod\Rsx_Env_Symlink::heal();
        if ($env_report['status'] !== 'already_healthy') {
            $this->line('.env symlink invariant restored (status: ' . $env_report['status'] . ').');
        }

        $was_sealed = Rsx_Prod_Seal::exists();

        if ($was_sealed) {
            $this->info('Leaving prod mode (removing seal)...');
        } else {
            $this->info('Returning to development mode...');
        }
        $this->newLine();

        // Step 1: Remove the seal first so nothing downstream is guarded.
        $this->line('  [1/5] Removing seal...');
        Rsx_Prod_Seal::clear();

        // Step 2: Switch mode back to development.
        $this->line('  [2/5] Setting RSX_MODE=development...');
        Rsx_Prod_Env::set_mode(Rsx::MODE_DEVELOPMENT);

        // Step 3: Clear the prod caches.
        $this->line('  [3/5] Clearing caches...');
        Rsx_Prod_Env::clear_laravel_caches();
        Rsx_Prod_Env::clear_rsx_caches();

        // Step 4: Restore the development composer autoloader. A strict-production
        // build ran `composer dump-autoload --optimize --classmap-authoritative`,
        // which pins the system/ PSR-4 tree to a frozen classmap and FORBIDS the
        // filesystem fallback - so a class added or renamed after that dump would
        // not be found until the next dump. Returning to development must undo that:
        // a plain --optimize dump (NON-authoritative) rebuilds the classmap while
        // restoring the filesystem fallback dev relies on. --classmap-authoritative
        // is deliberately omitted here.
        $this->line('  [4/5] Restoring development composer autoloader...');
        if ($this->_composer_available()) {
            passthru(
                'bash -c ' . escapeshellarg('cd ' . escapeshellarg(base_path()) . ' && composer dump-autoload --optimize --no-interaction'),
                $composer_exit
            );
            if ($composer_exit !== 0) {
                $this->warn('      composer dump-autoload reported an error - run it manually if class loading misbehaves.');
            }
        } else {
            $this->warn('      composer not found on PATH - skipping autoloader restore.');
        }

        // Step 5: Pre-warm the dev bundle JIT in a fresh dev-mode subprocess. The
        // subprocess boots reading the RSX_MODE=development just written to .env
        // (step 2), so no env prefix is needed - invocation carries no env params.
        $this->line('  [5/5] Pre-warming development build...');
        $this->newLine();
        $exit_code = Rsx_Artisan::passthru('rsx:bundle:compile');
        if ($exit_code !== 0) {
            $this->warn('Bundle pre-warm reported warnings, but development mode is active.');
        }

        $this->newLine();
        $this->info('[OK] Development mode active - files auto-compile on change.');

        return 0;
    }

    /**
     * Is the composer binary available on PATH?
     */
    private function _composer_available(): bool
    {
        $path = trim((string) @shell_exec('bash -c ' . escapeshellarg('command -v composer 2>/dev/null')));

        return $path !== '';
    }
}
