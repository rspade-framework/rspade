<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Flash;

use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Flash\Flash_Alert_Model;

/**
 * Flash_Alert - Server-side flash message system
 *
 * Provides a simple API for creating flash messages that will be delivered
 * to the client via page rendering or Ajax responses. Messages are stored
 * in the database and expire after 1 minute if not rendered.
 *
 * Usage:
 *   Flash_Alert::success('Account created successfully!');
 *   Flash_Alert::error('Invalid email address');
 *   Flash_Alert::info('Your session will expire in 5 minutes');
 *   Flash_Alert::warning('This action cannot be undone');
 *
 * Messages are automatically:
 * - Included in window.rsxapp.flash_alerts on page render
 * - Included in Ajax response.flash_alerts for Ajax calls
 * - Displayed via Flash_Alert JavaScript class on client
 * - Deleted after being retrieved for rendering
 * - Expired after 1 minute if not rendered
 *
 * SESSION + EXPERIENCE SCOPING
 * ----------------------------
 * A flash alert is queued against the BROWSER's session AND the EXPERIENCE that
 * queued it. There is one session per browser (one `rsx` cookie, one _sessions
 * row), shared by the staff app and the portal, so session_id answers only half
 * the question: it keeps one browser's alerts out of another browser's page, and
 * cannot say which experience the message was written for.
 *
 * The other half is the is_portal column, stamped on write from the REQUEST's own
 * experience (Rsx_Portal::is_portal_request() - the same signal @csrf, Auth_Gates
 * and the rsxapp payload use) and filtered on read. A portal page delivers only
 * portal-queued alerts, a staff page only staff-queued ones, and the per-session
 * cap is keyed per (session_id, is_portal) so one experience's runaway queue can
 * never evict the other's messages.
 *
 * CLI
 * ---
 * In console context nothing touches the database - no session is created, no row
 * is written, no row is read. The alert goes to STDERR, and warning/error levels
 * additionally reach the Laravel log. See _emit_cli().
 */
class Flash_Alert
{
    /**
     * Create a success flash message
     *
     * @param string $message The message to display
     * @return void
     */
    public static function success(string $message): void
    {
        static::_create_message($message, Flash_Alert_Model::TYPE_SUCCESS);
    }

    /**
     * Create an error flash message
     *
     * @param string $message The message to display
     * @return void
     */
    public static function error(string $message): void
    {
        static::_create_message($message, Flash_Alert_Model::TYPE_ERROR);
    }

    /**
     * Create an info flash message
     *
     * @param string $message The message to display
     * @return void
     */
    public static function info(string $message): void
    {
        static::_create_message($message, Flash_Alert_Model::TYPE_INFO);
    }

    /**
     * Create a warning flash message
     *
     * @param string $message The message to display
     * @return void
     */
    public static function warning(string $message): void
    {
        static::_create_message($message, Flash_Alert_Model::TYPE_WARNING);
    }

    /**
     * Get all pending flash messages for the current request's session AND
     * experience.
     *
     * Two predicates, because one browser has one session (one `rsx` cookie, one
     * row) shared by both experiences: the session id keeps another browser's
     * alerts out, and is_portal keeps the OTHER EXPERIENCE's alerts out. A staff
     * page never consumes a portal page's message, and vice versa.
     *
     * Returns array of messages and deletes them from database.
     * Also deletes any messages older than 1 minute (expired).
     *
     * @return array Array of ['type' => 'success'|'error'|'info'|'warning', 'message' => '...']
     */
    public static function get_pending_messages(): array
    {
        // CLI never writes a flash row (see _emit_cli), so there is nothing to read
        // and no reason to touch the database.
        if (app()->runningInConsole()) {
            return [];
        }

        $is_portal = Rsx_Portal::is_portal_request();

        // No session = no pending messages. has_session() is the question to ask
        // here: get_session_id() would CREATE a session for an anonymous visitor
        // who has never triggered activation. It resolves the `rsx` cookie and
        // creates nothing.
        $has_session = $is_portal ? Portal_Session::has_session() : Session::has_session();

        if (!$has_session) {
            return [];
        }

        $session_id = $is_portal ? Portal_Session::get_session_id() : Session::get_session_id();

        return static::_pending_for_session($session_id, $is_portal);
    }

