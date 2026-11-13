<?php

namespace Rsx\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\Lib\FieldChecks\Field_Checks;
use Rsx\Lib\TextTypes\RichText\Rich_Text;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: tasks
 *
 * @property int $assigned_to_user_id
 * @property string $completed_date
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property string $deleted_by_type type ref: a model class name, stored as a type-ref id
 * @property \Rsx\Lib\TextTypes\RichText\Rich_Text $description text type: a value object, filtered on write
 * @property int $direct_project_id
 * @property string $due_date
 * @property string $hour_estimate decimal
 * @property int $id
 * @property string $notes
 * @property int $priority enum: PRIORITY_LOW, PRIORITY_MEDIUM, PRIORITY_HIGH, PRIORITY_URGENT (also $priority__label and the other $priority__* properties)
 * @property-read int $project_id derived: computed by derive_project_id() from taskable_type, taskable_id, direct_project_id; never assigned
 * @property int $site_id
 * @property int $status enum: STATUS_PENDING, STATUS_IN_PROGRESS, STATUS_COMPLETED, STATUS_CANCELLED (also $status__label and the other $status__* properties)
 * @property int $taskable_id
 * @property string $taskable_type type ref: a model class name, stored as a type-ref id
 * @property string $title
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
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
     * Revision history: every committed save()/delete() on a task is recorded. No
     * #[Revision_Parent]: a task's parent is the POLYMORPHIC taskable, and the attribute
     * is belongsTo-only (a revision has exactly one root, and only a belongsTo names
     * exactly one parent row).
     */
    public static $revisions = true;

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
     * Column declarations (see rsx:man model).
     *
     * `description` is authored in a WYSIWYG and holds HTML, so its text_type makes the value
     * carry its own encoding instead of every template, endpoint and export remembering it -
     * the same decision Project_Model makes, and states in full, for the same kind of column.
     *
     * `project_id` is derived by derive_project_id() from the parent pair and the
     * user-editable `direct_project_id`; it is never assigned (see "The task's project"
     * below).
     */
    public static $columns = [
        'taskable_type' => ['type_ref' => true],
        'description' => ['text_type' => Rich_Text::class],
        'status' => ['enum' => [
            1 => ['constant' => 'STATUS_PENDING', 'label' => 'Pending', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'STATUS_IN_PROGRESS', 'label' => 'In Progress', 'badge' => 'bg-info'],
            3 => ['constant' => 'STATUS_COMPLETED', 'label' => 'Completed', 'badge' => 'bg-success'],
            4 => ['constant' => 'STATUS_CANCELLED', 'label' => 'Cancelled', 'badge' => 'bg-danger'],
        ]],
        'priority' => ['enum' => [
            1 => ['constant' => 'PRIORITY_LOW', 'label' => 'Low', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'PRIORITY_MEDIUM', 'label' => 'Medium', 'badge' => 'bg-primary'],
            3 => ['constant' => 'PRIORITY_HIGH', 'label' => 'High', 'badge' => 'bg-warning'],
            4 => ['constant' => 'PRIORITY_URGENT', 'label' => 'Urgent', 'badge' => 'bg-danger'],
        ]],
        'project_id' => ['derived' => 'derive_project_id', 'from' => ['taskable_type', 'taskable_id', 'direct_project_id']],
    ];

    /**
     * FACT GROUPS - the parent is one fact stored in two columns: a change saved to either
     * is a change to the parent, so Form_Edit asks before a stale save puts an old parent
     * back (rsx:man model, FACT GROUPS).
     */
    public static $fact_groups = [
        'parent' => ['label' => 'Parent', 'columns' => ['taskable_type', 'taskable_id']],
    ];

    /**
     * The columns a task edit names differently: both halves of the parent pair are the one
     * field `taskable` = {type, id}. Passed to Form_Edit::run() as fields: by every endpoint
     * that edits a task, so another editor's re-parent is matched to the submitted field.
     */
    public const EDIT_FIELDS = ['taskable_type' => 'taskable', 'taskable_id' => 'taskable'];

    /**
     * Max hops when walking the taskable parent chain. A runaway guard on top of the
     * visited-set cycle guard (a corrupt/self-referential chain can't loop forever). The
     * migration that introduced direct_project_id mirrors this number in SQL.
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
    // The task's project.
    //
    // project_id is a DERIVED column (see $columns): derive_project_id() computes it on every
    // save that changes the parent pair or direct_project_id, and it is never assigned. It is
    // the project the taskable parent chain (task -> task -> ... -> project) reaches, and
    // otherwise direct_project_id - the project a user chose for a task whose chain reaches
    // none. A task under a project chain keeps its direct_project_id untouched, so the value
    // comes back if the chain is later broken.
    //
    // The framework derives from THIS record's columns only. A descendant's project depends
    // on its ancestors' parent pairs, so save() rederives the whole subtree below a task
    // whose parent changed.
    // =========================================================================

    /**
     * Walk the taskable parent chain and return the reached project id, or null.
     *
     * - taskable = Project_Model  -> that project's id (chain resolved).
     * - taskable = Task_Model     -> recurse into that task's chain.
     * - taskable = Client/User/none -> null (no chain project; direct_project_id applies).
     *
     * A soft-deleted parent task still counts; the lookup is site-scoped. Cycle guard
     * (visited task-id set) + CHAIN_DEPTH_CAP so a malformed/self-referential chain resolves
     * to null instead of looping.
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
     * The derive method of project_id: the project the parent chain reaches, else
     * direct_project_id.
     */
    public function derive_project_id(): ?int
    {
        return $this->resolve_chain_project_id()
            ?? ($this->direct_project_id !== null ? (int) $this->direct_project_id : null);
    }

    /**
     * Save, and when an existing task's parent pair changed, rederive the project of every
     * task below it in the same transaction. The task's own project_id is derived by the
     * framework's save(); its descendants' are not, because what they derive from - their
     * ancestors' parent pairs - lives on other rows.
     */
    public function save(array $options = [])
    {
        if (!$this->exists || !$this->isDirty(['taskable_type', 'taskable_id'])) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options) {
            $result = parent::save($options);

            if ($result) {
                $this->__rederive_descendants();
            }

            return $result;
        });
    }

    /**
     * rederive() every task below this one, level by level through the task-parent links,
     * soft-deleted ones included (their chain still counts). Every descendant is visited,
     * also below a child whose own value did not change: a child whose direct_project_id
     * equals the new chain project keeps its value while its own children still move. Each
     * rederive() is a derived-only save that touches no parent pair, so it cascades no
     * further by itself. The visited set ends a corrupt cyclic chain.
     */
    private function __rederive_descendants(): void
    {
        $visited = [(int) $this->id => true];
        $level = [(int) $this->id];

        while ($level !== []) {
            $next = [];

            $children = static::withTrashed()
                ->where('taskable_type', 'Task_Model')
                ->whereIn('taskable_id', $level)
                ->result_set();

            foreach ($children as $child) {
                if (isset($visited[(int) $child->id])) {
                    continue;
                }
                $visited[(int) $child->id] = true;

                $child->rederive();
                $next[] = (int) $child->id;
            }

            $level = $next;
        }
    }

    // =========================================================================
    // Writing a task - the ONE rule set and the ONE write, run by the staff save
    // (Frontend_Tasks_Controller::save) and the external API (Tasks_Api_Controller
    // create/update) alike.
    // =========================================================================

    /** Polymorphic parent types a task may be attached to (the taskable_type values). */
    const PARENT_TYPES = ['Task_Model', 'Project_Model', 'Client_Model', 'User_Model'];

    /**
     * Answers [field => message] for every submitted value a task cannot hold; empty when
     * all of them can. Checks only the keys $values carries, so an API update that sends a
     * few fields is checked on those few. A caller that requires a field (the title, on a
     * staff save or an API create) makes sure the key is present.
     *
     *   - title, when present, is non-blank text that fits Task_Model::field_length('title')
     *   - status and priority, when not blank, are declared enum ids
     *   - due_date and completed_date, when not blank, are YYYY-MM-DD dates
     *   - hour_estimate, when not blank, is a number of zero or more
     *   - taskable ({type, id}, type null = no parent) names a known parent type and a live
     *     record of this site; a task cannot be parented to itself or a descendant
     *   - assigned_to_user_id and direct_project_id, when not blank, name a live record of this site
     *
     * A reference $task already holds (assignee, project, parent) is accepted as it stands,
     * so a task whose referent was since deleted still saves; a reference the submission
     * CHANGES must name a live record. Every reference is resolved through a site-scoped
     * query, so an id from another site misses exactly as a missing one does.
     *
     * @param array $values Submitted values keyed by field name
     * @param Task_Model|null $task The task being edited; null (or unsaved) for a new one
     * @return array<string, string>
     */
    public static function value_errors(array $values, ?Task_Model $task = null): array
    {
        $errors = [];
        $existing = $task && $task->exists ? $task : null;
        $parent_type = null;
        $parent_id = null;

        if (array_key_exists('title', $values)
            && (!is_string($values['title']) || trim($values['title']) === '')) {
            $errors['title'] = 'Title is required';
        }

        $errors += Field_Checks::length_errors(static::class, $values, ['title' => 'Title']);

        foreach (['status' => 'status', 'priority' => 'priority'] as $field => $noun) {
            if (!empty($values[$field]) && !Field_Checks::is_enum_value(static::class, $field, $values[$field])) {
                $errors[$field] = "Choose a {$noun} from the list";
            }
        }

        foreach (['due_date' => 'Due date', 'completed_date' => 'Completed date'] as $field => $label) {
            if (!empty($values[$field]) && !Rsx_Date::is_date($values[$field])) {
                $errors[$field] = "{$label} must be a date (YYYY-MM-DD)";
            }
        }

        if (static::__has_hours($values)
            && (!is_numeric($values['hour_estimate']) || (float) $values['hour_estimate'] < 0)) {
            $errors['hour_estimate'] = 'Estimated hours must be a number of zero or more';
        }

        if (array_key_exists('taskable', $values)) {
            [$parent_type, $parent_id, $parent_error] = static::read_taskable($values['taskable']);

            $parent_unchanged = $existing
                && $existing->taskable_type === $parent_type
                && (int) $existing->taskable_id === (int) $parent_id;

            if ($parent_error !== null) {
                $errors['taskable'] = $parent_error;
            } elseif ($parent_type && !$parent_unchanged && !static::__parent_exists($parent_type, $parent_id)) {
                $errors['taskable'] = 'The selected parent does not exist';
            }
        }

        $user_id = !empty($values['assigned_to_user_id']) ? (int) $values['assigned_to_user_id'] : null;
        if ($user_id && !static::__holds($existing, 'assigned_to_user_id', $user_id) && !User_Model::find($user_id)) {
            $errors['assigned_to_user_id'] = 'The selected user does not exist';
        }

        $project_id = !empty($values['direct_project_id']) ? (int) $values['direct_project_id'] : null;
        if ($project_id && !static::__holds($existing, 'direct_project_id', $project_id) && !Project_Model::find($project_id)) {
            $errors['direct_project_id'] = 'The selected project does not exist';
        }

        // Cycle guard: a task cannot be parented (directly or transitively) to itself.
        if (!$errors && $existing && $parent_type === 'Task_Model' && $parent_id
            && static::__would_create_cycle((int) $existing->id, $parent_id)) {
            $errors['taskable'] = 'That parent would create a circular task chain';
        }

        return $errors;
    }

    /**
     * Write submitted values onto this task through a Form_Edit and save it. Run inside
     * Form_Edit::run() after value_errors() found nothing: a new task is written whole, an
     * existing one only on the fields the submission changed.
     *
     * A blank status or priority is Pending / Medium. The parent arrives as ONE field,
     * `taskable` = {type, id}, and is written to the taskable_type / taskable_id pair.
     * `direct_project_id` is written only when the submission carries it: the staff form
     * leaves it out while the parent chain reaches a project (the picker shows the derived
     * project then), so the stored choice survives. project_id is derived by save(), which
     * also rederives every descendant when the parent changed.
     *
     * @param Form_Edit $edit The edit Form_Edit::run() handed its callback
     * @param array $values Submitted values keyed by field name
     */
    public function save_edit(Form_Edit $edit, array $values): void
    {
        $edit->set('title', $values['title'] ?? null);
        $edit->set('description', $values['description'] ?? null);
        $edit->set('status', !empty($values['status']) ? (int) $values['status'] : self::STATUS_PENDING);
        $edit->set('priority', !empty($values['priority']) ? (int) $values['priority'] : self::PRIORITY_MEDIUM);
        $edit->set('due_date', !empty($values['due_date']) ? $values['due_date'] : null);
        $edit->set('completed_date', !empty($values['completed_date']) ? $values['completed_date'] : null);
        $edit->set('assigned_to_user_id', !empty($values['assigned_to_user_id']) ? (int) $values['assigned_to_user_id'] : null);
        $edit->set('notes', $values['notes'] ?? null);
        $edit->set('hour_estimate', static::__has_hours($values) ? (float) $values['hour_estimate'] : null);

        // Polymorphic parent (nullable): one field, two columns.
        if ($edit->changed('taskable')) {
            [$parent_type, $parent_id] = static::read_taskable($values['taskable'] ?? null);
            $this->taskable_type = $parent_type;
            $this->taskable_id = $parent_type ? $parent_id : null;
        }

        if (array_key_exists('direct_project_id', $values)) {
            $edit->set('direct_project_id', !empty($values['direct_project_id']) ? (int) $values['direct_project_id'] : null);
        }

        $this->save();
    }

    /**
     * Read a `taskable` value - {type, id}, type null for no parent - into [type, id, error].
     * An absent or null value is no parent.
     *
     * @param mixed $taskable
     * @return array{0: ?string, 1: ?int, 2: ?string}
     */
    public static function read_taskable($taskable): array
    {
        if ($taskable === null || $taskable === '') {
            return [null, null, null];
        }
        if (!is_array($taskable)) {
            return [null, null, 'Unknown parent'];
        }

        $raw_type = $taskable['type'] ?? null;
        $type = static::normalize_parent_type($raw_type);
        $id = !empty($taskable['id']) ? (int) $taskable['id'] : null;

        if (!empty($raw_type) && $raw_type !== 'None' && !$type) {
            return [null, null, 'Unknown parent type'];
        }
        if ($type && !$id) {
            return [$type, null, 'A parent must be selected for the chosen parent type'];
        }

        return [$type, $type ? $id : null, null];
    }

    /**
     * Normalize a caller-supplied parent type string to a known type, or null.
     * Accepts empty/"None" as "no parent".
     */
    public static function normalize_parent_type($type): ?string
    {
        if (empty($type) || $type === 'None') {
            return null;
        }
        return in_array($type, self::PARENT_TYPES, true) ? $type : null;
    }

    /** Is hour_estimate present and not blank? */
    private static function __has_hours(array $values): bool
    {
        return isset($values['hour_estimate']) && $values['hour_estimate'] !== '';
    }

    /** Does the stored record already hold $id in $column? Such a reference is not re-validated. */
    private static function __holds(?Task_Model $task, string $column, int $id): bool
    {
        return $task !== null && (int) $task->{$column} === $id;
    }

    /**
     * Does the given parent entity exist in this site? Site-scoped, so another site's
     * record misses.
     */
    private static function __parent_exists(string $type, int $id): bool
    {
        return match ($type) {
            'Task_Model' => static::whereKey($id)->exists(),
            'Project_Model' => Project_Model::whereKey($id)->exists(),
            'Client_Model' => Client_Model::whereKey($id)->exists(),
            'User_Model' => User_Model::whereKey($id)->exists(),
            default => false,
        };
    }

    /**
     * Would parenting $task_id to task $parent_id create a cycle? Walks the prospective
     * parent's taskable chain; a cycle exists if it reaches $task_id (or the parent is the
     * task itself). Visited-set + depth cap guard against an already-corrupt chain.
     */
    private static function __would_create_cycle(int $task_id, int $parent_id): bool
    {
        if ($parent_id === $task_id) {
            return true;
        }

        $visited = [];
        $current = static::find($parent_id);
        $depth = 0;

        while ($current) {
            if (++$depth > self::CHAIN_DEPTH_CAP) {
                return true; // treat a runaway chain as unsafe
            }
            if ($current->id === $task_id) {
                return true;
            }
            if (isset($visited[$current->id])) {
                return false; // pre-existing cycle upstream, but not through $task_id
            }
            $visited[$current->id] = true;

            if ($current->taskable_type !== 'Task_Model' || empty($current->taskable_id)) {
                return false;
            }
            $current = static::find($current->taskable_id);
        }

        return false;
    }

    /**
     * Ajax model fetch - allows JavaScript to load task records
     * Unrestricted for development/testing - no auth required
     */
    #[Auth('can_view_data')]
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $model = static::find($id);
        return $model ?: false;
    }
}