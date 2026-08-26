<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;

/**
 * Portal_Request_Document_Model - per-attachment REVIEW metadata for a document posted in
 * a request thread. The file itself lives in the File_Attachment subsystem (referenced by
 * attachment_id); this row holds the review lifecycle (separate from the thread status):
 * PENDING -> ACCEPTED / REJECTED(+reason).
 *
 * requires_review distinguishes a CLIENT submission (reviewable; appears in the sidebar
 * Needs Review / Accepted buckets) from a STAFF attachment (informational; review-exempt).
 *
 * @property int $id
 * @property int $site_id
 * @property int $thread_id
 * @property int $message_id
 * @property int $attachment_id
 * @property int $requires_review
 * @property int $review_status
 * @property string|null $reject_reason
 * @property int|null $reviewed_by
 * @property string|null $reviewed_at
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_request_documents
 *
 * @property int $id
 * @property int $site_id
 * @property int $thread_id
 * @property int $message_id
 * @property int $attachment_id
 * @property int $requires_review
 * @property int $review_status
 * @property string $reject_reason
 * @property int $reviewed_by
 * @property string $reviewed_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $review_status__label
 * @property-read string $review_status__constant
 *
 * @method static array review_status__enum() Get all enum definitions with full metadata
 * @method static array review_status__enum_select() Get [{value, label}] array for dropdowns
 * @method static array review_status__enum_labels() Get simple id => label map
 * @method static array review_status__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Portal_Request_Document_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per document attached to a request.
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
    const REVIEW_PENDING = 1;
    const REVIEW_ACCEPTED = 2;
    const REVIEW_REJECTED = 3;

    protected $table = 'portal_request_documents';
    protected $fillable = [];

    public static $enums = [
        'review_status' => [
            1 => ['constant' => 'REVIEW_PENDING', 'label' => 'Needs Review', 'badge' => 'bg-info text-dark', 'order' => 1],
            2 => ['constant' => 'REVIEW_ACCEPTED', 'label' => 'Accepted', 'badge' => 'bg-success', 'order' => 2],
            3 => ['constant' => 'REVIEW_REJECTED', 'label' => 'Rejected', 'badge' => 'bg-danger', 'order' => 3],
        ],
    ];

    public function attachment(): ?File_Attachment_Model
    {
        return File_Attachment_Model::find($this->attachment_id);
    }

    public function is_pending(): bool
    {
        return (int) $this->review_status === static::REVIEW_PENDING;
    }

    public function is_accepted(): bool
    {
        return (int) $this->review_status === static::REVIEW_ACCEPTED;
    }

    public function is_rejected(): bool
    {
        return (int) $this->review_status === static::REVIEW_REJECTED;
    }

    /** Whether this document is a reviewable client submission (vs an informational staff attachment). */
    public function is_reviewable(): bool
    {
        return (int) $this->requires_review === 1;
    }

    /** Staff accepts the document. $staff = the reviewing Login_User_Model. */
    public function accept($staff = null): void
    {
        $this->review_status = static::REVIEW_ACCEPTED;
        $this->reject_reason = null;
        $this->reviewed_by = $staff ? (int) $staff->id : null;
        $this->reviewed_at = now()->toDateTimeString();
        $this->save();
    }

    /** Staff rejects the document with an optional reason. */
    public function reject($staff = null, ?string $reason = null): void
    {
        $this->review_status = static::REVIEW_REJECTED;
        $this->reject_reason = ($reason !== null && trim($reason) !== '') ? trim($reason) : null;
        $this->reviewed_by = $staff ? (int) $staff->id : null;
        $this->reviewed_at = now()->toDateTimeString();
        $this->save();
    }
}