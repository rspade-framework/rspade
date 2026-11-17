<?php

namespace Rsx\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Time\Rsx_Date;
use Rsx\Lib\TextTypes\RawText\Raw_Text;
use Rsx\Models\Billable_Entry_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Project_Model;
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: invoices
 *
 * @property int $client_id
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property string $deleted_by_type type ref: a model class name, stored as a type-ref id
 * @property string $due_date
 * @property int $id
 * @property int $invoice_number
 * @property string $issue_date
 * @property \Rsx\Lib\TextTypes\RawText\Raw_Text $notes text type: a value object, filtered on write
 * @property string $paid_date
 * @property int $site_id
 * @property int $status enum: STATUS_DRAFT, STATUS_ISSUED, STATUS_PAID, STATUS_VOID (also $status__label and the other $status__* properties)
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
 *
 * @property-read string $status__label
 * @property-read string $status__constant
 *
 * @method static array status__enum() Get all enum definitions with full metadata
 * @method static array status__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status__enum_labels() Get simple id => label map
 * @method static array status__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
#[Auth('is_logged_in')]
class Invoice_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per invoice raised - grows with every billing cycle.
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
    const STATUS_DRAFT = 1;
    const STATUS_ISSUED = 2;
    const STATUS_PAID = 3;
    const STATUS_VOID = 4;

    use SoftDeletes;

    protected $table = 'invoices';
    protected $fillable = []; // No mass assignment - always explicit

    // Realtime: an invoice emits its own change and (via #[Realtime_Touch] on client())
    // touches the client whose Invoices tab lists it. Its entries touch it back, so a line
    // added or released refreshes the invoice view.
    public static $realtime = true;

    /** Edited through Form_Edit, which reads what another editor changed from here. */
    public static $revisions = true;

    /** Days from the issue date to the default due date. */
    public const PAYMENT_TERMS_DAYS = 30;

    /**
     * Column declarations (see rsx:man model).
     *
     * `status` is the invoice lifecycle: DRAFT -> ISSUED -> PAID, and DRAFT or ISSUED -> VOID.
     * `notes` is a plain textarea (Raw_Text). There is no total column: the total is the sum
     * of the entries' amounts (total() below), so it can never disagree with its lines.
     */
    public static $columns = [
        'status' => ['enum' => [
            1 => ['constant' => 'STATUS_DRAFT', 'label' => 'Draft', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'STATUS_ISSUED', 'label' => 'Issued', 'badge' => 'bg-info'],
            3 => ['constant' => 'STATUS_PAID', 'label' => 'Paid', 'badge' => 'bg-success'],
            4 => ['constant' => 'STATUS_VOID', 'label' => 'Void', 'badge' => 'bg-dark'],
        ]],
        'notes' => ['text_type' => Raw_Text::class],
    ];

    /**
     * APPENDED PROPERTIES - computed values that must reach JavaScript.
     *
     * $appends is the route: Eloquent serializes each name through its getXAttribute()
     * accessor inside parent::toArray(), which Rsx_Model_Abstract::toArray() calls first, so
     * these ride every payload this model produces - fetch(), a relationship, a list - and the
     * generated Base_Invoice_Model.js declares each one. Hand-adding the keys inside fetch()
     * would put them on exactly one payload and on none of the others.
     *
     * `total` READS ROWS: one SUM over the invoice's entries per serialization. That is the
     * price of never storing a total; a list of many invoices that does not show totals
     * selects its columns rather than serializing whole records. `is_overdue` reads only the
     * row and today's date.
     *
     * @var array
     */
    protected $appends = ['invoice_number_formatted', 'total', 'is_overdue'];

    /**
     * The invoice number as printed: "INV-0001". Sequential per site (next_invoice_number()).
     */
    public function invoice_number_formatted(): string
    {
        return 'INV-' . str_pad((string) $this->invoice_number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor for the appended `invoice_number_formatted` property. Delegates - never
     * re-implements: the method is the definition, the property is only its serialization.
     */
    public function getInvoiceNumberFormattedAttribute(): string
    {
        return $this->invoice_number_formatted();
    }

    /**
     * The invoice's total: the exact sum of its entries' amounts, $0.00 with no entries.
     * Never stored, so removing a line or voiding needs no recalculation.
     */
    public function total(): Rsx_Money
    {
        return Billable_Entry_Model::where('invoice_id', $this->id)->sum_money('amount');
    }

    /** Accessor for the appended `total` property. Delegates - never re-implements. */
    public function getTotalAttribute(): Rsx_Money
    {
        return $this->total();
    }

    /**
     * Is the invoice ISSUED and past its due date? A paid, void or draft invoice is never
     * overdue; an issued one is overdue from the day after its due date.
     */
    public function is_overdue(): bool
    {
        return (int) $this->status === self::STATUS_ISSUED
            && $this->due_date !== null
            && Rsx_Date::diff_days($this->due_date, Rsx_Date::today()) > 0;
    }

    /** Accessor for the appended `is_overdue` property. Delegates - never re-implements. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->is_overdue();
    }

    /**
     * Restrict a query to the overdue invoices - the query form of is_overdue(), for lists
     * and totals.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public static function where_overdue($query)
    {
        return $query->where('invoices.status', self::STATUS_ISSUED)
            ->whereNotNull('invoices.due_date')
            ->where('invoices.due_date', '<', Rsx_Date::today());
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    #[Realtime_Touch]
    public function client()
    {
        return $this->belongsTo(Client_Model::class, 'client_id');
    }

    /**
     * The invoice's lines - every entry billed on it, oldest work first.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function entries()
    {
        return Billable_Entry_Model::where('invoice_id', $this->id)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
    }

    // =========================================================================
    // NUMBERING
    // =========================================================================

    /**
     * The next invoice number on the current site: the highest ever assigned (a deleted
     * invoice's number included) plus one, starting at 1.
     *
     * Read and used inside ONE site write lock (every caller is a #[Writer] or a
     * Site_Lock::write() section): nothing else writes the site meanwhile, so two invoices
     * can never be handed the same number. UNIQUE(site_id, invoice_number) makes a caller
     * that broke that rule fail loudly instead of numbering twice.
     */
    public static function next_invoice_number(): int
    {
        return (int) static::withTrashed()->max('invoice_number') + 1;
    }

    // =========================================================================
    // CREATING AN INVOICE
    // =========================================================================

    /**
     * Answers [field => message] when an invoice cannot be created for $client_id from
     * $entry_ids; empty when it can. Every entry must exist on this site, be APPROVED, not
     * already be on an invoice, and be on a project of that client.
     *
     * @param mixed $client_id
     * @param mixed $entry_ids A list of billable entry ids
     * @return array<string, string>
     */
    public static function create_errors($client_id, $entry_ids): array
    {
        $client_id = static::__read_id($client_id);
        if ($client_id === null) {
            return ['client_id' => 'Client is required'];
        }
        if (!Client_Model::find($client_id)) {
            return ['client_id' => 'The selected client does not exist'];
        }

        $ids = Project_Model::read_id_list($entry_ids);
        if ($ids === null) {
            return ['entry_ids' => 'One of the selected entries does not exist'];
        }
        if ($ids === []) {
            return ['entry_ids' => 'Select at least one approved entry to invoice'];
        }

        $entries = Billable_Entry_Model::whereIn('id', $ids)->get();
        if ($entries->count() !== count($ids)) {
            return ['entry_ids' => 'One of the selected entries does not exist'];
        }

        $project_clients = Project_Model::withTrashed()
            ->whereIn('id', $entries->pluck('project_id')->unique()->all())
            ->pluck('client_id', 'id')
            ->all();

        foreach ($entries as $entry) {
            if ((int) $entry->status !== Billable_Entry_Model::STATUS_APPROVED || $entry->invoice_id !== null) {
                return ['entry_ids' => "\"{$entry->description}\" is {$entry->status__label}; only approved, uninvoiced entries can be invoiced"];
            }
            if ((int) ($project_clients[$entry->project_id] ?? 0) !== $client_id) {
                return ['entry_ids' => "\"{$entry->description}\" is not on a project of this client"];
            }
        }

        return [];
    }

    /**
     * Create a DRAFT invoice for $client_id billing $entry_ids, which become BILLED on it.
     * The caller checked create_errors() first and holds the site's write lock.
     *
     * @param int $client_id
     * @param int[] $entry_ids
     */
    public static function create_for_client(int $client_id, array $entry_ids): self
    {
        $invoice = new static();
        $invoice->client_id = $client_id;
        $invoice->invoice_number = static::next_invoice_number();
        $invoice->status = self::STATUS_DRAFT;
        $invoice->save();

        foreach (Billable_Entry_Model::whereIn('id', $entry_ids)->orderBy('id')->get() as $entry) {
            $entry->bill_on($invoice);
        }

        return $invoice;
    }

    // =========================================================================
    // EDITING (dates and notes)
    // =========================================================================

    /** Why the invoice's dates and notes cannot be edited, or null. A paid or void invoice is closed. */
    public function edit_error(): ?string
    {
        if (in_array((int) $this->status, [self::STATUS_DRAFT, self::STATUS_ISSUED], true)) {
            return null;
        }

        return "This invoice is {$this->status__label} and can no longer be edited.";
    }

    /**
     * Answers [field => message] for every submitted value the invoice cannot hold:
     * issue_date and due_date, when not blank, are YYYY-MM-DD dates, and a due date is not
     * before the issue date (the submitted one, else the stored one). Only the keys $values
     * carries are checked.
     *
     * @return array<string, string>
     */
    public function value_errors(array $values): array
    {
        $errors = [];

        foreach (['issue_date' => 'Issue date', 'due_date' => 'Due date'] as $field => $label) {
            if (!empty($values[$field]) && !Rsx_Date::is_date($values[$field])) {
                $errors[$field] = "{$label} must be a date (YYYY-MM-DD)";
            }
        }

        if (!$errors) {
            $issue = array_key_exists('issue_date', $values) ? ($values['issue_date'] ?: null) : $this->issue_date;
            $due = array_key_exists('due_date', $values) ? ($values['due_date'] ?: null) : $this->due_date;
            if ($issue !== null && $due !== null && Rsx_Date::diff_days($issue, $due) < 0) {
                $errors['due_date'] = 'Due date cannot be before the issue date';
            }
        }

        return $errors;
    }

    /**
     * Write submitted dates and notes onto this invoice through a Form_Edit and save it. Run
     * inside Form_Edit::run() after edit_error() and value_errors() found nothing. A blank date
     * is none.
     */
    public function save_edit(Form_Edit $edit, array $values): void
    {
        $edit->set('issue_date', !empty($values['issue_date']) ? $values['issue_date'] : null);
        $edit->set('due_date', !empty($values['due_date']) ? $values['due_date'] : null);
        $edit->set('notes', $values['notes'] ?? null);

        $this->save();
    }

    // =========================================================================
    // THE LIFECYCLE
    //
    //   DRAFT --issue--> ISSUED --mark_paid--> PAID
    //     |                |
    //     +-----void-------+--> VOID   (every entry released back to APPROVED)
    //
    // Each transition is a pair: X_errors() answers why it cannot happen (keyed by field),
    // and X() performs it - refusing loudly when called while X_errors() has a reason.
    // =========================================================================

    /**
     * Why the invoice cannot be issued with these dates. A blank or absent issue date is
     * today; a blank or absent due date is PAYMENT_TERMS_DAYS after the issue date.
     *
     * @return array<string, string>
     */
    public function issue_errors(array $values = []): array
    {
        if ((int) $this->status !== self::STATUS_DRAFT) {
            return ['status' => "This invoice is {$this->status__label}; only a draft invoice can be issued."];
        }
        if (!Billable_Entry_Model::where('invoice_id', $this->id)->exists()) {
            return ['status' => 'An invoice with no lines cannot be issued.'];
        }

        $dates = static::__issue_dates($values);
        $errors = [];
        foreach (['issue_date' => 'Issue date', 'due_date' => 'Due date'] as $field => $label) {
            if ($dates[$field] === false) {
                $errors[$field] = "{$label} must be a date (YYYY-MM-DD)";
            }
        }
        if (!$errors && Rsx_Date::diff_days($dates['issue_date'], $dates['due_date']) < 0) {
            $errors['due_date'] = 'Due date cannot be before the issue date';
        }

        return $errors;
    }

    /** Issue the invoice: the dates are set and the invoice is sent to the client. */
    public function issue(array $values = []): void
    {
        static::__refuse($this->issue_errors($values));

        $dates = static::__issue_dates($values);
        $this->status = self::STATUS_ISSUED;
        $this->issue_date = $dates['issue_date'];
        $this->due_date = $dates['due_date'];
        $this->save();
    }

    /**
     * Why the invoice cannot be marked paid on this date (blank or absent: today).
     *
     * @return array<string, string>
     */
    public function mark_paid_errors(array $values = []): array
    {
        if ((int) $this->status !== self::STATUS_ISSUED) {
            return ['status' => "This invoice is {$this->status__label}; only an issued invoice can be marked paid."];
        }
        if (!empty($values['paid_date']) && !Rsx_Date::is_date($values['paid_date'])) {
            return ['paid_date' => 'Paid date must be a date (YYYY-MM-DD)'];
        }

        return [];
    }

    /** Record the invoice as paid. */
    public function mark_paid(array $values = []): void
    {
        static::__refuse($this->mark_paid_errors($values));

        $this->status = self::STATUS_PAID;
        $this->paid_date = !empty($values['paid_date']) ? Rsx_Date::parse($values['paid_date']) : Rsx_Date::today();
        $this->save();
    }

    /** @return array<string, string> */
    public function void_errors(): array
    {
        if (!in_array((int) $this->status, [self::STATUS_DRAFT, self::STATUS_ISSUED], true)) {
            return ['status' => "This invoice is {$this->status__label}; only a draft or issued invoice can be voided."];
        }

        return [];
    }

    /** Void the invoice: every entry on it is released back to APPROVED, ready to be invoiced again. */
    public function void(): void
    {
        static::__refuse($this->void_errors());

        foreach ($this->entries() as $entry) {
            $entry->release_from_invoice();
        }

        $this->status = self::STATUS_VOID;
        $this->save();
    }

    /**
     * Why $entry_id cannot be removed from this invoice: the invoice must be a DRAFT and the
     * entry one of its lines.
     *
     * @return array<string, string>
     */
    public function remove_line_errors($entry_id): array
    {
        if ((int) $this->status !== self::STATUS_DRAFT) {
            return ['status' => "This invoice is {$this->status__label}; lines can be removed only from a draft invoice."];
        }

        $entry_id = static::__read_id($entry_id);
        if ($entry_id === null || !Billable_Entry_Model::where('invoice_id', $this->id)->where('id', $entry_id)->exists()) {
            return ['entry_id' => 'That entry is not a line of this invoice'];
        }

        return [];
    }

    /** Remove one line: the entry is released back to APPROVED. */
    public function remove_line(int $entry_id): Billable_Entry_Model
    {
        static::__refuse($this->remove_line_errors($entry_id));

        $entry = Billable_Entry_Model::find($entry_id);
        $entry->release_from_invoice();

        return $entry;
    }

    /**
     * The issue and due dates an issue() with $values sets, each false when submitted but not
     * a date (a defaulted due date is null when the issue date is refused).
     *
     * @return array{issue_date: string|false, due_date: string|false|null}
     */
    private static function __issue_dates(array $values): array
    {
        $issue = !empty($values['issue_date'])
            ? (Rsx_Date::is_date($values['issue_date']) ? Rsx_Date::parse($values['issue_date']) : false)
            : Rsx_Date::today();

        if (!empty($values['due_date'])) {
            $due = Rsx_Date::is_date($values['due_date']) ? Rsx_Date::parse($values['due_date']) : false;
        } else {
            $due = $issue === false ? null : Rsx_Date::add_days($issue, self::PAYMENT_TERMS_DAYS);
        }

        return ['issue_date' => $issue, 'due_date' => $due];
    }

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

    private static function __refuse(array $errors): void
    {
        if ($errors) {
            shouldnt_happen('Invoice transition refused: ' . reset($errors) . ' (ask the matching *_errors() first)');
        }
    }

    /**
     * Ajax model fetch - JS loads an invoice; its number and total ride the payload as
     * appended properties.
     */
    #[Auth('can_view_data')]
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $invoice = static::find($id);

        if (!$invoice) {
            return false;
        }

        return $invoice->toArray();
    }
}
