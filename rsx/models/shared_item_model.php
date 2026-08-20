<?php

namespace Rsx\Models;

use Illuminate\Support\Str;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Portal\Portal_Authorizable;
use Rsx\Models\Contact_Model;
use Rsx\Portal_Permission;
/**
 * Shared_Item_Model - Tracks content shared with external contacts via portal
 *
 * Internal users share files/content with contacts via secure token-based links.
 * The contact receives an email, clicks the link, creates a portal account (or
 * logs in), and can view the shared item. No client portal membership required.
 *
 * Uses polymorphic type references for item_type (BIGINT, not VARCHAR).
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: shared_items
 *
 * @property int $id
 * @property int $site_id
 * @property int $shared_by
 * @property int $contact_id
 * @property int $item_type
 * @property int $item_id
 * @property string $token
 * @property string $message
 * @property string $expires_at
 * @property string $accessed_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
/**
 * AUTHORIZATION: the class gate is the PORTAL realm's 'is_logged_in' - the only fetch
 * entry point here is the trait-supplied portal_fetch(), which is indexed portal, so the
 * name resolves against Portal_Permission. The gate covers the "is there a portal
 * session" half; portal_can_read() below stays as the record-level rule.
 */
#[Auth('is_logged_in')]
class Shared_Item_Model extends Rsx_Site_Model_Abstract
             {
    use Portal_Authorizable;

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per share action.
     *
     * A DECLARATION, not a runtime gate - a small, well-narrowed query here is still
     * fine. It marks the tables where a bare ->get() deserves a second look, and where
     * ->result_set() is usually the right answer. See the "Do The Whole Job" section
     * of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;


    protected $table = 'shared_items';
    protected $fillable = [];

    /**
     * Never expose the secure token in serialization (incl. portal_fetch output).
     *
     * @var array<string>
     */
    protected $hidden = [
        'token',
    ];

    protected static $type_ref_columns = ['item_type'];

    public static $enums = [];

    /**
     * Generate a unique secure token
     */
    public static function generate_token(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (static::where('token', $token)->exists());

        return $token;
    }

    /**
     * Create a shared item
     *
     * @param int $site_id
     * @param int $shared_by Internal user ID who is sharing
     * @param int $contact_id Contact receiving the shared item
     * @param string $item_type Model class name (e.g., 'File_Attachment_Model')
     * @param int $item_id ID of the item being shared
     * @param string|null $message Optional message to include
     * @param int|null $expiry_days Days until expiration (null uses config default)
     * @return static
     */
    public static function create_share(
        int $site_id,
        int $shared_by,
        int $contact_id,
        string $item_type,
        int $item_id,
        ?string $message = null,
        ?int $expiry_days = null
    ): self {
        $expiry_days = $expiry_days ?? config('rsx.portal.shared_link_default_expiry_days', 30);

        $share = new static();
        $share->site_id = $site_id;
        $share->shared_by = $shared_by;
        $share->contact_id = $contact_id;
        $share->item_type = $item_type;
        $share->item_id = $item_id;
        $share->token = static::generate_token();
        $share->message = $message;
        $share->expires_at = now()->addDays($expiry_days);
        $share->save();

        return $share;
    }

    /**
     * Find a shared item by its secure token
     */
    public static function find_by_token(string $token): ?self
    {
        return static::where('token', $token)->first();
    }

    /**
     * Check if the shared item is valid (not expired)
     */
    public function is_valid(): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        return !now()->isAfter($this->expires_at);
    }

    /**
     * Check if the shared item has expired
     */
    public function is_expired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return now()->isAfter($this->expires_at);
    }

    /**
     * Check if the shared item has been accessed
     */
    public function is_accessed(): bool
    {
        return $this->accessed_at !== null;
    }

    /**
     * Mark the shared item as accessed
     */
    public function mark_accessed(): void
    {
        if ($this->accessed_at === null) {
            $this->accessed_at = now();
            $this->save();
        }
    }

    /**
     * Get all shared items for a contact
     */
    public static function get_for_contact(int $contact_id): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('contact_id', $contact_id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get valid (non-expired) shared items for a contact
     */
    public static function get_valid_for_contact(int $contact_id): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('contact_id', $contact_id)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Delete expired shared items older than given days
     */
    public static function cleanup_expired(int $days = 30): int
    {
        return static::whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * Relationships
     */
    #[Relationship]
    public function contact()
    {
        return $this->belongsTo(Contact_Model::class, 'contact_id');
    }

    /**
     * Record-level portal visibility (shared-recipient rule).
     *
     * A portal user may read a shared item only when it was shared with the
     * contact their portal account is linked to. Fail-closed: no linked contact,
     * or any mismatch, denies. portal_fetch() (Portal_Authorizable trait) has
     * already required an authenticated portal session before this is called.
     *
     * @return bool
     */
    public function portal_can_read(): bool
    {
        $portal_user = Portal_Permission::current_user();
        if (!$portal_user || empty($portal_user->contact_id)) {
            return false;
        }

        return (int) $this->contact_id === (int) $portal_user->contact_id;
    }
}
