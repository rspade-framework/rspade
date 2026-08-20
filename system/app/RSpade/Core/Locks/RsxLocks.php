<?php

namespace App\RSpade\Core\Locks;

use Exception;
use RuntimeException;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\Lockd_Client;

// Ensure helpers are loaded since we run early in bootstrap
$helpers_path = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'helpers.php';
if (file_exists($helpers_path)) {
    require_once $helpers_path;
}

/**
 * Advisory locking.
 *
 * A lock is held from the moment it is granted until it is released or the holder dies.
 * There is NO lease, NO TTL, NO renewal and NO heartbeat: a critical section may run for a
 * minute or for a day and it keeps its lock throughout, and a holder that is SIGKILLed
 * releases instantly. A timeout, when one is given at all, is a WAIT budget and nothing
 * else - it never bounds how long a granted lock is held. `$timeout = null` (the default
 * everywhere) means wait forever, which is the right answer far more often than any number.
 *
 * TWO KINDS OF LOCK. They are different things and the choice is not a tuning knob:
 *
 *   CLUSTER_LOCK - spans every web server in the cluster, the same way the cluster shares
 *       one database. Served by the rsx-lockd daemon (system/bin/rsx-lockd) over a socket,
 *       with full readers-writer semantics, strict FIFO grant order, and deadlock detection
 *       at enqueue. Use it for anything guarding shared state: a tenant, the blob store, a
 *       cluster-wide concurrency quota. `named_*_lock()` and `site_*_lock()` are cluster.
 *
 *   SYSTEM_LOCK - THIS BOX ONLY, backed by flock() over files in storage/flock/, and
 *       EXCLUSIVE ONLY (there is no such thing as a system READ lock). Use it only for
 *       something that is genuinely per-box: build artifacts on local disk, a local node
 *       process, this machine's environment. Rare by design.
 *
 * WHY A SOCKET. The connection IS the lock. The kernel already tracks whether the holder is
 * alive and reports it the instant the process dies, which is precisely the question a lock
 * has to answer and the one question a clock-based lease can only guess at. Every lease
 * duration is a moment at which mutual exclusion silently stops being true; there is no such
 * moment here.
 *
 * MAINTENANCE MODE GRANTS CLUSTER LOCKS AS NO-OPS (owner ruling 2026-08-11). Maintenance
 * stops the daemon along with the rest of the fleet, and the quiesce is the point: web
 * requests are 503, the task runners are refused, and the background workers are killed. A
 * lock exists to exclude a concurrent writer; under a fleet-wide quiesce there is no
 * concurrent writer to exclude, so the lock has nothing left to do.
 *
 * It USED to degrade to flock, on the reasoning that a single-box lock is equivalent to a
 * cluster-wide one once the fleet is down. True, and still beside the point: what it bought
 * was exclusion against a peer that maintenance had already removed, and what it cost was
 * real - flock is per open file description, so it forced every degraded lock to WRITE (a
 * READ-then-WRITE nesting would otherwise self-deadlock forever), it left lock files behind,
 * and it produced measurable contention against php-fpm during rsx:debug and similar work.
 * Paying a contention cost for exclusion nobody needs is a bad trade.
 *
 * SYSTEM locks are NOT affected and never degrade: they guard genuinely local resources
 * (build artifacts, a local process) whose contenders - a concurrent artisan command, a
 * lingering worker - are exactly what maintenance does NOT stop.
 *
 * The backend is chosen at ACQUISITION time from the per-process RSPADE_MAINT_MODE snapshot,
 * so it cannot change under a held lock; every held lock records which backend granted it and
 * is released through that one.
 *
 * REENTRANCY IS CLIENT-SIDE. Nested acquisition of the same lock in one process is counted
 * here and never leaves the process, so the daemon sees exactly one acquire per lock per
 * connection (and answers a second one with an error, deliberately).
 */
class RsxLocks
{
    /** Lock domains. See the class docblock - these are two different mechanisms. */
    const CLUSTER_LOCK = 'cluster';
    const SYSTEM_LOCK = 'system';

    // Lock types
    const READ_LOCK = 'READ';
    const WRITE_LOCK = 'WRITE';

    // Well-known framework lock names (system)
    const LOCK_MANIFEST_BUILD = 'MANIFEST_BUILD';  // Manifest rebuild operations
    const LOCK_BUNDLE_BUILD = 'BUNDLE_BUILD';  // Bundle compilation operations
    const LOCK_SSR_RENDER = 'SSR_RENDER';  // SSR server lifecycle + render operations

    // Well-known framework lock names (cluster)
    const LOCK_FILE_WRITE = 'FILE_WRITE';  // File upload/storage operations
    const LOCK_SITE_PREFIX = 'SITE_';  // Per-tenant lock prefix, e.g. SITE_1

    // Prefix of every token granted by the flock backend (see __acquire_flock).
    private const FLOCK_TOKEN_PREFIX = 'flock:';

    // Prefix of every token granted by the rsx-lockd backend. The daemon's own token rides
    // inside the held_locks record and is passed back to it verbatim; the public token is
    // process-local so a reconnect (which restarts the daemon's lk<n> numbering) can never
    // collide with a stale record.
    private const LOCKD_TOKEN_PREFIX = 'lockd:';

    // Semaphore sentinel handed out when there is no quota to enforce (unlimited, or
    // maintenance mode). release_semaphore() treats it as a no-op.
    private const SEM_UNLIMITED_PREFIX = 'sem-unlimited-';

    // Prefix of every token granted while maintenance mode holds the fleet down. Nothing is
    // locked anywhere - the grant is bookkeeping so reentrancy counts and release() behave
    // normally. release_lock() treats it as a successful no-op.
    private const MAINT_TOKEN_PREFIX = 'maint:';

    // 100ms polling interval - used ONLY by the flock backend when a caller gave a timeout.
    private static float $poll_interval = 0.1;

    // Locks held by this process. token => [domain, name, type, backend, ...]
    private static array $held_locks = [];

