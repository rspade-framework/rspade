<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Dependencies\Dependency_Manager;
use Illuminate\Console\Command;

class Framework_Post_Update_Dependency_Check_Command extends Command
{
    protected $signature = 'rsx:framework:post_update_dependency_check';

    protected $description = '[internal] Reconcile the app dependency layer against the framework after a pull';

    protected $hidden = true;

    public function handle()
    {
        // Purely local, zero network - a framework pull must stay offline-capable.
        // If there is no root composer.json this is not an RSpade-project layout
        // (framework-only install); there is nothing to reconcile.
        if (!file_exists(Dependency_Manager::root_composer_json_path())) {
            return 0;
        }

        // Refresh the app-layer replace map against the (possibly updated) framework
        // installed set. A change here is routine after a framework update that moved
        // framework deps - report it as information, not a warning.
        if (Dependency_Manager::regenerate_replace_map()) {
            $this->line('Framework dependency baseline refreshed (app-layer composer replace map regenerated).');
        }

        // Notices for recorded framework-provided packages that drifted. These are
        // findings, not failures - never a non-zero exit.
        foreach (Dependency_Manager::check_recorded_composer() as $problem) {
            $this->_report_problem('composer', $problem);
        }

        foreach (Dependency_Manager::check_recorded_npm() as $problem) {
            $this->_report_problem('npm', $problem);
        }

        // All-clean with no map change: completely silent (Unix silent-success).
        return 0;
    }

    /**
     * Print one actionable notice for a drifted recorded provided package.
     * $platform is 'composer' or 'npm' (selects the adopt command in the remedy).
     */
    private function _report_problem(string $platform, array $problem): void
    {
        $package = $problem['package'];
        $adopt = $platform === 'npm' ? 'rsx:npm install' : 'rsx:composer require';

        $this->line('');

        if ($problem['problem'] === 'removed') {
            $this->line("The framework no longer provides '{$package}' (you recorded it at {$problem['recorded']}). Run: php artisan {$adopt} {$package}  to adopt it into your application layer.");
            return;
        }

        // major_change
        $this->line("Framework-provided '{$package}' moved {$problem['recorded']} -> {$problem['installed']} (major version change). Review your usage; see pending upstream_changes documents. Re-record with: php artisan {$adopt} {$package}");
    }
}
