<?php

namespace App\RSpade\Core\Api;

use Illuminate\Support\Str;
use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;
use App\RSpade\Core\Models\User_Model;

/**
 * Api_Key_Model - System model for API key management
 *
 * API keys are system-level records that authenticate external API requests.
 * Keys are tied to users and establish session-like contexts for API access.
 *
 * Security:
 * - Keys are hashed before storage (plaintext never stored)
 * - Key prefix stored for identification without exposing full key
 * - Keys can be revoked (soft disable) or have expiration dates
 *
 * @see external_api.txt for full documentation
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key_hash
 * @property string $key_prefix
 * @property int|null $user_role_id
 * @property \Carbon\Carbon|null $last_used_at
 * @property \Carbon\Carbon|null $expires_at
 * @property bool $is_revoked
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _api_keys
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key_hash
 * @property string $key_prefix
 * @property int $user_role_id
 * @property string $last_used_at
 * @property string $expires_at
 * @property bool $is_revoked
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $scopes
 *
 * @mixin \Eloquent
 */
class Api_Key_Model extends Rsx_System_Model_Abstract
                  {
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * Nothing caps how many keys one user may mint.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_api_keys';

    public static $enums = [];

    protected $casts = [
        'is_revoked' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Generate a new API key for a user
     *
     * Returns the plaintext key (only time it's available) and the model.
     * The plaintext key must be shown to the user immediately as it cannot
     * be recovered after this method returns.
     *
     * @param int $user_id User ID to create key for
     * @param string $name Human-readable key name
     * @param string $environment 'live' or 'test'
     * @param int|null $user_role_id Optional role override
     * @param \Carbon\Carbon|null $expires_at Optional expiration date
     * @return array{key: string, model: Api_Key_Model} Plaintext key and saved model
     */
    public static function generate(
        int $user_id,
        string $name,
        string $environment = 'live',
        ?int $user_role_id = null,
        ?\Carbon\Carbon $expires_at = null
    ): array {
        // Generate random key: rsk_{env}_{32 random chars}
        $random = Str::random(32);
        $plaintext_key = "rsk_{$environment}_{$random}";
        $prefix = "rsk_{$environment}_" . substr($random, 0, 4) . '...';

        $model = new self();
        $model->user_id = $user_id;
        $model->name = $name;
        $model->key_hash = hash('sha256', $plaintext_key);
        $model->key_prefix = $prefix;
        $model->user_role_id = $user_role_id;
        $model->expires_at = $expires_at;
        $model->is_revoked = false;
        $model->save();

        return [
            'key' => $plaintext_key,
            'model' => $model,
        ];
    }

    /**
     * Find an API key by its plaintext value
     *
     * Hashes the provided key and looks up by hash.
     * Returns null if not found, revoked, or expired.
     *
     * @param string $plaintext_key The API key from request
     * @return Api_Key_Model|null The key model if valid, null otherwise
     */
    public static function find_by_key(string $plaintext_key): ?self
    {
        $hash = hash('sha256', $plaintext_key);

        $key = self::where('key_hash', $hash)
            ->where('is_revoked', false)
            ->first();

        if (!$key) {
            return null;
        }

        // Check expiration
        if ($key->expires_at && $key->expires_at->isPast()) {
            return null;
        }

        return $key;
    }

    /**
     * Get all keys for a user.
     *
     * Nothing caps how many keys one user may mint, so this returns an Rsx_Result_Set -
     * foreach it, count() it. Iteration walks by primary key, so the created_at ordering
     * is not preserved; sort in the view if the screen needs newest-first.
     *
     * @param int $user_id User ID
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    public static function get_for_user(int $user_id)
    {
        return self::where('user_id', $user_id)->result_set();
    }

    /**
     * Revoke this API key
     *
     * Soft-disables the key. It remains in the database but can no longer
     * be used for authentication.
     */
    public function revoke(): void
    {
        $this->is_revoked = true;
        $this->save();
    }

    /**
     * Update last_used_at timestamp
     *
     * Called when the key is used for authentication.
     */
    public function touch_last_used(): void
    {
        $this->last_used_at = now();
        $this->save();
    }

    /**
     * Get the user this key belongs to
     *
     * @return User_Model|null
     */
    public function get_user(): ?User_Model
    {
        return User_Model::find($this->user_id);
    }

    /**
     * Check if this key is currently valid
     *
     * @return bool
     */
    public function is_valid(): bool
    {
        if ($this->is_revoked) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
