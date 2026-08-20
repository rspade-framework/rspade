<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Rsx;
use Illuminate\Console\Command;
use App\RSpade\Core\Console\Rsx_Artisan;

/**
 * Set the application mode (development, debug, or production).
 *
 * Kept for muscle memory - it is a thin delegator over the prod-mode commands so
 * there is exactly ONE implementation of each transition:
 *   - dev   -> rsx:prod:disable  (unseal + return to development)
 *   - debug -> rsx:prod:enable --debug
 *   - prod  -> rsx:prod:enable
 *
 * Delegation is via passthru (a fresh process), matching how the prod-mode
 * commands manage their own subprocesses and in-memory cache lifecycle.
 */
class Mode_Set_Command extends Command
{
    protected $signature = 'rsx:mode:set
                            {mode : Mode to set (dev|development|debug|prod|production)}';

    protected $description = 'Set the application mode (delegates to the rsx:prod:* commands)';

    public function handle(): int
    {
        $mode = strtolower($this->argument('mode'));

        // [command, args] rather than one string: Rsx_Artisan escapes each argv token
        // itself, so the pieces stay pieces all the way to the child.
        $delegate = match ($mode) {
            'dev', 'development' => ['rsx:prod:disable', []],
            'debug' => ['rsx:prod:enable', ['--debug']],
            'prod', 'production' => ['rsx:prod:enable', []],
            default => null,
        };

        if ($delegate === null) {
            $this->error("Invalid mode: {$mode}");
            $this->line('');
            $this->line('Valid modes:');
            $this->line('  dev, development  - Auto-rebuild, full debugging (unseals)');
            $this->line('  debug             - Sealed build, unminified + sourcemaps + console_debug');
            $this->line('  prod, production  - Sealed build, fully optimized');

            return 1;
        }

        [$delegate_command, $delegate_args] = $delegate;

        $this->line("rsx:mode:set {$mode} -> php artisan " . trim($delegate_command . ' ' . implode(' ', $delegate_args)));
        $this->newLine();

        return Rsx_Artisan::passthru($delegate_command, $delegate_args);
    }
}
