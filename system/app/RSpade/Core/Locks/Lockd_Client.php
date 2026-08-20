<?php

namespace App\RSpade\Core\Locks;

use RuntimeException;
use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Framework\Framework_Maintenance;

/**
 * Socket transport for rsx-lockd (system/bin/rsx-lockd), the lock daemon whose CONNECTION
 * IS THE LOCK. This class owns bytes and framing only - every lock semantic lives in
 * RsxLocks above it and in the daemon below it.
 *
 * THE CONNECTION MODEL - one connection per SCRIPT EXECUTION, not per process:
 *
 *   Opened lazily on the first cluster-lock request, every lock multiplexed over it, held
 *   open for the rest of the script even while holding nothing, closed at the end. It must
 *   NOT be persistent across requests: a PHP-FPM worker serves thousands of requests, and a
 *   socket that outlived a request would carry that request's locks into the next one on
 *   the same worker - and the daemon would be right to keep holding them, because from its
 *   side the holder never went away.
 *
 *   The shutdown handler sends `release_all` and closes, but correctness does NOT depend on
 *   that frame arriving: closing the socket releases everything by itself, which is exactly
 *   why a `kill -9`'d holder frees its locks instantly.
 *
 * WAITING IS SILENCE. A blocking acquire receives NO frame until it is granted or fails, so
 * this client must be willing to block on the socket for as long as the caller is willing
 * to wait for the lock - a month, if that is what the critical section ahead of it takes.
 * There is therefore NO read timeout anywhere here: reads block on stream_select() with a
 * NULL timeout, and only a granted/failed frame or the socket dying ends the wait.
 *
 * Responses can arrive OUT OF ORDER (a parked acquire is answered long after a later ping),
 * so every request carries an `id` and the reader correlates on it, parking any frame that
 * belongs to a different request until its own requester asks for it.
 */
class Lockd_Client
{
    /**
     * Endpoint defaults. Deliberately duplicated from the daemon's shipped lockd.conf: this
     * client must work before config('rsx.locks') exists on a given box, and a wrong port is
     * a loud connect failure rather than a silent misbehavior.
     */
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 6210;

    /** The common failure is a daemon that is restarting, so a connect is worth retrying. */
    private const DEFAULT_CONNECT_RETRIES = 3;
    private const DEFAULT_CONNECT_RETRY_DELAY_MS = 1000;

    /** Applies to establishing the TCP connection only - never to reading an answer. */
    private const CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * The internal flag a parent uses to hand its lock group to a synchronous subprocess.
     * Rsx_Artisan attaches it; nothing else should. See current_group_id().
     */
    public const LOCK_GROUP_FLAG = '--_lock-group';

    /** @var resource|null The one socket for this script execution. */
    private static $socket = null;

    /** Bytes read from the socket that do not yet form a complete line. */
    private static string $read_buffer = '';

    /** Monotonic per-script request id source (correlates responses). */
    private static int $request_seq = 0;

    /** Frames that arrived while a DIFFERENT request was being awaited, keyed by their id. */
    private static array $parked_frames = [];

    /** Test/tooling seam - see _use_endpoint(). */
    private static ?array $endpoint_override = null;

    private static bool $shutdown_registered = false;

    /**
     * Point this client at a specific daemon for the rest of the process, overriding config.
     * The seam a test (or a tool driving a scratch daemon on another port) uses; production
     * code never calls it. Closes any existing connection so the next request redials.
     */
    public static function _use_endpoint(string $host, int $port): void
    {
        self::close();
        self::$endpoint_override = ['host' => $host, 'port' => $port];
    }

    /** True when a socket to the daemon is currently open. */
    public static function is_connected(): bool
    {
        return is_resource(self::$socket);
    }

