<?php

namespace Rsx\App\Api\V1;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Response\Error_Response;
use Rsx\Lib\ActionLog\Action_Log;
use Rsx\Lib\TextTypes\RichText\Rich_Text;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Project_Model;

/**
 * Projects API - external REST reads and writes for project records, and the template's
 * worked example of MONEY on the external API.
 *
 * THE BUDGET. `budget` is a money column (rsx:man money). The API carries plain values, so
 * a read answers it as the decimal string "27000.00" (or null for no budget) - the endpoint
 * returns the model and the dispatcher writes the string. A write declares it
 * #[Api_Param(type: 'money', column: 'Project_Model.budget')]: a plain decimal string, a JSON
 * int of whole units or a residue-free JSON float, null or blank for no budget. Anything else
 * - "12.345", text that is not an amount, the browser's {"__MONEY": ...} envelope, an amount
 * larger than the DECIMAL(15,2) column holds - is a 422 naming `budget`, decided by the
 * parameter check before this controller runs, so the endpoint receives an Rsx_Money that
 * fits the column.
 *
 * COMPUTED AMOUNTS. A single project's read adds the two figures the staff view shows beside
 * its budget: `subprojects_budget`, the total of its direct subprojects' budgets summed
 * exactly by the database (sum_money(), "0.00" when there are none), and
 * `unallocated_budget`, the budget less that total (negative when over-allocated; null when
 * the project has no budget). Both are Rsx_Money values placed in the answer as they are; the
 * dispatcher writes them as strings like the stored column.
 *
 * Create and update run the staff save's own rules and write: Project_Model::value_errors()
 * checks every value (client, parent project and the cycle guard resolved through the site
 * scope), and Project_Model::save_edit() writes it inside Form_Edit::run() - changed fields
 * only, under a row lock. The contact and user links the staff form edits are not part of
 * this API.
 *
 * `description` is a Rich_Text column: a read answers its HTML string, a write passes the
 * string through Rich_Text::from_untrusted_encoded() (the type's sanitizer), so a description
 * read and sent back is stored unchanged. `notes` is plain text, assigned as sent.
 *
 * All endpoints operate within the authenticated key's site: the global site scope filters
 * every read, so a cross-site id simply comes back null - there is no manual site_id here.
 *
 * AUTH. The class gates are can_use_api (PERM_API_ACCESS, also required of every bearer
 * request by Main::pre_dispatch) and can_view_data, and every write adds can_edit_data - the
 * same data checks the staff screens apply.
 */
