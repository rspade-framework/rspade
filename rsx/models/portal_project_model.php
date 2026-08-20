<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Portal\Portal_Authorizable;
use Rsx\Portal_Permission;
/**
 * Portal_Project_Model - Tracks which projects are visible on a client's portal
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_projects
 *
 * @property int $id
 * @property int $site_id
 * @property int $client_id
 * @property int $project_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
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
class Portal_Project_Model extends Rsx_Site_Model_Abstract
             {
    use Portal_Authorizable;

    protected $table = 'portal_projects';
    protected $fillable = [];

    public static $enums = [];

    /**
     * Record-level portal visibility (membership-scoped rule).
     *
     * A portal user may read a portal-project visibility row only for a client
     * they have access to. Fail-closed. portal_fetch() (Portal_Authorizable trait)
     * has already required an authenticated portal session before this is called.
     *
     * @return bool
     */
    public function portal_can_read(): bool
    {
        return Portal_Permission::has_client_access((int) $this->client_id);
    }

    /**
     * Check if a project is visible on a client's portal
     */
    public static function is_visible(int $client_id, int $project_id): bool
    {
        return static::where('client_id', $client_id)
            ->where('project_id', $project_id)
            ->exists();
    }

    /**
     * Get all visible project IDs for a client
     */
    public static function get_project_ids_for_client(int $client_id): array
    {
        return static::where('client_id', $client_id)
            ->pluck('project_id')
            ->toArray();
    }

    /**
     * Toggle project visibility for a client portal
     */
    public static function toggle(int $site_id, int $client_id, int $project_id): bool
    {
        $existing = static::where('client_id', $client_id)
            ->where('project_id', $project_id)
            ->first();

        if ($existing) {
            $existing->delete();
            return false;
        }

        $record = new static();
        $record->site_id = $site_id;
        $record->client_id = $client_id;
        $record->project_id = $project_id;
        $record->save();
        return true;
    }
}
