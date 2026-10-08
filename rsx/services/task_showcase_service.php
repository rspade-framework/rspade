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
 * remaining queue as a list, a stdout line per item, a stderr line for every seventh item (a
 * simulated transient failure, retried - stderr is for what went wrong without stopping the
 * run), a message per milestone, a small CSV
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

        // THE QUEUE: what is ahead, declared once and then advanced with the work. Each
        // finished item is one queue_pop(), so a watcher sees the head move - and a head that
        // has stopped moving is a run that is stuck. This list is short, so it is pushed
        // whole; a task with tens of thousands of items ahead pushes a window of the next
        // hundred and adds one item for each it pops (rsx:man tasks, THE QUEUE).
        $task->queue_clear();
        $task->queue_push_many($queue);

        foreach ($queue as $item) {
            if ($task->is_stop_requested()) {
                $task->status("Stopped after {$done} of {$items}");
                $task->summary("Stopped on request after {$done} of {$items} item(s).");

                return null;
            }

            $task->status("Processing {$item}");

            // The work itself: one second per item, so a watcher can follow it.
            sleep(1);

            $done++;
            $task->queue_pop();
            $csv .= "{$item}," . date('c') . "\n";
            if ($done % 7 === 0) {
                $task->stderr("{$item}: simulated transient failure, retried");
            }
            $task->stdout("Processed {$item}");
            if ($done % 5 === 0) {
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

        $task->attach_bytes('processed.csv', $csv, 'processed.csv', 'text/csv');
        $task->status('Done');
        $task->summary("Walked all {$items} item(s).");

        return null;
    }
}
