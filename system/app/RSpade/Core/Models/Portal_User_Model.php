<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Models;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\Models\Rsx_Site_Actor_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Portal\Portal_Authorizable;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
/**
 * Portal_User_Model - authentication identity for external (client portal) users.
 *
 * The portal equivalent of Login_User_Model/User_Model: it holds the generic
 * identity and credentials for a portal user (email, password, verification and
 * status, last login) and the auth helpers the portal login/register/reset flows
 * rely on. Portal users are site-scoped (one row per site_id).
 *
 * This is a framework-core model so the portal subsystem never depends on a class
 * defined inside the application. An application that needs to extend it (extra
 * columns, app-specific lookups) overrides it with the standard RSX override
 * pattern: a same-named class in /rsx/models/ replaces this one wholesale.
 *
 * App-specific linkage (e.g. tying a portal user to a CRM contact) is NOT modeled
 * here - it lives in the application via separate models/columns, exactly as
 * site-specific user data lives in separate models around User_Model.
 *
 * ACTOR: extends Rsx_Site_Actor_Model_Abstract - it signs in and it is the portal-side
 * target of the created_by/updated_by authorship pairs. An application overriding this
 * model must extend the same abstract (ACTOR-01 enforces it). See rsx:man actors.
 *
 * @property int $id
 * @property int $site_id
 * @property string $email
 * @property string $password
 * @property bool $is_verified
 * @property int $status_id
 * @property mixed $metadata
 * @property string $last_login
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by
 * @property int $updated_by
 *
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 * @property-read string $status_id__badge
 *
 * @method static array status_id__enum()
 * @method static array status_id__enum_select()
 * @method static array status_id__enum_labels()
 * @method static array status_id__enum_ids()
 *
 * @mixin \Eloquent
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_users
 *
 * @property int $id
 * @property int $site_id
 * @property int $contact_id
 * @property string $email
 * @property string $password
 * @property bool $is_verified
 * @property int $status_id
 * @property array $metadata
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
 *
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
/**
 * AUTHORIZATION: the class gate is the PORTAL realm's 'is_logged_in' - portal_fetch()
 * is indexed portal, so the name resolves against Portal_Permission. The gate covers
 * the "is there a portal session" half; portal_can_read() below stays as the
 * record-level own-record rule.
 */
#[Auth('is_logged_in')]
class Portal_User_Model extends Rsx_Site_Actor_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 2;
    const STATUS_SUSPENDED = 3;

    use Portal_Authorizable;

    protected $table = 'portal_users';
    protected $fillable = []; // No mass assignment - always explicit

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_verified' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Column metadata for special handling.
     *
     * @var array
     */
    protected $columnMeta = [
        'password' => [
            'never_export' => true,
            'hidden' => true,
        ],
    ];

    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active', 'badge' => 'bg-success', 'order' => 1],
            2 => ['constant' => 'STATUS_INACTIVE', 'label' => 'Inactive', 'badge' => 'bg-secondary', 'order' => 2],
            3 => ['constant' => 'STATUS_SUSPENDED', 'label' => 'Suspended', 'badge' => 'bg-danger', 'order' => 3, 'selectable' => false],
        ],
    ];

    /**
     * Whether this portal user is allowed to log in.
     *
     * @return bool
     */
    public function can_login(): bool
    {
        return $this->status_id === self::STATUS_ACTIVE && $this->is_verified;
    }

    /**
     * Verify a plain-text password against the stored hash.
     *
     * @param string $password
     * @return bool
     */
    public function check_password(string $password): bool
    {
        return Hash::check($password, $this->password);
    }

    /**
     * Set the password (hashes automatically). Does not save.
     *
     * @param string $password Plain text password
     * @return void
     */
    public function set_password(string $password): void
    {
        $this->password = Hash::make($password);
    }

    /**
     * Update and persist the last-login timestamp.
     *
     * @return void
     */
    public function touch_last_login(): void
    {
        $this->last_login = now();
        $this->save();
    }

    /**
     * Read a value from the JSON metadata column.
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
     * Write a value into the JSON metadata column. Does not save.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set_metadata(string $key, $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        $this->metadata = $metadata;
    }

    /**
     * Find a portal user by email within a site. Email is normalized (trimmed,
     * lower-cased) to match how addresses are stored.
     *
     * @param int $site_id
     * @param string $email
     * @return static|null
     */
    public static function find_by_email(int $site_id, string $email): ?self
    {
        return static::where('site_id', $site_id)
            ->where('email', strtolower(trim($email)))
            ->first();
    }

    // =========================================================================
    // ACTOR CONTRACT (see Rsx_Actor_Model_Abstract)
    // =========================================================================

    /**
     * The printed name of a portal account is its email address.
     *
     * portal_users deliberately holds only the generic identity - there are no name
     * columns, because the person's name is app data (a CRM contact, a membership
     * record) that the framework must not assume exists. An application that links
     * portal accounts to named records overrides this model and returns that name,
     * falling back to the email.
     *
     * Never empty; identical for a trashed record (no "(deleted)" marker).
     *
     * @return string
     */
    public function get_printed_name(): string
    {
        $email = trim((string) $this->email);
        if ($email !== '') {
            return $email;
        }

        return 'Portal User #' . (int) $this->id;
    }

    /**
     * Where the current viewer can see this portal account.
     *
     * - Portal realm, own record: the portal account screen. Resolved through
     *   Auth_Gates so the portal 'is_logged_in' gate on that action is what decides.
     * - Portal realm, someone else's record: null. Portal users do not browse each
     *   other's accounts; what one client's people may see about another is app
     *   policy expressed in app screens, never a framework-shipped link.
     * - Staff realm: null. RSpade ships no per-portal-user detail screen (portal
     *   accounts are administered from the client they belong to), so there is no
     *   destination to name. An app that builds one overrides this model.
     *
     * Never memoized: staff and the account owner get different answers for the same
     * record, in the same process.
     *
     * @return string|null
     */
    public function get_view_profile_url(): ?string
    {
        if (!Rsx_Portal::is_portal_request()) {
            return null;
        }

        if ((int) $this->id !== (int) Portal_Session::get_portal_user_id()) {
            return null;
        }

        return Auth_Gates::accessible_route('Portal_Settings_Action', Auth_Gates::REALM_PORTAL);
    }

    /**
     * Record-level portal visibility (own-record rule).
     *
     * A portal user may only read their own row. Fail-closed: any mismatch denies.
     * portal_fetch() (from the Portal_Authorizable trait) has already required an
     * authenticated portal session before this is called. The password is removed
     * via $hidden / $columnMeta.
     *
     * @return bool
     */
    public function portal_can_read(): bool
    {
        return (int) $this->id === (int) Portal_Session::get_portal_user_id();
    }
}