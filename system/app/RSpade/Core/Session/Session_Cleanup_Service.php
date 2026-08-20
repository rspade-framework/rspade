<?php

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Session_Cleanup_Service
 *
 * Hourly retention sweeps for the session concern's two tables: cleanup_sessions() expires
 * idle `_sessions` rows by type, cleanup_login_history() prunes `_login_history` past its
 * retention window. Separate tasks because they answer separate questions (inactivity vs age)
 * and either may be disabled without touching the other.
 *
 * For sessions this is the OPERATIONAL retention mechanism; Session::cleanup_expired()
 * is a blunt manual helper (single age cutoff, no type distinction) kept for
 * administrative/test use - see its docblock.
 *
 * A session expires by INACTIVITY. A BROWSER session (TYPE_WEB) is judged on whether
 * it carries an IDENTITY - either one, since one row serves both experiences - and
 * the machine types keep their own short backstops whatever they carry:
 *
 *   TYPE_WEB with login_user_id OR portal_user_id set
 *              rsx.sessions.web_timeout_minutes        (default 3 months)
 *   TYPE_WEB with neither
 *              rsx.sessions.anonymous_timeout_minutes  (default 30 days)
 *   TYPE_API   rsx.sessions.api_timeout_minutes        (default 7 days)
 *   TYPE_PLAYWRIGHT
 *              rsx.sessions.playwright_timeout_minutes (default 1 day)
 *   TYPE_CLI   rsx.sessions.cli_timeout_minutes        (default 1 day)
 *
 * The identity test spans BOTH columns because the row does: a browser signed into
 * the client portal and never into the staff app is a real, in-use session, and a
 * rule that only looked at login_user_id would collect it at the anonymous window.
 * There is no separate portal rule and no realm predicate - there is no realm.
 *
 * The type is read from the column - stamped by the writer at creation - never
 * sniffed out of the user-agent. A UA-matching sweep was what this replaced, and it
 * was both attacker-influenced and wrong about anything that did not announce itself.
 *
 * Deletes are CHUNKED (bounded DELETE ... LIMIT batches) so the first run against a
 * server with months of backlog never issues one giant lock-holding DELETE. Both tasks
 * are #[Exclusive]: a slow backlog-recovery run overlapping its own next tick would
 * re-scan the same range for no benefit.
 */
class Session_Cleanup_Service extends Rsx_Service_Abstract
{
    /**
     * Rows deleted per DELETE statement. Small enough to keep each statement's
     * lock hold time trivial, large enough that a 50k backlog clears in a few
     * statements. Overridable per run via $params['chunk_size'] (testing).
     */
    private const DELETE_CHUNK_SIZE = 10000;

    /**
     * Delete rows matching a base query in bounded chunks until none remain.
     * Heartbeats between chunks so a long backlog-recovery run keeps its worker
     * slot alive.
     *
     * @param callable $query_builder Returns a fresh base query (re-built per chunk;
     *                                a DELETE consumes the builder)
     * @param Task_Instance $task Task instance for heartbeats
     * @param int $chunk_size Rows per DELETE statement
     * @return int Total rows deleted
     */
    private static function _delete_chunked(callable $query_builder, Task_Instance $task, int $chunk_size): int
    {
        $total = 0;

        while (true) {
            $deleted = $query_builder()->limit($chunk_size)->delete();
            $total += $deleted;

            if ($deleted < $chunk_size) {
                break;
            }

            $task->heartbeat();
        }

        return $total;
    }

    /**
     * Expire sessions that have gone unused for longer than their type allows.
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return array Per-rule deletion counts
     */
    #[Task('Expire idle sessions by type (runs hourly)')]
    #[Exclusive]
    #[Schedule('hourly')]
    public static function cleanup_sessions(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $deleted = [];

        // Browser sessions that never carried ANY identity - in either experience.
        $deleted['anonymous'] = self::_delete_chunked(
            fn () => DB::table('_sessions')
                ->where('type_id', Session::TYPE_WEB)
                ->whereNull('login_user_id')
                ->whereNull('portal_user_id')
                ->where('last_active', '<', self::_cutoff('anonymous_timeout_minutes')),
            $task,
            $chunk_size
        );

        // Browser sessions that DID carry an identity, staff or portal.
        $deleted['web'] = self::_delete_chunked(
            fn () => DB::table('_sessions')
                ->where('type_id', Session::TYPE_WEB)
                ->where(function ($query) {
                    $query->whereNotNull('login_user_id')
                          ->orWhereNotNull('portal_user_id');
                })
                ->where('last_active', '<', self::_cutoff('web_timeout_minutes')),
            $task,
            $chunk_size
        );

        // Machine types: their own short backstops, whatever identity they carry.
        foreach ([
            'api' => Session::TYPE_API,
            'playwright' => Session::TYPE_PLAYWRIGHT,
            'cli' => Session::TYPE_CLI,
        ] as $name => $type_id) {
            $deleted[$name] = self::_delete_chunked(
                fn () => DB::table('_sessions')
                    ->where('type_id', $type_id)
                    ->where('last_active', '<', self::_cutoff($name . '_timeout_minutes')),
                $task,
                $chunk_size
            );
        }

        $total = array_sum($deleted);

        if ($total > 0) {
            foreach ($deleted as $name => $count) {
                if ($count > 0) {
                    $task->info("Deleted {$count} {$name} sessions");
                }
            }
            $task->info("Total sessions deleted: {$total}");
        }

        $deleted['total_deleted'] = $total;

        return $deleted;
    }

    /**
     * Prune aged rows from the login-history table.
     *
     * `_login_history` holds SUCCESSES only - failed attempts are ephemeral counters and never
     * become rows (see Login_History) - so it grows with real logins rather than with attack
     * volume. It still grows without bound over years, which is what this sweep answers.
     *
     * Chunked like the session sweep, and silent when there is nothing to delete.
     * rsx.sessions.login_history_retention_days at 0 or null disables the prune entirely.
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return array Deletion count
     */
    #[Task('Prune login history past its retention window (runs hourly)')]
    #[Exclusive]
    #[Schedule('hourly')]
    public static function cleanup_login_history(Task_Instance $task, array $params = [])
    {
        $retention_days = (int) config('rsx.sessions.login_history_retention_days');

        if ($retention_days <= 0) {
            return ['total_deleted' => 0];
        }

        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $cutoff = now()->subDays($retention_days);

        $deleted = self::_delete_chunked(
            fn () => DB::table('_login_history')->where('created_at', '<', $cutoff),
            $task,
            $chunk_size
        );

        if ($deleted > 0) {
            $task->info("Deleted {$deleted} login history rows older than {$retention_days} days");
        }

        return ['total_deleted' => $deleted];
    }

    /**
     * Resolve one rsx.sessions.* inactivity window to an absolute cutoff.
     *
     * @param string $config_key Key under rsx.sessions (e.g. 'web_timeout_minutes')
     * @return \Carbon\Carbon
     */
    private static function _cutoff(string $config_key)
    {
        $minutes = (int) config('rsx.sessions.' . $config_key);

        if ($minutes <= 0) {
            shouldnt_happen("rsx.sessions.{$config_key} must be a positive number of minutes");
        }

        return now()->subMinutes($minutes);
    }
}
