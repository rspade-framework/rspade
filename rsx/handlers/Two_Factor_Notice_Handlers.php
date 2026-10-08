<?php

namespace Rsx\Handlers;

use Rsx\Emails\Two_Factor_Challenge_Spent_Email;

/**
 * Two_Factor_Notice_Handlers
 *
 * Tells an account owner when their second factor has been failed until the challenge was
 * destroyed. The framework fires `two_factor.challenge.spent` (staff) and
 * `portal.two_factor.challenge.spent` (portal) at that moment and does nothing else about
 * it - whether anybody is told, and how, is this application's decision.
 *
 * Why it is worth an email: reaching a challenge at all means the PASSWORD was right. The
 * attempt limits make guessing the second factor slow - on the order of a couple of percent
 * a month for somebody who never stops - and only being noticed makes it hopeless.
 *
 * The handler only QUEUES the message (email is never sent inline), so it costs the failed
 * request nothing. Each event is one notice; a caller who exhausts challenge after challenge
 * is stopped by the identity lock after rsx.two_factor.identity_max_failures, which bounds
 * how many of these one account can receive in a window.
 *
 * See: php artisan rsx:man two_factor
 */
class Two_Factor_Notice_Handlers
{
    /**
     * @param array $data {identity: Login_User_Model, email: string, failures: int}
     */
    #[OnEvent('two_factor.challenge.spent', priority: 10)]
    public static function notify_staff_identity($data): void
    {
        (new Two_Factor_Challenge_Spent_Email($data['email']))->to($data['email'])->send();
    }

    /**
     * @param array $data {identity: Portal_User_Model, email: string, failures: int}
     */
    #[OnEvent('portal.two_factor.challenge.spent', priority: 10)]
    public static function notify_portal_identity($data): void
    {
        (new Two_Factor_Challenge_Spent_Email($data['email'], true))->to($data['email'])->send();
    }
}
