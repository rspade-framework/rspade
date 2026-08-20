<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Sms_Queue_Model;

/**
 * Sms_Recipient_Model - Per-number SMS blocklist + delivery stats (core).
 *
 * Mirrors Email_Recipient_Model, keyed by phone `number`. Tracks opt-out
 * preferences (STOP handling) and per-number send/fail counters. Created
 * automatically when an SMS is first sent to a number. Core infrastructure the
 * Rsx_Sms blocklist API depends on; overridable via the class-override pattern.
 *
 * @property integer $id
 * @property integer $site_id
 * @property string $number
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
 * Table: sms_recipients
 *
 * @property int $id
 * @property int $site_id
 * @property string $number
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
class Sms_Recipient_Model extends Rsx_Site_Model_Abstract
     {
    // Infrastructure queue table: not user-facing subscribed data, so it must not
    // kick the emitter engine.
    public static $realtime_silent = true;

    protected $table = 'sms_recipients';
    protected $fillable = [];

    protected $casts = [
        'is_blocked_notification' => 'boolean',
        'is_blocked_marketing' => 'boolean',
        'is_blocked_all' => 'boolean',
    ];

    public static $enums = [];

    /**
     * Find or create a recipient record for a phone number.
     */
    public static function find_or_create_by_number(int $site_id, string $number): self
    {
        $number = trim($number);

        $recipient = static::where('site_id', $site_id)
            ->where('number', $number)
            ->first();

        if (!$recipient) {
            $recipient = new static();
            $recipient->site_id = $site_id;
            $recipient->number = $number;
            $recipient->save();
        }

        return $recipient;
    }

    /**
     * Check if a number is blocked for a given category.
     *
     * @param int $category_id One of Sms_Queue_Model::CATEGORY_* constants
     */
    public static function is_blocked(int $site_id, string $number, int $category_id): bool
    {
        $recipient = static::where('site_id', $site_id)
            ->where('number', trim($number))
            ->first();

        if (!$recipient) {
            return false;
        }

        if ($recipient->is_blocked_all) {
            return true;
        }

        if ($category_id === Sms_Queue_Model::CATEGORY_NOTIFICATION && $recipient->is_blocked_notification) {
            return true;
        }

        if ($category_id === Sms_Queue_Model::CATEGORY_MARKETING && $recipient->is_blocked_marketing) {
            return true;
        }

        return false;
    }

    /**
     * Block a specific category for a number.
     */
    public static function block(int $site_id, string $number, int $category_id): void
    {
        $recipient = static::find_or_create_by_number($site_id, $number);

        if ($category_id === Sms_Queue_Model::CATEGORY_NOTIFICATION) {
            $recipient->is_blocked_notification = true;
        } elseif ($category_id === Sms_Queue_Model::CATEGORY_MARKETING) {
            $recipient->is_blocked_marketing = true;
        }

        if (!$recipient->unsubscribed_at) {
            $recipient->unsubscribed_at = now();
        }

        $recipient->save();
    }

    /**
     * Unblock a specific category for a number.
     */
    public static function unblock(int $site_id, string $number, int $category_id): void
    {
        $recipient = static::find_or_create_by_number($site_id, $number);

        if ($category_id === Sms_Queue_Model::CATEGORY_NOTIFICATION) {
            $recipient->is_blocked_notification = false;
        } elseif ($category_id === Sms_Queue_Model::CATEGORY_MARKETING) {
            $recipient->is_blocked_marketing = false;
        }

        if (!$recipient->is_blocked_notification && !$recipient->is_blocked_marketing && !$recipient->is_blocked_all) {
            $recipient->unsubscribed_at = null;
        }

        $recipient->save();
    }

    /**
     * Block all non-transactional SMS for a number (a STOP reply).
     */
    public static function block_all(int $site_id, string $number): void
    {
        $recipient = static::find_or_create_by_number($site_id, $number);
        $recipient->is_blocked_all = true;

        if (!$recipient->unsubscribed_at) {
            $recipient->unsubscribed_at = now();
        }

        $recipient->save();
    }

    /**
     * Increment sent count.
     */
    public function increment_sent(): void
    {
        $this->total_sent = $this->total_sent + 1;
        $this->last_sent_at = now();
        $this->save();
    }

    /**
     * Increment failed count.
     */
    public function increment_failed(): void
    {
        $this->total_failed = $this->total_failed + 1;
        $this->save();
    }
}
