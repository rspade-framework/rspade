<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Task_Killer;

/**
 * Force-kill ALL running tasks: SIGTERM, 5s grace, SIGKILL each. Used by rsx:framework:pull to
 * quiesce background work before an update. An --explanation is required and recorded on every
 * killed task. On-demand tasks go KILLED; recurring cron tracker rows recycle to PENDING so their
 * schedule survives.
 */
class Tasks_Kill_All_Command extends Command
{
    protected $signature = 'rsx:tasks:kill-all
        {--explanation= : Required human reason recorded on every killed task}';

    protected $description = 'Force-kill ALL running tasks (SIGTERM, 5s grace, SIGKILL); cron trackers recycle to pending';

    public function handle()
    {
        $explanation = trim((string) $this->option('explanation'));
        if ($explanation === '') {
            $this->error('An --explanation="..." is required to kill tasks.');
            return 1;
        }

        $rows = DB::table('_tasks')->where('status', Task_Status::RUNNING)->get();
        if ($rows->isEmpty()) {
            $this->info('No running tasks to kill.');
            return 0;
        }

        $killed = 0;
        $recycled = 0;
        foreach ($rows as $row) {
            $outcome = Task_Killer::kill($row, $explanation);
            if ($outcome === 'recycled') {
                $recycled++;
            } else {
                $killed++;
            }
            $this->line("  {$row->id} {$row->class}::{$row->method} -> {$outcome}");
        }

        $this->info("Killed {$killed} task(s)" . ($recycled ? ", recycled {$recycled} cron tracker(s)" : '') . '.');
        return 0;
    }
}
