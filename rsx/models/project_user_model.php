<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;

/**
 * Join model for the project_users pivot (1-to-many assigned users per project).
 *
 * A plain first-class model over the pivot table (house precedent: Portal_Membership_Model).
 * No enums, no soft deletes - just typed access to the pivot so all reads/writes go through
 * the ORM instead of DB::table().
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: project_users
 *
 * @property int $id
 * @property int $site_id
 * @property int $project_id
 * @property int $user_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Project_User_Model extends Rsx_Site_Model_Abstract
 {
    /** __AUTO_GENERATED: */

    /** __/AUTO_GENERATED */

    protected $table = 'project_users';
    protected $fillable = []; // No mass assignment - always explicit

    public static $enums = []; // Pivot has no enum fields

    /**
     * User ids assigned to a project.
     *
     * @return int[]
     */
    public static function user_ids_for_project(int $project_id): array
    {
        return array_map('intval', static::where('project_id', $project_id)->pluck('user_id')->all());
    }
}
