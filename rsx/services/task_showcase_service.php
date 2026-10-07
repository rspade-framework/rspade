<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Services;

use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Task_Showcase_Service - a background task that uses every report a task can make, so the
 * task widgets and the developer console have something to show.
 *
 * It walks a short work list, one item a second: the status line, ONE progress indicator (a
 * count; the percentage is derived from it), an ETA, a heartbeat, a JSON state object, the
 * remaining queue as a list, stdout and stderr lines, a message per milestone, a small CSV
 * attachment and a completion summary. It checks for a stop request and beats its heartbeat
 * on every item, so a graceful stop ends it cleanly (STOPPED). The CSV is the one shape an
 * attachment is for: a file the user who started the run is waiting to download.
 *
 * Started by a user from the Tasks screen (System_Tasks_Controller::start_showcase),
 * which is what makes it "theirs" to the application's task gates (rsx/handlers).
 */
class Task_Showcase_Service extends Rsx_Service_Abstract
{
    #[Task('Walk a short work list, reporting every kind of task status along the way')]
    public static function walk(Task_Instance $task, array $params = [])
    {
        $items = (int) ($params['items'] ?? 20);
        if ($items < 1 || $items > 500) {
            $task->stderr("items must be 1 to 500, got {$items}");

            return 2;
        }

        $queue = [];
        for ($i = 1; $i <= $items; $i++) {
            $queue[] = "item {$i}";
        }

        $csv = "item,processed_at\n";
        $done = 0;

        $task->status('Starting');
        $task->stdout("Walking {$items} item(s)");

        foreach ($queue as $index => $item) {
            if ($task->is_stop_requested()) {
                $task->status("Stopped after {$done} of {$items}");
                $task->summary("Stopped on request after {$done} of {$items} item(s).");

                return null;
            }

            $task->status("Processing {$item}");
            $task->state_list(array_slice($queue, $index));

            // The work itself: one second per item, so a watcher can follow it.
            sleep(1);

            $done++;
            $csv .= "{$item}," . date('c') . "\n";
            $task->stdout("Processed {$item}");
            if ($done % 5 === 0) {
                $task->stderr("Checkpoint at {$done} of {$items} (written to stderr)");
                $task->message("{$done} of {$items} done");
            }

            $task->progress_count($done, $items);
            $task->eta($items - $done);
            $task->heartbeat();
            $task->state([
                'done' => $done,
                'remaining' => $items - $done,
                'last_item' => $item,
                'checkpoints' => intdiv($done, 5),
            ]);
        }

        $task->state_list([]);
        $task->attach_bytes('processed.csv', $csv, 'processed.csv', 'text/csv');
        $task->status('Done');
        $task->summary("Walked all {$items} item(s).");

        return null;
    }
}