    /**
     * rsx:health probe: is the lock daemon listening? A public static
     * `#[Health_Check('label')]` (bare marker attribute - never a defined class). It opens
     * (and immediately closes) a TCP connection to the configured endpoint - a read-only
     * liveness probe that says no hello and therefore needs no key.
     *
     * A down daemon is a FAIL, not a WARN: there is no degraded mode. Outside maintenance
     * every cluster lock throws, which means a site write lock throws, which means the app
     * is down. The ONE exception is a raised maintenance flag, where the daemon is stopped
     * on purpose and cluster locks degrade to flock - that reports INFO, because a health
     * check that screams about a service you deliberately turned off is noise.
     *
     * The maintenance test reads the flag off DISK rather than the per-process snapshot:
     * this is a probe reporting the state of the box right now, not a consumer that needs
     * a stable answer for the life of the process.
     *
     * @return array
     */
    #[Health_Check('Lock Server')]
    public static function lock_server(): array
    {
        $endpoint = self::__endpoint();

        if (Framework_Maintenance::is_active_on_disk()) {
            return [
                'status' => 'INFO',
                'detail' => 'the maintenance flag is up - rsx-lockd is stopped on purpose'
                    . ' (cluster locks degraded to flock)',
            ];
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($endpoint['host'], $endpoint['port'], $errno, $errstr, 2);

        if ($socket === false) {
            return [
                'status' => 'FAIL',
                'detail' => 'rsx-lockd not listening on ' . $endpoint['host'] . ':' . $endpoint['port']
                    . ' (' . trim($errstr) . ') - every cluster lock will throw',
                'remediation' => 'start the lock daemon - check supervisor [program:rsx-lockd]'
                    . ' (bash system/bin/rsx-lockd/lockd-run.sh)',
            ];
        }

        // A listening port is not a serving daemon: a wedged event loop, or anything else
        // squatting the port, accepts the connection and then answers nothing. `ping` is
        // answered PRE-HELLO precisely so a health probe can prove the daemon is serving
        // frames without holding APP_KEY, so ask it rather than trusting the accept.
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, 2);
        @fwrite($socket, json_encode(['id' => 1, 'op' => 'ping']) . "\n");
        $line = @fgets($socket);
        $timed_out = (bool) (stream_get_meta_data($socket)['timed_out'] ?? false);
        fclose($socket);

        $answered = is_string($line) && str_contains($line, '"status":"ok"');

        if (!$answered) {
            return [
                'status' => 'FAIL',
                'detail' => 'rsx-lockd holds ' . $endpoint['host'] . ':' . $endpoint['port']
                    . ' but did not answer a ping'
                    . ($timed_out ? ' within 2s' : '')
                    . ' - it is wedged, or something else owns the port',
                'remediation' => 'restart the lock daemon - supervisorctl restart rsx-lockd'
                    . ' (check /var/log/supervisor/rsx-lockd-error.log first)',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => 'rsx-lockd answering on ' . $endpoint['host'] . ':' . $endpoint['port'],
        ];
    }

    /**
     * Send one request frame and return the response frame that answers it.
     *
     * Connects (and says hello) on first use. Blocks for as long as the daemon takes to
     * answer - which for a parked acquire is "until the lock is available".
     *
     * @throws RuntimeException on connect failure, socket death, or a protocol violation.
     */
    public static function request(array $frame): array
    {
        if (!is_resource(self::$socket)) {
            self::__connect();
        }

        self::$request_seq++;
        $id = 'p' . getmypid() . '-' . self::$request_seq;
        $frame['id'] = $id;

        self::__write($frame);

        return self::__await($id);
    }

    /**
     * Release everything and close. Safe to call when never connected.
     *
     * The release_all frame is a courtesy that makes the daemon's dump tidy a few
     * milliseconds earlier; the close on the next line is what actually releases.
     */
    public static function close(): void
    {
        if (!is_resource(self::$socket)) {
            self::$socket = null;
            self::$read_buffer = '';
            self::$parked_frames = [];

            return;
        }

        try {
            self::$request_seq++;
            $id = 'p' . getmypid() . '-' . self::$request_seq;
            self::__write(['op' => 'release_all', 'id' => $id]);
            self::__await($id);
        } catch (\Throwable $e) {
            // The socket is going away regardless, and its closure releases everything.
        }

        @fclose(self::$socket);
        self::$socket = null;
        self::$read_buffer = '';
        self::$parked_frames = [];
    }

    /** Shutdown handler: end of script = end of the connection = end of every lock it held. */
    public static function _shutdown(): void
    {
        self::close();
    }

    // ---------------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------------

    /**
     * Dial the daemon and complete the hello handshake.
     *
     * Retries the CONNECT (only) a few times a second apart, because the overwhelmingly
     * common failure is a daemon in the middle of a supervisor restart. A rejected hello is
     * NOT retried - the key is wrong and it will stay wrong.
     */
    private static function __connect(): void
    {
        $endpoint = self::__endpoint();
        $attempts = (int) config('rsx.locks.connect_retries', self::DEFAULT_CONNECT_RETRIES);
        $delay_ms = (int) config('rsx.locks.connect_retry_delay_ms', self::DEFAULT_CONNECT_RETRY_DELAY_MS);
        if ($attempts < 1) {
            $attempts = 1;
        }

        $socket = false;
        $errstr = '';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                'tcp://' . $endpoint['host'] . ':' . $endpoint['port'],
                $errno,
                $errstr,
                self::CONNECT_TIMEOUT_SECONDS
            );

            if ($socket !== false) {
                break;
            }

            if ($attempt < $attempts) {
                usleep($delay_ms * 1000);
            }
        }

