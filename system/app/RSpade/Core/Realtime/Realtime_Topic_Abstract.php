<?php

namespace App\RSpade\Core\Realtime;

/**
 * Realtime_Topic_Abstract
 *
 * Base class for realtime notification topics. Each topic class defines
 * who can subscribe to messages on that topic. can_subscribe() is the ONLY
 * enforcement boundary in the system — Realtime_Controller no longer gates on
 * any particular session type, so every topic must decide its own access rule.
 *
 * Topics are notification-only — messages must never contain confidential
 * data. They indicate that something happened (e.g., "record updated")
 * and the client reacts by fetching fresh data through normal Ajax.
 *
 * Authenticated example (the default — $requires_auth is true unless overridden):
 *     class Contact_Updated_Topic extends Realtime_Topic_Abstract {
 *         public static function can_subscribe(array $filter = []): bool {
 *             return Permission::has_permission(User_Model::PERM_VIEW_DATA);
 *         }
 *     }
 *
 * Public example (no session of any kind required — e.g. a public weather widget):
 *     class Weather_Updated_Topic extends Realtime_Topic_Abstract {
 *         public static bool $requires_auth = false;
 *         public static function can_subscribe(array $filter = []): bool {
 *             return true; // open to anyone; payload must contain nothing sensitive
 *         }
 *     }
 */
abstract class Realtime_Topic_Abstract
{
    /**
     * Declares whether this topic is expected to require an authenticated caller.
     *
     * This is NOT itself a runtime gate — can_subscribe() is, and it always runs.
     * $requires_auth exists to (a) document intent and (b) drive the
     * REALTIME-AUTH-01 code-quality rule: when true (the default), can_subscribe()
     * is expected to contain a real auth check; when explicitly set false, the
     * rule instead flags the topic for manual security review (a public topic
     * must never leak site/tenant-scoped or otherwise sensitive data).
     *
     * @var bool
     */
    public static bool $requires_auth = true;

    /**
     * Check if the current caller can subscribe to this topic
     *
     * Called by Realtime::subscribe_token() before issuing a token. Runs with
     * real request state, so it may use Session::, Portal_Session::,
     * Permission::, Portal_Permission::, etc. — or, for a public topic
     * ($requires_auth = false), no session check at all.
     *
     * @param array $filter The subscription filter (e.g., ['id' => 5])
     * @return bool
     */
    abstract public static function can_subscribe(array $filter = []): bool;
}