    // Semaphore slots held by this process. token => [name, server_token]
    private static array $held_semaphores = [];

    // Reentrancy counts. 'domain:name:TYPE' => ['token' => 'xxx', 'count' => 2]
    private static array $lock_counts = [];

    // Set when a lockd request FAILED while the maintenance flag was on disk - i.e. this
    // process booted before maintenance was enabled (RSPADE_MAINT_MODE = false) and raced the
    // daemon being stopped. From that point on its cluster locks are no-op grants, like every
    // process that booted after the flag went up.
    private static bool $maintenance_forced = false;

    // _cleanup_locks is registered once per process, before any backend is touched.
    private static bool $shutdown_registered = false;

    /**
     * Is the fleet quiesced, so that a CLUSTER lock has nothing left to exclude?
     *
     * Reads the per-process maintenance snapshot (RSPADE_MAINT_MODE via
     * Framework_Maintenance::is_active()), so the answer is stable for the process's whole
     * life - a lock can never be taken on one backend and released on another. The straggler
     * flag ($maintenance_forced) is the other way in: a process that booted BEFORE the flag
     * went up carries a "no maintenance" snapshot but meets a stopped daemon.
     */
    private static function __maintenance_degraded(): bool
    {
        return self::$maintenance_forced || Framework_Maintenance::is_active();
    }

    /**
     * Grant a CLUSTER lock that locks nothing, because maintenance mode has already removed
     * everything it would have excluded.
     *
     * The bookkeeping is real even though the exclusion is not: the token is recorded in
     * held_locks with backend 'maintenance', so reentrancy counts, release_lock(),
     * _release_since() and the shutdown sweep all behave exactly as they do for a real lock.
     * Only the backend round trip is gone. Nothing touches the filesystem, so nothing is left
     * behind and nothing contends.
     */
    private static function __grant_maintenance_noop(
        string $domain,
        string $name,
        string $type,
        string $count_key
    ): string {
        self::__register_shutdown();

        $token = uniqid(self::MAINT_TOKEN_PREFIX, true);

        self::$held_locks[$token] = [
            'domain' => $domain,
            'name' => $name,
            'type' => $type,
            'backend' => 'maintenance',
            'acquired_at' => time(),
        ];
        self::$lock_counts[$count_key] = ['token' => $token, 'count' => 1];

        return $token;
    }

    /**
     * Register the shutdown release handler exactly once.
     *
     * ORDER IS LOAD-BEARING: this is registered before the first backend call, so it runs
     * BEFORE Lockd_Client's own shutdown handler (registered when that class first
     * connects). Releasing each lock properly first means the release_all that follows finds
     * nothing left, and no held lock is ever reported as lost merely because the connection
     * was torn down ahead of it.
     */
    private static function __register_shutdown(): void
    {
        if (self::$shutdown_registered) {
            return;
        }
        self::$shutdown_registered = true;
        register_shutdown_function([self::class, '_cleanup_locks']);
    }

    // =============================================================================================
    // PUBLIC API
    // =============================================================================================

    /**
     * Acquire an advisory lock. The low-level entry point; prefer the named helpers below.
     *
     * @param string   $domain  CLUSTER_LOCK (rsx-lockd, cluster-wide) or SYSTEM_LOCK (flock, this box).
     * @param string   $name    Lock identifier, unique to the resource being guarded.
     * @param string   $type    READ_LOCK or WRITE_LOCK. SYSTEM_LOCK accepts WRITE_LOCK only.
     * @param int|null $timeout Seconds to WAIT for the lock, or NULL to wait forever (the default).
     *                          Never bounds how long the lock is HELD.
     * @return string Lock token - pass to release_lock().
     * @throws RuntimeException on wait timeout, or when the request would close a deadlock cycle.
     */
    public static function get_lock(
        string $domain,
        string $name,
        string $type,
        ?int $timeout = null
    ): string {
        if (!in_array($domain, [self::CLUSTER_LOCK, self::SYSTEM_LOCK], true)) {
            shouldnt_happen("Invalid lock domain: {$domain}");
        }

        if (!in_array($type, [self::READ_LOCK, self::WRITE_LOCK], true)) {
            shouldnt_happen("Invalid lock type: {$type}");
        }

        // System locks are exclusive only. flock() is per open-file-description, so the READ
        // vocabulary would be a lie (every flock we take is LOCK_EX) AND a trap (a READ-then-
        // WRITE nesting would open a second descriptor and block against itself forever).
        // Readers-writer semantics are a cluster-lock feature; ask for the cluster.
        if ($domain === self::SYSTEM_LOCK && $type === self::READ_LOCK) {
            shouldnt_happen(
                "System locks are exclusive only - there is no READ system lock ({$name}). "
                . 'Use CLUSTER_LOCK for readers-writer semantics.'
            );
        }

        if ($timeout !== null && $timeout < 0) {
            shouldnt_happen("Lock timeout cannot be negative ({$domain}:{$name})");
        }

        self::__register_shutdown();

        // SYSTEM locks are flock, always - maintenance does not stop the concurrent artisan
        // command or lingering worker they exist to exclude. A CLUSTER lock under a quiesced
        // fleet has no peer left to exclude and is granted as a no-op (see the class docblock).
        $use_flock = ($domain === self::SYSTEM_LOCK);
        $maintenance_noop = !$use_flock && self::__maintenance_degraded();

        // Under the flock backend EVERY lock is exclusive, whatever type was asked for, so
        // the lock's IDENTITY here ignores the requested type. It has to: flock() is per open
        // file description, so a READ-then-WRITE nesting that produced a second count key
        // would open a SECOND descriptor on the same file and block against itself forever -
        // and with wait-forever as the default that is a permanent hang, not a timeout. Only
        // SYSTEM locks reach this (READ is refused above), so it is exclusive already; the
        // line stays because the reasoning is what keeps it that way.
        //
        // The maintenance no-op keeps the REQUESTED type: there is no descriptor to
        // self-deadlock against, so nothing is gained by lying about what the caller asked
        // for, and an honest count key keeps upgrade_lock()'s READ->WRITE accounting intact.
        $effective_type = $use_flock ? self::WRITE_LOCK : $type;

        // Reentrancy, entirely client-side: a nested acquire never reaches a backend.
        $count_key = "{$domain}:{$name}:{$effective_type}";
        if (isset(self::$lock_counts[$count_key])) {
            self::$lock_counts[$count_key]['count']++;

            return self::$lock_counts[$count_key]['token'];
        }

        if ($use_flock) {
            return self::__get_flock_lock($domain, $name, $type, $timeout, $count_key);
        }

        if ($maintenance_noop) {
            return self::__grant_maintenance_noop($domain, $name, $effective_type, $count_key);
        }

        $response = self::__lockd([
            'op' => 'acquire',
            'name' => self::__wire_name($domain, $name),
            'mode' => $type === self::READ_LOCK ? 'read' : 'write',
            'timeout' => $timeout,
        ]);

        // Maintenance came up under us (the straggler path): re-enter, and the backend
        // selection at the top now resolves to the no-op grant.
        if ($response === null) {
            return self::get_lock($domain, $name, $type, $timeout);
        }

        $status = $response['status'] ?? 'error';

        if ($status === 'timeout') {
            throw new RuntimeException(self::__timeout_message($domain, $name, $type, (int) $timeout));
        }

        if ($status === 'deadlock') {
            throw new RuntimeException(self::__deadlock_message($response));
        }

        if ($status !== 'granted') {
            throw new RuntimeException(
                "rsx-lockd refused {$type} lock for {$domain}:{$name}: " . ($response['message'] ?? 'unknown error')
            );
        }

        $token = uniqid(self::LOCKD_TOKEN_PREFIX, true);
        self::$held_locks[$token] = [
            'domain' => $domain,
            'name' => $name,
            'type' => $type,
            'backend' => 'lockd',
            'server_token' => $response['token'],
            'acquired_at' => time(),
        ];
        self::$lock_counts[$count_key] = ['token' => $token, 'count' => 1];

        return $token;
    }

