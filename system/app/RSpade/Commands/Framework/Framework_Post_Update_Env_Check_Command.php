<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Prod\Rsx_Env_Symlink;
use Illuminate\Console\Command;

class Framework_Post_Update_Env_Check_Command extends Command
{
    protected $signature = 'rsx:framework:post_update_env_check';

    protected $description = '[internal] Restore the .env symlink invariant after a framework pull';

    protected $hidden = true;

    public function handle()
    {
        // A deploy/clone commonly materializes system/.env from a symlink into a
        // real file, drifting it from the authoritative root .env. Restore the
        // invariant so later post-update steps (and the running app) read one file.
        $report = Rsx_Env_Symlink::heal();

        // Unix silent-success: say nothing when it was already healthy.
        if ($report['status'] === 'already_healthy') {
            return 0;
        }

        $this->line('.env symlink invariant restored (status: ' . $report['status'] . ').');
        foreach ($report['actions'] as $action) {
            $this->line('  - ' . $action);
        }

        foreach ($report['overridden_keys'] as $row) {
            $this->line('  ! ' . $row['key'] . ': kept root value, discarded system value (' . $row['system_value'] . ').');
        }

        if (!empty($report['backup_path'])) {
            $this->line('  Backup: ' . $report['backup_path'] . ' (0600)');
        }

        return 0;
    }
}
