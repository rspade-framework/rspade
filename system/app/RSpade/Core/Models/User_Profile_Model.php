<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\User_Model;

/**
 * User_Profile_Model
 *
 * Auxiliary, descriptive, and non-system-critical columns for user records.
 * This table maintains a 1:1 relationship with users and stores profile-related
 * information that is not essential for system functionality.
 *
 * Relationship:
 * - 1:1 with User_Model (user_id foreign key with cascade delete)
 * - Auto-creates when accessed via User_Model relationship if doesn't exist
 *
 * No Soft Delete:
 * - This table does not use soft deletes since it only stores auxiliary data
 * - When a user is soft deleted, the profile remains accessible through the relationship
 * - When a user is hard deleted, the profile is cascade deleted
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $department
 * @property string $bio
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by
 * @property int $updated_by
 *
 * @property-read User_Model $user
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: user_profiles
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $department
 * @property string $bio
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class User_Profile_Model extends Rsx_Model_Abstract
                     {
    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'user_profiles';

    /**
     * Indicates if the model should use soft deletes
     * No soft delete since this is auxiliary data only
     *
     * @var bool
     */
    protected $use_soft_delete = false;

    /**
     * Enumeration definitions for fields ending in _id
     *
     * @var array
     */
    public static $enums = [];

    /**
     * Get the user that owns this profile
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    #[Relationship]
    public function user()
    {
        return $this->belongsTo(User_Model::class, 'user_id');
    }
}
