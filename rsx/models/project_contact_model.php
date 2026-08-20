<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;

/**
 * Join model for the project_contacts pivot (1-to-many contacts per project).
 *
 * A plain first-class model over the pivot table (house precedent: Portal_Membership_Model).
 * No enums, no soft deletes - just typed access to the pivot so all reads/writes go through
 * the ORM instead of DB::table().
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: project_contacts
 *
 * @property int $id
 * @property int $site_id
 * @property int $project_id
 * @property int $contact_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Project_Contact_Model extends Rsx_Site_Model_Abstract
 {
    /** __AUTO_GENERATED: */

    /** __/AUTO_GENERATED */

    protected $table = 'project_contacts';
    protected $fillable = []; // No mass assignment - always explicit

    public static $enums = []; // Pivot has no enum fields

    /**
     * Contact ids linked to a project.
     *
     * @return int[]
     */
    public static function contact_ids_for_project(int $project_id): array
    {
        return array_map('intval', static::where('project_id', $project_id)->pluck('contact_id')->all());
    }

    /**
     * Project ids a contact is linked to.
     *
     * @return int[]
     */
    public static function project_ids_for_contact(int $contact_id): array
    {
        return array_map('intval', static::where('contact_id', $contact_id)->pluck('project_id')->all());
    }
}
