<?php

namespace App\RSpade\Core\Throttle;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Throttle - Rate limiting utility for periodic per-user actions
 *
 * Provides atomic check-and-execute functionality for throttling operations.
 * Database implementation is hidden - API exposes only check().
 *
 * Use cases:
 * - Periodic maintenance tasks (notification expiry, cache refresh)
 * - Rate-limited background calculations
 * - Any operation that should run at most once per interval per user
 *
 * @example
 * if (Rsx_Throttle::check('NOTIFICATION_EXPIRE_CHECK', $user_id, minutes: 30)) {
 *     Notification::expire_old();
 * }
 *
 * TENANT AND IDENTITY
 * -------------------
 * The throttle key is (site_id, user_id, action_key). The SITE half is resolved from
 * the EXPERIENCE of the request (see __current_site_id), so a portal caller is throttled
 * within the portal's tenant rather than collapsing every tenant onto the staff session's
 * site - which, in portal domain mode, is site 0 for everyone.
 *
 * The IDENTITY half is NOT realm-aware, and cannot be until a portal caller exists:
 * $user_id is documented as a LOGIN user id, and the portal realm has no login user - a
 * portal caller would pass a portal_users.id into the same key space, where it can collide
 * with a staff login user of the same numeric id. There is no portal caller today (the only
 * consumer in the repo is the staff notifications controller). Whoever writes the first one
 * decides how the two id spaces are kept apart - a realm-qualified action_key is the
 * cheapest answer - and that is an owner call, not something to guess at here.
 */
class Rsx_Throttle
{
    /**
     * Check if action should run and atomically mark as executed
     *
     * First-time calls return true and create record.
     * Subsequent calls return true only after interval has passed.
     *
     * Safe inside a transaction: the claim is a single statement and participates in the
     * caller's transaction (a rollback discards the claim along with the caller's work).
     *
     * @param string $action_key Unique action identifier (use SCREAMING_SNAKE_CASE)
     * @param int $user_id Login user ID (authentication identity)
     * @param int $minutes Throttle interval in minutes (default 30)
     * @return bool True if action should execute now, false if throttled
     */
    public static function check(string $action_key, int $user_id, int $minutes = 30): bool
    {
        $site_id = static::__current_site_id();

        // ONE atomic statement, serialized by uk_throttle_site_user_action. The row is claimed
        // only when the interval has elapsed; a concurrent caller either loses the unique-key
        // race (insert path) or sees the already-advanced timestamp (update path).
        //
        // The interval is bound, never interpolated, and both sides of the comparison come from
        // the DB clock - the stored value is written by NOW(3), so there is no PHP/DB skew.
        //
        // updated_at is left to the column's ON UPDATE CURRENT_TIMESTAMP(3): it fires only when
        // last_executed_at actually moves, which keeps the untouched case a true no-change row.
        //
        // Affected rows: 1 = inserted (first ever), 2 = updated (interval elapsed),
        // 0 = row left untouched (still throttled).
        $affected = DB::affectingStatement(
            'INSERT INTO _throttle (site_id, user_id, action_key, last_executed_at, created_at, updated_at)
             VALUES (?, ?, ?, NOW(3), NOW(3), NOW(3))
             ON DUPLICATE KEY UPDATE
                 last_executed_at = IF(last_executed_at < NOW(3) - INTERVAL ? MINUTE, NOW(3), last_executed_at)',
            [$site_id, $user_id, $action_key, $minutes]
        );

        return $affected > 0;
    }

    /**
     * Force reset throttle for an action
     *
     * Useful for testing or forcing immediate re-execution.
     *
     * @param string $action_key Action identifier to reset
     * @param int $user_id Login user ID
     */
    public static function reset(string $action_key, int $user_id): void
    {
        $site_id = static::__current_site_id();

        DB::table('_throttle')
            ->where('site_id', $site_id)
            ->where('user_id', $user_id)
            ->where('action_key', $action_key)
            ->delete();
    }

    /**
     * The tenant this throttle bucket belongs to, resolved from the EXPERIENCE being
     * served rather than from who is signed in (one browser session carries both
     * identities, so an identity test picks the wrong tenant in both directions).
     *
     * A portal request with no declared site throws - the same refusal the ORM's tenant
     * boundary makes, for the same reason.
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
}