    /**
     * Release a lock.
     *
     * @param string $lock_token Token from get_lock() (or any of the helpers).
     * @return bool TRUE if we still held it (including a nested release that leaves an outer
     *              hold in place). FALSE means the backend no longer had us as the holder -
     *              which for a cluster lock means rsx-lockd RESTARTED while we held it, so
     *              mutual exclusion was not maintained. That case is logged as an error.
     */
    public static function release_lock(string $lock_token): bool
    {
        if (!isset(self::$held_locks[$lock_token])) {
            // Already released, or never ours. Nothing was held, and nothing was lost.
            return false;
        }

        $lock_info = self::$held_locks[$lock_token];
        $count_key = "{$lock_info['domain']}:{$lock_info['name']}:{$lock_info['type']}";

        // Reentrant: an inner release only unwinds one level of nesting.
        if (isset(self::$lock_counts[$count_key]) && self::$lock_counts[$count_key]['count'] > 1) {
            self::$lock_counts[$count_key]['count']--;

            return true;
        }

        unset(self::$held_locks[$lock_token]);
        unset(self::$lock_counts[$count_key]);

        // Route to the backend that GRANTED this lock, never to the currently-selected one.
        if ($lock_info['backend'] === 'flock') {
            self::__release_flock($lock_info);

            return true;
        }

        // Granted under maintenance: nothing was held anywhere, so nothing can be lost. TRUE
        // is the honest answer - the caller's critical section ran exactly as exclusively as
        // it was ever going to, on a fleet with no other writer in it.
        if ($lock_info['backend'] === 'maintenance') {
            return true;
        }

        // A release is nearly always in a finally block, so it never throws: a transport
        // failure HERE is itself the answer (the daemon died, therefore the lock is gone),
        // and throwing would replace the caller's real exception with this one.
        $lost_reason = null;
        try {
            $response = self::__lockd(['op' => 'release', 'token' => $lock_info['server_token']]);
        } catch (Exception $e) {
            $response = null;
            $lost_reason = $e->getMessage();
        }

        if ($lost_reason === null && $response === null) {
            // Maintenance came up mid-flight: it stopped the daemon, which released the lock
            // with the connection, and it stopped every peer that could have taken it. Nothing
            // is held anywhere and no exclusion was violated. A successful release.
            return true;
        }

        $held = $lost_reason === null && (bool) ($response['held'] ?? false);

        if (!$held) {
            \Illuminate\Support\Facades\Log::error(
                "RsxLocks: {$lock_info['type']} lock {$lock_info['domain']}:{$lock_info['name']} was no longer "
                . 'held by this connection at release time. rsx-lockd restarted while the lock was held, so mutual '
                . 'exclusion was NOT in force for the whole critical section.'
                . ($lost_reason === null ? '' : ' (' . $lost_reason . ')')
            );
        }

        return $held;
    }

    /**
     * Acquire a custom named WRITE (exclusive) lock - CLUSTER-wide.
     *
     * One writer holds a given $name at a time across every server in the cluster, waiting
     * for existing readers/writers of that name to drain. This is THE primitive for guarding
     * a critical section of a #[Task] (or any concurrent code) that cannot tolerate another
     * writer - tasks run concurrently and are NOT otherwise serialized. Pair every acquire
     * with release_lock($token) (use try/finally). Reentrant within a process.
     *
     * @param string   $name    Lock identifier (e.g. 'rebuild_search_index').
     * @param int|null $timeout Seconds to wait, or NULL to wait forever (the default).
     * @return string Lock token - pass to release_lock() to release.
     * @throws RuntimeException If a given $timeout elapses before the lock is granted.
     */
    public static function named_write_lock(string $name, ?int $timeout = null): string
    {
        return self::get_lock(self::CLUSTER_LOCK, $name, self::WRITE_LOCK, $timeout);
    }

