<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Services;

use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use Rsx\App\Frontend\Clients\List\Clients_DataGrid;
use Rsx\App\Frontend\Contacts\List\Contacts_DataGrid;
use Rsx\App\Frontend\Projects\List\Projects_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Project_Model;
use Rsx\Permission;

/**
 * Mass_Edit_Service - the datagrid mass actions, run as the person who started them.
 *
 * A mass edit is an action a user applies to a SELECTION ("delete these 40 clients", "delete
 * everything matching the filter"). It never holds the site's write lock across the selection
 * (rsx:man site_locks, HOLDING A LOCK WELL, rule 8). The endpoint validates the selection,
 * dispatches one of these tasks and returns the run's id; the grid shows the run's progress.
 * The task then:
 *
 *   1. reads the selection - the grid's own query rebuilt from the filters that were on
 *      screen, constrained by the selection payload - into a list of ids, with no lock held;
 *   2. takes ONE Site_Lock::write() section per record, and inside it RE-DECIDES: the person
 *      may still edit data, and the record is still in the selection (it may have been
 *      deleted, or changed so the filters no longer match it, since step 1). A record that is
 *      not is skipped and reported, never written from the stale read;
 *   3. deletes through the record's own writer - the model's delete() plus the action-log
 *      entry, exactly what the single-record delete endpoint does;
 *   4. reports each item's outcome and a summary. Items commit one by one: a run that is
 *      stopped, or refused part-way, keeps what it already deleted.
 *
 * #[Run_As_Dispatcher]: the run acts as the person who clicked, so revisions, the action log
 * and the authorship stamps name them, and their site is the run's site.
 * #[Task_Access('dispatcher')]: that person may watch and stop the run from the grid.
 */
class Mass_Edit_Service extends Rsx_Service_Abstract
{
    /**
     * Soft-delete the selected clients.
     *
     * $params: mode, ids, filter_params - the grid's selection payload, as the endpoint
     * validated it.
     */
    #[Task('Delete the selected clients')]
    #[Run_As_Dispatcher]
    #[Task_Access('dispatcher')]
    public static function delete_clients(Task_Instance $task, array $params = [])
    {
        return static::__delete_selection($task, $params, Clients_DataGrid::class, 'clients.id', Client_Model::class, Action_Log_Model::TYPE_CLIENT_DELETED, 'client');
    }

    /**
     * Soft-delete the selected contacts. $params as delete_clients().
     */
    #[Task('Delete the selected contacts')]
    #[Run_As_Dispatcher]
    #[Task_Access('dispatcher')]
    public static function delete_contacts(Task_Instance $task, array $params = [])
    {
        return static::__delete_selection($task, $params, Contacts_DataGrid::class, 'contacts.id', Contact_Model::class, Action_Log_Model::TYPE_CONTACT_DELETED, 'contact');
    }

    /**
     * Soft-delete the selected projects. $params as delete_clients().
     */
    #[Task('Delete the selected projects')]
    #[Run_As_Dispatcher]
    #[Task_Access('dispatcher')]
    public static function delete_projects(Task_Instance $task, array $params = [])
    {
        return static::__delete_selection($task, $params, Projects_DataGrid::class, 'projects.id', Project_Model::class, Action_Log_Model::TYPE_PROJECT_DELETED, 'project');
    }

    /**
     * The per-item mass delete every grid shares.
     *
     * Reports: status per phase, progress_count and heartbeat per item, a stdout line per item
     * not deleted (with the reason), {selected, deleted, skipped, refused} as its state, and a
     * one-line summary - which the grid shows when the run ends.
     *
     * @param string $grid DataGrid_Abstract subclass whose query the selection was made on
     * @param string $id_column Table-qualified primary key column of that query
     * @param string $model_class The record's model
     * @param int $log_type Action_Log_Model::TYPE_* recorded for each deleted record
     * @param string $noun Singular record noun for the reports
     */
    private static function __delete_selection(Task_Instance $task, array $params, string $grid, string $id_column, string $model_class, int $log_type, string $noun)
    {
        $site_id = (int) Session::get_site_id();
        $filter_params = $params['filter_params'] ?? [];
        $selection = ['mode' => $params['mode'] ?? null, 'ids' => $params['ids'] ?? []];

        // 1. The selection, read once with no lock held.
        $task->status("Reading the selected {$noun}s");

        $ids = [];
        foreach ($grid::iterate_selection(static::__selection_query($grid, $id_column, $filter_params, $selection), $id_column) as $row) {
            $ids[] = (int) $row->id;
        }

        $total = count($ids);
        $deleted = 0;
        $skipped = 0;
        $refused = 0;

        $task->status("Deleting {$total} {$noun}(s)");
        $task->progress_count(0, $total);

        // 2-3. One write section per record, re-deciding inside it.
        foreach ($ids as $index => $id) {
            $task->heartbeat();
            if ($task->is_stop_requested()) {
                $task->stdout("Stopped on request after {$index} of {$total}.");
                break;
            }

            $outcome = Site_Lock::write($site_id, function () use ($grid, $id_column, $filter_params, $selection, $model_class, $log_type, $id) {
                if (!Permission::can_edit_data()) {
                    return 'refused';
                }

                $still_selected = static::__selection_query($grid, $id_column, $filter_params, $selection)
                    ->where($id_column, $id)
                    ->exists();
                if (!$still_selected) {
                    return 'skipped';
                }

                $record = $model_class::find($id);
                Action_Log::record($log_type, $record);
                $record->delete();

                return 'deleted';
            }, "Delete {$noun} {$id}");

            if ($outcome === 'refused') {
                // The person lost the right to edit data during the run: nothing after this
                // item may be written as them.
                $refused = $total - $index;
                $task->stdout("Refused at {$noun} {$id}: you may no longer edit data. {$refused} {$noun}(s) left untouched.");
                break;
            }

            if ($outcome === 'skipped') {
                $skipped++;
                $task->stdout(ucfirst($noun) . " {$id}: skipped - deleted or no longer matching the selection since it was made.");
            } else {
                $deleted++;
            }

            $task->progress_count($index + 1, $total);
        }

        // 4. The outcome.
        $task->state(['selected' => $total, 'deleted' => $deleted, 'skipped' => $skipped, 'refused' => $refused]);

        $summary = "Deleted {$deleted} of {$total} {$noun}(s).";
        if ($skipped > 0) {
            $summary .= " {$skipped} skipped (deleted or changed since the selection was made).";
        }
        if ($refused > 0) {
            $summary .= " {$refused} refused (you may no longer edit data).";
        }
        $task->summary($summary);

        return null;
    }

    /**
     * The grid's query, rebuilt from the on-screen filters and constrained by the selection
     * payload. The endpoint validated the payload before dispatching, so a malformed one here
     * means the run was started some other way: fail loud.
     */
    private static function __selection_query(string $grid, string $id_column, array $filter_params, array $selection)
    {
        $query = $grid::apply_selection($grid::build_query_public($filter_params), $id_column, $selection);

        if ($query instanceof Error_Response) {
            throw new \RuntimeException("Mass_Edit_Service: malformed selection payload for {$grid}: " . json_encode($selection));
        }

        return $query;
    }
}
