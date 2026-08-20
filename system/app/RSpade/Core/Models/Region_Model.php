<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Country_Model;

/**
 * RSX:USE
 * Region_Model - ISO 3166-2 subdivision data (states, provinces, territories)
 *
 * Represents geographic subdivisions with their ISO codes and names.
 * Data populated from sokil/php-isocodes via rsx:seed:geographic-data command.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: regions
 *
 * @property int $id
 * @property string $code
 * @property string $country_alpha2
 * @property string $name
 * @property string $type
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
class Region_Model extends Rsx_Model_Abstract
                     {
    public static $enums = [];

    protected $table = 'regions';

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Get the country this region belongs to
     */
    public function country()
    {
        return $this->belongsTo(Country_Model::class, 'country_alpha2', 'alpha2');
    }

    /**
     * Scope to only enabled regions
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to regions for a specific country
     */
    public function scopeForCountry($query, string $country_alpha2)
    {
        return $query->where('country_alpha2', $country_alpha2);
    }

    /**
     * Get region by code
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }
}