    /**
     * Acquire a custom named READ (shared) lock - CLUSTER-wide.
     *
     * Multiple readers of the same $name proceed concurrently; they are blocked only while a
     * writer holds or is queued for that name. Use to coordinate with a named_write_lock()
     * writer (readers see a consistent view while no writer is active).
     *
     * @param string   $name    Lock identifier (must match the writer's name to coordinate).
     * @param int|null $timeout Seconds to wait, or NULL to wait forever (the default).
     * @return string Lock token - pass to release_lock() to release.
     * @throws RuntimeException If a given $timeout elapses before the lock is granted.
     */
    public static function named_read_lock(string $name, ?int $timeout = null): string
    {
        return self::get_lock(self::CLUSTER_LOCK, $name, self::READ_LOCK, $timeout);
    }

    /**
     * Acquire a WRITE (exclusive) lock on a single tenant (site) - CLUSTER-wide.
     *
     * This is the SAME lock the framework takes automatically for a request that mutates a
     * site's data (Rsx_Site_Model_Abstract). Exposed so a #[Task] operating on one tenant can
     * serialize against those writers and other tasks: hold it around a multi-step mutation
     * of site $site_id that must be atomic with respect to other writers.
     *
     * @param int      $site_id Tenant/site id to lock.
     * @param int|null $timeout Seconds to wait, or NULL to wait forever (the default).
     * @return string Lock token - pass to release_lock() to release.
     * @throws RuntimeException If a given $timeout elapses before the lock is granted.
     */
    public static function site_write_lock(int $site_id, ?int $timeout = null): string
    {
        return self::get_lock(self::CLUSTER_LOCK, self::LOCK_SITE_PREFIX . $site_id, self::WRITE_LOCK, $timeout);
    }

    /**
     * Acquire a READ (shared) lock on a single tenant (site) - CLUSTER-wide.
     *
     * The read counterpart of site_write_lock(): many readers of site $site_id proceed
     * concurrently and are blocked only while a site writer holds or awaits the lock.
     *
     * @param int      $site_id Tenant/site id to lock.
     * @param int|null $timeout Seconds to wait, or NULL to wait forever (the default).
     * @return string Lock token - pass to release_lock() to release.
     * @throws RuntimeException If a given $timeout elapses before the lock is granted.
     */
    public static function site_read_lock(int $site_id, ?int $timeout = null): string
    {
        return self::get_lock(self::CLUSTER_LOCK, self::LOCK_SITE_PREFIX . $site_id, self::READ_LOCK, $timeout);
    }

    /**
     * Acquire a SYSTEM lock - exclusive, THIS BOX ONLY, backed by flock().
     *
     * For work whose contended resource is genuinely local: build artifacts on local disk, a
     * local helper process, this machine's environment. If two servers doing the same thing
     * at the same time would be a correctness problem rather than duplicated effort, this is
     * the wrong lock - use named_write_lock().
     *
     * @param string   $name    Lock identifier (e.g. RsxLocks::LOCK_MANIFEST_BUILD).
     * @param int|null $timeout Seconds to wait, or NULL to wait forever (the default).
     * @return string Lock token - pass to release_lock() to release.
     * @throws RuntimeException If a given $timeout elapses before the lock is granted.
     */
    public static function system_lock(string $name, ?int $timeout = null): string
    {
        return self::get_lock(self::SYSTEM_LOCK, $name, self::WRITE_LOCK, $timeout);
    }

    /**
     * Convert a held READ lock to a WRITE lock.
     *
     * NOT ATOMIC, deliberately. The daemon drops the read hold FIRST and then queues the
     * write request at the tail like any other, so another writer can run in between:
     * RE-VERIFY whatever you read before the upgrade. The upside is the one that matters -
     * two readers upgrading at the same time cannot deadlock, because each has already given
     * up its read, so one is granted and the other simply waits behind it. An atomic
     * promotion would hang both forever under a wait-forever default.
     *
     * Upgrading a lock already held WRITE (which every SYSTEM lock is) returns the same token
     * unchanged, so an unconditional call is safe.
     *
     * NO framework code calls this today - the read-then-upgrade site-lock pattern it existed
     * for is gone (a read-only request now takes no lock at all). It is kept because it is a
     * legitimate primitive the daemon implements correctly, and an application holding a
     * named_read_lock() that discovers it must write is exactly its use case.
     *
     * @param string   $lock_token Token of a held lock.
     * @param int|null $timeout    Seconds to wait, or NULL to wait forever (the default).
     * @return string Token to use from now on - may differ from the one passed in.
     * @throws RuntimeException if the token is not held, or the upgrade times out / deadlocks.
     */
    public static function upgrade_lock(string $lock_token, ?int $timeout = null): string
    {
        if (!isset(self::$held_locks[$lock_token])) {
            throw new RuntimeException('Cannot upgrade lock - token not found or already released');
        }

        $lock_info = self::$held_locks[$lock_token];

        // Already exclusive - including every flock-backed lock, which is exclusive by
        // construction. No count bump: the caller replaces its token and releases once.
        if ($lock_info['type'] === self::WRITE_LOCK) {
            return $lock_token;
        }

        // A READ granted under maintenance has no server token to upgrade and no daemon to
        // ask. Re-grant as an exclusive no-op, carrying the reentrancy count across, so the
        // caller ends up holding what it asked for in the only sense available here.
        if ($lock_info['backend'] === 'maintenance') {
            $old_count_key = "{$lock_info['domain']}:{$lock_info['name']}:" . self::READ_LOCK;
            $new_count_key = "{$lock_info['domain']}:{$lock_info['name']}:" . self::WRITE_LOCK;
            $count = self::$lock_counts[$old_count_key]['count'] ?? 1;

            unset(self::$held_locks[$lock_token]);
            unset(self::$lock_counts[$old_count_key]);

            $new_token = self::__grant_maintenance_noop(
                $lock_info['domain'],
                $lock_info['name'],
                self::WRITE_LOCK,
                $new_count_key
            );
            self::$lock_counts[$new_count_key]['count'] = $count;

            return $new_token;
        }

        $response = self::__lockd([
            'op' => 'upgrade',
            'token' => $lock_info['server_token'],
            'timeout' => $timeout,
        ]);

        if ($response === null) {
            // Maintenance came up mid-flight: the daemon is gone, so the read hold no longer
            // exists anywhere. Re-acquire through the normal path, which now resolves to the
            // maintenance no-op, so the caller ends up holding what it asked for.
            $old_count_key = "{$lock_info['domain']}:{$lock_info['name']}:" . self::READ_LOCK;
            $count = self::$lock_counts[$old_count_key]['count'] ?? 1;
            unset(self::$held_locks[$lock_token]);
            unset(self::$lock_counts[$old_count_key]);

            $new_token = self::get_lock($lock_info['domain'], $lock_info['name'], self::WRITE_LOCK, $timeout);
            self::$lock_counts["{$lock_info['domain']}:{$lock_info['name']}:" . self::WRITE_LOCK]['count'] = $count;

            return $new_token;
        }

        $status = $response['status'] ?? 'error';

        if ($status === 'timeout') {
            throw new RuntimeException(
                self::__timeout_message($lock_info['domain'], $lock_info['name'], self::WRITE_LOCK, (int) $timeout)
            );
        }

        if ($status === 'deadlock') {
            throw new RuntimeException(self::__deadlock_message($response));
        }

        if ($status !== 'granted') {
            throw new RuntimeException(
                "Failed to upgrade read lock to write lock for {$lock_info['domain']}:{$lock_info['name']}: "
                . ($response['message'] ?? 'unknown error')
            );
        }

        // Carry the reentrancy count across from the READ identity to the WRITE identity.
        $old_count_key = "{$lock_info['domain']}:{$lock_info['name']}:" . self::READ_LOCK;
        $new_count_key = "{$lock_info['domain']}:{$lock_info['name']}:" . self::WRITE_LOCK;
        $count = self::$lock_counts[$old_count_key]['count'] ?? 1;

        unset(self::$held_locks[$lock_token]);
        unset(self::$lock_counts[$old_count_key]);

        $new_token = uniqid(self::LOCKD_TOKEN_PREFIX, true);
        self::$held_locks[$new_token] = [
            'domain' => $lock_info['domain'],
            'name' => $lock_info['name'],
            'type' => self::WRITE_LOCK,
            'backend' => 'lockd',
            'server_token' => $response['token'],
            'acquired_at' => time(),
            'upgraded_from' => $lock_token,
        ];
        self::$lock_counts[$new_count_key] = ['token' => $new_token, 'count' => $count];

        return $new_token;
    }

