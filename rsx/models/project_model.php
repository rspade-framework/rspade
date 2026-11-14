<?php

namespace Rsx\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\Lib\FieldChecks\Field_Checks;
use Rsx\Lib\TextTypes\RawText\Raw_Text;
use Rsx\Lib\TextTypes\RichText\Rich_Text;
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
 * @property \App\RSpade\Core\Database\Money\Rsx_Money $budget money: a value object, two decimal places, never a float
 * @property int $client_department_id
 * @property int $client_id
 * @property string $completed_date
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property string $deleted_by_type type ref: a model class name, stored as a type-ref id
 * @property \Rsx\Lib\TextTypes\RichText\Rich_Text $description text type: a value object, filtered on write
 * @property string $due_date
 * @property int $id
 * @property string $name
 * @property \Rsx\Lib\TextTypes\RawText\Raw_Text $notes text type: a value object, filtered on write
 * @property int $owner_user_id
 * @property int $parent_project_id
 * @property int $priority enum: PRIORITY_LOW, PRIORITY_MEDIUM, PRIORITY_HIGH, PRIORITY_URGENT (also $priority__label and the other $priority__* properties)
 * @property int $site_id
 * @property string $start_date
 * @property int $status enum: STATUS_PLANNING, STATUS_ACTIVE, STATUS_ON_HOLD, STATUS_COMPLETED, STATUS_CANCELLED (also $status__label and the other $status__* properties)
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

    /** Edited through Form_Edit, which reads what another editor changed from here. */
    public static $revisions = true;

    /**
     * Column declarations (see rsx:man model).
     *
     * TEXT TYPES - `description` is authored in a WYSIWYG and holds HTML; `notes` is a plain
     * textarea. Declared, that difference is no longer something each template, endpoint and
     * export has to remember - misremembering it was either a broken render or an XSS. The value
     * carries its own encoding: it is filtered by its type on write, rendered by its type's
     * component on a live page, rendered by to_html() into a server-generated document, and
     * reduced by to_plain_text() for a CSV cell or a search index. A TEXT column with no
     * text_type entry is an ordinary string; declaration is opt-in.
     *
     * MONEY - `budget` is an amount of money, so it reads as an Rsx_Money: exact to the cent,
     * never a float, printed by `<%= project.budget %>` in a template and totalled with
     * sum_money('budget'). It is null when the project has no budget, and an object is always
     * truthy, so "has a budget" is `!== null`, never a truthiness test (rsx:man money).
     */
    public static $columns = [
        'status' => ['enum' => [
            1 => ['constant' => 'STATUS_PLANNING', 'label' => 'Planning', 'badge' => 'bg-info'],
            2 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active', 'badge' => 'bg-success'],
            3 => ['constant' => 'STATUS_ON_HOLD', 'label' => 'On Hold', 'badge' => 'bg-warning'],
            4 => ['constant' => 'STATUS_COMPLETED', 'label' => 'Completed', 'badge' => 'bg-primary'],
            5 => ['constant' => 'STATUS_CANCELLED', 'label' => 'Cancelled', 'badge' => 'bg-secondary'],
        ]],
        'priority' => ['enum' => [
            1 => ['constant' => 'PRIORITY_LOW', 'label' => 'Low', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'PRIORITY_MEDIUM', 'label' => 'Medium', 'badge' => 'bg-primary'],
            3 => ['constant' => 'PRIORITY_HIGH', 'label' => 'High', 'badge' => 'bg-warning'],
            4 => ['constant' => 'PRIORITY_URGENT', 'label' => 'Urgent', 'badge' => 'bg-danger'],
        ]],
        'description' => ['text_type' => Rich_Text::class],
        'notes' => ['text_type' => Raw_Text::class],
        'budget' => ['money' => true],
    ];

    /**
     * APPENDED PROPERTIES - computed values that must reach JavaScript.
     *
     * $appends is the route: Eloquent serializes each name through its getXAttribute()
     * accessor inside parent::toArray(), which Rsx_Model_Abstract::toArray() calls first, so
     * these ride every payload this model produces - fetch(), a relationship, a list - and the
     * generated Base_Project_Model.js declares each one. Hand-adding the keys inside fetch()
     * would put them on exactly one payload and on none of the others.
     *
     * @var array
     */
    protected $appends = ['project_id_formatted'];

    /**
     * Get formatted project ID for display
     * @return string
     */
    public function project_id_formatted()
    {
        return '#PR' . str_pad($this->id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor for the appended `project_id_formatted` property. Delegates - never
     * re-implements: the method is the definition, the property is only its serialization.
     *
     * @return string
     */
    public function getProjectIdFormattedAttribute(): string
    {
        return $this->project_id_formatted();
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
     * endpoint per house pattern.
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

    // =========================================================================
    // VALUE RULES AND THE WRITE - shared by every surface that saves a project (the staff
    // save, the external API's create/update).
    // =========================================================================

    /**
     * Answers [field => message] for every submitted value a project cannot hold; empty when
     * all of them can. Checks only the keys $values carries, so an API update that sends a
     * few fields is checked on those few. A caller that requires a field (the name and the
     * client, on a staff save or an API create) makes sure the key is present.
     *
     *   - name, when present, is non-blank text that fits Project_Model::field_length('name')
     *   - status and priority, when not blank, are declared enum ids
     *   - start_date and due_date, when not blank, are YYYY-MM-DD dates
     *   - budget is read by money_from_submitted('budget', ...): what a person typed or an
     *     Rsx_Money, blank is no budget; not an amount, a third decimal place or an amount
     *     beyond the column is refused with the exception's own message, and a negative
     *     amount with "Budget must be zero or more."
     *   - contacts and assigned_users, when present, are lists of record ids of this site
     *   - client_id, when present, names a live client of this site (blank: "Client is
     *     required"); parent_project_id, when not blank, a live project of this site that is
     *     neither this project nor one of its descendants
     *
     * A reference $project already holds (client, parent, a linked contact or user) is
     * accepted as it stands, so a project whose referent was since deleted still saves; a
     * reference the submission adds or changes must name a live record. Every reference is
     * resolved through a site-scoped query, so an id from another site misses exactly as a
     * missing one does.
     *
     * @param array $values Submitted values keyed by field name
     * @param Project_Model|null $project The project being edited; null (or unsaved) for a new one
     * @return array<string, string>
     */
    public static function value_errors(array $values, ?Project_Model $project = null): array
    {
        $errors = [];
        $existing = $project && $project->exists ? $project : null;

        if (array_key_exists('name', $values)
            && (!is_string($values['name']) || trim($values['name']) === '')) {
            $errors['name'] = 'Project name is required';
        }

        $errors += Field_Checks::length_errors(static::class, $values, ['name' => 'Project name']);

        foreach (['status' => 'status', 'priority' => 'priority'] as $field => $noun) {
            if (!empty($values[$field]) && !Field_Checks::is_enum_value(static::class, $field, $values[$field])) {
                $errors[$field] = "Choose a {$noun} from the list";
            }
        }

        foreach (['start_date' => 'Start date', 'due_date' => 'Due date'] as $field => $label) {
            if (!empty($values[$field]) && !Rsx_Date::is_date($values[$field])) {
                $errors[$field] = "{$label} must be a date (YYYY-MM-DD)";
            }
        }

        // Budget: a money column. A refusal whose is_input_problem() is true is the field
        // error; any other exception is a coding error and is left to fail loud.
        if (array_key_exists('budget', $values)) {
            try {
                $budget = static::money_from_submitted('budget', $values['budget']);
                if ($budget !== null && $budget->is_negative()) {
                    $errors['budget'] = 'Budget must be zero or more.';
                }
            } catch (Rsx_Money_Exception $e) {
                if (!$e->is_input_problem()) {
                    throw $e;
                }
                $errors['budget'] = $e->getMessage();
            }
        }

        // A pivot list is a list of record ids; anything else ("abc", 0, a nested array) is
        // a field error, never an id 0 that fails a foreign key.
        foreach (['contacts' => 'contacts', 'assigned_users' => 'users'] as $field => $noun) {
            if (array_key_exists($field, $values) && static::read_id_list($values[$field]) === null) {
                $errors[$field] = "One of the selected {$noun} does not exist";
            }
        }

        if (array_key_exists('client_id', $values)) {
            $client_id = !empty($values['client_id']) ? (int) $values['client_id'] : null;
            if (!$client_id) {
                $errors['client_id'] = 'Client is required';
            } elseif (!static::__holds($existing, 'client_id', $client_id) && !Client_Model::find($client_id)) {
                $errors['client_id'] = 'The selected client does not exist';
            }
        }

        $parent_id = !empty($values['parent_project_id']) ? (int) $values['parent_project_id'] : null;
        if ($parent_id && !static::__holds($existing, 'parent_project_id', $parent_id) && !static::find($parent_id)) {
            $errors['parent_project_id'] = 'The selected parent project does not exist';
        }

        // Every pivot id must name a record of this site - or be one the project already links.
        $linked_contacts = $existing ? Project_Contact_Model::contact_ids_for_project((int) $existing->id) : [];
        $linked_users = $existing ? Project_User_Model::user_ids_for_project((int) $existing->id) : [];

        if (array_key_exists('contacts', $values) && !isset($errors['contacts'])
            && !static::__all_on_site(Contact_Model::class, array_diff(static::read_id_list($values['contacts']), $linked_contacts))) {
            $errors['contacts'] = 'One of the selected contacts does not exist';
        }

        if (array_key_exists('assigned_users', $values) && !isset($errors['assigned_users'])
            && !static::__all_on_site(User_Model::class, array_diff(static::read_id_list($values['assigned_users']), $linked_users))) {
            $errors['assigned_users'] = 'One of the selected users does not exist';
        }

        // Cycle guard: a project cannot be parented to itself OR to one of its own descendants
        // (which would form a loop) - the forbidden set is its whole subtree. A new project
        // has no subtree, so any existing project is a valid parent.
        if (!$errors && $existing && $parent_id
            && in_array($parent_id, static::self_and_descendant_ids((int) $existing->id), true)) {
            $errors['parent_project_id'] = 'A project cannot be its own parent or a descendant of itself';
        }

        return $errors;
    }

    /**
     * Write submitted values onto this project through a Form_Edit and save it. Run inside
     * Form_Edit::run() after value_errors() found nothing: a new project is written whole, an
     * existing one only on the fields the submission changed.
     *
     * A blank status is Active and a blank priority Medium; a blank date, parent or budget is
     * none. The budget is read again by money_from_submitted(), so Form_Edit compares the
     * exact amount and a figure re-typed differently ("1234.5" for 1234.50) writes nothing.
     * The contact and user links are the caller's: they are not columns of this record.
     *
     * @param Form_Edit $edit The edit Form_Edit::run() handed its callback
     * @param array $values Submitted values keyed by field name
     */
    public function save_edit(Form_Edit $edit, array $values): void
    {
        $edit->set('name', $values['name'] ?? null);
        $edit->set('description', $values['description'] ?? null);
        $edit->set('client_id', !empty($values['client_id']) ? (int) $values['client_id'] : null);
        $edit->set('parent_project_id', !empty($values['parent_project_id']) ? (int) $values['parent_project_id'] : null);
        $edit->set('status', !empty($values['status']) ? (int) $values['status'] : self::STATUS_ACTIVE);
        $edit->set('priority', !empty($values['priority']) ? (int) $values['priority'] : self::PRIORITY_MEDIUM);
        $edit->set('start_date', !empty($values['start_date']) ? $values['start_date'] : null);
        $edit->set('due_date', !empty($values['due_date']) ? $values['due_date'] : null);
        $edit->set('budget', static::money_from_submitted('budget', $values['budget'] ?? null));
        $edit->set('notes', $values['notes'] ?? null);

        $this->save();
    }

    /**
     * A submitted contact or user list as distinct positive record ids, or null when any
     * entry is not one. An absent or empty list is an empty set.
     *
     * @param mixed $value
     * @return array<int>|null
     */
    public static function read_id_list($value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $entry) {
            if (is_int($entry) && $entry > 0) {
                $ids[] = $entry;
            } elseif (is_string($entry) && ctype_digit($entry) && (int) $entry > 0) {
                $ids[] = (int) $entry;
            } else {
                return null;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Does the stored record already hold $id in $column? Such a reference is not re-validated. */
    private static function __holds(?Project_Model $project, string $column, int $id): bool
    {
        return $project !== null && (int) $project->{$column} === $id;
    }

    /**
     * Does every id name a record of the current site? The model's site scope does the
     * tenant check: an id from another site (or one that does not exist) is not counted.
     *
     * @param string $model_class A site-scoped model class
     * @param array $ids Submitted ids
     * @return bool
     */
    private static function __all_on_site(string $model_class, array $ids): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            return true;
        }

        return $model_class::whereIn('id', $ids)->count() === count($ids);
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
    #[Auth('can_view_data')]
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $project = static::find($id);

        if (!$project) {
            return false;
        }

        // No hand-added keys: project_id_formatted is a declared appended property ($appends
        // above), so toArray() already carries it.
        return $project->toArray();
    }
}