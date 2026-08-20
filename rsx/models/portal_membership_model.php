<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Authorizable;
use Rsx\Models\Client_Model;
use Rsx\Portal_Permission;
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_memberships
 *
 * @property int $id
 * @property int $site_id
 * @property int $portal_user_id
 * @property int $client_id
 * @property int $role_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $role_id__label
 * @property-read string $role_id__constant
 *
 * @method static array role_id__enum() Get all enum definitions with full metadata
 * @method static array role_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array role_id__enum_labels() Get simple id => label map
 * @method static array role_id__enum_ids() Get array of all valid enum IDs
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
class Portal_Membership_Model extends Rsx_Site_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * Portal users multiplied by the clients they can reach.
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
    const ROLE_VIEWER = 1;
    const ROLE_COLLABORATOR = 2;

    use Portal_Authorizable;

    protected $table = 'portal_memberships';
    protected $fillable = [];

    public static $enums = [
        'role_id' => [
            1 => ['constant' => 'ROLE_VIEWER', 'label' => 'Viewer', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'ROLE_COLLABORATOR', 'label' => 'Collaborator', 'badge' => 'bg-primary'],
        ],
    ];

    /**
     * Find membership for a portal user + client combination
     */
    public static function find_for_user_and_client(int $portal_user_id, int $client_id): ?self
    {
        return static::where('portal_user_id', $portal_user_id)
            ->where('client_id', $client_id)
            ->first();
    }

    /**
     * Get all memberships for a portal user.
     *
     * Returns an Rsx_Result_Set - foreach it, count() it. This model is $unbounded
     * (one row per user per client), so the whole set is walked a page at a time
     * rather than held in memory.
     */
    public static function get_for_user(int $portal_user_id): \App\RSpade\Core\Database\Rsx_Result_Set
    {
        return static::where('portal_user_id', $portal_user_id)->result_set();
    }

    /**
     * Get all memberships for a client (all portal users with access)
     */
    public static function get_for_client(int $client_id): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('client_id', $client_id)->get();
    }

    /**
     * Count active portal members for a client
     */
    public static function count_for_client(int $client_id): int
    {
        return static::where('client_id', $client_id)->count();
    }

    /**
     * Check if a portal user has membership for a specific client
     */
    public static function has_membership(int $portal_user_id, int $client_id): bool
    {
        return static::where('portal_user_id', $portal_user_id)
            ->where('client_id', $client_id)
            ->exists();
    }

    /**
     * Relationships
     */
    #[Relationship]
    public function portal_user()
    {
        return $this->belongsTo(Portal_User_Model::class, 'portal_user_id');
    }

    #[Relationship]
    public function client()
    {
        return $this->belongsTo(Client_Model::class, 'client_id');
    }

    /**
     * Record-level portal visibility (membership-scoped rule).
     *
     * A portal user may read a membership row only for a client they have access
     * to. Fail-closed. portal_fetch() (Portal_Authorizable trait) has already
     * required an authenticated portal session before this is called.
     *
     * @return bool
     */
    public function portal_can_read(): bool
    {
        return Portal_Permission::has_client_access((int) $this->client_id);
    }
}