        if ($socket === false) {
            throw new RuntimeException(
                "Cannot reach rsx-lockd at {$endpoint['host']}:{$endpoint['port']} after {$attempts} attempts"
                . ($errstr !== '' ? " ({$errstr})" : '')
                . ' - is the rsx-lockd supervisor program running?'
            );
        }

        // MANDATORY (enforced by StreamBlockingMode_CodeQualityRule): every read below is a
        // deliberate block. No stream_set_timeout call exists anywhere in this class - see
        // the class docblock on why a lock wait has no upper bound.
        stream_set_blocking($socket, true);

        self::$socket = $socket;
        self::$read_buffer = '';
        self::$parked_frames = [];

        self::__hello();

        if (!self::$shutdown_registered) {
            self::$shutdown_registered = true;
            register_shutdown_function([self::class, '_shutdown']);
        }
    }

    /**
     * Authenticate the connection: HMAC-SHA256 over host:pid:ts keyed by APP_KEY, exactly as
     * system/bin/rsx-lockd/lib/protocol.js signs it. The RAW APP_KEY string is the shared
     * secret (the daemon reads the same characters out of the environment or .env) - never a
     * base64-decoded form of it.
     */
    private static function __hello(): void
    {
        $key = (string) env('APP_KEY');
        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set - rsx-lockd connections cannot be authenticated');
        }

        $host = (string) gethostname();
        $pid = getmypid();
        $ts = time();

        self::$request_seq++;
        $id = 'p' . $pid . '-' . self::$request_seq;

        self::__write([
            'op' => 'hello',
            'host' => $host,
            'pid' => $pid,
            'ts' => $ts,
            'sig' => hash_hmac('sha256', "{$host}:{$pid}:{$ts}", $key),
            'group_id' => self::current_group_id(),
            'protocol' => 1,
            'id' => $id,
        ]);

        $response = self::__await($id);

        if (($response['status'] ?? '') !== 'ok') {
            $message = $response['message'] ?? 'unknown reason';
            @fclose(self::$socket);
            self::$socket = null;
            throw new RuntimeException("rsx-lockd rejected the hello handshake: {$message}");
        }
    }

    /**
     * This process's LOCK GROUP - the unit of lock ownership across a process tree.
     *
     * Resolved once and never recomputed: a process belongs to exactly one group for its
     * whole life, and the answer must not change if the socket reconnects.
     *
     * Two cases, and only two:
     *
     *   1. `--_lock-group=<id>` was on our command line. We are a SUBPROCESS that some
     *      parent spawned synchronously and is now blocked waiting for, so we join its
     *      group and inherit whatever it holds. Rsx_Artisan is what puts the flag there.
     *   2. No flag. We are our own root. The id is unique to this process, so nothing
     *      inherits from us by accident, and behavior is identical to having no groups.
     *
     * The id is not a secret and is not treated as one - the HMAC key is the trust
     * boundary for the whole connection. It is unguessable only so two unrelated roots on
     * one box can never collide.
     */
    public static function current_group_id(): string
    {
        static $group_id = null;

        if ($group_id !== null) {
            return $group_id;
        }

        $inherited = Rsx_Internal_Flags::get(self::LOCK_GROUP_FLAG);
        if ($inherited !== null && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $inherited) === 1) {
            $group_id = $inherited;

            return $group_id;
        }

        $group_id = 'g' . getmypid() . '-' . bin2hex(random_bytes(6));

        return $group_id;
    }

    /** Where the daemon lives. Override wins, then config, then the shipped defaults. */
    private static function __endpoint(): array
    {
        if (self::$endpoint_override !== null) {
            return self::$endpoint_override;
        }

        return [
            'host' => (string) config('rsx.locks.server_host', self::DEFAULT_HOST),
            'port' => (int) config('rsx.locks.server_port', self::DEFAULT_PORT),
        ];
    }

    private static function __write(array $frame): void
    {
        $line = json_encode($frame) . "\n";
        $written = @fwrite(self::$socket, $line);

        if ($written === false || $written < strlen($line)) {
            self::__socket_died('write failed');
        }
    }

    /**
     * Read frames until the one answering $id arrives. Anything else is parked for whoever
     * is waiting on it (responses genuinely arrive out of order), and an id-less frame is a
     * connection-level error the daemon emitted without a request to attach it to.
     */
    private static function __await(string $id): array
    {
        if (isset(self::$parked_frames[$id])) {
            $frame = self::$parked_frames[$id];
            unset(self::$parked_frames[$id]);

            return $frame;
        }

        while (true) {
            $line = self::__read_line();
            $frame = json_decode($line, true);

            if (!is_array($frame)) {
                self::__socket_died('unparseable frame: ' . $line);
            }

            $frame_id = $frame['id'] ?? null;

            if ($frame_id === $id) {
                return $frame;
            }

            if ($frame_id === null) {
                $message = $frame['message'] ?? 'no message';
                self::__socket_died("connection-level error from rsx-lockd: {$message}");
            }

            self::$parked_frames[$frame_id] = $frame;
        }
    }

    /**
     * One newline-delimited frame off the wire.
     *
     * stream_select() with a NULL timeout is THE wait: it blocks indefinitely and returns
     * only when there are bytes (or the peer went away). Nothing here arms a clock, so a
     * parked acquire costs one blocked process and zero traffic.
     */
    private static function __read_line(): string
    {
        while (true) {
            $newline = strpos(self::$read_buffer, "\n");
            if ($newline !== false) {
                $line = substr(self::$read_buffer, 0, $newline);
                self::$read_buffer = substr(self::$read_buffer, $newline + 1);

                return trim($line);
            }

            $read = [self::$socket];
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, null);
            if ($ready === false) {
                self::__socket_died('stream_select failed');
            }

            $chunk = @fread(self::$socket, 65536);
            if ($chunk === false || $chunk === '') {
                self::__socket_died('connection closed by the daemon');
            }

            self::$read_buffer .= $chunk;
        }
    }

    /**
     * The socket is unusable. Drop it (a later request redials a FRESH connection, which by
     * the model holds nothing) and throw - the caller must never carry on believing it holds
     * a lock that no longer exists anywhere.
     */
    private static function __socket_died(string $reason): void
    {
        if (is_resource(self::$socket)) {
            @fclose(self::$socket);
        }
        self::$socket = null;
        self::$read_buffer = '';
        self::$parked_frames = [];

        $endpoint = self::__endpoint();

        throw new RuntimeException(
            "Lost the connection to rsx-lockd at {$endpoint['host']}:{$endpoint['port']} ({$reason})"
            . ' - every lock this process held has been released by the daemon'
        );
    }
}
