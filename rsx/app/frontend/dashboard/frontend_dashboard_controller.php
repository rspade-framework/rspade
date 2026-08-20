<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Dashboard;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;
use Rsx\Permission;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Dashboard_Controller extends Rsx_Controller_Abstract
{
    /**
     * Test endpoint for ACL system verification
     * Usage: php artisan rsx:debug /test-acl --user=1
     */
    #[Route('/test-acl')]
    public static function test_acl(Request $request, array $params = [])
    {
        $user = Session::get_user();

        if (!$user) {
            echo "No user in session. Use --user=N to set a user.\n";
            die();
        }

        $output = [];

        // User info
        $output['user'] = [
            'id' => $user->id,
            'name' => $user->get_full_name(),
            'role_id' => $user->role_id,
            'role_label' => $user->role_id__label,
        ];

        // Role checks (hierarchical - true if user has this role or higher)
        $output['role_checks'] = [
            'ROOT_ADMIN' => $user->has_role(User_Model::ROLE_ROOT_ADMIN),
            'SITE_OWNER' => $user->has_role(User_Model::ROLE_SITE_OWNER),
            'SITE_ADMIN' => $user->has_role(User_Model::ROLE_SITE_ADMIN),
            'MANAGER' => $user->has_role(User_Model::ROLE_MANAGER),
            'USER' => $user->has_role(User_Model::ROLE_USER),
            'VIEWER' => $user->has_role(User_Model::ROLE_VIEWER),
        ];

        // Permission checks
        $output['permission_checks'] = [
            'MANAGE_SITES_ROOT' => $user->has_permission(User_Model::PERM_MANAGE_SITES_ROOT),
            'MANAGE_SITE_BILLING' => $user->has_permission(User_Model::PERM_MANAGE_SITE_BILLING),
            'MANAGE_SITE_SETTINGS' => $user->has_permission(User_Model::PERM_MANAGE_SITE_SETTINGS),
            'MANAGE_SITE_USERS' => $user->has_permission(User_Model::PERM_MANAGE_SITE_USERS),
            'VIEW_USER_ACTIVITY' => $user->has_permission(User_Model::PERM_VIEW_USER_ACTIVITY),
            'EDIT_DATA' => $user->has_permission(User_Model::PERM_EDIT_DATA),
            'VIEW_DATA' => $user->has_permission(User_Model::PERM_VIEW_DATA),
            'API_ACCESS' => $user->has_permission(User_Model::PERM_API_ACCESS),
            'DATA_EXPORT' => $user->has_permission(User_Model::PERM_DATA_EXPORT),
        ];

        // Can admin roles
        $output['can_admin_roles'] = [
            'SITE_OWNER' => $user->can_admin_role(User_Model::ROLE_SITE_OWNER),
            'SITE_ADMIN' => $user->can_admin_role(User_Model::ROLE_SITE_ADMIN),
            'MANAGER' => $user->can_admin_role(User_Model::ROLE_MANAGER),
            'USER' => $user->can_admin_role(User_Model::ROLE_USER),
            'VIEWER' => $user->can_admin_role(User_Model::ROLE_VIEWER),
        ];

        // Permission:: static method checks
        $output['permission_class'] = [
            'has_role(MANAGER)' => Permission::has_role(User_Model::ROLE_MANAGER),
            'has_permission(EDIT_DATA)' => Permission::has_permission(User_Model::PERM_EDIT_DATA),
            'can_admin_role(USER)' => Permission::can_admin_role(User_Model::ROLE_USER),
        ];

        // Role's default permissions and can_admin_roles from enum
        $output['role_defaults'] = [
            'permissions' => $user->role_id__permissions ?? [],
            'can_admin_roles' => $user->role_id__can_admin_roles ?? [],
        ];

        // Supplementary permissions
        $output['supplementary'] = User_Permission_Model::for_user($user->id);

        echo "<pre>";
        echo "=== ACL TEST OUTPUT ===\n\n";
        print_r($output);
        echo "</pre>";
        die();
    }

    /**
     * Ajax endpoint: the dashboard's aggregate data.
     *
     * Every number and row here is a REAL, site-scoped query (site models
     * auto-scope to the session site) - the dashboard never renders invented
     * figures. Returns: the four headline KPIs, the recent activity feed
     * (from the action log), the active-projects, recent-contacts and open-task
     * widget lists. Empty results are returned honestly (the page renders an
     * empty state, not a placeholder).
     */
    #[Ajax_Endpoint]
    public static function dashboard_data(Request $request, array $params = [])
    {
        $today = Rsx_Date::today();

        // Open = pending or in progress (the two live task states).
        $open_task_states = [Task_Model::STATUS_PENDING, Task_Model::STATUS_IN_PROGRESS];

        // --- Headline KPIs -------------------------------------------------
        $stats = [
            'active_projects' => Project_Model::where('status', Project_Model::STATUS_ACTIVE)->count(),
            'total_contacts' => Contact_Model::count(),
            'open_tasks' => Task_Model::whereIn('status', $open_task_states)->count(),
            'overdue_tasks' => Task_Model::whereIn('status', $open_task_states)
                ->whereNotNull('due_date')
                ->where('due_date', '<', $today)
                ->count(),
        ];

        // --- Recent activity feed (action log) -----------------------------
        // render() already emits the linked "actor verb object" summary; we add
        // an icon + accent per action category.
        $recent_activity = [];
        foreach (Action_Log_Model::orderBy('created_at', 'desc')->limit(8)->get() as $log) {
            [$icon, $variant] = static::_activity_icon((int) $log->type_id);
            $recent_activity[] = [
                'id' => $log->id,
                'html' => $log->render(),
                'icon' => $icon,
                'variant' => $variant,
                'created_at' => $log->created_at,
            ];
        }

        // --- Active projects -----------------------------------------------
        $active_projects = [];
        $projects = Project_Model::where('status', Project_Model::STATUS_ACTIVE)
            ->orderBy('due_date')
            ->limit(6)
            ->get();
        foreach ($projects as $project) {
            $client = Client_Model::find($project->client_id);
            $active_projects[] = [
                'id' => $project->id,
                'name' => $project->name,
                'client_id' => $project->client_id,
                'client_name' => $client ? $client->name : null,
                'status__label' => $project->status__label,
                'status__badge' => $project->status__badge,
                'due_date' => $project->due_date,
            ];
        }

        // --- Recent contacts -----------------------------------------------
        $recent_contacts = [];
        $contacts = Contact_Model::orderBy('created_at', 'desc')->limit(6)->get();
        foreach ($contacts as $contact) {
            $client = Client_Model::find($contact->client_id);
            $recent_contacts[] = [
                'id' => $contact->id,
                'full_name' => $contact->full_name(),
                'title' => $contact->title,
                'client_id' => $contact->client_id,
                'client_name' => $client ? $client->name : null,
                'created_at' => $contact->created_at,
            ];
        }

        // --- Open tasks needing attention (due today or overdue first) -----
        $todays_tasks = [];
        $tasks = Task_Model::whereIn('status', $open_task_states)
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $today)
            ->orderBy('due_date')
            ->limit(8)
            ->get();
        foreach ($tasks as $t) {
            // The polymorphic parent resolves through the standard taskable morphTo -
            // type-ref columns are transparent to Eloquent's morph relations.
            $parent = $t->taskable;
            $todays_tasks[] = [
                'id' => $t->id,
                'title' => $t->title,
                'status__label' => $t->status__label,
                'status__badge' => $t->status__badge,
                'due_date' => $t->due_date,
                'is_overdue' => $t->due_date < $today,
                'project_id' => $parent && isset($parent->id) ? $parent->id : null,
                'project_name' => $parent ? ($parent->name ?? $parent->title ?? null) : null,
            ];
        }

        return [
            'stats' => $stats,
            'recent_activity' => $recent_activity,
            'active_projects' => $active_projects,
            'recent_contacts' => $recent_contacts,
            'todays_tasks' => $todays_tasks,
        ];
    }

    /**
     * Map an action-log type_id to a [icon, variant] pair for the feed row.
     *
     * @param int $type_id
     * @return array{0:string,1:string} [bootstrap-icon class, Feed_Row variant]
     */
    private static function _activity_icon(int $type_id): array
    {
        // Type ranges: client 1-9, contact 10-19, project 20-29, task 30-39.
        return match (intdiv($type_id, 10)) {
            0 => ['bi bi-building', 'primary'],
            1 => ['bi bi-person', 'info'],
            2 => ['bi bi-folder', 'success'],
            3 => ['bi bi-check2-square', 'warning'],
            default => ['bi bi-activity', 'secondary'],
        };
    }

}
