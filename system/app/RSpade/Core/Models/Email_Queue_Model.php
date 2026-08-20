<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;

/**
 * Email_Queue_Model - Database-backed email queue (framework core).
 *
 * Every email goes through this table before delivery. The queue processor picks
 * up PENDING records, renders templates, and (once real delivery is wired) sends.
 * This is CORE infrastructure the Rsx_Mail facade hard-depends on; apps may still
 * override it via the class-override pattern (rsx/models/email_queue_model.php).
 *
 * @property integer $id
 * @property integer $site_id
 * @property string $to_address
 * @property string $to_name
 * @property string $subject
 * @property string $template
 * @property array $template_data
 * @property integer $category_id
 * @property string $rendered_html
 * @property integer $status_id
 * @property string $error
 * @property integer $retry_count
 * @property string $retry_at
 * @property string $dev_original_to
 * @property string $sent_at
 * @property string $opened_at
 * @property integer $related_type
 * @property integer $related_id
 * @property string $created_at
 * @property string $updated_at
 * @property integer $created_by
 * @property integer $updated_by
 * @mixin \Eloquent
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: email_queue
 *
 * @property int $id
 * @property int $site_id
 * @property string $to_address
 * @property string $to_name
 * @property string $subject
 * @property string $template
 * @property array $template_data
 * @property int $category_id
 * @property string $rendered_html
 * @property int $status_id
 * @property string $error
 * @property int $retry_count
 * @property string $retry_at
 * @property string $dev_original_to
 * @property string $sent_at
 * @property string $opened_at
 * @property int $related_type
 * @property int $related_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 * @property-read string $category_id__label
 * @property-read string $category_id__constant
 *
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 * @method static array category_id__enum() Get all enum definitions with full metadata
 * @method static array category_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array category_id__enum_labels() Get simple id => label map
 * @method static array category_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Email_Queue_Model extends Rsx_Site_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_PENDING = 1;
    const STATUS_SENDING = 2;
    const STATUS_SENT = 3;
    const STATUS_FAILED = 4;
    const STATUS_BLOCKED = 5;
    const STATUS_HELD_BACK = 6;
    const CATEGORY_TRANSACTIONAL = 1;
    const CATEGORY_NOTIFICATION = 2;
    const CATEGORY_MARKETING = 3;

    // Infrastructure queue: writes here (queueing, drain status updates) are not
    // user-facing data any UI subscribes to, so they must not kick the emitter engine.
    public static $realtime_silent = true;
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per email the application ever sends.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;


    


    

    protected $table = 'email_queue';
    protected $fillable = [];

    protected $casts = [
        'template_data' => 'array',
    ];

    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_PENDING', 'label' => 'Pending', 'badge' => 'bg-warning'],
            2 => ['constant' => 'STATUS_SENDING', 'label' => 'Sending', 'badge' => 'bg-info'],
            3 => ['constant' => 'STATUS_SENT', 'label' => 'Sent', 'badge' => 'bg-success'],
            4 => ['constant' => 'STATUS_FAILED', 'label' => 'Failed', 'badge' => 'bg-danger'],
            5 => ['constant' => 'STATUS_BLOCKED', 'label' => 'Blocked', 'badge' => 'bg-secondary'],
            // Recorded but intentionally NOT delivered (dev / delivery suppressed /
            // real send not yet wired) - distinct from a genuine Sent and a Failure.
            6 => ['constant' => 'STATUS_HELD_BACK', 'label' => 'Held (dev)', 'badge' => 'bg-dark'],
        ],
        'category_id' => [
            1 => ['constant' => 'CATEGORY_TRANSACTIONAL', 'label' => 'Transactional', 'badge' => 'bg-primary'],
            2 => ['constant' => 'CATEGORY_NOTIFICATION', 'label' => 'Notification', 'badge' => 'bg-info'],
            3 => ['constant' => 'CATEGORY_MARKETING', 'label' => 'Marketing', 'badge' => 'bg-secondary'],
        ],
    ];

    /**
     * Ajax model fetch - load one email record (subject, rendered_html, status,
     * category, to/dev_original_to, error, timestamps, polymorphic related_*) for a
     * developer-facing Email Transaction Log detail view. toArray() includes the
     * enum BEM props (status_id__label/__badge, category_id__label/__badge); no
     * aliasing - field names pass straight through DB -> PHP -> JSON -> JS.
     *
     * Auth: any logged-in staff user for now; the app should narrow the gate to a
     * developer-level check once its Permission class defines one.
     */
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]
    public static function fetch($id)
    {
        $email = static::find($id);
        if (!$email) {
            return false;
        }

        return $email->toArray();
    }

    /**
     * Create a queued email record
     */
    public static function enqueue(
        int $site_id,
        string $to_address,
        string $subject,
        string $template,
        array $template_data = [],
        int $category_id = 2,
        ?string $to_name = null,
        ?string $dev_original_to = null,
        ?int $related_type = null,
        ?int $related_id = null
    ): self {
        $record = new static();
        $record->site_id = $site_id;
        $record->to_address = strtolower(trim($to_address));
        $record->to_name = $to_name;
        $record->subject = $subject;
        $record->template = $template;
        $record->template_data = $template_data;
        $record->category_id = $category_id;
        $record->status_id = self::STATUS_PENDING;
        $record->dev_original_to = $dev_original_to;
        $record->related_type = $related_type;
        $record->related_id = $related_id;
        $record->retry_count = 0;
        $record->save();

        return $record;
    }

    /**
     * Create a blocked email record (for audit trail)
     */
    public static function enqueue_blocked(
        int $site_id,
        string $to_address,
        string $subject,
        string $template,
        array $template_data = [],
        int $category_id = 2,
        ?string $to_name = null
    ): self {
        $record = new static();
        $record->site_id = $site_id;
        $record->to_address = strtolower(trim($to_address));
        $record->to_name = $to_name;
        $record->subject = $subject;
        $record->template = $template;
        $record->template_data = $template_data;
        $record->category_id = $category_id;
        $record->status_id = self::STATUS_BLOCKED;
        $record->error = 'Recipient has unsubscribed from this email category';
        $record->retry_count = 0;
        $record->save();

        return $record;
    }

    /**
     * Get pending emails ready for processing
     */
    public static function get_pending(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('status_id', self::STATUS_PENDING)
            ->where(function ($query) {
                $query->whereNull('retry_at')
                    ->orWhere('retry_at', '<=', now());
            })
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Mark as sent
     */
    public function mark_sent(): void
    {
        $this->status_id = self::STATUS_SENT;
        $this->sent_at = now();
        $this->error = null;
        $this->save();
    }

    /**
     * Mark as held back: recorded but intentionally not delivered (dev / delivery
     * suppressed / real send not wired). Distinct from Sent and Failed.
     */
    public function mark_held_back(?string $note = null): void
    {
        $this->status_id = self::STATUS_HELD_BACK;
        $this->sent_at = now();
        $this->error = $note;
        $this->save();
    }

    /**
     * Mark as failed with error message and retry scheduling
     */
    public function mark_failed(string $error): void
    {
        $this->retry_count = $this->retry_count + 1;
        $max_retries = config('rsx.mail.retry_max', 6);
        $backoff = config('rsx.mail.retry_backoff_minutes', [2, 4, 8, 15, 30, 60]);

        if ($this->retry_count >= $max_retries) {
            $this->status_id = self::STATUS_FAILED;
            $this->error = $error;
        } else {
            $this->status_id = self::STATUS_PENDING;
            $this->error = $error;
            $delay_index = min($this->retry_count - 1, count($backoff) - 1);
            $this->retry_at = now()->addMinutes($backoff[$delay_index]);
        }

        $this->save();
    }

    /**
     * Delete old sent/failed/blocked/held-back emails
     */
    public static function cleanup_old(int $days = 90): int
    {
        return static::whereIn('status_id', [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_BLOCKED, self::STATUS_HELD_BACK])
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}