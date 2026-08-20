<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;

/**
 * User_Invite model for inviting users to sites
 *
 * Site-scoped invitations that allow existing users to invite
 * new users to join their site/organization.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: user_invites
 *
 * @property int $id
 * @property int $user_id
 * @property int $site_id
 * @property string $invite_code
 * @property string $expires_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class User_Invite_Model extends Rsx_Site_Model_Abstract
                      {
    /**
     * Enum field definitions
     * @var array
     */
    public static $enums = [];

    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'user_invites';

    /**
     * The attributes that should be cast to native types
     *
     * @var array
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Column metadata for special handling
     *
     * @var array
     */
    protected $columnMeta = [
        'invite_code' => [
            'never_export' => true,
            'sensitive' => true,
        ],
    ];

    /**
     * Get the user who created this invite
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    #[Relationship]
    public function inviter()
    {
        return $this->belongsTo(Test_User_Model::class, 'user_id');
    }

    /**
     * Get the site this invite is for
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    #[Relationship]
    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /**
     * Check if the invite has expired
     *
     * @return bool
     */
    public function is_expired()
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the invite is valid
     *
     * @return bool
     */
    public function is_valid()
    {
        return !$this->is_expired();
    }

    /**
     * Generate a unique invite code
     *
     * @return string
     */
    public static function generate_invite_code()
    {
        do {
            // Generate a readable invite code (uppercase alphanumeric)
            $code = strtoupper(substr(md5(random_bytes(16)), 0, 8));
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }

    /**
     * Create a new invite
     *
     * @param int $user_id The user creating the invite
     * @param int $site_id The site to invite to
     * @param int $expires_in_days How many days until expiry
     * @return static
     */
    public static function create_invite($user_id, $site_id, $expires_in_days = 7)
    {
        $invite = new static();
        $invite->user_id = $user_id;
        $invite->site_id = $site_id;
        $invite->invite_code = static::generate_invite_code();
        $invite->expires_at = now()->addDays($expires_in_days);
        $invite->save();

        return $invite;
    }

    /**
     * Find a valid invite by code
     *
     * @param string $code
     * @return static|null
     */
    public static function find_valid_by_code($code)
    {
        return static::where('invite_code', $code)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Get the invite URL
     *
     * @return string
     */
    public function get_invite_url()
    {
        return url("/invite/{$this->invite_code}");
    }

    /**
     * Scope to get unexpired invites
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnexpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope to get invites created by a specific user
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $user_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreatedBy($query, $user_id)
    {
        return $query->where('user_id', $user_id);
    }
}
