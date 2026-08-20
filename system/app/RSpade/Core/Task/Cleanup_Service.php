<?php

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Status;

/**
 * Cleanup Service
 *
 * System maintenance tasks that run on a schedule.
 * Handles cleanup of old data and temporary files.
 */
class Cleanup_Service extends Rsx_Service_Abstract
{
    /**
     * Clean up old completed/failed tasks
     *
     * Deletes task records older than the retention period (default: 30 days)
     * Keeps pending/running tasks regardless of age.
     *
     * Runs daily at 3 AM
     */
    #[Task_Attribute('Clean up old completed and failed task records')]
    #[Schedule('0 3 * * *')]
    public static function cleanup_old_tasks(Task_Instance $task, array $params = []): array
    {
        $retention_days = config('rsx.tasks.task_retention_days', 30);
        $cutoff_date = now()->subDays($retention_days);

        $task->info("Cleaning up tasks older than {$retention_days} days (before {$cutoff_date})");

        // Delete old completed tasks
        $deleted_completed = DB::table('_tasks')
            ->where('status', Task_Status::COMPLETED)
            ->where('completed_at', '<', $cutoff_date)
            ->delete();

        $task->info("Deleted {$deleted_completed} completed task(s)");

        // Delete old failed tasks
        $deleted_failed = DB::table('_tasks')
            ->where('status', Task_Status::FAILED)
            ->where('completed_at', '<', $cutoff_date)
            ->delete();

        $task->info("Deleted {$deleted_failed} failed task(s)");

        $total_deleted = $deleted_completed + $deleted_failed;

        return [
            'deleted_completed' => $deleted_completed,
            'deleted_failed' => $deleted_failed,
            'total_deleted' => $total_deleted,
            'retention_days' => $retention_days,
        ];
    }

    /**
     * Clean up orphaned task temporary directories
     *
     * Removes temp directories for tasks that no longer exist or are completed.
     * Checks the temp_expires_at timestamp for cleanup eligibility.
     *
     * Runs every hour
     */
    #[Task_Attribute('Clean up orphaned task temporary directories')]
    #[Schedule('0 * * * *')]
    public static function cleanup_temp_directories(Task_Instance $task, array $params = []): array
    {
        $base_temp_dir = storage_path('rsx-tmp/tasks');

        if (!is_dir($base_temp_dir)) {
            $task->info("Temp directory does not exist: {$base_temp_dir}");
            return ['directories_removed' => 0];
        }

        $task->info("Scanning temp directory: {$base_temp_dir}");

        $directories_removed = 0;
        $directories = File::directories($base_temp_dir);

        foreach ($directories as $dir) {
            $dir_name = basename($dir);

            // Check if it's an immediate task temp dir (these should be cleaned up by the task itself)
            if (str_starts_with($dir_name, 'immediate_')) {
                // Check if directory is old (more than 1 hour)
                $dir_time = filemtime($dir);
                if (time() - $dir_time > 3600) {
                    $task->info("Removing old immediate temp directory: {$dir_name}");
                    File::deleteDirectory($dir);
                    $directories_removed++;
                }
                continue;
            }

            // Extract task ID from directory name (format: task_123)
            if (preg_match('/^task_(\d+)$/', $dir_name, $matches)) {
                $task_id = (int) $matches[1];

                // Check if task still exists
                $task_row = DB::table('_tasks')->where('id', $task_id)->first();

                if (!$task_row) {
                    // Task doesn't exist, remove directory
                    $task->info("Removing orphaned temp directory for deleted task: {$dir_name}");
                    File::deleteDirectory($dir);
                    $directories_removed++;
                    continue;
                }

                // Check if task is completed/failed
                if (in_array($task_row->status, [Task_Status::COMPLETED, Task_Status::FAILED])) {
                    $task->info("Removing temp directory for completed/failed task: {$dir_name}");
                    File::deleteDirectory($dir);
                    $directories_removed++;
                    continue;
                }
            }
        }

        $task->info("Removed {$directories_removed} temp director(ies)");

        return [
            'directories_removed' => $directories_removed,
            'total_directories_scanned' => count($directories),
        ];
    }
}
