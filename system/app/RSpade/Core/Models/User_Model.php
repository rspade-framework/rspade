<?php

namespace App\RSpade\Core\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\Models\Rsx_Site_Actor_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Models\User_Profile_Model;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Session\Session;

/**
 * User_Model - Site-specific user profile with role-based access control
 *
 * Represents a user's profile within a specific site/organization.
 * A single login identity (Login_User_Model) can have multiple User_Model records
 * for different sites, allowing different names, roles, and profiles per organization.
 *
 * ACL System:
 * - Primary role (role_id) grants base permissions
 * - Supplementary permissions (user_permissions table) can GRANT or DENY specific permissions
 * - Resolution: DISABLED check → DENY override → GRANT override → role default
 *
 * ACTOR: extends Rsx_Site_Actor_Model_Abstract - it signs in and it is the site-scoped
 * target of the created_by/updated_by authorship pairs. See rsx:man actors.
 *
 * See: php artisan rsx:man acls
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: users
 *
 * @property int $id
 * @property int $login_user_id
 * @property int $site_id
 * @property string $first_name
 * @property string $last_name
 * @property string $phone
 * @property int $role_id
 * @property bool $is_enabled
 * @property string $email
 * @property string $deleted_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 * @property string $invite_code
 * @property string $invite_accepted_at
 * @property string $invite_expires_at
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
class User_Model extends Rsx_Site_Actor_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const ROLE_DEVELOPER = 100;
    const ROLE_ROOT_ADMIN = 200;
    const ROLE_SITE_OWNER = 300;
    const ROLE_SITE_ADMIN = 400;
    const ROLE_MANAGER = 500;
    const ROLE_USER = 600;
    const ROLE_VIEWER = 700;
    const ROLE_DISABLED = 800;

    // =========================================================================
    // ROLE CONSTANTS (lower ID = higher privilege, 100-based for future expansion)
    // =========================================================================

    

    // =========================================================================
    // PERMISSION CONSTANTS
    // =========================================================================

    

    // =========================================================================
    // INVITATION STATUS CONSTANTS
    // =========================================================================

    const INVITATION_PENDING = 'pending';
    const INVITATION_ACCEPTED = 'accepted';
    const INVITATION_EXPIRED = 'expired';

    // Permission constants - must match integer values used in $enums['role_id']['permissions']
    const PERM_MANAGE_SITES_ROOT = 1;
    const PERM_MANAGE_SITE_BILLING = 2;
    const PERM_MANAGE_SITE_SETTINGS = 3;
    const PERM_MANAGE_SITE_USERS = 4;
    const PERM_VIEW_USER_ACTIVITY = 5;
    const PERM_EDIT_DATA = 6;
    const PERM_VIEW_DATA = 7;
    const PERM_API_ACCESS = 8;
    const PERM_DATA_EXPORT = 9;

    /**
     * Cached supplementary permissions for this user (avoids repeated DB queries)
     * @var array|null
     */
    protected $_supplementary_permissions = null;

    /**
     * Cache generation the cached supplementary permissions were loaded at.
     * When User_Permission_Model advances the generation for this user (via
     * grant/deny/remove), the cache is stale and reloaded on next access.
     * @var int|null
     */
    protected ?int $_supplementary_permissions_generation = null;

    /**
     * Enum field definitions with ACL permissions and can_admin_roles
     *
     * NOTE: Cannot use self:: constants in static property initialization (PHP limitation).
     * Values must match the ROLE_* and PERM_* constants defined above.
     *
     * @var array
     */
    public static $enums = [
        'role_id' => [
            // ROLE_DEVELOPER = 100
            100 => [
                'constant' => 'ROLE_DEVELOPER',
                'label' => 'Developer',
                'permissions' => [1, 2, 3, 4, 5, 6, 7], // All core PERM_* (1-7)
                'can_admin_roles' => [200, 300, 400, 500, 600, 700, 800], // All roles below
                'selectable' => false, // Developer assigned by system only
            ],
            // ROLE_ROOT_ADMIN = 200
            200 => [
                'constant' => 'ROLE_ROOT_ADMIN',
                'label' => 'Root Admin',
                'permissions' => [1, 2, 3, 4, 5, 6, 7], // All core PERM_* (1-7)
                'can_admin_roles' => [300, 400, 500, 600, 700, 800], // All roles below
                'selectable' => false, // Root admin assigned by system only
            ],
            // ROLE_SITE_OWNER = 300
            300 => [
                'constant' => 'ROLE_SITE_OWNER',
                'label' => 'Site Owner',
                'permissions' => [2, 3, 4, 5, 6, 7], // BILLING(2) through VIEW(7)
                'can_admin_roles' => [400, 500, 600, 700, 800], // Site Admin and below
            ],
            // ROLE_SITE_ADMIN = 400
            400 => [
                'constant' => 'ROLE_SITE_ADMIN',
                'label' => 'Site Admin',
                'permissions' => [3, 4, 5, 6, 7], // SETTINGS(3) through VIEW(7)
                'can_admin_roles' => [500, 600, 700, 800], // Manager and below
            ],
            // ROLE_MANAGER = 500
            500 => [
                'constant' => 'ROLE_MANAGER',
                'label' => 'Manager',
                'permissions' => [5, 6, 7], // ACTIVITY(5), EDIT(6), VIEW(7)
                'can_admin_roles' => [600, 700, 800], // User and below
            ],
            // ROLE_USER = 600
            600 => [
                'constant' => 'ROLE_USER',
                'label' => 'User',
                'permissions' => [6, 7], // EDIT(6), VIEW(7)
                'can_admin_roles' => [],
            ],
            // ROLE_VIEWER = 700
            700 => [
                'constant' => 'ROLE_VIEWER',
                'label' => 'Viewer',
                'permissions' => [7], // VIEW(7) only
                'can_admin_roles' => [],
            ],
            // ROLE_DISABLED = 800
            800 => [
                'constant' => 'ROLE_DISABLED',
                'label' => 'Disabled',
                'permissions' => [],
                'can_admin_roles' => [],
            ],
        ],
    ];

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * Grows with every tenant's headcount; "every user on this site" is a real query shape.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'users';

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the login user (authentication identity)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    #[Relationship]
    public function login_user()
    {
        return $this->belongsTo(Login_User_Model::class, 'login_user_id');
    }

    /**
     * Get the site
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    #[Relationship]
    public function site()
    {
        return $this->belongsTo(Site_Model::class, 'site_id');
    }

    /**
     * Get the user's profile (1:1 relationship)
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    #[Relationship]
    public function user_profile()
    {
        return $this->hasOne(User_Profile_Model::class, 'user_id');
    }

    /**
     * Get supplementary permissions
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function supplementary_permissions()
    {
        return $this->hasMany(User_Permission_Model::class, 'user_id');
    }

    // =========================================================================
    // ACL METHODS
    // =========================================================================

    /**
     * Get all resolved permissions for this user
     *
     * Returns the final permission array after applying:
     * 1. Role default permissions
     * 2. Supplementary GRANTs (added)
     * 3. Supplementary DENYs (removed)
     *
     * @return array Array of permission IDs the user has
     */
    public function get_resolved_permissions(): array
    {
        // Disabled users have no permissions
        if ($this->role_id === self::ROLE_DISABLED) {
            return [];
        }

        // Start with role default permissions
        $permissions = $this->role_id__permissions ?? [];

        // Load supplementary overrides (DB query is cached)
        $supplementary = $this->_load_supplementary_permissions();

        // Add supplementary GRANTs
        foreach ($supplementary['grants'] as $perm_id) {
            if (!in_array($perm_id, $permissions, true)) {
                $permissions[] = $perm_id;
            }
        }

        // Remove supplementary DENYs
        $permissions = array_values(array_diff($permissions, $supplementary['denies']));

        // Sort for consistent ordering
        sort($permissions);

        return $permissions;
    }

    /**
     * Check if user has a specific permission
     *
     * @param int $permission Permission constant (PERM_*)
     * @return bool
     */
    public function has_permission(int $permission): bool
    {
        return in_array($permission, $this->get_resolved_permissions(), true);
    }

    /**
     * Check if user can administer users with the given role
     *
     * Prevents privilege escalation - users can only assign roles
     * at or below their own permission level.
     *
     * @param int $role_id Role constant (ROLE_*)
     * @return bool
     */
    public function can_admin_role(int $role_id): bool
    {
        $can_admin = $this->role_id__can_admin_roles ?? [];
        return in_array($role_id, $can_admin, true);
    }

    /**
     * Check if user has at least the specified role level
     *
     * "At least" means same or higher privilege (lower role_id number).
     *
     * @param int $role_id Role constant (ROLE_*)
     * @return bool
     */
    public function has_role(int $role_id): bool
    {
        // Lower role_id = higher privilege
        return $this->role_id <= $role_id;
    }

    // =========================================================================
    // SUPPLEMENTARY PERMISSION METHODS
    // =========================================================================

    /**
     * Load supplementary permissions for this user (cached per request)
     *
     * @return array ['grants' => [permission_ids], 'denies' => [permission_ids]]
     */
    protected function _load_supplementary_permissions(): array
    {
        $current_generation = User_Permission_Model::_current_generation($this->id);

        // Cache hit only if populated AND loaded at the current generation. A
        // grant/deny/remove advances the generation, forcing a reload here even
        // for an instance that already cached its permissions this request.
        if ($this->_supplementary_permissions !== null
            && $this->_supplementary_permissions_generation === $current_generation) {
            return $this->_supplementary_permissions;
        }

        $this->_supplementary_permissions = [
            'grants' => [],
            'denies' => [],
        ];
        $this->_supplementary_permissions_generation = $current_generation;

        // Load from user_permissions table
        $permissions = User_Permission_Model::where('user_id', $this->id)->get();

        foreach ($permissions as $perm) {
            if ($perm->is_grant) {
                $this->_supplementary_permissions['grants'][] = $perm->permission_id;
            } else {
                $this->_supplementary_permissions['denies'][] = $perm->permission_id;
            }
        }

        return $this->_supplementary_permissions;
    }

    /**
     * Check if user has explicit GRANT for permission
     *
     * @param int $permission Permission constant
     * @return bool
     */
    public function has_supplementary_grant(int $permission): bool
    {
        $supplementary = $this->_load_supplementary_permissions();
        return in_array($permission, $supplementary['grants'], true);
    }

    /**
     * Check if user has explicit DENY for permission
     *
     * @param int $permission Permission constant
     * @return bool
     */
    public function has_supplementary_deny(int $permission): bool
    {
        $supplementary = $this->_load_supplementary_permissions();
        return in_array($permission, $supplementary['denies'], true);
    }

    /**
     * Clear cached supplementary permissions (call after modifying user_permissions table)
     */
    public function clear_permission_cache(): void
    {
        $this->_supplementary_permissions = null;
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Get the full name of the user
     *
     * @return string
     */
    public function get_full_name()
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    // =========================================================================
    // ACTOR CONTRACT (see Rsx_Actor_Model_Abstract)
    // =========================================================================

    /**
     * The printed name of a staff user: their full name, falling back to the login
     * identity's email, then to the id. Never empty.
     *
     * This is the ONE display-name method on this model (it replaced the former
     * get_display_name(), which did the same job under a name only this class knew).
     * get_full_name() remains as the raw first+last accessor, and may legitimately be
     * empty - use it when you specifically want the name fields, this when you want
     * something to PRINT.
     *
     * Works on a trashed record and renders it identically: no "(deleted)" marker,
     * per the actor contract. A screen that wants to mark deleted authorship reads
     * trashed() itself.
     *
     * @return string
     */
    public function get_printed_name(): string
    {
        $full_name = $this->get_full_name();
        if ($full_name !== '') {
            return $full_name;
        }

        $email = trim((string) $this->email);
        if ($email !== '') {
            return $email;
        }

        // The name fields are empty (invited-but-never-completed user): fall through to
        // the login identity, which always has an email.
        $login_user = $this->login_user;
        if ($login_user) {
            return $login_user->get_printed_name();
        }

        return 'User #' . (int) $this->id;
    }

    /**
     * Where the current viewer can see this staff user.
     *
     * Realm-dependent by design, and resolved through the gates declared on the
     * destinations (Auth_Gates::accessible_route) rather than any role test here:
     *
     * - Portal realm: null. A client-portal user has no business reaching a staff
     *   administration screen, and the portal registry contains no staff surface.
     * - Own record: the viewer's own profile page - reachable by every signed-in staff
     *   user, so authorship of your own records always links somewhere useful even
     *   without user-management rights.
     * - Anyone else: the user-management detail screen, which is gated
     *   'can_manage_users'. A viewer without that permission gets null and the name
     *   renders as plain text.
     *
     * Never memoized: the answer is about WHO IS ASKING, not about this record.
     *
     * @return string|null
     */
    public function get_view_profile_url(): ?string
    {
        if (Rsx_Portal::is_portal_request()) {
            return null;
        }

        if ((int) $this->id === (int) Session::get_user_id()) {
            $own = Auth_Gates::accessible_route('Settings_Profile_Display_Action', Auth_Gates::REALM_STAFF);
            if ($own !== null) {
                return $own;
            }
        }

        return Auth_Gates::accessible_route(
            'Settings_User_Management_View_Action',
            Auth_Gates::REALM_STAFF,
            (int) $this->id
        );
    }

    // =========================================================================
    // STATUS METHODS
    // =========================================================================

    /**
     * Check if user is active in this site
     *
     * @return bool
     */
    public function is_active()
    {
        return $this->is_enabled && !$this->trashed();
    }

    /**
     * Scope to only get enabled site users
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to get users with a specific role
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $role_id Role constant
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithRole($query, int $role_id)
    {
        return $query->where('role_id', $role_id);
    }

    /**
     * Get the invitation status
     *
     * @return string One of: INVITATION_PENDING, INVITATION_ACCEPTED, INVITATION_EXPIRED
     */
    public function get_invitation_status()
    {
        // If invitation has been accepted
        if ($this->invite_accepted_at !== null) {
            return self::INVITATION_ACCEPTED;
        }

        // If invitation exists and has expired
        if ($this->invite_expires_at !== null && \App\RSpade\Core\Time\Rsx_Time::is_past($this->invite_expires_at)) {
            return self::INVITATION_EXPIRED;
        }

        // Default: invitation is pending
        return self::INVITATION_PENDING;
    }

    // =========================================================================
    // REALTIME USER-RECORD REFRESH
    //
    // A change to identity-affecting fields (name / role / enabled) or a soft-delete
    // pushes every live STAFF connection of this user to reload, so a running session
    // reflects an admin's edit or a disablement immediately. ACL row changes are pushed
    // from User_Permission_Model. No-op when realtime is disabled (push_user_refresh gates).
    // =========================================================================

    /**
     * The identity-affecting fields that trigger a user-refresh push when changed.
     */
    private const REALTIME_REFRESH_FIELDS = ['first_name', 'last_name', 'role_id', 'is_enabled'];

    /**
     * Override save to push a user-refresh when a watched field changes to a confirmed
     * different value. The decision is captured BEFORE the write (isDirty is cleared by
     * save); a brand-new record is skipped (no live connection can exist for it yet).
     */
    public function save(array $options = [])
    {
        $should_push = $this->exists && $this->isDirty(self::REALTIME_REFRESH_FIELDS);

        $result = parent::save($options);

        if ($result && $should_push) {
            $this->_realtime_push_user_refresh();
        }

        return $result;
    }

    /**
     * Override delete to push a user-refresh on soft-delete: removing the user's access is
     * an is_enabled-equivalent transition, so live connections must reload.
     */
    public function delete()
    {
        $was_existing = $this->exists;

        $result = parent::delete();

        if ($result && $was_existing) {
            $this->_realtime_push_user_refresh();
        }

        return $result;
    }

    /**
     * Push a refresh to every live staff connection of this (site, user).
     */
    private function _realtime_push_user_refresh(): void
    {
        Realtime::push_user_refresh((int) $this->site_id, (int) $this->id);
    }

    // =========================================================================
    // AJAX FETCH
    // =========================================================================

    /**
     * Ajax model fetch - allows JavaScript to load user records
     * Filters out invite_* fields for security
     *
     * Gated 'is_logged_in' declaratively; the body keeps the record-level work
     * (existence + the invite_* redaction).
     */
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]
    public static function fetch($id)
    {
        $user = static::find($id);

        if (!$user) {
            return false;
        }

        $data = $user->toArray();

        // Filter out invite_* fields - these contain sensitive invitation data
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, 'invite_')) {
                unset($data[$key]);
            }
        }

        // Augment with computed properties
        $data['get_full_name'] = $user->get_full_name();
        $data['get_printed_name'] = $user->get_printed_name();

        return $data;
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    /**
     * Convert model to array with resolved permissions
     *
     * Adds resolved_permissions and removes role_id__permissions
     * (which is redundant since resolved_permissions includes it with
     * supplementary grants/denies applied).
     *
     * @return array
     */
    public function toArray()
    {
        $data = parent::toArray();

        // Add resolved permissions
        $data['resolved_permissions'] = $this->get_resolved_permissions();

        // Remove role_id__permissions (redundant, use resolved_permissions instead)
        unset($data['role_id__permissions']);

        return $data;
    }
}