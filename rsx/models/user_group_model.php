<?php

namespace Rsx\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\User_Model;
/**
 * User_Group_Model - User group for organizing site users
 *
 * Groups allow organizing users for permissions, notifications, and assignments.
 * Groups are site-specific and support soft deletes.
 *
 * The deletion_protection field prevents accidental deletion of critical groups
 * (e.g., "Administrators", "All Users"). This field cannot be set via UI.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: user_groups
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property string $description
 * @property int $deletion_protection
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
#[Auth('is_logged_in')]
class User_Group_Model extends Rsx_Site_Model_Abstract
                 {
    use SoftDeletes;

    protected $table = 'user_groups';
    protected $fillable = []; // No mass assignment - always explicit

    public static $enums = [];

    /**
     * Get users in this group
     */
    #[Relationship]
    public function members()
    {
        return $this->belongsToMany(
            User_Model::class,
            'user_group_members',
            'user_group_id',
            'user_id'
        );
    }

    /**
     * DERIVED PROPERTIES - computed values that must reach JavaScript.
     *
     * $appends is the route: Eloquent serializes each name through its getXAttribute()
     * accessor inside parent::toArray(), which Rsx_Model_Abstract::toArray() calls first, so
     * these ride every payload this model produces - fetch(), a relationship, a list - and the
     * generated Base_User_Group_Model.js declares each one. Hand-adding the keys inside
     * fetch() would put them on exactly one payload and on none of the others.
     *
     * member_count COUNTS ROWS to answer, so a payload carrying many groups pays a count per
     * record. It is declared here anyway because the alternative - each surface recomputing it
     * - is how the answers drift; a caller that needs a wide list without it selects the
     * columns it wants rather than serializing.
     *
     * @var array
     */
    protected $appends = ['member_count', 'can_delete'];

    /**
     * Get member count
     * @return int
     */
    public function member_count(): int
    {
        return $this->members()->count();
    }

    /**
     * Accessor for the appended `member_count` property. Delegates - never re-implements.
     *
     * @return int
     */
    public function getMemberCountAttribute(): int
    {
        return $this->member_count();
    }

    /**
     * Check if this group can be deleted
     * @return bool
     */
    public function can_delete(): bool
    {
        return !$this->deletion_protection;
    }

    /**
     * Accessor for the appended `can_delete` property. Delegates - never re-implements: the
     * method is the definition, the property is only its serialization.
     *
     * @return bool
     */
    public function getCanDeleteAttribute(): bool
    {
        return $this->can_delete();
    }

    /**
     * Ajax model fetch - allows JavaScript to load group records
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $group = static::find($id);

        if (!$group) {
            return false;
        }

        // No hand-added keys: member_count and can_delete are declared derived properties
        // ($appends above), so toArray() already carries them.
        return $group->toArray();
    }
}