    /**
     * FRAMEWORK-INTERNAL. Snapshot of every lock and semaphore token held right now.
     *
     * Pair with _release_since() to bound a unit of work: whatever the work acquires is
     * released when it ends, whatever the caller already held is left alone. See
     * Task_Worker_Command::execute_task(), the one production caller.
     *
     * @return array<int, string> Opaque token list - only ever passed back to _release_since()
     */
    public static function _checkpoint(): array
    {
        return array_merge(
            array_keys(self::$held_locks),
            array_keys(self::$held_semaphores)
        );
    }

    /**
     * FRAMEWORK-INTERNAL. Release everything acquired SINCE a checkpoint, and nothing else.
     *
     * Why this exists: a lock taken by ordinary application code is held until the PROCESS
     * exits (Rsx_Site_Model_Abstract::__upgrade_to_write_lock() registers a shutdown
     * handler and nothing else ever lets go). In a web request that is exactly right - the
     * request IS the unit of work. In a task WORKER it is not: one long-lived process runs
     * many unrelated tasks, so the first task to write to a site would hold that tenant's
     * write lock against the entire cluster for the worker's whole lifetime.
     *
     * Reentrancy counts are ignored, deliberately and for the same reason _cleanup_locks()
     * ignores them: a count describes a call stack, and the stack that built it is gone.
     * A task that leaked a nested acquire does not get to keep the lock.
     *
     * Releasing is unconditional but never silent - each leaked lock is returned so the
     * caller can say so. A task that ends still holding a lock is a defect in that task,
     * and this is a safety net, not a licence.
     *
     * @param array<int, string> $checkpoint From _checkpoint()
     * @return array<int, string> Human-readable names of the locks that had to be released
     */
    public static function _release_since(array $checkpoint): array
    {
        $known = array_flip($checkpoint);
        $released = [];
        $released_tokens = [];

        foreach (array_keys(self::$held_locks) as $token) {
            if (isset($known[$token])) {
                continue;
            }

            $lock_info = self::$held_locks[$token];
            $count_key = "{$lock_info['domain']}:{$lock_info['name']}:{$lock_info['type']}";

            // Flatten the nesting first, or release_lock() would only decrement and the
            // backend would keep the lock for the rest of the worker's life.
            if (isset(self::$lock_counts[$count_key])) {
                self::$lock_counts[$count_key]['count'] = 1;
            }

            try {
                self::release_lock($token);
                $released[] = "{$lock_info['domain']}:{$lock_info['name']} ({$lock_info['type']})";
                $released_tokens[] = $token;
            } catch (Exception $e) {
                // A lock we cannot release is not a reason to abandon the rest of them.
            }
        }

        foreach (array_keys(self::$held_semaphores) as $token) {
            if (isset($known[$token])) {
                continue;
            }

            $name = self::$held_semaphores[$token]['name'] ?? 'semaphore';

            try {
                self::release_semaphore($token);
                $released[] = "semaphore:{$name}";
            } catch (Exception $e) {
                // Same reasoning as above.
            }
        }

        // The site-lock registry caches "this process already holds site N" and would
        // otherwise never re-acquire for the next task, having just had that lock taken
        // out from under it here.
        if ($released_tokens !== []) {
            Rsx_Site_Model_Abstract::_forget_site_lock_tokens($released_tokens);
        }

        return $released;
    }

