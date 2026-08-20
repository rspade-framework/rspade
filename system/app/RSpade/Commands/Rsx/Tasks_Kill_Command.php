<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Task_Killer;

/**
 * Force-kill ONE running task by its _tasks row id: SIGTERM, 5s grace, SIGKILL. An
 * --explanation is required and recorded on the task (status KILLED, or PENDING if it is a
 * recurring cron tracker).
 */
class Tasks_Kill_Command extends Command
{
    protected $signature = 'rsx:tasks:kill
        {id : The _tasks row id of the running task to kill}
        {--explanation= : Required human reason recorded on the killed task}';

    protected $description = 'Force-kill a running task (SIGTERM, 5s grace, SIGKILL) and mark it KILLED';

    public function handle()
    {
        $explanation = trim((string) $this->option('explanation'));
        if ($explanation === '') {
            $this->error('An --explanation="..." is required to kill a task.');
            return 1;
        }

        $id = (int) $this->argument('id');
        $row = DB::table('_tasks')->where('id', $id)->first();
        if (!$row) {
            $this->error("No task with id {$id}.");
            return 1;
        }
        if ($row->status !== Task_Status::RUNNING) {
            $this->error("Task {$id} is not running (status: {$row->status}).");
            return 1;
        }

        $outcome = Task_Killer::kill($row, $explanation);
        $this->info("Task {$id} ({$row->class}::{$row->method}) -> {$outcome}.");
        return 0;
    }
}
