<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Projects;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\App\Frontend\Projects\List\Projects_DataGrid;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Project_Contact_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Project_User_Model;
use Rsx\Models\Task_Model;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Projects_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        // Call static fetch method on DataGrid
        return Projects_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: Get tasks for a project.
     *
     * Uses Project_Model::tasks() (the type-ref-safe manual query - morphMany over the
     * taskable type-ref column returns zero rows). Each row: title, status label/badge,
     * due_date, and is_overdue (an OPEN task past its due date).
     */
    #[Ajax_Endpoint]
    public static function project_tasks(Request $request, array $params = [])
    {
        $project_id = $params['id'] ?? null;
        if (!$project_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Project ID is required');
        }

        $project = Project_Model::withTrashed()->find($project_id);
        if (!$project) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Project not found');
        }

        $today = Rsx_Date::today();
        $open_states = [Task_Model::STATUS_PENDING, Task_Model::STATUS_IN_PROGRESS];

        $result = [];
        foreach ($project->tasks() as $task) {
            $is_overdue = $task->due_date
                && $task->due_date < $today
                && in_array((int) $task->status, $open_states, true);

            $result[] = [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'status__label' => $task->status__label,
                'status__badge' => $task->status__badge,
                'due_date' => $task->due_date,
                'is_overdue' => $is_overdue,
            ];
        }

        return ['tasks' => $result];
    }

    /**
     * Ajax endpoint: Get the activity feed (action log) for a project.
     *
     * Shared Activity-tab shape (id, rendered summary html, created_at, type_id).
     */
    #[Ajax_Endpoint]
    public static function project_activity(Request $request, array $params = [])
    {
        $project_id = $params['id'] ?? null;
        if (!$project_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Project ID is required');
        }

        $project = Project_Model::withTrashed()->find($project_id);
        if (!$project) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Project not found');
        }

        $activity = [];
        foreach (Action_Log::get_for_entity($project, 50) as $log) {
            $activity[] = [
                'id' => $log->id,
                'html' => $log->render(),
                'created_at' => $log->created_at,
                'type_id' => $log->type_id,
            ];
        }

        return ['activity' => $activity];
    }

    /**
     * Ajax endpoint: Save project (add or edit)
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        // Validate required fields
        $errors = [];

        if (empty($params['name'])) {
            $errors['name'] = 'Project name is required';
        }

        if (empty($params['client_id'])) {
            $errors['client_id'] = 'Client is required';
        }

        if (!empty($errors)) {
            return response_error(Ajax::ERROR_VALIDATION, $errors);
        }

        // Determine if this is create or update
        $project_id = $params['id'] ?? null;

        if ($project_id) {
            // Update existing project
            $project = Project_Model::find($project_id);
            if (!$project) {
                return response_error(Ajax::ERROR_NOT_FOUND, 'Project not found');
            }
        } else {
            // Create new project
            $project = new Project_Model();
            $project->site_id = 1; // Default site
        }

        // parent_project_id (self-referencing hierarchy). Cycle guard: a project cannot be
        // parented to itself OR to one of its own descendants (which would form a loop). The
        // forbidden set = the project + its whole subtree (self_and_descendant_ids). New
        // projects have no subtree, so any existing project is a valid parent.
        $parent_project_id = !empty($params['parent_project_id']) ? (int) $params['parent_project_id'] : null;
        if ($parent_project_id && $project_id) {
            $forbidden = Project_Model::self_and_descendant_ids((int) $project_id);
            if (in_array($parent_project_id, $forbidden, true)) {
                return response_error(Ajax::ERROR_VALIDATION, ['parent_project_id' => 'A project cannot be its own parent or a descendant of itself']);
            }
        }

        // Normalize pivot inputs (arrays of ids). Absent key = leave the pivot untouched;
        // present (even empty) = replace the set.
        $contacts_provided = array_key_exists('contacts', $params);
        $users_provided = array_key_exists('assigned_users', $params);
        $contact_ids = $contacts_provided ? array_values(array_unique(array_map('intval', (array) $params['contacts']))) : [];
        $user_ids = $users_provided ? array_values(array_unique(array_map('intval', (array) $params['assigned_users']))) : [];

        DB::transaction(function () use (
            &$project, $params, $parent_project_id,
            $contacts_provided, $users_provided, $contact_ids, $user_ids
        ) {
            // Set all fields explicitly
            $project->name = $params['name'];
            $project->description = $params['description'] ?? null;
            $project->client_id = (int)$params['client_id'];
            $project->parent_project_id = $parent_project_id;
            $project->status = !empty($params['status']) ? (int)$params['status'] : 1;
            $project->priority = !empty($params['priority']) ? (int)$params['priority'] : 2;
            $project->start_date = !empty($params['start_date']) ? $params['start_date'] : null;
            $project->due_date = !empty($params['due_date']) ? $params['due_date'] : null;
            $project->budget = !empty($params['budget']) ? $params['budget'] : null;
            $project->notes = $params['notes'] ?? null;

            $project->save();

            if ($contacts_provided) {
                static::__sync_pivot(Project_Contact_Model::class, 'contact_id', $project, $contact_ids);
            }
            if ($users_provided) {
                static::__sync_pivot(Project_User_Model::class, 'user_id', $project, $user_ids);
            }
        });

        // Record action log
        Action_Log::record(
            $project_id ? Action_Log_Model::TYPE_PROJECT_UPDATED : Action_Log_Model::TYPE_PROJECT_CREATED,
            $project
        );

        return [
            'project_id' => $project->id,
            'message' => $project_id ? 'Project updated successfully' : 'Project created successfully',
            'redirect' => Rsx::Route('Projects_View_Action', $project->id)
        ];
    }

    /**
     * Ajax endpoint: Get subprojects (direct children) of a project.
     *
     * Row shape mirrors the other project-list endpoints (id, name, status/priority label +
     * badge, due_date) so a Record_Table can render them verbatim.
     */
    #[Ajax_Endpoint]
    public static function project_subprojects(Request $request, array $params = [])
    {
        $project_id = $params['id'] ?? null;
        if (!$project_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Project ID is required');
        }

        $project = Project_Model::withTrashed()->find($project_id);
        if (!$project) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Project not found');
        }

        $result = [];
        foreach ($project->subprojects() as $sub) {
            $result[] = [
                'id' => $sub->id,
                'name' => $sub->name,
                'status' => $sub->status,
                'status__label' => $sub->status__label,
                'status__badge' => $sub->status__badge,
                'priority' => $sub->priority,
                'priority__label' => $sub->priority__label,
                'priority__badge' => $sub->priority__badge,
                'due_date' => $sub->due_date,
            ];
        }

        return ['subprojects' => $result];
    }

    /**
     * Ajax endpoint: Get the people associated with a project (for the view page).
     *
     * Two calm People_List sections on the Overview tab. Contacts (via project_contacts)
     * carry an id so a row click navigates to the contact view; assigned users (via
     * project_users) are display-only. Each person: {id, type, name, subtitle}.
     */
    #[Ajax_Endpoint]
    public static function project_people(Request $request, array $params = [])
    {
        $project_id = $params['id'] ?? null;
        if (!$project_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Project ID is required');
        }

        $project = Project_Model::withTrashed()->find($project_id);
        if (!$project) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Project not found');
        }

        $contacts = [];
        foreach ($project->contacts() as $contact) {
            $contacts[] = [
                'id' => $contact->id,
                'type' => 'contact',
                'name' => $contact->full_name() ?: $contact->email,
                'subtitle' => $contact->email,
            ];
        }

        $users = [];
        foreach ($project->assigned_users() as $user) {
            $users[] = [
                'id' => $user->id,
                'type' => 'user',
                'name' => $user->get_printed_name(),
                'subtitle' => null,
            ];
        }

        return ['contacts' => $contacts, 'users' => $users];
    }

    /**
     * Ajax endpoint: Options + current selections for the edit form's contacts/users
     * multi-selects.
     *
     * Contacts are scoped to the project's client (matches the domain - a project's contacts
     * belong to its client); users are all site users. When a project_id is given, the
     * currently-linked ids are returned so the edit form can prefill the selections.
     * Each option: {id, label, subtitle?}.
     */
    #[Ajax_Endpoint]
    public static function project_form_options(Request $request, array $params = [])
    {
        $client_id = !empty($params['client_id']) ? (int) $params['client_id'] : null;
        $project_id = !empty($params['project_id']) ? (int) $params['project_id'] : null;

        // Current selections first - they drive which off-client contacts must still be offered.
        $selected_contacts = [];
        $selected_users = [];
        if ($project_id) {
            $selected_contacts = Project_Contact_Model::contact_ids_for_project($project_id);
            $selected_users = Project_User_Model::user_ids_for_project($project_id);
        }

        // Contacts are scoped to the project's client, UNION any already-linked contacts that
        // predate the current client (single-contact data migrated into the pivot in an
        // earlier batch can reference another client). Without the union a currently-linked
        // contact would not prefill and would be silently dropped on save.
        $contacts = [];
        $seen_contact_ids = [];
        if ($client_id) {
            $contact_rows = Contact_Model::where('client_id', $client_id)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();
            foreach ($contact_rows as $contact) {
                $contacts[] = [
                    'id' => $contact->id,
                    'label' => $contact->full_name() ?: $contact->email,
                    'subtitle' => $contact->email,
                ];
                $seen_contact_ids[$contact->id] = true;
            }
        }
        $missing_selected = array_values(array_filter($selected_contacts, fn ($id) => !isset($seen_contact_ids[$id])));
        if ($missing_selected) {
            foreach (Contact_Model::withTrashed()->whereIn('id', $missing_selected)->get() as $contact) {
                $contacts[] = [
                    'id' => $contact->id,
                    'label' => $contact->full_name() ?: $contact->email,
                    'subtitle' => $contact->email,
                ];
            }
        }

        $users = [];
        foreach (User_Model::orderBy('first_name')->orderBy('last_name')->get() as $user) {
            $users[] = [
                'id' => $user->id,
                'label' => $user->get_printed_name(),
            ];
        }

        return [
            'contacts' => $contacts,
            'users' => $users,
            'selected_contacts' => $selected_contacts,
            'selected_users' => $selected_users,
        ];
    }

    /**
     * Replace a project pivot's rows with the given entity ids (delete-then-insert inside the
     * caller's transaction). site_id is inherited from the project (both pivot models are
     * site-scoped, so the model layer forces the current site on write).
     *
     * @param class-string<\App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract> $model_class
     */
    private static function __sync_pivot(string $model_class, string $fk_column, Project_Model $project, array $ids): void
    {
        $model_class::where('project_id', $project->id)->delete();

        foreach ($ids as $id) {
            $row = new $model_class();
            $row->site_id = $project->site_id;
            $row->project_id = $project->id;
            $row->$fk_column = $id;
            $row->save();
        }
    }

    /**
     * Ajax endpoint: bulk-delete the projects a datagrid footer action selected.
     *
     * The selection payload names a SET, not a page: additive ids, subtractive exclusions, or
     * the whole filtered result. The filters ride along in filter_params so the server rebuilds
     * the exact query the user was looking at rather than trusting a client-side id list to be
     * complete - and because the projects grid opens on Active, a mass action launched from the
     * default view covers active projects ONLY, which is exactly what the user is looking at.
     * Every row goes through the model layer one at a time - a raw bulk DELETE would skip the
     * soft delete, the audit stamp, the realtime frame and the action log.
     *
     * Gate: the class-level 'is_logged_in'. Projects have no single-record delete endpoint to
     * copy a gate from, so this matches save() - the controller's other mutating endpoint.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function bulk_delete(Request $request, array $params = [])
    {
        $query = Projects_DataGrid::build_query_public($params['filter_params'] ?? []);

        $query = Projects_DataGrid::apply_selection($query, 'projects.id', $params);

        if ($query instanceof Error_Response) {
            return $query;
        }

        $deleted = 0;

        foreach (Projects_DataGrid::iterate_selection($query, 'projects.id') as $project) {
            Action_Log::record(Action_Log_Model::TYPE_PROJECT_DELETED, $project);
            $project->delete();
            $deleted++;
        }

        return [
            'deleted' => $deleted,
        ];
    }

    /**
     * Ajax endpoint: export the selected projects as CSV.
     *
     * Same selection resolution as bulk_delete(). Returns the CSV as a string; the browser
     * turns it into a download, because an Ajax response cannot be one.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Auth('can_export_data')]
    #[Ajax_Endpoint]
    public static function export_csv(Request $request, array $params = [])
    {
        $query = Projects_DataGrid::build_query_public($params['filter_params'] ?? []);

        $query = Projects_DataGrid::apply_selection($query, 'projects.id', $params);

        if ($query instanceof Error_Response) {
            return $query;
        }

        $rows = [];

        foreach (Projects_DataGrid::iterate_selection($query, 'projects.id') as $project) {
            $rows[] = [
                $project->id,
                $project->name,
                $project->client_name,
                $project->status__label,
                $project->priority__label,
                $project->start_date,
                $project->due_date,
                $project->completed_date,
                $project->budget,
                $project->created_at,
            ];
        }

        $csv = Projects_DataGrid::build_csv(
            [
                'ID', 'Project Name', 'Client', 'Status', 'Priority', 'Start Date', 'Due Date',
                'Completed Date', 'Budget', 'Created',
            ],
            $rows
        );

        return [
            'csv' => $csv,
            'filename' => 'projects_export_' . Rsx_Date::today() . '.csv',
            'count' => count($rows),
        ];
    }
}
