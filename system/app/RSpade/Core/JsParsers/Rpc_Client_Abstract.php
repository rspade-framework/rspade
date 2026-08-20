<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\JsParsers;

use Symfony\Component\Process\Process;
use App\RSpade\Core\JsParsers\Rpc_Startup_Diagnostics;
use App\RSpade\Core\Locks\RsxLocks;

/**
 * The ONE lifecycle for every node RPC helper client.
 *
 * SIX helpers (js-parser, js-transformer, minify, jqhtml-compile, js-sanitizer,
 * js-code-quality) each hand-rolled the same spawn / ping / ready-wait / stop, and the six
 * copies had drifted into four separate divergences - two of them defects:
 *
 *   1. The lock-fd guard (RsxLocks::command_without_inherited_locks) was applied in four
 *      copies and MISSING in two (Minifier, JqhtmlWebpackCompiler). A daemon that inherits
 *      an flock descriptor holds that lock for its entire idle life, and because
 *      Manifest::init() locks at boot, that wedges the whole CLI. It is applied HERE, once,
 *      so no future helper can be written without it.
 *   2. Three copies pinged an existing socket and reused the daemon behind it; three
 *      unconditionally killed and respawned on any existing socket (a dev-freshness hack).
 *      The killer was also an orphan FACTORY: its "force stop" was a message sent over the
 *      socket, which by definition can never reach a daemon still holding a socket inode
 *      that was already unlinked out from under it.
 *   3. Three published the process handle before the readiness wait, so one slow boot left
 *      a non-null handle behind and every later caller skipped the spawn entirely (the
 *      Field incident: one slow start, six failed bundles - see Rpc_Startup_Diagnostics).
 *   4. Two resolved their storage paths through a private helper carrying a hardcoded
 *      /var/www/html path.
 *
 * This class is LIFECYCLE ONLY. Every consumer keeps its own request marshaling, its own
 * protocol quirks and its own caches; it declares three constants and calls
 * ensure_rpc_server() before it talks to its daemon.
 *
 * FRESHNESS (what replaces both the ping-first reuse and the kill-always hack). At spawn the
 * client writes `<socket>.meta` holding the sha1 of the server script it launched. A daemon
 * is reused only when the socket exists AND that hash still matches the script on disk AND
 * the daemon answers a ping. So an unchanged script reuses (what ping-first was for), an
 * edited script respawns (what kill-always was for), and a framework pull that ships a new
 * server script invalidates every box's daemon AT SOURCE rather than relying on someone
 * remembering to reap. `.meta` lives inside rsx-tmp on purpose: rsx:clean wipes it, and a
 * missing meta reads as "unknown provenance" - which forces a fresh spawn.
 *
 * SELF-HEAL is the only teardown that reaches an orphan. A daemon whose socket file was
 * unlinked (by rsx:clean, by a newer daemon rebinding the path, by a storage relocation)
 * cannot be reached by any socket message ever again, but it still shows up in
 * `pgrep -f -- '--socket=<path>'`, because the absolute socket path is in its argv and never
 * changes. That is the handle used here, and the handle the shell twins use
 * (bin/environment_updates/030_relocate_storage.sh, bin/maintenance-mode.sh).
 *
 * PROCESS LIFETIME, as MEASURED (2026-08-13) rather than assumed - the two contexts differ:
 *
 *   - CLI: Symfony's Process destructor calls stop(0), so a daemon spawned by an artisan run
 *     is killed when that process exits. Verified directly.
 *   - php-fpm: the worker outlives the request, and a daemon spawned during one observably
 *     keeps serving later requests (three consecutive rsx:debug renders reused the same two
 *     pids). This is the case the reuse path is for, and why a stale daemon must be detected
 *     by PROVENANCE rather than trusted because it answers.
 *
 * Orphans come from the ABRUPT deaths neither path covers - a SIGKILLed worker, an
 * interrupted build - which is what the ten-deep live pile on this box turned out to be.
 * Nothing here registers a shutdown function: on CLI the destructor already reaps what we
 * spawned, and a socket file left behind by a killed daemon is detected and removed by the
 * next ensure_rpc_server().
 *
 * @see Rpc_Startup_Diagnostics for what a helper says when it will not come up.
 */
abstract class Rpc_Client_Abstract
{
    /**
     * Node entry script, relative to base_path(). Declared by each child.
     */
    protected const RPC_SERVER_SCRIPT = '';

