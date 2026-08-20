<?php

namespace App\RSpade\Core\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use App\RSpade\Core\Database\Models\Rsx_Actor_Model_Abstract;
use App\RSpade\Core\Models\User_Invite_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Verification_Model;
use App\RSpade\Core\Session\Session;
/**
 * Login_User_Model - Authentication identity for multi-tenant system
 *
 * Represents the login credentials and authentication state for a user.
 * A single login identity can have multiple site-specific User_Model records.
 *
 * Contains: email, password, verification status, remember tokens
 * Does NOT contain: first_name, last_name, phone (those are on site-specific User_Model)
 *
 * This model integrates with Laravel's authentication system.
 *
 * ACTOR: extends Rsx_Actor_Model_Abstract - it signs in and it is a target of the
 * created_by/updated_by authorship pairs (the cross-site half of the stamp matrix).
 * The NON site-scoped abstract is the right one: a login identity spans every site by
 * definition, so it must not carry the site global scope. See rsx:man actors.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: login_users
 *
 * @property int $id
 * @property string $email
 * @property string $password
 * @property bool $is_activated
 * @property bool $is_verified
 * @property int $status_id
 * @property string $timezone
 * @property bool $timezone_auto
 * @property string $remember_token
 * @property string $last_login
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 *
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 * @property-read string $is_verified__label
 * @property-read string $is_verified__constant
 *
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 * @method static array is_verified__enum() Get all enum definitions with full metadata
 * @method static array is_verified__enum_select() Get [{value, label}] array for dropdowns
 * @method static array is_verified__enum_labels() Get simple id => label map
 * @method static array is_verified__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Login_User_Model extends Rsx_Actor_Model_Abstract implements
    \Illuminate\Contracts\Auth\Authenticatable,
    \Illuminate\Contracts\Auth\Access\Authorizable,
    \Illuminate\Contracts\Auth\CanResetPassword
                      {
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 2;
    const STATUS_SUSPENDED = 3;


    use Authenticatable;
    use Authorizable;
    use CanResetPassword;
    use Notifiable;

    /**
     * Enum field definitions
     *
     * Define enums for fields that have predefined values.
     * This provides:
     * - Constants: User_Model::STATUS_ACTIVE, User_Model::ROLE_ADMIN
     * - Magic properties: $user->status_label, $user->role_label
     * - Static methods: User_Model::status_enum_select(), User_Model::role_enum_ids()
     *
     * @var array
     */
    public static $enums = [
        'status_id' => [
            1 => [
                'constant' => 'STATUS_ACTIVE',
                'label' => 'Active',
                'order' => 1,
            ],
            2 => [
                'constant' => 'STATUS_INACTIVE',
                'label' => 'Inactive',
                'order' => 2,
            ],
            3 => [
                'constant' => 'STATUS_SUSPENDED',
                'label' => 'Suspended',
                'order' => 3,
                'selectable' => false,  // Won't appear in dropdown selects
            ],
        ],
        'is_verified' => [
            0 => [
                'label' => 'Not Verified',
            ],
            1 => [
                'label' => 'Verified',
            ],
        ],
    ];

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * Grows with every person who ever signs in to the install.
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
    protected $table = 'login_users';

    /**
     * The attributes that should be hidden for serialization
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Column metadata for special handling
     *
     * @var array
     */
    protected $columnMeta = [
        'password' => [
            'never_export' => true,
            'hidden' => true,
        ],
        'remember_token' => [
            'never_export' => true,
            'hidden' => true,
        ],
    ];

    /**
     * Get all site-specific user records for this login identity
     *
     * A single login identity can have multiple site-specific user profiles.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function users()
    {
        return $this->hasMany(User_Model::class, 'login_user_id');
    }

    /**
     * Get all sessions for this login user
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function sessions()
    {
        return $this->hasMany(Session_Model::class, 'login_user_id');
    }

    /**
     * Get all verification records for this login user
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function verifications()
    {
        return $this->hasMany(User_Verification_Model::class, 'email', 'email');
    }

    /**
     * Get all invites for this login user
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function invites()
    {
        return $this->hasMany(User_Invite_Model::class, 'user_id');
    }

    /**
     * Check if the user is active
     *
     * @return bool
     */
    public function is_active()
    {
        return $this->is_activated && $this->is_verified;
    }

    /**
     * Scope to only get activated users
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActivated($query)
    {
        return $query->where('is_activated', true);
    }

    /**
     * Scope to only get verified users
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Find a user by email address
     *
     * @param string $email
     * @return static|null
     */
    public static function find_by_email($email)
    {
        return static::where('email', $email)->first();
    }

    /**
     * Hash a password for storage
     *
     * @param string $password
     * @return string
     */
    public static function hash_password($password)
    {
        return bcrypt($password);
    }

    // =========================================================================
    // ACTOR CONTRACT (see Rsx_Actor_Model_Abstract)
    // =========================================================================

    /**
     * The printed name of a login identity is its email address - the only human-
     * readable field the table has (names live on the site-specific User_Model rows
     * hanging off it, and there is no single "correct" one across sites).
     *
     * Never empty, and identical for a trashed record: an email is a fact about the
     * identity, not a status. A row with no email at all is not reachable through any
     * live code path, but the id form keeps the never-empty promise unconditional.
     *
     * @return string
     */
    public function get_printed_name(): string
    {
        $email = trim((string) $this->email);
        if ($email !== '') {
            return $email;
        }

        return 'Login User #' . (int) $this->id;
    }

    /**
     * There is no login-identity screen anywhere in RSpade: a login user is auth
     * plumbing, and everything a person would want to look at (name, role, profile)
     * lives on the site-specific User_Model. Null is therefore the honest permanent
     * answer, in both realms.
     *
     * An application that builds a cross-site identity admin overrides this model
     * (standard class-override pattern) and returns its own route.
     *
     * @return string|null
     */
    public function get_view_profile_url(): ?string
    {
        return null;
    }

    /**
     * Fetch a login_user record by ID for Ajax ORM
     *
     * This implementation:
     * - Requires authentication (the declarative 'is_logged_in' gate)
     * - Only allows users to fetch their own login record (record-level, below)
     * - Filters out sensitive data (password is already hidden via $hidden property)
     *
     * @param int $id Single ID
     * @return static|false Single model object or false if not found
     */
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]
    public static function fetch($id)
    {
        $login_user_id = Session::get_login_user_id();

        // Check authorization: users can only fetch their own login record
        if ($id != $login_user_id) {
            return false;
        }

        // Fetch single record
        $model = static::find($id);

        return $model ?: false;
    }
}
