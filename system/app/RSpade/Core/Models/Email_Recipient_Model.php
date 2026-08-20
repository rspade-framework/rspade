<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Email_Queue_Model;

/**
 * Email_Recipient_Model - Per-address tracking and unsubscribe management (core).
 *
 * Tracks delivery stats and blocklist preferences per email address. Created
 * automatically when an email is first sent to an address. Core infrastructure the
 * Rsx_Mail blocklist API depends on; overridable via the class-override pattern.
 *
 * @property integer $id
 * @property integer $site_id
 * @property string $email
 * @property boolean $is_blocked_notification
 * @property boolean $is_blocked_marketing
 * @property boolean $is_blocked_all
 * @property integer $total_sent
 * @property integer $total_failed
 * @property string $last_sent_at
 * @property string $unsubscribed_at
 * @property string $created_at
 * @property string $updated_at
 * @property integer $created_by
 * @property integer $updated_by
 * @mixin \Eloquent
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: email_recipients
 *
 * @property int $id
 * @property int $site_id
 * @property string $email
 * @property bool $is_blocked_notification
 * @property bool $is_blocked_marketing
 * @property bool $is_blocked_all
 * @property int $total_sent
 * @property int $total_failed
 * @property string $last_sent_at
 * @property string $unsubscribed_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
class Email_Recipient_Model extends Rsx_Site_Model_Abstract
       {
    // Infrastructure queue table: not user-facing subscribed data, so it must not
    // kick the emitter engine.
    public static $realtime_silent = true;
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per recipient per queued email - a multiple of the queue itself.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;


    protected $table = 'email_recipients';
    protected $fillable = [];

    protected $casts = [
        'is_blocked_notification' => 'boolean',
        'is_blocked_marketing' => 'boolean',
        'is_blocked_all' => 'boolean',
    ];

    public static $enums = [];

    /**
     * Find or create a recipient record for an email address
     */
    public static function find_or_create_by_email(int $site_id, string $email): self
    {
        $email = strtolower(trim($email));

        $recipient = static::where('site_id', $site_id)
            ->where('email', $email)
            ->first();

        if (!$recipient) {
            $recipient = new static();
            $recipient->site_id = $site_id;
            $recipient->email = $email;
            $recipient->save();
        }

        return $recipient;
    }

    /**
     * Check if an email address is blocked for a given category
     *
     * @param int $site_id
     * @param string $email
     * @param int $category_id One of Email_Queue_Model::CATEGORY_* constants
     * @return bool
     */
    public static function is_blocked(int $site_id, string $email, int $category_id): bool
    {
        $recipient = static::where('site_id', $site_id)
            ->where('email', strtolower(trim($email)))
            ->first();

        if (!$recipient) {
            return false;
        }

        if ($recipient->is_blocked_all) {
            return true;
        }

        if ($category_id === Email_Queue_Model::CATEGORY_NOTIFICATION && $recipient->is_blocked_notification) {
            return true;
        }

        if ($category_id === Email_Queue_Model::CATEGORY_MARKETING && $recipient->is_blocked_marketing) {
            return true;
        }

        return false;
    }

    /**
     * Block a specific category for an email address
     */
    public static function block(int $site_id, string $email, int $category_id): void
    {
        $recipient = static::find_or_create_by_email($site_id, $email);

        if ($category_id === Email_Queue_Model::CATEGORY_NOTIFICATION) {
            $recipient->is_blocked_notification = true;
        } elseif ($category_id === Email_Queue_Model::CATEGORY_MARKETING) {
            $recipient->is_blocked_marketing = true;
        }

        if (!$recipient->unsubscribed_at) {
            $recipient->unsubscribed_at = now();
        }

        $recipient->save();
    }

    /**
     * Unblock a specific category for an email address
     */
    public static function unblock(int $site_id, string $email, int $category_id): void
    {
        $recipient = static::find_or_create_by_email($site_id, $email);

        if ($category_id === Email_Queue_Model::CATEGORY_NOTIFICATION) {
            $recipient->is_blocked_notification = false;
        } elseif ($category_id === Email_Queue_Model::CATEGORY_MARKETING) {
            $recipient->is_blocked_marketing = false;
        }

        // Clear unsubscribed_at if nothing is blocked anymore
        if (!$recipient->is_blocked_notification && !$recipient->is_blocked_marketing && !$recipient->is_blocked_all) {
            $recipient->unsubscribed_at = null;
        }

        $recipient->save();
    }

    /**
     * Block all non-transactional email for an address
     */
    public static function block_all(int $site_id, string $email): void
    {
        $recipient = static::find_or_create_by_email($site_id, $email);
        $recipient->is_blocked_all = true;

        if (!$recipient->unsubscribed_at) {
            $recipient->unsubscribed_at = now();
        }

        $recipient->save();
    }

    /**
     * Increment sent count
     */
    public function increment_sent(): void
    {
        $this->total_sent = $this->total_sent + 1;
        $this->last_sent_at = now();
        $this->save();
    }

    /**
     * Increment failed count
     */
    public function increment_failed(): void
    {
        $this->total_failed = $this->total_failed + 1;
        $this->save();
    }
}
