<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Prod\Rsx_Env_Symlink;
use Illuminate\Console\Command;

/**
 * Restore the .env symlink invariant (system/.env -> the authoritative root .env).
 *
 * Runs automatically after a framework pull, on rsx:clean, and before every
 * prod-mode transition. This command is the manual entry point + the drift check
 * (--dry-run). Root value wins on a merge conflict; discarded system values are
 * always reported, never silently dropped.
 */
class Env_Heal_Command extends Command
{
    protected $signature = 'rsx:env:heal
                            {--dry-run : Report drift without changing anything}';

    protected $description = 'Heal the .env symlink invariant (root .env is authoritative)';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $report = Rsx_Env_Symlink::get_drift_report();
            $this->_print_report($report, true);

            return 0;
        }

        $report = Rsx_Env_Symlink::heal();
        $this->_print_report($report, false);

        return 0;
    }

    /**
     * Print the heal / drift report as plain professional ASCII.
     */
    private function _print_report(array $report, bool $dry_run): void
    {
        $status = $report['status'];

        if ($dry_run) {
            $this->line('env healer (dry run) - status would be: ' . $status);
        } else {
            $this->line('env healer - status: ' . $status);
        }

        foreach ($report['actions'] as $action) {
            $this->line('  - ' . $action);
        }

        $merged = $report['merged_keys'] ?? [];
        if (!empty($merged)) {
            $this->newLine();
            $this->line('Merged keys (unique to system/.env, moved to root .env):');
            foreach ($merged as $key) {
                $this->line('  + ' . $key);
            }
        }

        $overridden = $report['overridden_keys'] ?? [];
        if (!empty($overridden)) {
            $this->newLine();
            $this->line('Overridden keys (root value kept; system value discarded):');
            foreach ($overridden as $row) {
                $this->line('  ! ' . $row['key']
                    . '  root=' . $row['root_value']
                    . '  (discarded system=' . $row['system_value'] . ')');
            }
        }

        if (!$dry_run && !empty($report['backup_path'])) {
            $this->newLine();
            $this->line('Backup of the replaced system/.env: ' . $report['backup_path'] . ' (0600)');
        }
    }
}
