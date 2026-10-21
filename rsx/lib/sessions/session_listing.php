<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace Rsx\Lib\Sessions;

/**
 * Session_Listing - this application's convention for showing "where you're signed in".
 *
 * The framework answers the list - Session::get_sessions_for_user() (staff) and
 * Portal_Session::get_sessions_for_user() (portal): one entry per browser x site (x
 * impersonator) the account signed in on, signed in or recently signed out, most recently
 * active first. Every screen here that shows one passes it through this class, so they all
 * cut it the same way and ship the same row:
 *
 *   - recent() keeps the entries active within the last LISTED_WITHIN_MONTHS months. A
 *     signed-out entry already leaves the framework's list after
 *     rsx.sessions.unused_session_days; the cut reaches the rest - a sign-in with no idle
 *     limit, and an API key's use (an entry for as long as the key lives) - which nobody
 *     needs to review once a quarter has passed without activity.
 *   - row() is the one payload shape, and decides whether the screen offers "Sign out":
 *     only on a SIGNED-IN entry that is not the browser reading the page (signing yourself
 *     out is the sign-out link's job) and is not an API key's use (that ends when the key is
 *     revoked).
 *   - find() is the record rule for an endpoint handed an id: the id must be one of the
 *     entries the screen lists, or it is not found.
 *
 * Worked examples: Frontend_Settings_User_Management_Controller (an administrator's view of
 * one user on this site), Frontend_Settings_Password_Security_Controller (your own) and
 * Portal_Settings_Controller (a portal user's own).
 */
class Session_Listing
{
    /** Entries active longer ago than this many months are not listed. */
    const LISTED_WITHIN_MONTHS = 3;

    /**
     * The entries active within LISTED_WITHIN_MONTHS, in the order given.
     *
     * @param array $sessions get_sessions_for_user() entries
     * @return array
     */
    public static function recent(array $sessions): array
    {
        $since = now()->subMonths(self::LISTED_WITHIN_MONTHS)->getTimestamp();

        return array_values(array_filter(
            $sessions,
            fn ($session) => $session['last_active'] !== null && strtotime($session['last_active']) >= $since
        ));
    }

    /**
     * The listed entry with this id, or null when the id is not one of them.
     *
     * @param array $sessions recent() entries
     * @param mixed $user_session_id A request param
     * @return array|null
     */
    public static function find(array $sessions, $user_session_id): ?array
    {
        if (!is_numeric($user_session_id) || (string) (int) $user_session_id !== (string) $user_session_id) {
            return null;
        }

        foreach ($sessions as $session) {
            if ($session['id'] === (int) $user_session_id) {
                return $session;
            }
        }

        return null;
    }

    /**
     * One entry as these screens ship it. Datetimes are ISO strings, formatted in the
     * browser.
     *
     * @param array $session A get_sessions_for_user() entry
     * @return array {id, device_summary, user_agent, ip_address, first_seen, signed_in_at,
     *               last_active, is_signed_in, signed_out_at, sign_out_reason, sign_in_count,
     *               is_current, is_impersonation, is_api, can_sign_out}
     */
    public static function row(array $session): array
    {
        return [
            'id' => $session['id'],
            'device_summary' => $session['device_summary'],
            'user_agent' => $session['user_agent'],
            'ip_address' => $session['ip_address'],
            'first_seen' => $session['first_seen'],
            'signed_in_at' => $session['signed_in_at'],
            'last_active' => $session['last_active'],
            'is_signed_in' => $session['is_signed_in'],
            'signed_out_at' => $session['signed_out_at'],
            'sign_out_reason' => $session['sign_out_reason'],
            'sign_in_count' => $session['sign_in_count'],
            'is_current' => $session['is_current'],
            'is_impersonation' => $session['is_impersonation'],
            'is_api' => $session['is_api'],
            'can_sign_out' => $session['is_signed_in'] && !$session['is_api'] && !$session['is_current'],
        ];
    }

    /**
     * recent() then row() - the list a screen shows.
     *
     * @param array $sessions get_sessions_for_user() entries
     * @return array
     */
    public static function rows(array $sessions): array
    {
        return array_map(fn ($session) => self::row($session), self::recent($sessions));
    }
}
