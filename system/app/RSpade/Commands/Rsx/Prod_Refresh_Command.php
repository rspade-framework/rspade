<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Prod\Rsx_Prod_Env;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;
use Illuminate\Console\Command;
use App\RSpade\Core\Console\Rsx_Artisan;

/**
 * Rebuild the sealed prod assets in place.
 *
 * Requires an existing seal. Re-runs the build pipeline (authorized) and rewrites
 * the seal. Keeps the prior mode unless --debug is given, which switches the
 * sealed build to the debug variant.
 */
class Prod_Refresh_Command extends Command
{
    protected $signature = 'rsx:prod:refresh
                            {--debug : Switch the sealed build to the debug variant}';

    protected $description = 'Rebuild and re-seal the production assets';

    public function handle(): int
    {
        if (!Rsx_Prod_Seal::exists()) {
            $this->error('Not in prod mode - there is no sealed build to refresh.');
            $this->line('  Use rsx:prod:enable to compile and seal a build first.');

            return 1;
        }

        // Heal FIRST: ensure system/.env is a symlink to the authoritative root
        // .env before the RSX_MODE write below, so the mode lands in one file.
        $env_report = \App\RSpade\Core\Prod\Rsx_Env_Symlink::heal();
        if ($env_report['status'] !== 'already_healthy') {
            $this->line('.env symlink invariant restored (status: ' . $env_report['status'] . ').');
        }

        $prior = Rsx_Prod_Seal::read();
        $prior_mode = $prior['rsx_mode'] ?? Rsx::MODE_PRODUCTION;

        // --debug present -> debug; absent -> keep whatever the seal was built for.
        $mode = $this->option('debug') ? Rsx::MODE_DEBUG : $prior_mode;

        $this->info('Refreshing sealed ' . $mode . ' build...');
        $this->newLine();

        // Authorize this process: the current .env already selects a prod-like mode,
        // so this process is sealed - guarded writes (incl. the seal rewrite) need it.
        Rsx_Prod_Seal::authorize_process();

        // Keep .env mode aligned (e.g. when --debug flips production -> debug).
        $this->line('  [1/3] Setting RSX_MODE=' . $mode . '...');
        Rsx_Prod_Env::set_mode($mode);

        $this->line('  [2/3] Clearing old build...');
        Rsx_Prod_Env::clear_laravel_caches();
        Rsx_Prod_Env::clear_rsx_caches();
        Rsx_Prod_Seal::_reset_cache();

        // The subprocess boots reading the RSX_MODE just written to .env, and carries
        // its force + authorization as INVOCATION FLAGS (never env prefixes).
        $this->line('  [3/3] Running build pipeline...');
        $this->newLine();

        $exit_code = Rsx_Artisan::passthru('rsx:prod:build', ['--force', Rsx_Prod_Seal::AUTHORIZED_FLAG]);

        if ($exit_code !== 0) {
            $this->newLine();
            $this->error('Build pipeline failed (exit ' . $exit_code . '). The seal was NOT rewritten.');

            return 1;
        }

        $seal = Rsx_Prod_Seal::write($mode);

        $this->newLine();
        $this->info('[OK] Re-sealed ' . $seal['rsx_mode'] . ' build');
        $this->line('     Build key: ' . $seal['build_key']);
        $this->line('     Assets:    ' . count($seal['assets']) . ' files');
        $this->line('     Sealed at: ' . $seal['created_at']);

        return 0;
    }
}