    /**
     * Unix socket, as a PROJECT-relative 'storage/...' path. Declared by each child.
     */
    protected const RPC_SOCKET = '';

    /**
     * Human name used in diagnostics ("JS Transformer"). Declared by each child.
     */
    protected const RPC_LABEL = '';

    /**
     * Live daemon handles, keyed by child class.
     *
     * The six consumers are static classes, so a single inherited property would be ONE
     * slot shared by all of them - every family would clobber the others. Keyed arrays on
     * the base keep the state per family without asking each child to redeclare anything.
     *
     * @var array<string, Process|null>
     */
    private static array $processes = [];

    /**
     * Families whose daemon this process has already proven to be serving.
     *
     * @var array<string, bool>
     */
    private static array $ensured = [];

    /**
     * Request id counter for lifecycle traffic (ping / shutdown). Deliberately separate
     * from each child's request counter, which belongs to its own protocol.
     */
    private static int $lifecycle_request_id = 0;

    /**
     * Guarantee this family's daemon is running, current, and answering.
     *
     * Reuses a live daemon whose provenance still matches the server script on disk;
     * otherwise collects whatever holds the socket (including an unreachable orphan) and
     * spawns a fresh one.
     *
     * @throws \RuntimeException when the daemon does not answer within the configured budget
     */
    public static function ensure_rpc_server(): void
    {
        $class = static::class;

        if (!empty(self::$ensured[$class])) {
            return;
        }

        $socket_path = static::_rpc_socket_path();
        $server_script = static::_rpc_server_script_path();
        $label = static::_rpc_label();

        if (!file_exists($server_script)) {
            throw new \RuntimeException("{$label} RPC server script not found at {$server_script}");
        }

        // Reuse: a socket, spawned from THIS script, with something alive behind it.
        if (
            file_exists($socket_path)
            && self::__meta_matches($socket_path, $server_script)
            && static::ping_rpc_server()
        ) {
            self::$ensured[$class] = true;
            console_debug('RPC', $label . ': reusing the daemon already serving ' . $socket_path);

            return;
        }

        // Anything else holding this socket is stale, wedged, or orphaned. Collect it.
        static::__self_heal_kill();

        $socket_dir = dirname($socket_path);
        if (!is_dir($socket_dir)) {
            mkdir($socket_dir, 0755, true);
        }

        console_debug('RPC', $label . ': starting ' . $server_script);

        // The lock fds MUST NOT reach this daemon. It can outlive the process that spawns it,
        // and a POSIX flock lives on the open file description - so an inherited lock fd
        // would be held for as long as the node process idles, wedging every future build and
        // (because Manifest::init() locks at boot) the whole CLI with it. See
        // RsxLocks::inherited_lock_fds() for the measured incident. Applied here for EVERY
        // helper: two of the six spawn sites were missing it.
        $process = new Process(RsxLocks::command_without_inherited_locks([
            'node',
            $server_script,
            '--socket=' . $socket_path,
        ]));

        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null); // A daemon runs for as long as it is useful.
        $process->start();

        // Record what this socket was spawned from, so a later process can tell whether the
        // daemon behind it is running the code currently on disk.
        self::__write_meta($socket_path, $server_script);

        $max_wait_ms = (int) config('rsx.javascript.rpc_server_ready_wait_ms', 10000);
        $wait_interval_ms = 50;
        $iterations = (int) ($max_wait_ms / $wait_interval_ms);

        for ($i = 0; $i < $iterations; $i++) {
            usleep($wait_interval_ms * 1000);

            if (static::ping_rpc_server()) {
                // Published only once the daemon is SERVING. Publishing before the wait meant
                // a failed start left a non-null handle, so every later caller saw "already
                // started", skipped the spawn, and died at "socket not found" instead
                // (field report, 2026-08-11: one slow boot, six failed bundles).
                self::$processes[$class] = $process;
                self::$ensured[$class] = true;
                console_debug('RPC', $label . ': ready after ' . ($i * $wait_interval_ms) . 'ms');

                return;
            }
        }

        // Build the diagnosis BEFORE killing anything: whether the process is still RUNNING
        // (slow) or has EXITED (with its own stderr) is the single most useful fact in the
        // message, and stopping it first would report every slow start as a crash.
        $message = Rpc_Startup_Diagnostics::failure_message(
            $label,
            $socket_path,
            $server_script,
            $max_wait_ms,
            $process
        );

