<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Models;

use Illuminate\Support\Str;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;

/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_invitations
 *
 * @property int $id
 * @property int $site_id
 * @property string $email
 * @property string $invitation_code
 * @property int $status_id
 * @property array $metadata
 * @property string $expires_at
 * @property string $used_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 *
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Portal_Invitation_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per invitation ever sent.
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
    const STATUS_USED = 2;
    const STATUS_EXPIRED = 3;
    const STATUS_REVOKED = 4;

    protected $table = 'portal_invitations';
    protected $fillable = []; // No mass assignment - always explicit

    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_PENDING', 'label' => 'Pending', 'badge' => 'bg-info'],
            2 => ['constant' => 'STATUS_USED', 'label' => 'Used', 'badge' => 'bg-secondary'],
            3 => ['constant' => 'STATUS_EXPIRED', 'label' => 'Expired', 'badge' => 'bg-warning'],
            4 => ['constant' => 'STATUS_REVOKED', 'label' => 'Revoked', 'badge' => 'bg-danger'],
        ],
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Create a new invitation
     *
     * @param int $site_id
     * @param string $email
     * @param array $metadata Optional metadata (e.g., contact_id, client_id)
     * @param int|null $expiry_days Days until expiration (null uses config default)
     * @return static
     */
    public static function create_invitation(int $site_id, string $email, array $metadata = [], ?int $expiry_days = null): self
    {
        $expiry_days = $expiry_days ?? config('rsx.portal.invitation_expiry_days', 14);

        $invitation = new static();
        $invitation->site_id = $site_id;
        $invitation->email = strtolower(trim($email));
        $invitation->invitation_code = static::generate_code();
        $invitation->status_id = self::STATUS_PENDING;
        $invitation->metadata = $metadata;
        $invitation->expires_at = now()->addDays($expiry_days);
        $invitation->save();

        return $invitation;
    }

    /**
     * Generate a unique invitation code
     *
     * @return string
     */
    public static function generate_code(): string
    {
        do {
            // Generate a URL-safe random code
            $code = Str::random(48);
        } while (static::where('invitation_code', $code)->exists());

        return $code;
    }

    /**
     * Find invitation by code
     *
     * @param string $code
     * @return static|null
     */
    public static function find_by_code(string $code): ?self
    {
        return static::where('invitation_code', $code)->first();
    }

    /**
     * Find a live (unused, unexpired) invitation for a contact + client.
     *
     * Per-client invitations carry contact_id + client_id in their metadata, so a
     * pending invite is keyed on that pair (independent of whether a portal account
     * already exists). Used to render per-contact invite status and to keep the
     * bulk-invite endpoint idempotent.
     *
     * @param int $contact_id
     * @param int $client_id
     * @return static|null
     */
    public static function find_pending_for_contact_client(int $contact_id, int $client_id): ?self
    {
        $candidates = static::where('status_id', self::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->get();

        foreach ($candidates as $invitation) {
            if ((int) $invitation->get_metadata('contact_id') === $contact_id
                && (int) $invitation->get_metadata('client_id') === $client_id) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * Live (unused, unexpired) invitations addressed to an email within a site.
     *
     * Powers the portal-side "pending invitations" Accept/Decline surface: an
     * existing portal account is matched to its invitations by its email + site
     * (the same identity the invitation was addressed to).
     *
     * @param int $site_id
     * @param string $email
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function get_pending_for_email(int $site_id, string $email): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('site_id', $site_id)
            ->where('email', strtolower(trim($email)))
            ->where('status_id', self::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * All live (unused, unexpired) invitations for a site.
     *
     * Used when matching an existing portal account to its invitations by linked
     * contact_id (metadata), in addition to the email match.
     *
     * @param int $site_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function get_pending_for_site(int $site_id): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('site_id', $site_id)
            ->where('status_id', self::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->get();
    }

    /**
     * Live (unused, unexpired) invitations for a client (by metadata client_id).
     *
     * Powers the per-client members table's "pending invites" rows. Returns newest
     * first.
     *
     * @param int $client_id
     * @return \Illuminate\Support\Collection
     */
    public static function get_pending_for_client(int $client_id): \Illuminate\Support\Collection
    {
        return static::where('status_id', self::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn ($invitation) => (int) $invitation->get_metadata('client_id') === $client_id)
            ->values();
    }

    /**
     * Refresh this invitation's expiry window (used by "resend invite").
     *
     * @param int|null $expiry_days Days from now (null uses config default).
     * @return void
     */
    public function refresh_expiry(?int $expiry_days = null): void
    {
        $expiry_days = $expiry_days ?? config('rsx.portal.invitation_expiry_days', 14);
        $this->expires_at = now()->addDays($expiry_days);
        // Resending reactivates a stale invite (e.g. one the cron marked expired).
        $this->status_id = self::STATUS_PENDING;
        $this->save();
    }

    /**
     * Whether the invitation is live and usable for registration/acceptance:
     * status is PENDING and not past its expiry window.
     *
     * @return bool
     */
    public function is_valid(): bool
    {
        return (int) $this->status_id === self::STATUS_PENDING
            && !now()->isAfter($this->expires_at);
    }

    /**
     * Whether the invitation has been consumed (an account was created via it, or
     * it was accepted/declined).
     *
     * @return bool
     */
    public function is_used(): bool
    {
        return (int) $this->status_id === self::STATUS_USED;
    }

    /**
     * Whether the invitation has expired. True for an EXPIRED status, and also for
     * a still-PENDING invite already past its window (before the hourly cron marks
     * it) so the register flow treats it as expired immediately.
     *
     * @return bool
     */
    public function is_expired(): bool
    {
        if ((int) $this->status_id === self::STATUS_EXPIRED) {
            return true;
        }

        return (int) $this->status_id === self::STATUS_PENDING
            && now()->isAfter($this->expires_at);
    }

    /**
     * Whether the invitation was proactively cancelled by an admin.
     *
     * @return bool
     */
    public function is_revoked(): bool
    {
        return (int) $this->status_id === self::STATUS_REVOKED;
    }

    /**
     * Mark the invitation as used (an account resulted from it, or it was
     * accepted/declined).
     *
     * @return void
     */
    public function mark_used(): void
    {
        $this->status_id = self::STATUS_USED;
        $this->used_at = now();
        $this->save();
    }

    /**
     * Mark a still-pending invitation expired (used by the hourly expiry task).
     * No-op if the invitation is not currently pending.
     *
     * @return void
     */
    public function mark_expired(): void
    {
        if ((int) $this->status_id !== self::STATUS_PENDING) {
            return;
        }
        $this->status_id = self::STATUS_EXPIRED;
        $this->save();
    }

    /**
     * Proactively cancel a pending invitation (admin action). No-op if the
     * invitation is not currently pending.
     *
     * @return void
     */
    public function revoke(): void
    {
        if ((int) $this->status_id !== self::STATUS_PENDING) {
            return;
        }
        $this->status_id = self::STATUS_REVOKED;
        $this->save();
    }

    /**
     * Get a metadata value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get_metadata(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get the full invitation URL
     *
     * @return string
     */
    public function get_invitation_url(): string
    {
        return rsx_absolute_url(\App\RSpade\Core\Portal\Rsx_Portal::Route(
            'Portal_Register_Controller',
            ['code' => $this->invitation_code]
        ));
    }

    /**
     * Relationship: Get the portal user created from this invitation
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    #[Relationship]
    public function portal_user()
    {
        // Portal users are linked via email within the same site
        return $this->hasOne(Portal_User_Model::class, 'email', 'email')
            ->where('site_id', $this->site_id);
    }

    /**
     * Delete expired/used invitations older than given days
     *
     * @param int $days
     * @return int Number of deleted records
     */
    public static function cleanup_old_invitations(int $days = 30): int
    {
        return static::whereIn('status_id', [self::STATUS_USED, self::STATUS_EXPIRED, self::STATUS_REVOKED])
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}