    /**
     * The read itself, once the caller has resolved WHICH session and WHICH
     * experience is being read.
     *
     * Session ids are globally unique, so the pair is the whole scoping story: the
     * id cannot match another browser's rows, and is_portal cannot match the other
     * experience's rows on this same browser.
     *
     * The expiry sweep carries the same experience predicate deliberately - a staff
     * read has no business deleting the portal's rows, even stale ones. The hourly
     * retention task (Flash_Alert_Cleanup_Service) is the age-based, experience-blind
     * sweep that reaches everything.
     *
     * Expired alerts (older than 1 minute) are swept first, then the whole remaining
     * set is returned and deleted - the read is one-time delivery, and it returns
     * EVERYTHING that exists (the cap that keeps the set bounded lives on the writer,
     * see _enforce_session_cap).
     *
     * @param int $session_id
     * @param bool $is_portal The experience being read - portal alerts are invisible
     *                        to a staff read on the same session, and vice versa
     * @return array Array of ['type' => ..., 'message' => ...]
     */
    protected static function _pending_for_session(int $session_id, bool $is_portal): array
    {
        // Delete expired messages (older than 1 minute)
        Flash_Alert_Model::where('session_id', $session_id)
            ->where('is_portal', $is_portal)
            ->where('created_at', '<', now()->subMinute())
            ->delete();

        // Get all pending flash alerts for this session + experience
        $alerts = Flash_Alert_Model::where('session_id', $session_id)
            ->where('is_portal', $is_portal)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($alerts->isEmpty()) {
            return [];
        }

        // Delete the alerts now that we're retrieving them
        Flash_Alert_Model::where('session_id', $session_id)
            ->where('is_portal', $is_portal)
            ->delete();

        // Convert to client format
        $messages = [];
        foreach ($alerts as $alert) {
            $messages[] = [
                'type' => $alert->get_type_string(),
                'message' => $alert->message,
            ];
        }

        return $messages;
    }

    /**
     * Create a flash message in the database
     *
     * @param string $message The message text
     * @param int $type_id The type ID (use Flash_Alert_Model constants)
     * @return void
     */
    protected static function _create_message(string $message, int $type_id): void
    {
        // Console context: print, never persist. This is DELIBERATE POLICY, not a
        // limitation - a CLI process CAN mint a real _sessions row on demand
        // (Session::__activate_cli) and flash still refuses to use one. A flash alert
        // is a message for a browser that is about to render; a command has an operator
        // reading its output right now, and that terminal is the honest delivery
        // channel. Owner ruling 2026-08-09.
        if (app()->runningInConsole()) {
            static::_emit_cli($message, $type_id);

            return;
        }

        $is_portal = Rsx_Portal::is_portal_request();
        $session_id = static::_resolve_session_id();

        $flash_alert = new Flash_Alert_Model();
        $flash_alert->session_id = $session_id;
        $flash_alert->is_portal = $is_portal;
        $flash_alert->type_id = $type_id;
        $flash_alert->message = $message;
        $flash_alert->created_at = now();
        $flash_alert->save();

        static::_enforce_session_cap($session_id, $is_portal);
    }

    /**
     * Resolve the session id this request's alerts belong to, creating the session
     * if the visitor does not have one yet (queueing an alert is exactly the moment
     * a session becomes necessary - the browser has to come back for it).
     *
     * The experience is the REQUEST's, never a guess: Rsx_Portal::is_portal_request()
     * is the same idiom Auth_Gates and the bundle's rsxapp payload use. With one
     * session per browser both branches resolve the same row today; the fork stays
     * because the portal facade is where the portal's own site contract lives.
     *
     * The portal facade needs a site to create a session with, and refuses to invent
     * one: it uses the site the APPLICATION declared (Portal_Session::set_site_id,
     * normally in Portal_Main::init), and throws when the application declared none.
     * That throw is the portal's contract and belongs to the portal - silently
     * dropping the alert here would hide a misconfigured portal instead.
     *
     * @return int
     */
    protected static function _resolve_session_id(): int
    {
        if (Rsx_Portal::is_portal_request()) {
            return Portal_Session::get_session_id();
        }

        return Session::get_session_id();
    }