        // A failed start must leave NO trace - no handle, no half-written meta, no socket -
        // so the next use is a clean retry.
        $process->stop(0);
        static::__self_heal_kill();

        throw new \RuntimeException($message);
    }

    /**
     * Is this family's daemon answering?
     */
    public static function ping_rpc_server(): bool
    {
        $socket_path = static::_rpc_socket_path();

        if (!file_exists($socket_path)) {
            return false;
        }

        try {
            $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 1);
            if (!$socket) {
                return false;
            }

            stream_set_blocking($socket, true);

            self::$lifecycle_request_id++;
            fwrite($socket, json_encode([
                'id' => self::$lifecycle_request_id,
                'method' => 'ping',
            ]) . "\n");

            $response = fgets($socket);
            fclose($socket);

            if (!$response) {
                return false;
            }

            $result = json_decode($response, true);

            return isset($result['result']) && $result['result'] === 'pong';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Stop this family's daemon, and mean it.
     *
     * Asks politely first (the socket `shutdown` method), then reaps by pid - because the
     * polite half cannot reach a daemon whose socket inode was unlinked, which is precisely
     * the daemon most in need of stopping. $force skips the polite half.
     */
    public static function stop_rpc_server(bool $force = false): void
    {
        if (!$force) {
            static::__send_shutdown();
        }

        static::__self_heal_kill();
    }

    /**
     * The daemon handle this process published for this family, or null.
     *
     * Exists so a caller (the lifecycle tests) can assert what was published without
     * reaching into private state. Null means "this process is not the one that spawned the
     * daemon", which is a normal state - it says nothing about whether one is running.
     */
    public static function get_rpc_process(): ?Process
    {
        return self::$processes[static::class] ?? null;
    }

    /**
     * Kill EVERY node helper daemon bound to a socket under storage/rsx-tmp, whatever
     * family it belongs to, and return how many were reaped.
     *
     * The PHP twin of the bash reaper in bin/environment_updates/030_relocate_storage.sh and
     * the one in bin/maintenance-mode.sh. It exists because of the rule in bin/CLAUDE.md:
     * any framework operation that changes a socket or state directory must reap the helpers
     * bound to the previous one. rsx:clean is that operation - it unlinks every socket in
     * rsx-tmp, which strands every daemon behind them permanently.
     *
     * The match is on argv, not on family: the socket path a daemon was launched with is in
     * its command line and never changes, so this catches all six helper families, the SSR
     * server, duplicates of any of them, and any helper added later - without knowing they
     * exist. Killing them is free: they hold no state and are respawned on demand by the
     * next process that needs one.
     */
    public static function quiesce_all(): int
    {
        $socket_dir = rtrim(storage_path('rsx-tmp'), '/');

        $pids = self::__pgrep_node_daemons('--socket=' . $socket_dir . '/');
        $killed = self::__term_then_kill($pids);

        // Whatever this process knew about a running daemon is no longer true.
        self::$processes = [];
        self::$ensured = [];

        return $killed;
    }

    /**
     * Absolute path of this family's unix socket.
     */
    protected static function _rpc_socket_path(): string
    {
        return rsx_project_file_path(self::__require_const(static::RPC_SOCKET, 'RPC_SOCKET'));
    }

    /**
     * Absolute path of this family's node entry script.
     */
    protected static function _rpc_server_script_path(): string
    {
        return base_path(self::__require_const(static::RPC_SERVER_SCRIPT, 'RPC_SERVER_SCRIPT'));
    }

    /**
     * Human name for diagnostics.
     */
    protected static function _rpc_label(): string
    {
        return self::__require_const(static::RPC_LABEL, 'RPC_LABEL');
    }

    /**
     * TERM, one settle pass, KILL - then remove the socket and its provenance file.
     *
     * Narrow by construction: it matches only a node process whose command line names THIS
     * family's absolute socket path, so a daemon of another family, or one already rebound
     * to a different storage root, is untouched.
     */
    private static function __self_heal_kill(): void
    {
        $socket_path = static::_rpc_socket_path();

        $pids = self::__pgrep_node_daemons('--socket=' . $socket_path);
        $killed = self::__term_then_kill($pids);

        if ($killed > 0) {
            console_debug('RPC', static::_rpc_label() . ': collected ' . $killed . ' stale daemon(s)');
        }

        @unlink($socket_path);
        @unlink(self::__meta_path($socket_path));

        self::$processes[static::class] = null;
        self::$ensured[static::class] = false;
    }

    /**
     * Ask a daemon to shut itself down over its own socket. Best effort by nature: the
     * message cannot reach a daemon whose socket was already unlinked, which is why every
     * caller follows it with the pid-based reap.
     */
    private static function __send_shutdown(): void
    {
        $socket_path = static::_rpc_socket_path();

        if (!file_exists($socket_path)) {
            return;
        }

        try {
            $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 1);
            if (!$socket) {
                return;
            }

            stream_set_blocking($socket, true);

            self::$lifecycle_request_id++;
            fwrite($socket, json_encode([
                'id' => self::$lifecycle_request_id,
                'method' => 'shutdown',
            ]) . "\n");

            fclose($socket);
        } catch (\Exception $e) {
            // A daemon that cannot be asked will be taken.
        }
    }

    /**
     * Node helper daemons whose command line contains $pattern.
     *
     * `pgrep -a` gives us the command line as well as the pid, which is what lets this
     * exclude the shell that is running the pgrep itself - that shell's own command line
     * necessarily contains the pattern we are searching for.
     *
     * @return int[]
     */
    private static function __pgrep_node_daemons(string $pattern): array
    {
        $output = [];
        $return_var = 0;

        // pgrep exits 1 when nothing matched, which is not an error here.
        exec_safe('pgrep -a -f -- ' . escapeshellarg($pattern), $output, $return_var);

        $pids = [];

        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode(' ', $line, 2);
            $pid = (int) $parts[0];
            $command_line = $parts[1] ?? '';

            if ($pid <= 0 || $pid === getmypid()) {
                continue;
            }

            // The pgrep call runs through a shell whose own command line carries the pattern.
            if (str_contains($command_line, 'pgrep')) {
                continue;
            }

            // These daemons are node processes; nothing else in rsx-tmp is ours to kill.
            if (!str_contains($command_line, 'node ')) {
                continue;
            }

            $pids[] = $pid;
        }

        return $pids;
    }

    /**
     * The reaper pattern (see bin/environment_updates/030_relocate_storage.sh): SIGTERM,
     * ONE settle pass, SIGKILL to whatever is left.
     *
     * The settle is a fixed pause before escalating - bounded local work, not a wait on an
     * external party, and never a deadline imposed on an operation that is making progress.
     * A survivor is a wedged daemon on a socket nobody can reach; there is nothing in it
     * worth preserving.
     *
     * @param  int[] $pids
     * @return int   How many processes were signalled
     */
    private static function __term_then_kill(array $pids): int
    {
        if (!$pids) {
            return 0;
        }

        foreach ($pids as $pid) {
            @posix_kill($pid, SIGTERM);
        }

        usleep(200000); // 200ms settle: these are cooperative servers that close on SIGTERM.

        foreach ($pids as $pid) {
            if (@posix_kill($pid, 0)) {
                @posix_kill($pid, SIGKILL);
            }
        }

        return count($pids);
    }

    /**
     * Provenance sidecar for a socket.
     */
    private static function __meta_path(string $socket_path): string
    {
        return $socket_path . '.meta';
    }

    /**
     * Record which server script this socket was spawned from.
     */
    private static function __write_meta(string $socket_path, string $server_script): void
    {
        $hash = @sha1_file($server_script);

        if ($hash === false) {
            return;
        }

        file_put_contents_safe(self::__meta_path($socket_path), $hash);
    }

    /**
     * Was the daemon behind this socket spawned from the script currently on disk?
     *
     * A missing meta file is "unknown provenance" and therefore NOT a match: an rsx:clean
     * wipes rsx-tmp, and the correct answer after that is a fresh spawn.
     */
    private static function __meta_matches(string $socket_path, string $server_script): bool
    {
        $meta_path = self::__meta_path($socket_path);

        if (!file_exists($meta_path)) {
            return false;
        }

        $recorded = trim((string) file_get_contents($meta_path));
        $current = @sha1_file($server_script);

        return $recorded !== '' && $current !== false && $recorded === $current;
    }

    /**
     * A child that forgot one of the three declarations must say so, at the point of use.
     */
    private static function __require_const(string $value, string $name): string
    {
        if ($value === '') {
            throw new \RuntimeException(
                static::class . ' must declare ' . $name . ' - every Rpc_Client_Abstract child '
                . 'declares RPC_SERVER_SCRIPT, RPC_SOCKET and RPC_LABEL.'
            );
        }

        return $value;
    }
}
