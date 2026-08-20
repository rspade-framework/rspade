<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Region_Model;

/**
 * RSX:USE
 * Country_Model - ISO 3166-1 country data
 *
 * Represents countries with their ISO codes and names.
 * Data populated from sokil/php-isocodes via rsx:seed:geographic-data command.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: countries
 *
 * @property int $id
 * @property string $alpha2
 * @property string $alpha3
 * @property string $numeric
 * @property string $name
 * @property string $common_name
 * @property bool $enabled
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Country_Model extends Rsx_Model_Abstract
                     {
    public static $enums = [];

    protected $table = 'countries';

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Get all regions (subdivisions) for this country
     */
    public function regions()
    {
        return $this->hasMany(Region_Model::class, 'country_alpha2', 'alpha2');
    }

    /**
     * Scope to only enabled countries
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Get country by alpha2 code
     */
    public static function findByAlpha2(string $alpha2): ?self
    {
        return static::where('alpha2', $alpha2)->first();
    }

    /**
     * Get country by alpha3 code
     */
    public static function findByAlpha3(string $alpha3): ?self
    {
        return static::where('alpha3', $alpha3)->first();
    }
}
