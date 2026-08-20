<?php

namespace App\RSpade\Core\Sms;

use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Models\Sms_Recipient_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;

/**
 * Rsx_Sms - Framework SMS API (mirrors Rsx_Mail).
 *
 * One-call SMS sending. All messages are queued - never sent inline. Dev sites can
 * redirect/suppress outbound SMS. REAL OUTBOUND DELIVERY IS NOT WIRED YET: the send
 * task (Sms_Queue_Service) records each message as HELD_BACK. See the backlog.
 *
 * Usage:
 *   Rsx_Sms::send('+15555550123', 'Your code is 4821', Rsx_Sms::TRANSACTIONAL);
 */
class Rsx_Sms
{
    // SMS categories - match Sms_Queue_Model::CATEGORY_* constants.
    const TRANSACTIONAL = 1;
    const NOTIFICATION = 2;
    const MARKETING = 3;

    /**
     * Queue an SMS for delivery.
     *
     * @param string $to Destination phone number (E.164 recommended)
     * @param string $body Message text
     * @param int $category One of self::TRANSACTIONAL, NOTIFICATION, MARKETING
     * @param int|null $related_type Polymorphic type ref for what triggered this SMS
     * @param int|null $related_id Polymorphic ID
     * @return Sms_Queue_Model The queued record
     */
    public static function send(
        string $to,
        string $body,
        int $category = self::NOTIFICATION,
        ?int $related_type = null,
        ?int $related_id = null
    ): Sms_Queue_Model {
        $site_id = static::__current_site_id();
        $to = trim($to);

        // Blocklist: transactional SMS always delivers; other categories are skipped
        // (recorded BLOCKED) for opted-out numbers.
        if ($category !== self::TRANSACTIONAL) {
            if (Sms_Recipient_Model::is_blocked($site_id, $to, $category)) {
                return Sms_Queue_Model::enqueue_blocked($site_id, $to, $body, $category);
            }
        }

        // Dev-site redirect (mirror of the email dev safety): capture the original
        // destination in dev_original_to when redirected/suppressed.
        $dev_original_to = null;
        if (self::is_dev_mode()) {
            [$actual_to, $dev_original_to] = self::_apply_dev_redirect($to);
            $to = $actual_to;
        }

        $record = Sms_Queue_Model::enqueue(
            $site_id, $to, $body, $category, $dev_original_to, $related_type, $related_id
        );

        // Drain the queue promptly via a background worker (coalesced + throttled).
        // Skipped in console (tests/CLI/cron), where the 5-minute Sms_Queue_Service
        // cron or a direct run handles processing.
        if (!app()->runningInConsole()) {
            Task::dispatch('Sms_Queue_Service', 'send_pending_queue');
        }

        return $record;
    }

    // =========================================================================
    // BLOCKLIST API (opt-out / STOP handling)
    // =========================================================================

    /**
     * Whether a number is blocked for a category.
     */
    public static function is_blocked(string $number, int $category): bool
    {
        $site_id = static::__current_site_id();
        return Sms_Recipient_Model::is_blocked($site_id, $number, $category);
    }

    /**
     * Block a category for a number.
     */
    public static function block(string $number, int $category): void
    {
        $site_id = static::__current_site_id();
        Sms_Recipient_Model::block($site_id, $number, $category);
    }

    /**
     * Unblock a category for a number.
     */
    public static function unblock(string $number, int $category): void
    {
        $site_id = static::__current_site_id();
        Sms_Recipient_Model::unblock($site_id, $number, $category);
    }

    /**
     * Block all non-transactional SMS for a number (a STOP reply).
     */
    public static function block_all(string $number): void
    {
        $site_id = static::__current_site_id();
        Sms_Recipient_Model::block_all($site_id, $number);
    }

    /**
     * The tenant this send/blocklist call belongs to, resolved from the EXPERIENCE that
     * is actually being served (the Rsx_Mail fork, byte for byte - these two facades have
     * the same shape and the same defect).
     *
     * The site stamps the _sms_queue row AND drives the
     * Sms_Recipient_Model::is_blocked($site_id, ...) opt-out lookup, so asking the staff
     * facade on a portal request files the message under the wrong tenant and consults the
     * wrong blocklist - a recipient who sent STOP could still be messaged. No portal
     * caller exists in the template today (a portal 2FA / verification code is the obvious
     * first one); the fork is here so the first one is correct. CLI (the queue drain,
     * tests) is not a portal request and keeps the staff path unchanged.
     *
     * @return int
     */
    private static function __current_site_id(): int
    {
        if (Rsx_Portal::is_portal_request()) {
            return (int) Portal_Session::get_site_id();
        }

        return (int) Session::get_site_id();
    }

    // =========================================================================
    // DEV SITE SMS SAFETY (mirror of Rsx_Mail)
    // =========================================================================

    /**
     * Whether delivery is suppressed (dev site with suppress flag).
     */
    public static function is_delivery_suppressed(): bool
    {
        return (bool) env('SMS_DEV_SUPPRESS_DELIVERY', false);
    }

    /**
     * Whether dev-site SMS gating is active (hostname-based, via Rsx::is_dev_site()).
     */
    public static function is_dev_mode(): bool
    {
        return \App\RSpade\Core\Rsx::is_dev_site();
    }

    /**
     * Apply dev-site SMS redirect logic. On dev sites:
     *   1. Number in whitelist -> deliver as-is
     *   2. Catchall number set  -> redirect to catchall (original captured)
     *   3. Otherwise            -> stays queued, not delivered
     *
     * @return array [actual_to, original_to_or_null]
     */
    private static function _apply_dev_redirect(string $to): array
    {
        $whitelist = env('SMS_DEV_NUMBER_WHITELIST', '');
        if ($whitelist) {
            $numbers = array_map('trim', explode(',', $whitelist));
            if (in_array($to, $numbers)) {
                return [$to, null];
            }
        }

        $catchall = env('SMS_DEV_CATCHALL_NUMBER');
        if ($catchall) {
            return [$catchall, $to];
        }

        return [$to, null];
    }
}
