<?php

namespace App\RSpade\Core\Mail;

use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Email_Recipient_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Mail - Framework email API
 *
 * One-call email sending. All emails are queued — never sent inline.
 * Dev mode redirects all outbound email to a safe address.
 * Blocklist enforcement skips non-transactional emails to opted-out recipients.
 *
 * Usage:
 *   Rsx_Mail::send('user@example.com', 'Welcome', 'welcome', ['name' => 'John']);
 *   Rsx_Mail::send($email, 'Reset', 'portal_password_reset', $data, Rsx_Mail::TRANSACTIONAL);
 */
class Rsx_Mail
{
    // Email categories — match Email_Queue_Model::CATEGORY_* constants
    const TRANSACTIONAL = 1;
    const NOTIFICATION = 2;
    const MARKETING = 3;

    /**
     * Queue an email for delivery
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject line
     * @param string $template Template name (blade file in /rsx/emails/)
     * @param array $data Template variables
     * @param int $category One of self::TRANSACTIONAL, NOTIFICATION, MARKETING
     * @param string|null $to_name Recipient display name
     * @param int|null $related_type Polymorphic type ref for what triggered this email
     * @param int|null $related_id Polymorphic ID
     * @return Email_Queue_Model The queued record
     */
    public static function send(
        string $to,
        string $subject,
        string $template,
        array $data = [],
        int $category = self::NOTIFICATION,
        ?string $to_name = null,
        ?int $related_type = null,
        ?int $related_id = null
    ): Email_Queue_Model {
        $site_id = static::__current_site_id();
        $to = strtolower(trim($to));

        // Check blocklist (transactional emails always deliver)
        if ($category !== self::TRANSACTIONAL) {
            if (Email_Recipient_Model::is_blocked($site_id, $to, $category)) {
                return Email_Queue_Model::enqueue_blocked(
                    $site_id, $to, $subject, $template, $data, $category, $to_name
                );
            }
        }

        // Apply dev mode redirect
        $dev_original_to = null;
        if (self::is_dev_mode()) {
            [$actual_to, $dev_original_to] = self::_apply_dev_redirect($to);
            $to = $actual_to;
        }

        $record = Email_Queue_Model::enqueue(
            $site_id, $to, $subject, $template, $data, $category,
            $to_name, $dev_original_to, $related_type, $related_id
        );

        // Drain the queue promptly via a background worker (coalesced + throttled by
        // the worker cap). Skipped in console (tests/CLI/cron), where the 5-minute
        // Mail_Queue_Service cron or a direct run handles processing.
        if (!app()->runningInConsole()) {
            \App\RSpade\Core\Task\Task::dispatch('Mail_Queue_Service', 'send_pending_queue');
        }

        return $record;
    }

    /**
     * Queue an email to a Contact
     */
    public static function send_to_contact(
        $contact,
        string $subject,
        string $template,
        array $data = [],
        int $category = self::NOTIFICATION
    ): Email_Queue_Model {
        $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
        return self::send($contact->email, $subject, $template, $data, $category, $name ?: null);
    }

    /**
     * Queue an email to a Portal User
     */
    public static function send_to_portal_user(
        $portal_user,
        string $subject,
        string $template,
        array $data = [],
        int $category = self::TRANSACTIONAL
    ): Email_Queue_Model {
        return self::send($portal_user->email, $subject, $template, $data, $category);
    }

    /**
     * The tenant this send/blocklist call belongs to, resolved from the realm that is
     * actually being served.
     *
     * The template app's portal endpoints send mail (password reset, request access,
     * request threads), and those run as PORTAL requests where the staff facade knows
     * nothing: the queue row would be filed under the wrong site and - worse - the
     * recipient's unsubscribe state for their REAL site would never be consulted, so a
     * blocked address would still be mailed. CLI (the queue drain, tests) is not a portal
     * request, so it keeps the staff path unchanged.
     *
     * See docs.dev/audits/portal_realm_session_audit_2026_08_09.md.
     */
    private static function __current_site_id(): int
    {
        if (Rsx_Portal::is_portal_request()) {
            return (int) Portal_Session::get_site_id();
        }

        return (int) Session::get_site_id();
    }

