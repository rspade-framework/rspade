<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;

/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: client_departments
 *
 * @property int $id
 * @property int $site_id
 * @property int $client_id
 * @property string $name
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $created_at
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Client_Department_Model extends Rsx_Site_Model_Abstract
                     {
    protected $table = 'client_departments';
    protected $fillable = []; // No mass assignment - always explicit

    /**
     * Enum field definitions
     * Format: 'field_name' => [value => ['constant' => 'NAME', 'label' => 'Display']]
     */
    public static $enums = [];

    /**
     * Relationships
     */
    #[Relationship]
    public function client()
    {
        return $this->belongsTo(Client_Model::class, 'client_id');
    }

    #[Relationship]
    public function contacts()
    {
        return $this->hasMany(Contact_Model::class, 'client_department_id');
    }
}
