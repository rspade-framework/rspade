<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * User_Verification model for email/SMS verification codes
 *
 * Handles verification codes for email confirmation, password recovery,
 * and two-factor authentication via email or SMS.
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: user_verifications
 *
 * @property int $id
 * @property string $email
 * @property string $verification_code
 * @property int $verification_type_id
 * @property string $verified_at
 * @property string $expires_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $verification_type_id__label
 * @property-read string $verification_type_id__constant
 *
 * @method static array verification_type_id__enum() Get all enum definitions with full metadata
 * @method static array verification_type_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array verification_type_id__enum_labels() Get simple id => label map
 * @method static array verification_type_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class User_Verification_Model extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const VERIFICATION_TYPE_EMAIL = 1;
    const VERIFICATION_TYPE_SMS = 2;
    const VERIFICATION_TYPE_EMAIL_RECOVERY = 3;
    const VERIFICATION_TYPE_SMS_RECOVERY = 4;

    /**
     * Enum field definitions
     * @var array
     */
    public static $enums = [
        'verification_type_id' => [
            1 => [
                'constant' => 'VERIFICATION_TYPE_EMAIL',
                'label' => 'Email Verification',
            ],
            2 => [
                'constant' => 'VERIFICATION_TYPE_SMS',
                'label' => 'SMS Verification',
            ],
            3 => [
                'constant' => 'VERIFICATION_TYPE_EMAIL_RECOVERY',
                'label' => 'Email Recovery',
            ],
            4 => [
                'constant' => 'VERIFICATION_TYPE_SMS_RECOVERY',
                'label' => 'SMS Recovery',
            ],
        ],
    ];

    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'user_verifications';

    /**
     * The attributes that should be cast to native types
     *
     * @var array
     */
    protected $casts = [
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Column metadata for special handling
     *
     * @var array
     */
    protected $columnMeta = [
        'verification_code' => [
            'never_export' => true,
            'sensitive' => true,
        ],
        'verification_type_id' => [
            'type' => 'enum',
            'values' => [
                1 => 'email',           // Email verification
                2 => 'sms',             // SMS verification
                3 => 'email_recovery',  // Password recovery via email
                4 => 'sms_recovery',     // Password recovery via SMS
            ],
        ],
    ];

    /**
     * Get the user associated with this verification (by email)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    #[Relationship]
    public function user()
    {
        return $this->belongsTo(Test_User_Model::class, 'email', 'email');
    }

    /**
     * Get the verification type name
     *
     * @return string|null
     */
    public function get_verification_type_name()
    {
        $types = $this->get_enum_values('verification_type_id');

        return $types[$this->verification_type_id] ?? null;
    }

    /**
     * Check if this is an email verification
     *
     * @return bool
     */
    public function is_email_verification()
    {
        return in_array($this->verification_type_id, [1, 3]);
    }

    /**
     * Check if this is an SMS verification
     *
     * @return bool
     */
    public function is_sms_verification()
    {
        return in_array($this->verification_type_id, [2, 4]);
    }

    /**
     * Check if this is a recovery verification
     *
     * @return bool
     */
    public function is_recovery_verification()
    {
        return in_array($this->verification_type_id, [3, 4]);
    }

    /**
     * Check if the verification has been used
     *
     * @return bool
     */
    public function is_verified()
    {
        return !is_null($this->verified_at);
    }

    /**
     * Check if the verification has expired
     *
     * @return bool
     */
    public function is_expired()
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the verification is valid (not used and not expired)
     *
     * @return bool
     */
    public function is_valid()
    {
        return !$this->is_verified() && !$this->is_expired();
    }

    /**
     * Mark this verification as used
     *
     * @return bool
     */
    public function mark_as_verified()
    {
        $this->verified_at = now();

        return $this->save();
    }

    /**
     * Generate a random verification code
     *
     * @param int $length
     * @param bool $numeric_only
     * @return string
     */
    public static function generate_code($length = 6, $numeric_only = true)
    {
        if ($numeric_only) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= random_int(0, 9);
            }

            return $code;
        }

        return strtoupper(bin2hex(random_bytes($length / 2)));
    }

    /**
     * Create a new verification record
     *
     * @param string $email
     * @param int $type_id
     * @param int $expires_in_minutes
     * @return static
     */
    public static function create_verification($email, $type_id, $expires_in_minutes = 60)
    {
        $verification = new static();
        $verification->email = $email;
        $verification->verification_code = static::generate_code();
        $verification->verification_type_id = $type_id;
        $verification->expires_at = now()->addMinutes($expires_in_minutes);
        $verification->save();

        return $verification;
    }

    /**
     * Find a valid verification by email and code
     *
     * @param string $email
     * @param string $code
     * @return static|null
     */
    public static function find_valid($email, $code)
    {
        $verification = static::where('email', $email)
            ->where('verification_code', $code)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->first();

        return $verification;
    }

    /**
     * Scope to get unexpired verifications
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnexpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope to get unused verifications
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnused($query)
    {
        return $query->whereNull('verified_at');
    }
}