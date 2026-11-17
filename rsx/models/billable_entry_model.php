<?php

namespace Rsx\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Date;
use App\RSpade\Core\Time\Rsx_Time;
use Rsx\Lib\FieldChecks\Field_Checks;
use Rsx\Models\Billable_Entry_Expense_Detail_Model;
use Rsx\Models\Billable_Entry_Time_Detail_Model;
use Rsx\Models\Invoice_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;
use Rsx\Permission;
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: billable_entries
 *
 * @property \App\RSpade\Core\Database\Money\Rsx_Money $amount money: a value object, two decimal places, never a float
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property string $deleted_by_type type ref: a model class name, stored as a type-ref id
 * @property string $description
 * @property string $entry_date
 * @property int $id
 * @property int $invoice_id
 * @property int $project_id
 * @property string $rejection_reason
 * @property string $reviewed_at
 * @property int $reviewed_by_user_id
 * @property int $site_id
 * @property int $status enum: STATUS_DRAFT, STATUS_SUBMITTED, STATUS_APPROVED, STATUS_REJECTED, STATUS_BILLED (also $status__label and the other $status__* properties)
 * @property int $task_id
 * @property int $type_id enum: TYPE_TIME, TYPE_EXPENSE, TYPE_FIXED_FEE (also $type_id__label and the other $type_id__* properties)
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
 * @property int $user_id
 * @property \App\RSpade\Core\Database\Money\Rsx_Money $hourly_rate (detail: billable_entry_time_details) money: a value object, two decimal places, never a float
 * @property string $hours (detail: billable_entry_time_details) decimal
 * @property \App\RSpade\Core\Database\Money\Rsx_Money $cost (detail: billable_entry_expense_details) money: a value object, two decimal places, never a float
 * @property string $vendor (detail: billable_entry_expense_details)
 *
 * @property-read string $type_id__label
 * @property-read string $type_id__constant
 * @property-read string $status__label
 * @property-read string $status__constant
 *
 * @method static array type_id__enum() Get all enum definitions with full metadata
 * @method static array type_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array type_id__enum_labels() Get simple id => label map
 * @method static array type_id__enum_ids() Get array of all valid enum IDs
 * @method static array status__enum() Get all enum definitions with full metadata
 * @method static array status__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status__enum_labels() Get simple id => label map
 * @method static array status__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