#[Auth('is_logged_in', 'can_use_api', 'can_view_data')]
class Projects_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * List projects.
     *
     * Returns a paginated list of projects for the current site, newest first. Optional
     * filters narrow by status and by client. `budget` is a decimal string, or null when the
     * project has none.
     *
     * @api-response
     * {
     *   "items": [
     *     {
     *       "id": 4,
     *       "site_id": 1,
     *       "name": "Website relaunch",
     *       "client_id": 2,
     *       "parent_project_id": null,
     *       "status": 2,
     *       "status__label": "Active",
     *       "priority": 3,
     *       "priority__label": "High",
     *       "start_date": "2026-09-01",
     *       "due_date": "2026-12-15",
     *       "budget": "27000.00",
     *       "project_id_formatted": "#PR004",
     *       "__MODEL": "Project_Model"
     *     }
     *   ],
     *   "meta": { "page": 1, "per_page": 20, "total": 1, "total_pages": 1 }
     * }
     */
    #[Api_Endpoint('/api/v1/projects', methods: ['GET'])]
    #[Api_Param('page', type: 'int', default: 1, description: 'Page number (1-based)')]
    #[Api_Param('per_page', type: 'int', default: 20, description: 'Results per page (max 100)')]
    #[Api_Param('status', type: 'int', description: 'Filter by status id (1 Planning, 2 Active, 3 On Hold, 4 Completed, 5 Cancelled)')]
    #[Api_Param('client_id', type: 'int', description: 'Filter to one client')]
    public static function list(Request $request, array $params = [])
    {
        $page = max(1, (int) $params['page']);
        $per_page = min(100, max(1, (int) $params['per_page']));

        $query = Project_Model::query();

        if (!empty($params['status'])) {
            $query->where('status', (int) $params['status']);
        }

        if (!empty($params['client_id'])) {
            $query->where('client_id', (int) $params['client_id']);
        }

        $total = $query->count();
        $projects = $query->orderByDesc('id')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get();

        return [
            'items' => $projects,
            'meta' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ];
    }

    /**
     * Get a single project.
     *
     * Returns the full project record with two computed amounts beside its stored budget:
     * `subprojects_budget`, the total of its direct subprojects' budgets ("0.00" when none
     * has one), and `unallocated_budget`, the budget less that total - negative when the
     * subprojects are allocated more than the project holds, null when the project has no
     * budget. Every amount is a decimal string with two places. A soft-deleted or cross-site
     * id is not found.
     *
     * @api-response
     * {
     *   "id": 4,
     *   "site_id": 1,
     *   "name": "Website relaunch",
     *   "description": "<p>Replace the marketing site.</p>",
     *   "client_id": 2,
     *   "parent_project_id": null,
     *   "status": 2,
     *   "status__label": "Active",
     *   "priority": 3,
     *   "priority__label": "High",
     *   "start_date": "2026-09-01",
     *   "due_date": "2026-12-15",
     *   "budget": "27000.00",
     *   "notes": "Phase two follows sign-off.",
     *   "project_id_formatted": "#PR004",
     *   "__MODEL": "Project_Model",
     *   "subprojects_budget": "18500.00",
     *   "unallocated_budget": "8500.00"
     * }
     */
    #[Api_Endpoint('/api/v1/projects/:id', methods: ['GET'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Project ID')]
    public static function get(Request $request, array $params = [])
    {
        $project = Project_Model::find($params['id']);

        if (!$project) {
            return Rsx_Api::not_found('Project not found');
        }

        $subprojects_budget = Project_Model::where('parent_project_id', $project->id)->sum_money('budget');

        return $project->toArray() + [
            'subprojects_budget' => $subprojects_budget,
            'unallocated_budget' => $project->budget?->subtract($subprojects_budget),
        ];
    }

    /**
     * Create a project.
     *
     * Requires a non-blank name and the client the project belongs to. Every other field is
     * optional: a blank status is Active and a blank priority Medium. `budget` is a decimal
     * string ("27000", "27000.5", "27000.50"); blank or null is no budget, a negative amount,
     * a third decimal place or an amount larger than the field holds is a 422 naming
     * `budget`. A parent project must be a live project of the key's site. A value the
     * project cannot hold is a 422 naming the field.
     *
     * `description` is HTML: it is sanitized (the staff editor's allow-list) and stored, and a
     * description read from a project is sent back as it was read.
     *
     * @api-response
     * {
     *   "id": 57,
     *   "site_id": 1,
     *   "name": "Website relaunch",
     *   "client_id": 2,
     *   "parent_project_id": null,
     *   "status": 2,
     *   "status__label": "Active",
     *   "priority": 2,
     *   "priority__label": "Medium",
     *   "budget": "27000.00",
     *   "project_id_formatted": "#PR057",
     *   "__MODEL": "Project_Model"
     * }
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Api_Endpoint('/api/v1/projects/create', methods: ['POST'])]
    #[Api_Param('name', type: 'string', required: true, description: 'Project name')]
    #[Api_Param('client_id', type: 'int', required: true, description: 'Client the project belongs to')]
    #[Api_Param('description', type: 'string', description: 'Description, HTML (sanitized on save); send back the string a read returned to keep it unchanged')]
    #[Api_Param('parent_project_id', type: 'int', description: 'Parent project id')]
    #[Api_Param('status', type: 'int', description: 'Status id (1 Planning, 2 Active, 3 On Hold, 4 Completed, 5 Cancelled); default Active')]
    #[Api_Param('priority', type: 'int', description: 'Priority id (1 Low, 2 Medium, 3 High, 4 Urgent); default Medium')]
    #[Api_Param('start_date', type: 'string', description: 'Start date, YYYY-MM-DD')]
    #[Api_Param('due_date', type: 'string', description: 'Due date, YYYY-MM-DD')]
    #[Api_Param('budget', type: 'money', column: 'Project_Model.budget', description: 'Budget, a decimal string with at most two places ("27000.50"), zero or more; blank or null is no budget')]
    #[Api_Param('notes', type: 'string', description: 'Internal notes, plain text')]
    public static function create(Request $request, array $params = [])
    {
        $values = static::__values($params);

        $errors = Project_Model::value_errors($values);
        if (!empty($errors)) {
            return Rsx_Api::validation_error($errors);
        }

        // site_id is stamped by the site-scoped model on save.
        $project = new Project_Model();

        Form_Edit::run($project, $values, function (Form_Edit $edit) use ($project, $values) {
            $project->save_edit($edit, $values);
            Action_Log::record(Action_Log_Model::TYPE_PROJECT_CREATED, $project);
        });

        return Rsx_Api::created($project->refresh());
    }

    /**
     * Update a project.
     *
     * Applies only the fields present in the request; omitted fields are left unchanged.
     * The value rules of create apply to every field sent, and a name or client sent must not
     * be blank. Send a blank date or budget, or 0 for parent_project_id, to clear it. A
     * project cannot be parented to itself or one of its own subprojects. A reference the
     * project already holds is accepted as it stands, even when its record has since been
     * deleted. A save another editor made meanwhile to a field this request sends is a 409.
     *
     * @api-response
     * {
     *   "id": 57,
     *   "site_id": 1,
     *   "name": "Website relaunch",
     *   "client_id": 2,
     *   "status": 3,
     *   "status__label": "On Hold",
     *   "budget": "31500.00",
     *   "project_id_formatted": "#PR057",
     *   "__MODEL": "Project_Model"
     * }
     */
    #[Auth('can_edit_data')]
    #[Writer]
    #[Api_Endpoint('/api/v1/projects/:id/update', methods: ['POST'])]
    #[Api_Param('id', type: 'int', required: true, description: 'Project ID')]
    #[Api_Param('name', type: 'string', description: 'Project name')]
    #[Api_Param('client_id', type: 'int', description: 'Client the project belongs to')]
    #[Api_Param('description', type: 'string', description: 'Description, HTML (sanitized on save); send back the string a read returned to keep it unchanged')]
    #[Api_Param('parent_project_id', type: 'int', description: 'Parent project id; 0 clears')]
    #[Api_Param('status', type: 'int', description: 'Status id (1 Planning, 2 Active, 3 On Hold, 4 Completed, 5 Cancelled)')]
    #[Api_Param('priority', type: 'int', description: 'Priority id (1 Low, 2 Medium, 3 High, 4 Urgent)')]
    #[Api_Param('start_date', type: 'string', description: 'Start date, YYYY-MM-DD; blank clears')]
    #[Api_Param('due_date', type: 'string', description: 'Due date, YYYY-MM-DD; blank clears')]
    #[Api_Param('budget', type: 'money', column: 'Project_Model.budget', description: 'Budget, a decimal string with at most two places ("27000.50"), zero or more; blank or null clears')]
    #[Api_Param('notes', type: 'string', description: 'Internal notes, plain text')]
    public static function update(Request $request, array $params = [])
    {
        $project = Project_Model::find($params['id']);

        if (!$project) {
            return Rsx_Api::not_found('Project not found');
        }

        $values = static::__values($params);

        $errors = Project_Model::value_errors($values, $project);
        if (!empty($errors)) {
            return Rsx_Api::validation_error($errors);
        }

        // An API caller edits the project as it stands now: the fields it sent are the
        // changed set, and the version it edits from is the one just read. Form_Edit writes
        // only those fields, under the row lock.
        $values['_changed'] = array_keys($values);
        $values['_loaded_updated_at'] = $project->updated_at;

        $result = Form_Edit::run($project, $values, function (Form_Edit $edit) use ($project, $values) {
            $project->save_edit($edit, $values);
            Action_Log::record(Action_Log_Model::TYPE_PROJECT_UPDATED, $project);

            return [];
        });

        // A save that landed between the read above and the lock, on a field this request
        // also sent, is answered as a conflict rather than overwritten.
        if ($result instanceof Error_Response) {
            if ($result->get_error_code() === Ajax::ERROR_NOT_FOUND) {
                return Rsx_Api::not_found('Project not found');
            }

            return Rsx_Api::error('conflict', 'The project was changed by another save while this update was applied. Fetch it and retry.', 409);
        }

        return $project->refresh();
    }

    /**
     * The request's project values, keyed as Project_Model::value_errors() and save_edit()
     * read them: only the fields sent. The budget is already the Rsx_Money (or null) the
     * parameter check produced.
     */
    private static function __values(array $params): array
    {
        $values = $params;
        unset($values['id']);

        // The API's door for the rich-text column: the string IS the HTML, so it is passed
        // through the type's filter as encoded content. Assigned bare it would be plain
        // text, and a description read and sent back would be stored as visible tags.
        if (isset($values['description'])) {
            $values['description'] = Rich_Text::from_untrusted_encoded($values['description']);
        }

        return $values;
    }
}
