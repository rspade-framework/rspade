<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;

/**
 * Ip_Address model for tracking and geocoding IP addresses
 *
 * This is a system model - internal metadata not exported to JavaScript ORM.
 * Used for security, analytics, and audit logging.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: ip_addresses
 *
 * @property int $id
 * @property string $ip_address
 * @property string $city
 * @property string $state
 * @property string $country
 * @property float $lat
 * @property float $lng
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Ip_Address_Model extends Rsx_System_Model_Abstract
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
    protected $table = 'ip_addresses';

    /**
     * The attributes that should be cast to native types
     *
     * @var array
     */
    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Column metadata for special handling
     *
     * @var array
     */
    protected $columnMeta = [
        // All columns in system models are never exported by default
    ];

    /**
     * Get sessions that logged in from this IP
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function login_sessions()
    {
        return $this->hasMany(Session_Model::class, 'login_ip_address_id');
    }

    /**
     * Get sessions that last used this IP
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function last_sessions()
    {
        return $this->hasMany(Session_Model::class, 'last_ip_address_id');
    }

    /**
     * Check if this IP has location data
     *
     * @return bool
     */
    public function has_location()
    {
        return !is_null($this->lat) && !is_null($this->lng);
    }

    /**
     * Get the location as a string
     *
     * @return string
     */
    public function get_location_string()
    {
        $parts = [];

        if ($this->city) {
            $parts[] = $this->city;
        }
        if ($this->state) {
            $parts[] = $this->state;
        }
        if ($this->country) {
            $parts[] = $this->country;
        }

        return implode(', ', $parts) ?: 'Unknown';
    }

    /**
     * Get coordinates as an array
     *
     * @return array|null
     */
    public function get_coordinates()
    {
        if (!$this->has_location()) {
            return null;
        }

        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }

    /**
     * Find or create an IP address record
     *
     * @param string $ip_address
     * @return static
     */
    public static function find_or_create($ip_address)
    {
        $ip = static::where('ip_address', $ip_address)->first();

        if (!$ip) {
            $ip = new static();
            $ip->ip_address = $ip_address;
            $ip->save();

            // Queue for geocoding if needed
            // TODO: Implement geocoding service integration
        }

        return $ip;
    }

    /**
     * Check if IP is private/local
     *
     * @param string $ip_address
     * @return bool
     */
    public static function is_private_ip($ip_address)
    {
        return !filter_var(
            $ip_address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Scope to get IPs from a specific country
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $country
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFromCountry($query, $country)
    {
        return $query->where('country', $country);
    }

    /**
     * Scope to get IPs with location data
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithLocation($query)
    {
        return $query->whereNotNull('lat')->whereNotNull('lng');
    }
}
