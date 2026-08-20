<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Models;

use Illuminate\Support\Str;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;

/**
 * Portal_Password_Reset_Model - Manages portal user password reset tokens
 *
 * Password reset flow:
 *
 * 1. User requests password reset with their email
 * 2. System creates token and sends email with reset link
 * 3. User clicks link, lands on password reset page
 * 4. User sets new password
 * 5. Token marked as used (used_at set)
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: portal_password_resets
 *
 * @property int $id
 * @property int $site_id
 * @property int $portal_user_id
 * @property string $token
 * @property string $expires_at
 * @property string $used_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Portal_Password_Reset_Model extends Rsx_Site_Model_Abstract
             {
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per reset request - machine-generated, and a sweep target.
     *
     * A DECLARATION, not a runtime gate - a small, well-narrowed query here is still
     * fine. It marks the tables where a bare ->get() deserves a second look, and where
     * ->result_set() is usually the right answer. See the "Do The Whole Job" section
     * of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = 'portal_password_resets';
    protected $fillable = []; // No mass assignment - always explicit

    // No enums for this model
    public static $enums = [];

    /**
     * Create a password reset token for a portal user
     *
     * @param Portal_User_Model $portal_user
     * @param int|null $expiry_hours Hours until expiration (null uses config default)
     * @return static
     */
    public static function create_for_user(Portal_User_Model $portal_user, ?int $expiry_hours = null): self
    {
        $expiry_hours = $expiry_hours ?? config('rsx.portal.password_reset_expiry_hours', 1);

        // Invalidate any existing unused tokens for this user
        static::where('portal_user_id', $portal_user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $reset = new static();
        $reset->site_id = $portal_user->site_id;
        $reset->portal_user_id = $portal_user->id;
        $reset->token = static::generate_token();
        $reset->expires_at = now()->addHours($expiry_hours);
        $reset->save();

        return $reset;
    }

    /**
     * Generate a unique token
     *
     * @return string
     */
    public static function generate_token(): string
    {
        do {
            // Generate a URL-safe random token
            $token = Str::random(64);
        } while (static::where('token', $token)->exists());

        return $token;
    }

    /**
     * Find password reset by token
     *
     * @param string $token
     * @return static|null
     */
    public static function find_by_token(string $token): ?self
    {
        return static::where('token', $token)->first();
    }

    /**
     * Check if the reset token is valid (not expired, not used)
     *
     * @return bool
     */
    public function is_valid(): bool
    {
        // Already used
        if ($this->used_at !== null) {
            return false;
        }

        // Expired
        if (now()->isAfter($this->expires_at)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the token has been used
     *
     * @return bool
     */
    public function is_used(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Check if the token has expired
     *
     * @return bool
     */
    public function is_expired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    /**
     * Mark the reset as used
     *
     * @return void
     */
    public function mark_used(): void
    {
        $this->used_at = now();
        $this->save();
    }

    /**
     * Get the associated portal user
     *
     * @return Portal_User_Model|null
     */
    public function portal_user(): ?Portal_User_Model
    {
        return Portal_User_Model::find($this->portal_user_id);
    }

    /**
     * Get the full reset URL
     *
     * @return string
     */
    public function get_reset_url(): string
    {
        return rsx_absolute_url(\App\RSpade\Core\Portal\Rsx_Portal::Route(
            'Portal_Password_Reset_Controller::reset',
            ['token' => $this->token]
        ));
    }

    /**
     * Delete expired/used tokens older than given days
     *
     * @param int $days
     * @return int Number of deleted records
     */
    public static function cleanup_old_tokens(int $days = 7): int
    {
        return static::where(function ($query) {
            $query->whereNotNull('used_at');
        })
            ->orWhere(function ($query) {
                $query->whereNull('used_at')
                    ->where('expires_at', '<', now());
            })
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