    // =========================================================================
    // BLOCKLIST API
    // =========================================================================

    /**
     * Check if an email address is blocked for a category
     */
    public static function is_blocked(string $email, int $category): bool
    {
        $site_id = static::__current_site_id();
        return Email_Recipient_Model::is_blocked($site_id, $email, $category);
    }

    /**
     * Block a specific category for an email address
     */
    public static function block(string $email, int $category): void
    {
        $site_id = static::__current_site_id();
        Email_Recipient_Model::block($site_id, $email, $category);
    }

    /**
     * Unblock a specific category for an email address
     */
    public static function unblock(string $email, int $category): void
    {
        $site_id = static::__current_site_id();
        Email_Recipient_Model::unblock($site_id, $email, $category);
    }

    /**
     * Block all non-transactional email for an address
     */
    public static function block_all(string $email): void
    {
        $site_id = static::__current_site_id();
        Email_Recipient_Model::block_all($site_id, $email);
    }

    // =========================================================================
    // UNSUBSCRIBE URL
    // =========================================================================

    /**
     * Generate a signed unsubscribe URL for use in email templates
     *
     * @param string $email Recipient email address
     * @param int $category Category to unsubscribe from
     * @return string Signed URL
     */
    public static function unsubscribe_url(string $email, int $category): string
    {
        $secret = config('rsx.mail.unsubscribe_secret') ?: config('app.key');
        $payload = $email . '|' . $category;
        $signature = hash_hmac('sha256', $payload, $secret);

        return rsx_absolute_url('/_mail/unsubscribe?' . http_build_query([
            'email' => $email,
            'category' => $category,
            'sig' => $signature,
        ]));
    }

    /**
     * Verify an unsubscribe signature
     */
    public static function verify_unsubscribe_signature(string $email, int $category, string $signature): bool
    {
        $secret = config('rsx.mail.unsubscribe_secret') ?: config('app.key');
        $payload = $email . '|' . $category;
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    // =========================================================================
    // DEV SITE EMAIL SAFETY
    // =========================================================================

    /**
     * Check if email delivery is suppressed (dev site with suppress flag)
     */
    public static function is_delivery_suppressed(): bool
    {
        return (bool) env('EMAIL_DEV_SUPPRESS_EMAIL_DELIVERY', false);
    }

    /**
     * Check if dev site email gating is active
     *
     * Uses Rsx::is_dev_site() — hostname-based detection.
     * On dev sites, emails are caught by catchall/whitelist unless suppressed.
     */
    public static function is_dev_mode(): bool
    {
        return \App\RSpade\Core\Rsx::is_dev_site();
    }

    /**
     * Apply dev site email redirect logic
     *
     * On dev sites:
     * 1. If recipient is in address whitelist → deliver normally
     * 2. If recipient domain is in domain whitelist → deliver normally
     * 3. If catchall address is set → redirect to catchall
     * 4. Otherwise → email stays in queue (won't be delivered)
     *
     * @param string $to Original recipient
     * @return array [actual_to, original_to_or_null]
     */
    private static function _apply_dev_redirect(string $to): array
    {
        $to_lower = strtolower($to);

        // Check address whitelist
        $address_whitelist = env('EMAIL_DEV_EMAIL_ADDRESS_WHITELIST', '');
        if ($address_whitelist) {
            $addresses = array_map('trim', array_map('strtolower', explode(',', $address_whitelist)));
            if (in_array($to_lower, $addresses)) {
                return [$to, null];
            }
        }

        // Check domain whitelist
        $domain_whitelist = env('EMAIL_DEV_EMAIL_DOMAIN_WHITELIST', '');
        if ($domain_whitelist) {
            $domains = array_map('trim', array_map('strtolower', explode(',', $domain_whitelist)));
            $to_domain = substr($to_lower, strpos($to_lower, '@') + 1);
            if (in_array($to_domain, $domains)) {
                return [$to, null];
            }
        }

        // Redirect to catchall address
        $catchall = env('EMAIL_DEV_CATCHALL_ADDRESS');
        if ($catchall) {
            return [$catchall, $to];
        }

        // No catchall configured — email stays in queue but won't be delivered
        return [$to, null];
    }
}
