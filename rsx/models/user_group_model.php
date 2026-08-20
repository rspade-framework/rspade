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
 * @property bool $deletion_protection
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
     * Get member count
     * @return int
     */
    public function member_count(): int
    {
        return $this->members()->count();
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
     * Ajax model fetch - allows JavaScript to load group records
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        $group = static::find($id);

        if (!$group) {
            return false;
        }

        // Start with model's toArray() to get __MODEL and base data
        $data = $group->toArray();

        // Augment with model methods (key must match method name)
        $data['member_count'] = $group->member_count();
        $data['can_delete'] = $group->can_delete();

        return $data;
    }
}
