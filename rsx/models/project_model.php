<?php

namespace Rsx\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\User_Model;
use Rsx\Lib\Formatters;
use Rsx\Models\Client_Department_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Project_Contact_Model;
use Rsx\Models\Project_User_Model;
use Rsx\Models\Task_Model;
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: projects
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property string $description
 * @property int $client_id
 * @property int $parent_project_id
 * @property int $client_department_id
 * @property int $status
 * @property int $priority
 * @property string $start_date
 * @property string $due_date
 * @property string $completed_date
 * @property float $budget
 * @property string $notes
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $owner_user_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 *
 * @property-read string $status__label
 * @property-read string $status__constant
 * @property-read string $priority__label
 * @property-read string $priority__constant
 *
 * @method static array status__enum() Get all enum definitions with full metadata
 * @method static array status__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status__enum_labels() Get simple id => label map
 * @method static array status__enum_ids() Get array of all valid enum IDs
 * @method static array priority__enum() Get all enum definitions with full metadata
 * @method static array priority__enum_select() Get [{value, label}] array for dropdowns
 * @method static array priority__enum_labels() Get simple id => label map
 * @method static array priority__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
#[Auth('is_logged_in')]
class Project_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * Grows with every engagement the business takes on.
     *
     * A DECLARATION, not a runtime gate - a small, well-narrowed query here is still
     * fine. It marks the tables where a bare ->get() deserves a second look, and where
     * ->result_set() is usually the right answer. See the "Do The Whole Job" section
     * of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_PLANNING = 1;
    const STATUS_ACTIVE = 2;
    const STATUS_ON_HOLD = 3;
    const STATUS_COMPLETED = 4;
    const STATUS_CANCELLED = 5;
    const PRIORITY_LOW = 1;
    const PRIORITY_MEDIUM = 2;
    const PRIORITY_HIGH = 3;
    const PRIORITY_URGENT = 4;

    use SoftDeletes;

    protected $table = 'projects';
    protected $fillable = []; // No mass assignment - always explicit

    // Realtime: this model emits its own change AND (via #[Realtime_Touch] on client())
    // touches its parent client so the client's projects result set (Clients_View projects
    // tab) live-refreshes on edit — including bulk builder writes.
    public static $realtime = true;

    public static $enums = [
        'status' => [
            1 => ['constant' => 'STATUS_PLANNING', 'label' => 'Planning', 'badge' => 'bg-info'],
            2 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active', 'badge' => 'bg-success'],
            3 => ['constant' => 'STATUS_ON_HOLD', 'label' => 'On Hold', 'badge' => 'bg-warning'],
            4 => ['constant' => 'STATUS_COMPLETED', 'label' => 'Completed', 'badge' => 'bg-primary'],
            5 => ['constant' => 'STATUS_CANCELLED', 'label' => 'Cancelled', 'badge' => 'bg-secondary'],
        ],
        'priority' => [
            1 => ['constant' => 'PRIORITY_LOW', 'label' => 'Low', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'PRIORITY_MEDIUM', 'label' => 'Medium', 'badge' => 'bg-primary'],
            3 => ['constant' => 'PRIORITY_HIGH', 'label' => 'High', 'badge' => 'bg-warning'],
            4 => ['constant' => 'PRIORITY_URGENT', 'label' => 'Urgent', 'badge' => 'bg-danger'],
        ],
    ];

    /**
     * Get formatted project ID for display
     * @return string
     */
    public function project_id_formatted()
    {
        return '#PR' . str_pad($this->id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get formatted budget for display
     *
     * Through Formatters::currency(), which carries the amount as an exact decimal
     * (brick/money) instead of casting the DECIMAL column to a float. The column holds
     * money; nothing in its path to the screen is allowed to round it on the way.
     *
     * @return string|null
     */
    public function budget_formatted()
    {
        return $this->budget ? Formatters::currency($this->budget, show_symbol: true, allow_decimals: true) : null;
    }

    /**
     * Relationships
     */
    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    #[Realtime_Touch]
    public function client()
    {
        return $this->belongsTo(Client_Model::class, 'client_id');
    }

    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function client_department()
    {
        return $this->belongsTo(Client_Department_Model::class, 'client_department_id');
    }

    /**
     * Parent project (self-referencing hierarchy), or null for a top-level project.
     * A plain FK (parent_project_id) - NOT a type-ref column, so a normal belongsTo is safe.
     */
    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function parent_project()
    {
        return $this->belongsTo(Project_Model::class, 'parent_project_id');
    }

    /**
     * Direct child projects (subprojects). Plain hasMany on the self-FK.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subprojects()
    {
        return Project_Model::where('parent_project_id', $this->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Contacts associated with this project (1-to-many via the project_contacts pivot).
     * Query method (not an Eloquent relationship) - JS reads via a dedicated controller
     * endpoint per house pattern. Replaces the old single contact() belongsTo.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function contacts()
    {
        $contact_ids = Project_Contact_Model::contact_ids_for_project($this->id);

        if (empty($contact_ids)) {
            return Contact_Model::whereRaw('1 = 0')->get(); // empty collection
        }

        return Contact_Model::whereIn('id', $contact_ids)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Users assigned to this project (1-to-many via the project_users pivot).
     * Query method - JS reads via a dedicated controller endpoint.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function assigned_users()
    {
        $user_ids = Project_User_Model::user_ids_for_project($this->id);

        if (empty($user_ids)) {
            return User_Model::whereRaw('1 = 0')->get(); // empty collection
        }

        return User_Model::whereIn('id', $user_ids)->get();
    }

    /**
     * A project id + the ids of every project descending from it (children whose
     * parent_project_id chain runs through it, recursively). Used to exclude a project
     * and its subtree from parent-project options and to reject a cycle-forming parent on
     * save (a project cannot be parented to itself or one of its own descendants). BFS with
     * a visited-set + depth cap to survive an already-corrupt chain.
     *
     * @return int[]
     */
    public static function self_and_descendant_ids(int $project_id): array
    {
        $ids = [$project_id => true];
        $queue = [$project_id];
        $depth = 0;

        while (!empty($queue)) {
            if (++$depth > 50) {
                break;
            }
            $children = static::whereIn('parent_project_id', $queue)
                ->pluck('id')
                ->all();

            $next = [];
            foreach ($children as $cid) {
                if (isset($ids[$cid])) {
                    continue;
                }
                $ids[$cid] = true;
                $next[] = $cid;
            }
            $queue = $next;
        }

        return array_keys($ids);
    }

    /**
     * Tasks belonging to this project.
     *
     * A plain ordered query returning the Collection callers iterate, NOT a relation.
     * morphMany(Task_Model::class, 'taskable') would be correct here too (type-ref columns
     * are transparent to Eloquent's morph relations), but it returns a relation the caller
     * has to unwrap, and this method's job is "the tasks", ordered.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function tasks()
    {
        return Task_Model::where('taskable_type', 'Project_Model')
            ->where('taskable_id', $this->id)
            ->orderBy('due_date')
            ->get();
    }

    #[Relationship]
    public function owner()
    {
        return $this->belongsTo(User_Model::class, 'owner_user_id');
    }

    /**
     * Ajax model fetch - allows JavaScript to load project records
     * Unrestricted for development/testing - no auth required
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $project = static::find($id);

        if (!$project) {
            return false;
        }

        // Start with model's toArray() to get __MODEL and base data
        $data = $project->toArray();

        // Augment with model methods (key must match method name)
        $data['project_id_formatted'] = $project->project_id_formatted();
        $data['budget_formatted'] = $project->budget_formatted();

        return $data;
    }
}