    /**
     * Cleanup on process exit. Releases everything unconditionally, ignoring reentrancy
     * counts - a nested count is a fact about the call stack, and there is no call stack
     * left to unwind at shutdown.
     */
    public static function _cleanup_locks(): void
    {
        foreach (array_keys(self::$held_locks) as $lock_token) {
            $lock_info = self::$held_locks[$lock_token];
            $count_key = "{$lock_info['domain']}:{$lock_info['name']}:{$lock_info['type']}";

            // Flatten the nesting first, so the release below actually reaches the backend.
            // (The old implementation released through the reentrancy layer and left a
            // nested lock held on the backend for the rest of the daemon's life.)
            if (isset(self::$lock_counts[$count_key])) {
                self::$lock_counts[$count_key]['count'] = 1;
            }

            try {
                self::release_lock($lock_token);
            } catch (Exception $e) {
                // Ignore errors during cleanup
            }
        }

        foreach (array_keys(self::$held_semaphores) as $sem_token) {
            try {
                self::release_semaphore($sem_token);
            } catch (Exception $e) {
                // Ignore errors during cleanup
            }
        }
    }

    /**
     * Force clear a lock (emergency use only). Drops every holder and lets the queue proceed;
     * waiters are left alone, since unsticking them is the point.
     *
     * @param string $domain Lock domain
     * @param string $name Lock name
     */
    public static function force_clear_lock(string $domain, string $name): void
    {
        // Under maintenance a CLUSTER lock is a no-op grant with no state anywhere, so there is
        // nothing to clear and no file to unlink.
        if ($domain !== self::SYSTEM_LOCK && self::__maintenance_degraded()) {
            return;
        }

        // Flock equivalent: drop the lock file. Advisory locking means an flock held by a live
        // process survives the unlink (it holds the inode) - this only clears stale state.
        if ($domain === self::SYSTEM_LOCK) {
            @unlink(self::__flock_path($domain, $name));

            return;
        }

        $response = self::__lockd(['op' => 'force_clear', 'name' => self::__wire_name($domain, $name)]);

        // Maintenance came up mid-flight: re-enter, which now short-circuits (a no-op grant
        // leaves no state anywhere to clear).
        if ($response === null) {
            self::force_clear_lock($domain, $name);
        }
    }

    /**
     * Lock statistics for monitoring.
     *
     * @param string $domain Lock domain
     * @param string $name Lock name
     * @return array ['readers_active', 'writer_active', 'writer_conn', 'readers_waiting',
     *                'writers_waiting', 'queue_length']
     */
    public static function get_lock_stats(string $domain, string $name): array
    {
        // Neither backend here keeps queue/holder bookkeeping - there is nothing to report but
        // "held by this process or not". For flock, a lock held elsewhere is invisible without
        // blocking on it; for a maintenance grant there IS nothing elsewhere, by construction.
        if ($domain === self::SYSTEM_LOCK || self::__maintenance_degraded()) {
            $held = false;
            foreach (self::$held_locks as $info) {
                if (!in_array($info['backend'], ['flock', 'maintenance'], true)) {
                    continue;
                }
                if ($info['domain'] === $domain && $info['name'] === $name) {
                    $held = true;
                    break;
                }
            }

            return [
                'readers_active' => 0,
                'writer_active' => $held,
                'writer_conn' => null,
                'readers_waiting' => 0,
                'writers_waiting' => 0,
                'queue_length' => 0,
            ];
        }

        $response = self::__lockd(['op' => 'stats', 'name' => self::__wire_name($domain, $name)]);

        if ($response === null) {
            return self::get_lock_stats($domain, $name);
        }

        return [
            'readers_active' => (int) ($response['readers_active'] ?? 0),
            'writer_active' => (bool) ($response['writer_active'] ?? false),
            'writer_conn' => $response['writer_conn'] ?? null,
            'readers_waiting' => (int) ($response['readers_waiting'] ?? 0),
            'writers_waiting' => (int) ($response['writers_waiting'] ?? 0),
            'queue_length' => (int) ($response['queue_length'] ?? 0),
        ];
    }

    // =============================================================================================
    // COUNTING SEMAPHORES (max-concurrency quotas)
    // =============================================================================================

    /**
     * Acquire one slot of a counting semaphore (a max-concurrency quota).
     *
     * Unlike the readers-writer lock (one writer / many readers), a semaphore grants up to
     * $max_slots simultaneous holders of the same $name. Served by rsx-lockd, so the quota
     * spans processes and containers (cluster-wide). A slot is held exactly like a lock -
     * until released or until the holder's connection dies - so a crashed holder frees its
     * slot immediately and no lease is involved.
     *
     * Typical use: bound expensive external work (e.g. LibreOffice document rendering) to N
     * at a time. Pair every successful acquire with release_semaphore() (use try/finally).
     *
     * @param string   $name      Semaphore identifier (e.g. 'libreoffice_thumbnail').
     * @param int      $max_slots Maximum simultaneous holders. 0 or negative = UNLIMITED (no gating).
     * @param int|null $timeout   Seconds to wait for a free slot, or NULL to wait forever.
     * @return string|null Token for release_semaphore(), or NULL if a given $timeout elapsed
     *                     with no slot free (the caller decides how to degrade).
     */
    public static function acquire_semaphore(string $name, int $max_slots, ?int $timeout = null): ?string
    {
        // Unlimited quota: never gate, never open a socket.
        if ($max_slots <= 0) {
            return uniqid(self::SEM_UNLIMITED_PREFIX);
        }

        // Maintenance mode: the daemon (the only quota store) is stopped, and capping
        // concurrency is meaningless with the services down and background tasks killed.
        if (self::__maintenance_degraded()) {
            return uniqid(self::SEM_UNLIMITED_PREFIX);
        }

        self::__register_shutdown();

        $response = self::__lockd([
            'op' => 'sem_acquire',
            'name' => $name,
            'max_slots' => $max_slots,
            'timeout' => $timeout,
        ]);

        // Maintenance came up mid-flight: the daemon was the only quota store, and capping
        // concurrency is meaningless with the fleet down. Hand out the unlimited sentinel.
        if ($response === null) {
            return uniqid(self::SEM_UNLIMITED_PREFIX);
        }

        $status = $response['status'] ?? 'error';

        if ($status === 'timeout') {
            return null;
        }

        if ($status === 'deadlock') {
            throw new RuntimeException(self::__deadlock_message($response));
        }

        if ($status !== 'granted') {
            throw new RuntimeException(
                "rsx-lockd refused semaphore slot for {$name}: " . ($response['message'] ?? 'unknown error')
            );
        }

        $token = uniqid('sem-', true);
        self::$held_semaphores[$token] = [
            'name' => $name,
            'server_token' => $response['token'],
            'acquired_at' => time(),
        ];

        return $token;
    }