    /**
     * Deliver an alert raised in console context.
     *
     * STDERR, because STDOUT is a command's RESULT and a flash alert is commentary
     * alongside it - a command whose output is piped somewhere keeps that pipe clean.
     * Warning and error additionally reach the Laravel log: those two levels describe
     * something that went wrong, and a scheduled task's terminal output is nobody's
     * inbox. Success and info are terminal-only - logging every "Saved!" from a
     * seeder would be noise.
     *
     * @param string $message
     * @param int $type_id Flash_Alert_Model::TYPE_*
     * @return void
     */
    protected static function _emit_cli(string $message, int $type_id): void
    {
        fwrite(STDERR, static::_format_cli_line($message, $type_id) . "\n");

        if ($type_id === Flash_Alert_Model::TYPE_ERROR) {
            Log::error($message);
        } elseif ($type_id === Flash_Alert_Model::TYPE_WARNING) {
            Log::warning($message);
        }
    }

    /**
     * Format one console line: "[FLASH:ERROR] message". ASCII only, and no color -
     * a redirected STDERR is a plain text file, not a terminal.
     *
     * @param string $message
     * @param int $type_id Flash_Alert_Model::TYPE_*
     * @return string
     */
    protected static function _format_cli_line(string $message, int $type_id): string
    {
        $labels = Flash_Alert_Model::type_id__enum_labels();
        $label = strtoupper($labels[$type_id] ?? 'INFO');

        return '[FLASH:' . $label . '] ' . $message;
    }

    /**
     * Keep one session's pending alerts under the configured cap.
     *
     * The cap lives on the WRITER, deliberately. get_pending_messages() hands the whole
     * set to the browser and deletes it in the same breath, so a LIMIT on the READ would
     * silently drop alerts the user was supposed to see - the exact truncation the
     * framework forbids. Bounding what can be queued instead keeps the read honest: it
     * still returns everything that exists.
     *
     * Keyed on the (session_id, is_portal) pair the writer already resolved, so it is
     * session- AND experience-correct by construction. The experience half is not
     * decoration: the cap is an eviction policy, and a shared cap would let a portal
     * page that queues 50 alerts silently evict the staff alerts sitting on the same
     * browser session (and vice versa). Each experience is capped against its own rows.
     *
     * Only reachable in a web request (the caller prints and returns in console context),
     * and only trims when a single session has genuinely run away - a request that queues
     * a handful of alerts never issues the DELETE.
     *
     * Set rsx.flash.max_alerts_per_session to 0 or null to disable.
     *
     * @param int $session_id
     * @param bool $is_portal The experience whose queue is being capped
     * @return void
     */
    protected static function _enforce_session_cap(int $session_id, bool $is_portal): void
    {
        $max = config('rsx.flash.max_alerts_per_session');

        if (empty($max)) {
            return;
        }

        $pending = Flash_Alert_Model::where('session_id', $session_id)
            ->where('is_portal', $is_portal)
            ->count();

        if ($pending <= $max) {
            return;
        }

        // Drop the OLDEST overflow: the newest alerts are the ones describing what the
        // user just did, and are the ones worth keeping.
        $keep = Flash_Alert_Model::where('session_id', $session_id)
            ->where('is_portal', $is_portal)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit((int) $max)
            ->pluck('id')
            ->all();

        Flash_Alert_Model::where('session_id', $session_id)
            ->where('is_portal', $is_portal)
            ->whereNotIn('id', $keep)
            ->raw_bulk()
            ->delete();
    }
}