#[Auth('is_logged_in')]
class Billable_Entry_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per unit of billable work or cost - grows every working day.
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
    const TYPE_TIME = 1;
    const TYPE_EXPENSE = 2;
    const TYPE_FIXED_FEE = 3;
    const STATUS_DRAFT = 1;
    const STATUS_SUBMITTED = 2;
    const STATUS_APPROVED = 3;
    const STATUS_REJECTED = 4;
    const STATUS_BILLED = 5;

    use SoftDeletes;

    protected $table = 'billable_entries';
    protected $fillable = []; // No mass assignment - always explicit

    // Realtime: an entry emits its own change and (via #[Realtime_Touch] on project() and
    // invoice()) touches the project whose Billing tab lists it and the invoice it is billed
    // on. Its detail models touch it back, so a detail-only write refreshes the entry too.
    public static $realtime = true;

    /** Edited through Form_Edit, which reads what another editor changed from here. */
    public static $revisions = true;

    /**
     * Column declarations (see rsx:man model).
     *
     * `type_id` is the detail-table discriminator ($detail_tables below) and never changes
     * once the entry exists. `status` is the approval lifecycle (see THE LIFECYCLE below).
     *
     * `amount` is the entry's billable amount - money, so an Rsx_Money. It is written by
     * save_edit() and never typed in, except for a fixed fee: hours x rate rounded half-up
     * for time, the cost for an expense, the entered amount for a fixed fee.
     */
    public static $columns = [
        'type_id' => ['enum' => [
            1 => ['constant' => 'TYPE_TIME', 'label' => 'Time', 'badge' => 'bg-primary'],
            2 => ['constant' => 'TYPE_EXPENSE', 'label' => 'Expense', 'badge' => 'bg-info'],
            3 => ['constant' => 'TYPE_FIXED_FEE', 'label' => 'Fixed Fee', 'badge' => 'bg-secondary'],
        ]],
        'status' => ['enum' => [
            1 => ['constant' => 'STATUS_DRAFT', 'label' => 'Draft', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'STATUS_SUBMITTED', 'label' => 'Submitted', 'badge' => 'bg-info'],
            3 => ['constant' => 'STATUS_APPROVED', 'label' => 'Approved', 'badge' => 'bg-success'],
            4 => ['constant' => 'STATUS_REJECTED', 'label' => 'Rejected', 'badge' => 'bg-danger'],
            5 => ['constant' => 'STATUS_BILLED', 'label' => 'Billed', 'badge' => 'bg-primary'],
        ]],
        'amount' => ['money' => true],
    ];

    /**
     * DETAIL TABLES (rsx:man detail_tables) - the fields only one kind of entry has.
     *
     * A time entry's hours and hourly rate live on billable_entry_time_details
     * ($entry->billable_entry_time), an expense's vendor and cost on
     * billable_entry_expense_details ($entry->billable_entry_expense). A fixed fee is absent
     * from the map: it has no detail row, and its whole value is the base `amount`.
     */
    public static $detail_tables = [
        'type_id' => [
            self::TYPE_TIME => Billable_Entry_Time_Detail_Model::class,
            self::TYPE_EXPENSE => Billable_Entry_Expense_Detail_Model::class,
            // TYPE_FIXED_FEE absent => no detail row.
        ],
    ];

    /** The attachment category of an entry's receipt - one per entry, on the BASE record. */
    public const RECEIPT_CATEGORY = 'receipt';

    /** The statuses in which the entry's author may still change it. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    #[Realtime_Touch]
    public function project()
    {
        return $this->belongsTo(Project_Model::class, 'project_id');
    }

    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function task()
    {
        return $this->belongsTo(Task_Model::class, 'task_id');
    }

    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    #[Realtime_Touch]
    public function invoice()
    {
        return $this->belongsTo(Invoice_Model::class, 'invoice_id');
    }

    /** The person who did the work (a users row - this site's membership). */
    #[Relationship]
    public function user()
    {
        return $this->belongsTo(User_Model::class, 'user_id');
    }

    /** The approver who last approved or rejected the entry. */
    #[Relationship]
    public function reviewed_by_user()
    {
        return $this->belongsTo(User_Model::class, 'reviewed_by_user_id');
    }

    // =========================================================================
    // WHO MAY CHANGE AN ENTRY
    // =========================================================================

    /**
     * Whether the signed-in user may change this entry: its author (the person whose work it
     * records), or an approver (Permission::can_approve_billing()). Asked by every endpoint
     * that edits, submits, deletes or attaches a receipt to an existing entry; the endpoint's
     * #[Auth] gates still apply on top of it.
     */
    public function is_changeable_by_current_user(): bool
    {
        return (int) $this->user_id === (int) Session::get_user_id() || Permission::can_approve_billing();
    }

    // =========================================================================
    // VALUE RULES AND THE WRITE - shared by every surface that saves an entry.
    // =========================================================================

    /**
     * Why this entry cannot be edited, or null when it can. Only a DRAFT or REJECTED entry
     * is edited; a submitted entry waits for its approver, and an approved or billed entry
     * is locked - its amount is what the client is charged.
     */
    public function edit_error(): ?string
    {
        if (!$this->exists || in_array((int) $this->status, self::EDITABLE_STATUSES, true)) {
            return null;
        }

        return "This entry is {$this->status__label} and can no longer be edited.";
    }

    /**
     * Answers [field => message] for every submitted value an entry of type $type_id cannot
     * hold; empty when all of them can.
     *
     * A NEW entry treats an absent required field as blank: project_id, entry_date,
     * description and its kind's own figures (hours for time, cost for an expense, amount for
     * a fixed fee). Two keys may be absent on a new entry: user_id (the signed-in user did the
     * work) and hourly_rate (the project's rate is copied). An EXISTING entry checks only the
     * keys $values carries; an absent key is left as stored.
     *
     *   - type_id, on a new entry, is a declared type
     *   - project_id names a live project of this site (one the entry already holds stands)
     *   - task_id, when not blank, names a task whose (derived) project is the entry's
     *     project - the chosen one, or the stored one when the submission does not change it
     *   - user_id, when present, names a user of this site; recording work for somebody
     *     other than the entry's author (yourself, on a new entry) needs can_approve_billing
     *   - entry_date is a YYYY-MM-DD date, description non-blank text that fits the column
     *   - TIME: hours more than zero with at most two decimal places; hourly_rate an amount
     *     of zero or more (blank on a new entry whose project has no rate: required)
     *   - EXPENSE: cost an amount of zero or more; vendor fits its column
     *   - FIXED_FEE: amount an amount of zero or more
     *   - the computed amount fits the amount column
     *
     * @param array $values Submitted values keyed by field name
     * @param int $type_id The entry's type: the stored one on an edit, the chosen one on a create
     * @param Billable_Entry_Model|null $entry The entry being edited; null (or unsaved) for a new one
     * @return array<string, string>
     */
    public static function value_errors(array $values, int $type_id, ?Billable_Entry_Model $entry = null): array
    {
        $errors = [];
        $existing = $entry && $entry->exists ? $entry : null;

        if (!$existing) {
            if (!Field_Checks::is_enum_value(static::class, 'type_id', $type_id)) {
                return ['type_id' => 'Choose the kind of entry'];
            }
            $values += array_fill_keys(array_merge(
                ['project_id', 'entry_date', 'description'],
                static::__required_figures($type_id)
            ), null);
        }

        // Project
        $project_id = $existing ? (int) $existing->project_id : null;
        if (array_key_exists('project_id', $values)) {
            $project_id = static::__read_id($values['project_id']);
            if ($project_id === null) {
                $errors['project_id'] = 'Project is required';
            } elseif (!($existing && (int) $existing->project_id === $project_id) && !Project_Model::find($project_id)) {
                $errors['project_id'] = 'The selected project does not exist';
                $project_id = null;
            }
        }

        // Task: must belong to the entry's project. A task and project the entry already holds
        // together stand as they are, even if the task has since been deleted or moved.
        $task_present = array_key_exists('task_id', $values);
        $task_id = $task_present ? static::__read_id($values['task_id']) : ($existing ? ($existing->task_id !== null ? (int) $existing->task_id : null) : null);
        if ($task_present && $task_id === null && $values['task_id'] !== null && $values['task_id'] !== '') {
            $errors['task_id'] = 'The selected task does not exist';
        } elseif ($task_id !== null && $project_id !== null && !isset($errors['project_id'])) {
            $unchanged = $existing
                && (int) $existing->task_id === $task_id
                && (int) $existing->project_id === $project_id;
            if (!$unchanged) {
                $task = Task_Model::find($task_id);
                if (!$task) {
                    $errors['task_id'] = 'The selected task does not exist';
                } elseif ((int) $task->project_id !== $project_id) {
                    $errors['task_id'] = 'The selected task is not part of this project';
                }
            }
        }

        // Who did the work
        if (array_key_exists('user_id', $values)) {
            $user_id = static::__read_id($values['user_id']);
            $author_id = $existing ? (int) $existing->user_id : (int) Session::get_user_id();
            if ($user_id === null) {
                $errors['user_id'] = 'Choose who did the work';
            } elseif ($user_id !== $author_id && !Permission::can_approve_billing()) {
                $errors['user_id'] = 'Only an approver can record work for someone else';
            } elseif ($user_id !== $author_id && !User_Model::find($user_id)) {
                $errors['user_id'] = 'The selected person does not exist';
            }
        }

        if (array_key_exists('entry_date', $values)
            && (empty($values['entry_date']) || !Rsx_Date::is_date($values['entry_date']))) {
            $errors['entry_date'] = empty($values['entry_date']) ? 'Date is required' : 'Date must be a date (YYYY-MM-DD)';
        }

        if (array_key_exists('description', $values)
            && (!is_string($values['description']) || trim($values['description']) === '')) {
            $errors['description'] = 'Description is required';
        }
        $errors += Field_Checks::length_errors(static::class, $values, ['description' => 'Description']);

        if ($type_id === self::TYPE_EXPENSE) {
            $errors += Field_Checks::length_errors(Billable_Entry_Expense_Detail_Model::class, $values, ['vendor' => 'Vendor']);
        }

        $errors += static::__pricing($values, $type_id, $existing, $project_id)['errors'];

        return $errors;
    }

    /**
     * Write submitted values onto this entry through a Form_Edit and save it, with its detail
     * row. Run inside Form_Edit::run() after value_errors() found nothing; the caller sets
     * type_id on a NEW entry before calling (it never changes afterwards).
     *
     * A new entry is written whole and starts as DRAFT, its user defaulting to the signed-in
     * user and a time entry's rate to the project's. An existing entry is written only on the
     * fields the submission changed; a REJECTED entry that is saved returns to DRAFT. The
     * amount is recomputed from what will be stored - a changed field's submitted value,
     * otherwise the stored one - so it always agrees with the detail.
     *
     * The base row is saved BEFORE the detail accessor is read: the accessor builds a new
     * detail with the entry's id already set, which needs the id to exist. After a new
     * detail is saved, refresh() the entry before toArray(), or the payload leaves it out.
     *
     * Answers [field => message] when the stored values this edit combines with make an
     * amount the entry cannot hold (an amount value_errors() could not see); nothing has been
     * written then. Empty on success.
     *
     * @param Form_Edit $edit The edit Form_Edit::run() handed its callback
     * @param array $values Submitted values keyed by field name
     * @return array<string, string>
     */
    public function save_edit(Form_Edit $edit, array $values): array
    {
        $type_id = (int) $this->type_id;
        $is_new = !$this->exists;

        // The values this save writes: every submitted field on a new entry, only the changed
        // ones on an edit. The amount is priced from these, falling back to what is stored.
        $writing = array_filter($values, fn ($field) => $edit->changed((string) $field), ARRAY_FILTER_USE_KEY);
        $project_id = array_key_exists('project_id', $writing) ? static::__read_id($writing['project_id']) : (int) $this->project_id;
        $pricing = static::__pricing($writing, $type_id, $is_new ? null : $this, $project_id);
        if ($pricing['errors']) {
            return $pricing['errors'];
        }

        $edit->set('project_id', static::__read_id($values['project_id'] ?? null));
        $edit->set('task_id', static::__read_id($values['task_id'] ?? null));
        $edit->set('entry_date', $values['entry_date'] ?? null);
        $edit->set('description', isset($values['description']) ? trim($values['description']) : null);

        if ($is_new && !array_key_exists('user_id', $values)) {
            $this->user_id = (int) Session::get_user_id();
        } else {
            $edit->set('user_id', static::__read_id($values['user_id'] ?? null));
        }

        $this->amount = $pricing['amount'];

        if ($is_new || (int) $this->status === self::STATUS_REJECTED) {
            $this->status = self::STATUS_DRAFT;
        }

        $this->save();

        if ($type_id === self::TYPE_TIME) {
            $detail = $this->billable_entry_time;
            $detail->hours = $pricing['hours'];
            $detail->hourly_rate = $pricing['hourly_rate'];
            $detail->save();
        } elseif ($type_id === self::TYPE_EXPENSE) {
            $detail = $this->billable_entry_expense;
            if ($edit->changed('vendor')) {
                $vendor = isset($values['vendor']) ? trim((string) $values['vendor']) : '';
                $detail->vendor = $vendor === '' ? null : $vendor;
            }
            $detail->cost = $pricing['cost'];
            $detail->save();
        }

        return [];
    }

    /**
     * The figures an entry of $type_id cannot be created without.
     *
     * @return string[]
     */
    private static function __required_figures(int $type_id): array
    {
        return match ($type_id) {
            self::TYPE_TIME => ['hours'],
            self::TYPE_EXPENSE => ['cost'],
            self::TYPE_FIXED_FEE => ['amount'],
            default => [],
        };
    }

    /**
     * Price an entry: read its figures from $values (falling back to the stored entry, and
     * for a new time entry with no hourly_rate key to the project's rate), and compute the
     * amount.
     *
     * Answers ['errors' => [field => message], 'amount' => ?Rsx_Money, 'hours' => ?string,
     * 'hourly_rate' => ?Rsx_Money, 'cost' => ?Rsx_Money]. The amount is null whenever a
     * figure is missing or refused.
     *
     *   TIME       hourly_rate x hours, rounded ROUND_HALF_UP - hours is a factor, never money
     *   EXPENSE    the cost
     *   FIXED_FEE  the amount entered
     */
    private static function __pricing(array $values, int $type_id, ?Billable_Entry_Model $existing, ?int $project_id): array
    {
        $result = ['errors' => [], 'amount' => null, 'hours' => null, 'hourly_rate' => null, 'cost' => null];
        $errors = &$result['errors'];

        if ($type_id === self::TYPE_TIME) {
            $detail = $existing ? $existing->billable_entry_time : null;

            if (array_key_exists('hours', $values)) {
                $result['hours'] = static::read_hours($values['hours']);
                if ($result['hours'] === null) {
                    $errors['hours'] = static::__is_blank($values['hours'])
                        ? 'Hours is required'
                        : 'Hours must be a number more than zero, with at most two decimal places';
                }
            } elseif ($detail && $detail->hours !== null) {
                $result['hours'] = (string) $detail->hours;
            }

            if (array_key_exists('hourly_rate', $values)) {
                $result['hourly_rate'] = static::__read_amount(
                    Billable_Entry_Time_Detail_Model::class, 'hourly_rate', $values['hourly_rate'], 'Hourly rate', $errors
                );
            } elseif ($detail) {
                $result['hourly_rate'] = $detail->hourly_rate;
            } elseif ($project_id !== null) {
                $result['hourly_rate'] = Project_Model::withTrashed()->find($project_id)?->hourly_rate;
                if ($result['hourly_rate'] === null) {
                    $errors['hourly_rate'] = 'Hourly rate is required - this project has no rate to copy';
                }
            }

            if ($result['hours'] !== null && $result['hourly_rate'] !== null) {
                $result['amount'] = $result['hourly_rate']->multiply($result['hours'], Rsx_Money::ROUND_HALF_UP);
            }
        } elseif ($type_id === self::TYPE_EXPENSE) {
            if (array_key_exists('cost', $values)) {
                $result['cost'] = static::__read_amount(
                    Billable_Entry_Expense_Detail_Model::class, 'cost', $values['cost'], 'Cost', $errors
                );
            } elseif ($existing) {
                $result['cost'] = $existing->billable_entry_expense->cost;
            }
            $result['amount'] = $result['cost'];
        } elseif ($type_id === self::TYPE_FIXED_FEE) {
            if (array_key_exists('amount', $values)) {
                $result['amount'] = static::__read_amount(static::class, 'amount', $values['amount'], 'Amount', $errors);
            } elseif ($existing) {
                $result['amount'] = $existing->amount;
            }
        }

        if ($result['amount'] !== null && $result['amount']->greater_than(static::money_limit('amount'))) {
            $field = $type_id === self::TYPE_TIME ? 'hours' : ($type_id === self::TYPE_EXPENSE ? 'cost' : 'amount');
            $errors[$field] = 'The amount of this entry is larger than an entry can hold.';
            $result['amount'] = null;
        }

        return $result;
    }

    /**
     * A required, non-negative amount for a money column, read through that column's model
     * (a detail column through the DETAIL model - the base model's money accessors cover the
     * base table only). Records a field error and answers null when it is blank or refused.
     */
    private static function __read_amount(string $model_class, string $column, $value, string $label, array &$errors): ?Rsx_Money
    {
        try {
            $amount = $model_class::money_from_submitted($column, $value);
        } catch (Rsx_Money_Exception $e) {
            if (!$e->is_input_problem()) {
                throw $e;
            }
            $errors[$column] = $e->getMessage();

            return null;
        }

        if ($amount === null) {
            $errors[$column] = "{$label} is required";

            return null;
        }

        if ($amount->is_negative()) {
            $errors[$column] = "{$label} must be zero or more.";

            return null;
        }

        return $amount;
    }

    /**
     * A submitted number of hours as the canonical decimal string the column stores
     * ("1.5" -> "1.50"), or null when it is blank, not a number, not more than zero, has more
     * than two decimal places or is beyond the column (DECIMAL(8,2)). Time_Entry_Input submits
     * decimal hours ("1.5"; "1:30" is converted in the browser).
     */
    public static function read_hours($value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value) || !preg_match('/^\s*(\d{1,6})(?:\.(\d{1,2})0*)?\s*$/', $value, $m)) {
            return null;
        }

        $hours = ltrim($m[1], '0') . '.' . str_pad($m[2] ?? '', 2, '0');
        $hours = str_starts_with($hours, '.') ? '0' . $hours : $hours;

        return $hours === '0.00' ? null : $hours;
    }

    /** A submitted record id as a positive int, or null for blank or anything else. */
    private static function __read_id($value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private static function __is_blank($value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    // =========================================================================
    // THE LIFECYCLE
    //
    //   DRAFT --submit--> SUBMITTED --approve--> APPROVED --(invoiced)--> BILLED
    //     ^                   |                     ^                       |
    //     |                 reject                  +----(void / remove)----+
    //     |                   v
    //     +---(saved)---- REJECTED --submit--> SUBMITTED
    //
    // Each transition is a pair: X_error() answers why it cannot happen now (null when it
    // can), and X() performs it - refusing loudly when called while X_error() has a reason,
    // because the caller is required to ask first. Billing and releasing are the invoice's
    // (Invoice_Model), never an endpoint's of their own.
    // =========================================================================

    public function submit_error(): ?string
    {
        if (!in_array((int) $this->status, self::EDITABLE_STATUSES, true)) {
            return "This entry is {$this->status__label}; only a draft or rejected entry can be submitted.";
        }

        return null;
    }

    /** Submit the entry for approval, clearing any earlier review. */
    public function submit(): void
    {
        static::__refuse($this->submit_error());

        $this->status = self::STATUS_SUBMITTED;
        $this->reviewed_by_user_id = null;
        $this->reviewed_at = null;
        $this->rejection_reason = null;
        $this->save();
    }

    public function approve_error(): ?string
    {
        if ((int) $this->status !== self::STATUS_SUBMITTED) {
            return "This entry is {$this->status__label}; only a submitted entry can be approved.";
        }

        return null;
    }

    /** Approve the entry as the signed-in user. Its amount is final from here on. */
    public function approve(): void
    {
        static::__refuse($this->approve_error());

        $this->status = self::STATUS_APPROVED;
        $this->reviewed_by_user_id = (int) Session::get_user_id();
        $this->reviewed_at = Rsx_Time::now_iso();
        $this->rejection_reason = null;
        $this->save();
    }

    /**
     * Why the entry cannot be rejected with this reason, keyed by field
     * (['rejection_reason' => ...] for the reason itself, ['status' => ...] for the state).
     *
     * @return array<string, string>
     */
    public function reject_errors($reason): array
    {
        if ((int) $this->status !== self::STATUS_SUBMITTED) {
            return ['status' => "This entry is {$this->status__label}; only a submitted entry can be rejected."];
        }

        if (!is_string($reason) || trim($reason) === '') {
            return ['rejection_reason' => 'A reason is required to reject an entry'];
        }

        return Field_Checks::length_errors(static::class, ['rejection_reason' => trim($reason)], ['rejection_reason' => 'The reason']);
    }

    /** Reject the entry back to its author with a reason, as the signed-in user. */
    public function reject(string $reason): void
    {
        $errors = $this->reject_errors($reason);
        static::__refuse($errors ? reset($errors) : null);

        $this->status = self::STATUS_REJECTED;
        $this->reviewed_by_user_id = (int) Session::get_user_id();
        $this->reviewed_at = Rsx_Time::now_iso();
        $this->rejection_reason = trim($reason);
        $this->save();
    }

    public function delete_error(): ?string
    {
        if (!in_array((int) $this->status, self::EDITABLE_STATUSES, true)) {
            return "This entry is {$this->status__label}; only a draft or rejected entry can be deleted.";
        }

        return null;
    }

    /**
     * Bill this APPROVED entry on $invoice. Called by Invoice_Model only, which has checked
     * the entry is approved, uninvoiced and on the invoice's client.
     */
    public function bill_on(Invoice_Model $invoice): void
    {
        if ((int) $this->status !== self::STATUS_APPROVED || $this->invoice_id !== null) {
            shouldnt_happen("Billable entry {$this->id} is not an approved, uninvoiced entry");
        }

        $this->status = self::STATUS_BILLED;
        $this->invoice_id = $invoice->id;
        $this->save();
    }

    /** Take this BILLED entry off its invoice: it is APPROVED again, ready to be invoiced. */
    public function release_from_invoice(): void
    {
        if ((int) $this->status !== self::STATUS_BILLED) {
            shouldnt_happen("Billable entry {$this->id} is not billed");
        }

        $this->status = self::STATUS_APPROVED;
        $this->invoice_id = null;
        $this->save();
    }

    private static function __refuse(?string $reason): void
    {
        if ($reason !== null) {
            shouldnt_happen("Billable entry transition refused: {$reason} (ask the matching *_error() first)");
        }
    }

    /**
     * Ajax model fetch - JS loads an entry (with its detail, embedded by toArray()).
     */
    #[Auth('can_view_data')]
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $entry = static::find($id);

        if (!$entry) {
            return false;
        }

        return $entry->toArray();
    }
}