    /**
     * Release a semaphore slot acquired via acquire_semaphore().
     *
     * Safe to call with a null or sentinel (unlimited) token - those are no-ops.
     *
     * @param string|null $token Token from acquire_semaphore().
     * @return void
     */
    public static function release_semaphore(?string $token): void
    {
        if ($token === null || !isset(self::$held_semaphores[$token])) {
            return;
        }

        $server_token = self::$held_semaphores[$token]['server_token'];
        unset(self::$held_semaphores[$token]);

        // Same reasoning as release_lock(): this runs in finally blocks, and a dead transport
        // means the slot is already free. Never throw over a slot that no longer exists.
        try {
            self::__lockd(['op' => 'sem_release', 'token' => $server_token]);
        } catch (Exception $e) {
            // The daemon is gone; so is the slot.
        }
    }

    /**
     * How many slots of a semaphore are currently held, cluster-wide (for monitoring).
     *
     * @param string $name      Semaphore identifier.
     * @param int    $max_slots The configured maximum. 0 or negative = unlimited, never gated.
     * @return int Number of currently-held slots.
     */
    public static function get_semaphore_usage(string $name, int $max_slots): int
    {
        if ($max_slots <= 0) {
            return 0;
        }

        // Maintenance mode: no quota store, and acquire_semaphore() hands out the unlimited
        // sentinel, so nothing is held by construction.
        if (self::__maintenance_degraded()) {
            return 0;
        }

        $response = self::__lockd(['op' => 'stats', 'name' => $name]);

        if ($response === null) {
            return 0;
        }

        return (int) ($response['semaphore']['slots_used'] ?? 0);
    }

    // =============================================================================================
    // BACKEND: rsx-lockd
    // =============================================================================================

    /**
     * One request/response round trip to the lock daemon.
     *
     * Returns NULL to mean "this process has just switched to the flock backend, re-enter" -
     * the STRAGGLER PATH. A process that booted before maintenance mode went up carries a
     * "no maintenance" snapshot but meets a stopped daemon; if the flag is on disk NOW, that
     * is maintenance rather than a fault, and we degrade exactly as the redis backend used
     * to. With no flag on disk, a failed request stays as loud as it has always been.
     *
     * @return array|null The response frame, or NULL if the caller must retry on flock.
     */
    private static function __lockd(array $frame): ?array
    {
        try {
            return Lockd_Client::request($frame);
        } catch (Exception $e) {
            if (Framework_Maintenance::is_active_on_disk()) {
                self::$maintenance_forced = true;

                return null;
            }

            throw $e;
        }
    }

    /**
     * The name a lock is known by ON THE WIRE. Domain-qualified, which keeps `lockd dump`
     * readable and makes the daemon's own timeout message identical to __timeout_message().
     */
    private static function __wire_name(string $domain, string $name): string
    {
        return "{$domain}:{$name}";
    }

    /**
     * THE timeout message, emitted verbatim. system/bin/framework-pull-upstream.sh greps
     * `Failed to acquire.*lock` to classify a retryable pull failure, and
     * system/bin/rsx-lockd/lib/protocol.js produces character-for-character the same string.
     * Do not reword.
     */
    private static function __timeout_message(string $domain, string $name, string $type, int $timeout): string
    {
        return "Failed to acquire {$type} lock for {$domain}:{$name} after {$timeout} seconds";
    }

    /**
     * A deadlock refusal, kept textually DISTINCT from the timeout message (a deadlock is not
     * retryable and must not be classified as one). The daemon's cycle description is the
     * whole debugging payload, so it is carried through verbatim.
     */
    private static function __deadlock_message(array $response): string
    {
        $message = $response['message'] ?? 'Deadlock detected by rsx-lockd';
        $cycle = $response['cycle'] ?? [];

        if (!empty($cycle) && is_array($cycle)) {
            $message .= "\n  " . implode("\n  ", $cycle);
        }

        return $message;
    }

    // =============================================================================================
    // BACKEND: flock (SYSTEM locks only - a cluster lock never reaches here)
    // =============================================================================================

    /**
     * Lock file path for the flock backend. One file per domain:name, filesystem-sanitized.
     * Files are left in place on release - flock() is advisory, so a stale file is inert.
     */
    /**
     * File descriptor numbers this process is holding flock locks on.
     *
     * WHY THIS EXISTS: PHP's fopen() does NOT set FD_CLOEXEC, and PHP offers no way to set
     * it on a stream. A POSIX flock lives on the OPEN FILE DESCRIPTION, not the process - so
     * any child we spawn while holding a lock inherits a fully live lock, and if that child
     * OUTLIVES us (the js-parser / js-transformer RPC daemons do, by design) the lock is
     * held forever by an idle process nobody would think to look at.
     *
     * That is not hypothetical. Measured on a live box (field report, 2026-08-10, reproduced twice
     * hours apart): rsx:manifest:build wedged for 8 minutes at 0m00s CPU in
     * locks_lock_inode_wait, four php-fpm workers queued behind it likewise, and the two node
     * daemons sat at ep_poll with almost no CPU holding the descriptor. Nobody was
     * holding-and-working. Killing the two node processes drained the queue instantly. And
     * because Manifest::init() takes MANIFEST_BUILD at BOOT - before command resolution -
     * a leaked lock makes the entire CLI unusable, not merely builds.
     *
     * Detection is evidence-based rather than bookkeeping: every descriptor this process has
     * open onto storage/flock/ IS one of our lock handles, whoever opened it. Linux-only by
     * construction (/proc/self/fd); an empty result elsewhere simply means no wrapping, which
     * is the pre-existing behaviour.
     *
     * @return int[] fd numbers, ascending
     */
    public static function inherited_lock_fds(): array
    {
        $fd_dir = '/proc/self/fd';
        if (!is_dir($fd_dir)) {
            return [];
        }

        $lock_dir = rtrim(storage_path('flock'), '/') . '/';
        $fds = [];

        foreach ((array) @scandir($fd_dir) as $entry) {
            if (!is_numeric($entry)) {
                continue;
            }
            $target = @readlink($fd_dir . '/' . $entry);
            if ($target !== false && str_starts_with($target, $lock_dir)) {
                $fds[] = (int) $entry;
            }
        }

        sort($fds);

        return $fds;
    }

