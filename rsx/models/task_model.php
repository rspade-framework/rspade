<?php

namespace Rsx\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\User_Model;
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: tasks
 *
 * @property int $id
 * @property int $site_id
 * @property string $title
 * @property string $description
 * @property int $taskable_type
 * @property int $taskable_id
 * @property int $project_id
 * @property int $status
 * @property int $priority
 * @property string $due_date
 * @property string $completed_date
 * @property int $assigned_to_user_id
 * @property string $notes
 * @property float $hour_estimate
 * @property int $created_by_id
 * @property int $created_by_type
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
class Task_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * Tasks multiply by project, and nothing prunes finished ones.
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
    const STATUS_PENDING = 1;
    const STATUS_IN_PROGRESS = 2;
    const STATUS_COMPLETED = 3;
    const STATUS_CANCELLED = 4;
    const PRIORITY_LOW = 1;
    const PRIORITY_MEDIUM = 2;
    const PRIORITY_HIGH = 3;
    const PRIORITY_URGENT = 4;

    use SoftDeletes;

    protected $table = 'tasks';
    protected $fillable = []; // No mass assignment - always explicit

    // Realtime: a task emits its own change AND touches its taskable parent so the parent's
    // dependent result set live-refreshes — a subtask save refreshes the parent task's
    // Subtasks tab; a task under a project/client refreshes that project's/client's view.
    public static $realtime = true;

    /**
     * A change to this task should also notify subscribers of its polymorphic parent (the
     * parent's task list depends on it). The taskable morphTo resolves the parent model
     * instance (Task / Project / Client / User); withTrashed() so a soft-deleted parent is
     * still notified. Null (no parent, or a hard-deleted parent row) is skipped — null
     * parents are illegal in the touch cascade.
     *
     * @return \App\RSpade\Core\Database\Models\Rsx_Model_Abstract[]
     */
    public function realtime_touch(): array
    {
        if (!$this->taskable_type || !$this->taskable_id) {
            return [];
        }

        $parent = $this->taskable()->withTrashed()->first();

        return $parent ? [$parent] : [];
    }

    /**
     * Polymorphic type reference columns
     *
     * taskable_type stores integer ID in database but exposes class name string
     * @var array
     */
    protected static $type_ref_columns = ['taskable_type'];

    public static $enums = [
        'status' => [
            1 => ['constant' => 'STATUS_PENDING', 'label' => 'Pending', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'STATUS_IN_PROGRESS', 'label' => 'In Progress', 'badge' => 'bg-info'],
            3 => ['constant' => 'STATUS_COMPLETED', 'label' => 'Completed', 'badge' => 'bg-success'],
            4 => ['constant' => 'STATUS_CANCELLED', 'label' => 'Cancelled', 'badge' => 'bg-danger'],
        ],
        'priority' => [
            1 => ['constant' => 'PRIORITY_LOW', 'label' => 'Low', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'PRIORITY_MEDIUM', 'label' => 'Medium', 'badge' => 'bg-primary'],
            3 => ['constant' => 'PRIORITY_HIGH', 'label' => 'High', 'badge' => 'bg-warning'],
            4 => ['constant' => 'PRIORITY_URGENT', 'label' => 'Urgent', 'badge' => 'bg-danger'],
        ],
    ];

    /**
     * Max hops when walking the taskable parent chain. A runaway guard on top of the
     * visited-set cycle guard (a corrupt/self-referential chain can't loop forever).
     */
    const CHAIN_DEPTH_CAP = 25;

    /**
     * Relationships
     */
    #[Relationship]
    public function assigned_to()
    {
        return $this->belongsTo(User_Model::class, 'assigned_to_user_id');
    }

    /**
     * The polymorphic parent (Project / Task / Client / User), or null.
     *
     * Stock morphTo() over the taskable_type/taskable_id type-ref pair. Read it as a
     * property ($task->taskable), not a method call - the method returns the relation,
     * which is what you want when you need withTrashed() (see realtime_touch()).
     */
    #[Relationship]
    public function taskable()
    {
        return $this->morphTo('taskable', 'taskable_type', 'taskable_id');
    }

    /**
     * Direct-descendant tasks (whose taskable IS this task), ordered by due date.
     *
     * A plain ordered query returning the Collection callers iterate, NOT a relation -
     * morphMany() would be correct here too, but it returns a relation the caller has to
     * unwrap. Server-side only (not a JS-fetchable relationship).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subtasks()
    {
        return static::where('taskable_type', 'Task_Model')
            ->where('taskable_id', $this->id)
            ->orderBy('due_date')
            ->get();
    }

    // =========================================================================
    // Derived project_id (see docs.dev/BACKLOG.md B-1 - this is the application-level
    // case study the framework derived-column abstraction should generalize).
    //
    // A task's project_id is DERIVED (auto-populated, read-only) when its taskable parent
    // chain (task -> task -> ... recursively) reaches a Project, or its direct parent IS a
    // Project. Otherwise (parent is a Client/User, or there is no parent) project_id is
    // user-editable. The save path computes this and cascades to descendants.
    // =========================================================================

    /**
     * Walk the taskable parent chain and return the reached project id, or null.
     *
     * - taskable = Project_Model  -> that project's id (chain resolved).
     * - taskable = Task_Model     -> recurse into that task's chain.
     * - taskable = Client/User/none -> null (no chain project; project_id is user-editable).
     *
     * Cycle guard (visited task-id set) + CHAIN_DEPTH_CAP so a malformed/self-referential
     * chain resolves to null instead of looping.
     */
    public function resolve_chain_project_id(): ?int
    {
        $visited = [];
        if ($this->id) {
            $visited[$this->id] = true;
        }

        $type = $this->taskable_type;
        $id = $this->taskable_id;
        $depth = 0;

        while (!empty($type) && !empty($id)) {
            if (++$depth > self::CHAIN_DEPTH_CAP) {
                return null;
            }

            if ($type === 'Project_Model') {
                return (int) $id;
            }

            if ($type === 'Task_Model') {
                if (isset($visited[$id])) {
                    return null; // cycle
                }
                $visited[$id] = true;

                $parent = static::withTrashed()->find($id);
                if (!$parent) {
                    return null;
                }
                $type = $parent->taskable_type;
                $id = $parent->taskable_id;
                continue;
            }

            // Client_Model, User_Model, or anything else: no chain project.
            return null;
        }

        return null;
    }

    /**
     * True when the taskable chain resolves to a project (project_id is then read-only).
     */
    public function is_project_id_derived(): bool
    {
        return $this->resolve_chain_project_id() !== null;
    }

    /**
     * Recompute project_id for every descendant reachable through a task-parent chain from
     * this task, and persist any that changed. Called after a save that may have altered the
     * chain (parent change, or this task's own project_id).
     *
     * A descendant (a task whose parent chain passes through this one) is chain-governed: its
     * project_id tracks resolve_chain_project_id() - non-null when the chain still reaches a
     * project, null when a re-parent broke the chain. NOTE (B-1 limitation): a task that BOTH
     * has a task-parent rooted at a non-project AND a user-set project_id would be reset here;
     * without a framework derived-flag column we cannot distinguish a stale derived value from
     * a deliberate user value. Acceptable for this app-level case study; the framework
     * abstraction (B-1) is expected to resolve it.
     */
    public function recompute_descendant_project_ids(): void
    {
        $visited = [];
        $queue = [$this->id];

        $depth = 0;
        while (!empty($queue)) {
            if (++$depth > self::CHAIN_DEPTH_CAP) {
                break; // runaway guard
            }

            $children = static::where('taskable_type', 'Task_Model')
                ->whereIn('taskable_id', $queue)
                ->get();

            $next = [];
            foreach ($children as $child) {
                if (isset($visited[$child->id])) {
                    continue; // cycle guard
                }
                $visited[$child->id] = true;

                $chain = $child->resolve_chain_project_id();
                if ((int) $child->project_id !== (int) $chain) {
                    $child->project_id = $chain;
                    $child->save();
                }

                $next[] = $child->id;
            }

            $queue = $next;
        }
    }

    /**
     * Ajax model fetch - allows JavaScript to load task records
     * Unrestricted for development/testing - no auth required
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $model = static::find($id);
        return $model ?: false;
    }
}