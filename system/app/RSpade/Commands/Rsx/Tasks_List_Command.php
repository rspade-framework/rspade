<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Status;

/**
 * List live task state: the RUNNING instances (with worker PIDs), then every recurring
 * #[Schedule] tracker row with its cadence and failure record. Distinct from
 * rsx:task:list, which lists task DEFINITIONS from the manifest and touches no database.
 */
class Tasks_List_Command extends Command
{
    protected $signature = 'rsx:tasks:list';
    protected $description = 'List running task instances (with worker PIDs) and the schedule trackers';

    /** Characters of the failure explanation shown in the Schedules table. */
    private const ERROR_EXCERPT_LENGTH = 50;

    public function handle()
    {
        $this->list_running_tasks();
        $this->newLine();
        $this->list_schedules();

        return 0;
    }

    /**
     * Task instances currently executing, with the worker PID that owns each.
     */
    private function list_running_tasks(): void
    {
        $rows = DB::table('_tasks')
            ->where('status', Task_Status::RUNNING)
            ->orderBy('started_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No running tasks.');
            return;
        }

        $this->table(
            ['ID', 'Task', 'Worker PID', 'Started', 'Last heartbeat', 'Kind'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->class . '::' . $r->method,
                $r->worker_pid ?? '-',
                $r->started_at ?? '-',
                $r->last_heartbeat_at ?? '-',
                $r->next_run_at !== null ? 'cron' : 'on-demand',
            ])->all()
        );
    }

    /**
     * Every recurring #[Schedule] tracker row: its cadence and its failure record.
     *
     * A tracker is never terminal - it recycles to PENDING after every run, success or
     * failure - so status alone says nothing about health. The failure columns do:
     * consecutive_failures counts runs that threw since the last success, and completed_at
     * on a tracker is the LAST SUCCESSFUL run.
     *
     * The whole set is listed, unpaginated: there is exactly one row per #[Schedule] in the
     * manifest, so this is bounded by the codebase, not by customer activity.
     */
    private function list_schedules(): void
    {
        $rows = DB::table('_tasks')
            ->whereNotNull('next_run_at')
            ->get()
            // Sort by the DISPLAYED name (class basename), not the FQCN - services should
            // group alphabetically as the operator reads them, not by namespace.
            ->sortBy(fn ($r) => class_basename($r->class) . '::' . $r->method)
            ->values();

        if ($rows->isEmpty()) {
            $this->info('No schedules registered.');
            return;
        }

        $this->info('Schedules');

        $this->table(
            ['Task', 'Status', 'Next run', 'Last success', 'Failures', 'Last error'],
            $rows->map(function ($r) {
                $failures = (int) ($r->consecutive_failures ?? 0);

                return [
                    class_basename($r->class) . '::' . $r->method,
                    $r->status,
                    $r->next_run_at ?? '-',
                    $r->completed_at ?? '-',
                    $failures > 0 ? $failures : '-',
                    $failures > 0 ? $this->describe_last_error($r) : '',
                ];
            })->all()
        );
    }

    /**
     * "<last_error_at>: <excerpt>" for a tracker with a live failure streak.
     *
     * status_reason is the one-line summary the recycle writes; error is the full text and
     * is used when a row carries no summary (a pre-fix strand revived by the backstop).
     *
     * @param object $row A _tasks tracker row with consecutive_failures > 0.
     */
    private function describe_last_error(object $row): string
    {
        $when = $row->last_error_at ?? '-';
        $text = trim((string) ($row->status_reason ?? $row->error ?? ''));

        if ($text === '') {
            return $when;
        }

        $text = trim(explode("\n", $text)[0]);

        if (mb_strlen($text) > self::ERROR_EXCERPT_LENGTH) {
            $text = mb_substr($text, 0, self::ERROR_EXCERPT_LENGTH - 3) . '...';
        }

        return $when . ': ' . $text;
    }
}