    /**
     * Shell-statement prefix that closes our lock descriptors, for a spawn built as a COMMAND
     * STRING rather than an argv array (the detached-worker path). Empty when there is
     * nothing to close.
     *
     * Same reasoning as command_without_inherited_locks(): a DETACHED child is long-lived by
     * definition - a task worker runs for as long as there is work - so a lock fd inherited
     * into one is held for that worker's entire life.
     *
     * The prefix contains MULTI-DIGIT fd redirections, so the consuming shell MUST be bash -
     * never sh/dash. POSIX guarantees only single-digit fds in a redirection, and dash (which
     * is /bin/sh on Debian/Ubuntu) enforces that: it parses `exec 11>&-` as a command named 11
     * and the spawn dies with `exec: 11: not found`.
     */
    public static function shell_prefix_without_inherited_locks(): string
    {
        $prefix = '';
        foreach (self::inherited_lock_fds() as $fd) {
            $prefix .= 'exec ' . $fd . '>&-; ';
        }

        return $prefix;
    }

    /**
     * Wrap a command so the child starts with our lock descriptors CLOSED.
     *
     * Returns the command unchanged when there is nothing to close, so the common path is
     * untouched. Otherwise the command runs through `bash -c 'exec N>&- ...; exec "$@"'`,
     * which closes exactly our lock fds and nothing else - the child's own 0/1/2 and every
     * unrelated descriptor are left alone. Use this for ANY long-lived helper spawned while
     * a lock may be held; a short-lived child that exits promptly is harmless either way,
     * but wrapping it costs nothing.
     *
     * The wrapper shell MUST be bash, never sh: POSIX guarantees only single-digit fds in a
     * redirection, and dash (/bin/sh on Debian/Ubuntu) enforces it - once a lock fd lands at
     * 10 or above, `exec 11>&-` parses as a command named 11 and every wrapped spawn fails
     * with `exec: 11: not found` (observed in the field 2026-08-13).
     *
     * @param  string[] $command
     * @return string[]
     */
    public static function command_without_inherited_locks(array $command): array
    {
        $fds = self::inherited_lock_fds();
        if (!$fds) {
            return $command;
        }

        $closes = '';
        foreach ($fds as $fd) {
            $closes .= 'exec ' . $fd . '>&-; ';
        }

        return array_merge(['bash', '-c', $closes . 'exec "$@"', 'bash'], $command);
    }

    private static function __flock_path(string $domain, string $name): string
    {
        $dir = storage_path('flock');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', "{$domain}__{$name}");

        return $dir . '/' . $base . '.lock';
    }

    /**
     * Acquire through the flock backend and record the hold. $type is what the CALLER asked
     * for (it names the lock in a timeout message); what is recorded is WRITE, because that
     * is what an flock actually is - see the $effective_type note in get_lock().
     */
    private static function __get_flock_lock(
        string $domain,
        string $name,
        string $type,
        ?int $timeout,
        string $count_key
    ): string {
        $granted = self::__acquire_flock($domain, $name, $timeout);

        if ($granted === null) {
            throw new RuntimeException(self::__timeout_message($domain, $name, $type, (int) $timeout));
        }

        self::$held_locks[$granted['token']] = [
            'domain' => $domain,
            'name' => $name,
            'type' => self::WRITE_LOCK,
            'backend' => 'flock',
            'handle' => $granted['handle'],
            'path' => $granted['path'],
            'acquired_at' => time(),
        ];
        self::$lock_counts[$count_key] = ['token' => $granted['token'], 'count' => 1];

        return $granted['token'];
    }

    /**
     * Take an EXCLUSIVE flock. With no timeout this is a genuinely blocking flock(LOCK_EX) -
     * the kernel parks the process and wakes it the moment the lock is free, with no polling
     * and no upper bound. A caller-supplied timeout is the only reason the poll loop exists.
     *
     * @return array|null The held_locks fields (token + open handle), or NULL on wait timeout.
     */
    private static function __acquire_flock(string $domain, string $name, ?int $timeout): ?array
    {
        $path = self::__flock_path($domain, $name);
        $handle = fopen($path, 'c');
        if ($handle === false) {
            shouldnt_happen("Failed to open lock file for {$domain}:{$name} ({$path})");
        }

        $granted = [
            'token' => self::FLOCK_TOKEN_PREFIX . uniqid(gethostname() . ':' . getmypid() . ':', true),
            'handle' => $handle,
            'path' => $path,
        ];

        if ($timeout === null) {
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                shouldnt_happen("Blocking flock() failed for {$domain}:{$name} ({$path})");
            }

            return $granted;
        }

        $start_time = microtime(true);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $granted;
            }
            usleep((int) (self::$poll_interval * 1000000));
        } while ((microtime(true) - $start_time) < $timeout);

        fclose($handle);

        return null;
    }

    /** Release an flock-backed lock: unlock + close. The file itself stays (advisory). */
    private static function __release_flock(array $lock_info): void
    {
        $handle = $lock_info['handle'] ?? null;
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
