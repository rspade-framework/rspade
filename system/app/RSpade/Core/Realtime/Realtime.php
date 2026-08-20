<?php

namespace App\RSpade\Core\Realtime;

use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;
use App\RSpade\Core\Session\Session;

/**
 * Realtime
 *
 * Static API for the WebSocket realtime notification system.
 *
 * PHP is the authority — it issues auth tokens, checks permissions,
 * and publishes events. The Node.js WebSocket server is a dumb relay
 * that validates HMAC signatures and routes messages.
 *
 * Usage:
 *     // Publish after saving a record
 *     Realtime::publish('Contact_Updated_Topic', ['id' => $contact->id]);
 *
 *     // Generate tokens (called by Realtime_Controller)
 *     $token = Realtime::connection_token();
 *     $token = Realtime::subscribe_token('Contact_Updated_Topic', ['id' => 5]);
 */
class Realtime
{
    /**
     * Token expiry in seconds
     */
    private const TOKEN_EXPIRY = 60;

    /**
     * Redis channel prefix for realtime messages
     */
    private const REDIS_PREFIX = 'rsx_rt';

    /**
     * Redis channel for targeted control-plane frames (session/user refresh pushes).
     * Distinct from the per-site topic channels (rsx_rt:{site_id}); the Node relay routes
     * a frame on this channel by the connection STAMPS it already holds (realm + session_id,
     * or realm + site_id + user_id) with NO topic/subscription involved — a browser cannot be
     * trusted to subscribe to its own refresh signal. Caught by Node's pSubscribe('rsx_rt:*').
     */
    private const REDIS_CONTROL_CHANNEL = self::REDIS_PREFIX . ':control';

    /**
     * Raw (unprefixed) phpredis connection - see _redis() for why this is not
     * the Illuminate Redis facade.
     */
    private static ?\Redis $redis = null;

    /**
     * Per-request memo of the Node subscriber registry (SMEMBERS 'rsx_rt:subs',
     * decoded). Null = not yet read this process. The emitter dispatch gate may
     * consult it more than once per request; the emitter task resets it first
     * (reset_registry_memo()) so a long-lived worker always recomputes fresh.
     *
     * @var array<int, array{site_id: int, topic: string, filter: array}>|null
     */
    private static ?array $registry_memo = null;

    /**
     * Test capture seam for publish(). Null in production (zero overhead). When a
     * test calls _testing_start_publish_capture(), every publish() records its
     * (topic, data, site_id) here — even when realtime is disabled — so a test can
     * assert what WOULD be sent without a live Node relay or Redis subscriber.
     *
     * @var array<int, array{topic: string, data: array, site_id: int}>|null
     */
    private static ?array $publish_capture = null;

    /** One "dropped under maintenance" warning per process (see _maintenance_drop()). */
    private static bool $maintenance_warned = false;

    /**
     * Check if realtime is enabled
     */
    public static function is_enabled(): bool
    {
        return (bool) config('rsx.realtime.enabled');
    }

    /**
     * Is realtime traffic semantically VOID right now because maintenance mode is up?
     *
     * Not merely undeliverable: maintenance stops the Node relay AND flushes redis, so the
     * subscriber registry is empty and nobody is listening. A frame published into that has no
     * meaning, and the alternative is worse - _redis() fatals on the refused connect, which
     * would abort `migrate` inside the update window (model writes emit at commit through an
     * uncaught chain). _redis() itself stays LOUD outside maintenance.
     */
    private static function _maintenance_active(): bool
    {
        return Framework_Maintenance::is_active();
    }

    /** Record that realtime traffic was dropped under maintenance. Once per process. */
    private static function _maintenance_drop(): void
    {
        if (self::$maintenance_warned) {
            return;
        }

        self::$maintenance_warned = true;
        \Illuminate\Support\Facades\Log::warning(
            'maintenance mode: realtime frame dropped (relay stopped and the subscriber registry is flushed)'
        );
    }

    /**
     * rsx:health probe: is the Node WebSocket relay listening? A public static
     * `#[Health_Check('label')]` (bare marker attribute - never a defined class). When
     * realtime is disabled it is an INFO, not a FAIL. When enabled it opens (and closes)
     * a TCP connection to the configured ws_port - a read-only liveness probe.
     *
     * @return array
     */
    #[Health_Check('Realtime Relay')]
    public static function realtime_relay(): array
    {
        if (!self::is_enabled()) {
            return ['status' => 'INFO', 'detail' => 'disabled (REALTIME_ENABLED=false)'];
        }

        $port = (int) config('rsx.realtime.ws_port');
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);

        if ($socket === false) {
            return [
                'status' => 'FAIL',
                'detail' => 'relay not listening on port ' . $port . ' (' . trim($errstr) . ')',
                'remediation' => 'start the relay - check supervisor [program:realtime] (node system/bin/realtime-server.js)',
            ];
        }

        fclose($socket);

        return ['status' => 'OK', 'detail' => 'relay listening on 127.0.0.1:' . $port];
    }

    /**
     * Generate a connection token for WebSocket authentication
     *
     * Contains user_id, site_id, session_id, and expiry.
     * Signed with APP_KEY via HMAC-SHA256.
     * Short-lived (60 seconds) — only valid for initial handshake.
     *
     * Identity-agnostic: this token proves nothing beyond "a real client asked
     * for it" — it does NOT gate which topics can be subscribed to (can_subscribe()
     * does that). So it is minted for the staff session, the portal session, or a
     * fully anonymous visitor alike.
     *
     * The branch is on the EXPERIENCE OF THE REQUEST (Rsx_Portal::is_portal_request()),
     * never on who happens to be signed in — a public portal page, the invite/register
     * flow and a logged-out portal tab are all portal pages and must be stamped as such.
     * The 'realm' stamp records which EXPERIENCE this page was minted on, so a targeted
     * refresh push for a PORTAL property change reaches the portal tabs and leaves the
     * staff tabs on the same browser session alone.
     *
     * session_id is resolved ONCE, outside the fork — one browser, one session — and is
     * gated on has_session(): an anonymous first visit has no session to refresh, and
     * asking for one here would create it as a pure side effect of opening a socket.
     * A null session_id is part of the token contract (see Core/Realtime/CLAUDE.md,
     * Token Format).
     *
     * @return string Signed token
     */
    public static function connection_token(): string
    {
        // ONE session per browser, so the id is resolved once - outside the fork - and
        // through the facade that owns the row. Asking for it only when one already
        // exists is what keeps opening a socket from CREATING a session.
        $session_id = Session::has_session() ? Session::get_session_id() : null;

        if (Rsx_Portal::is_portal_request()) {
            $payload = [
                // 'realm' = the EXPERIENCE this page was minted on, so a targeted
                // session-refresh control frame reaches only the tabs that care.
                // See push_session_refresh().
                'realm' => 'portal',
                'user_id' => Portal_Session::get_portal_user_id(),
                'site_id' => Portal_Session::get_site_id(),
                'session_id' => $session_id,
                'exp' => time() + self::TOKEN_EXPIRY,
            ];
        } else {
            $payload = [
                'realm' => 'staff',
                'user_id' => Session::get_user_id(),
                'site_id' => Session::get_site_id(),
                'session_id' => $session_id,
                'exp' => time() + self::TOKEN_EXPIRY,
            ];
        }

        return self::_sign_token($payload);
    }

    /**
     * Resolve the current caller's site_id the same way for every token type: the
     * PORTAL tenant on a portal request (portal_site_id / the app's declaration), the
     * staff Session's site_id otherwise — they are different properties of the one
     * session row and a browser can legitimately be on a different tenant in each.
     * Both token methods MUST agree on this, or Node's site-match check (subscribe
     * payload.site_id vs connection conn.site_id) rejects every portal subscribe —
     * which is what happened while this branched on "a portal user is logged in"
     * and connection_token() then handed an unauthenticated portal visitor the
     * staff site.
     */
    private static function _current_site_id(): int
    {
        return Rsx_Portal::is_portal_request() ? Portal_Session::get_site_id() : Session::get_site_id();
    }

    /**
     * Generate a subscribe token for a specific topic
     *
     * Checks the topic's can_subscribe() method first.
     * Contains topic name, filter, site_id, and expiry.
     *
     * @param string $topic_class Topic class name (e.g., 'Contact_Updated_Topic')
     * @param array $filter Subscription filter (e.g., ['id' => 5])
     * @return string Signed token
     * @throws \RuntimeException If topic class not found or permission denied
     */
    public static function subscribe_token(string $topic_class, array $filter = []): string
    {
        // Resolve topic class (simple name -> FQCN; the map stores one FQCN per
        // simple name in a single-element array, since RSpade enforces unique
        // simple class names across the codebase)
        $fqcn = Manifest::get_autoloader_class_map()[$topic_class][0] ?? null;

        if (!$fqcn) {
            throw new \RuntimeException("Realtime topic class not found: {$topic_class}");
        }

        if (!is_subclass_of($fqcn, Realtime_Topic_Abstract::class)) {
            throw new \RuntimeException("Class {$topic_class} is not a Realtime_Topic_Abstract");
        }

        // Check permission
        if (!$fqcn::can_subscribe($filter)) {
            throw new \RuntimeException("Permission denied for topic: {$topic_class}");
        }

        $payload = [
            'topic' => $topic_class,
            'filter' => $filter,
            'site_id' => self::_current_site_id(),
            'exp' => time() + self::TOKEN_EXPIRY,
        ];

        return self::_sign_token($payload);
    }

    /**
     * Publish a message to all subscribers of a topic
     *
     * Sends via Redis pub/sub to the Node.js WebSocket server.
     * Messages are scoped to the current site_id automatically.
     *
     * IMPORTANT: Never include confidential data in the payload.
     * Messages are notifications only — clients fetch fresh data
     * through normal Ajax after receiving a notification.
     *
     * @param string $topic_class Topic class name (e.g., 'Contact_Updated_Topic')
     * @param array $data Notification payload (e.g., ['id' => 5, 'updated_by' => 3])
     * @param int|null $site_id Explicit site to scope the message to. Defaults to
     *                          the current staff Session site. Pass this when
     *                          publishing outside a staff-session context (e.g.
     *                          the portal stack uses Portal_Session, and rows
     *                          carry their own site_id).
     */
    public static function publish(string $topic_class, array $data = [], ?int $site_id = null): void
    {
        $site_id = $site_id ?? Session::get_site_id();

        // Test capture: record the intent to publish before the enablement gate, so
        // a framework test can assert emitter/emission output without a live relay.
        if (self::$publish_capture !== null) {
            self::$publish_capture[] = ['topic' => $topic_class, 'data' => $data, 'site_id' => $site_id];
        }

        if (!self::is_enabled()) {
            return;
        }

        if (self::_maintenance_active()) {
            self::_maintenance_drop();

            return;
        }

        $message = json_encode([
            'topic' => $topic_class,
            'data' => $data,
            'site_id' => $site_id,
            'ts' => time(),
        ]);

        $channel = self::REDIS_PREFIX . ':' . $site_id;

        $redis = self::_redis();
        if ($redis === null) {
            self::_maintenance_drop();

            return;
        }

        $redis->publish($channel, $message);
    }

    // -------------------------------------------------------------------------
    // Targeted refresh pushes (control plane)
    //
    // A refresh push tells every live browser connection matching a set of
    // connection STAMPS to do a full document reload (client schedules it 500ms
    // out, deduped). It is NOT a topic notification: no subscription is involved,
    // the relay matches conn.realm/session_id/user_id directly. Both methods are
    // no-ops when realtime is disabled, and ride the SAME transactional outbox/
    // batching as publish() (buffered in Realtime_Emissions, flushed on commit,
    // discarded on rollback, transmitted once at web request termination) so a
    // rolled-back mutation never strands a refresh.
    // -------------------------------------------------------------------------

    /**
     * Push a "your session changed — reload" refresh to every live connection holding
     * the given session id in the given realm. Emitted at the explicit session-mutation
     * sites (login/logout, identity change, tenant switch) with confirmed-different
     * gating — a re-save of the same value emits nothing.
     *
     * @param string $realm 'staff' | 'portal' — the EXPERIENCE the target connections'
     *                       pages were minted on. One browser has ONE session and can hold
     *                       staff tabs and portal tabs on it at once, so this is what makes
     *                       a portal-property change refresh the portal tabs and leave the
     *                       staff tabs alone. Not an id disambiguator.
     * @param int $session_id The _sessions row id — one row per browser, shared by both
     *                        experiences.
     */
    public static function push_session_refresh(string $realm, int $session_id): void
    {
        if (!self::is_enabled()) {
            return;
        }

        Realtime_Emissions::queue_session_refresh($realm, $session_id);
    }

    /**
     * Push a refresh to every live STAFF connection of the given site user. Emitted when a
     * staff user's identity-affecting record fields change (name/role/enabled) or the user's
     * ACL rows change. The connection user_id stamp is the SITE user id (users.id, what
     * connection_token() stamps via Session::get_user_id()), so targeting is (site_id, user_id).
     *
     * @param int $site_id The user's site scope.
     * @param int $user_id The site user id (users.id).
     */
    public static function push_user_refresh(int $site_id, int $user_id): void
    {
        if (!self::is_enabled()) {
            return;
        }

        Realtime_Emissions::queue_user_refresh($site_id, $user_id);
    }

    /**
     * Transmit one control frame to the Node relay on the control channel. Called by
     * Realtime_Emissions at flush / web-termination transmit (the same seam Realtime::publish()
     * is driven from). Framework-internal — call the push_* methods, not this.
     *
     * @param array $frame {kind, realm, ...} — session_refresh {realm, session_id} or
     *                     user_refresh {realm, site_id, user_id}.
     */
    public static function transmit_control_frame(array $frame): void
    {
        if (!self::is_enabled()) {
            return;
        }

        if (self::_maintenance_active()) {
            self::_maintenance_drop();

            return;
        }

        $redis = self::_redis();
        if ($redis === null) {
            self::_maintenance_drop();

            return;
        }

        $redis->publish(self::REDIS_CONTROL_CHANNEL, json_encode($frame));
    }

    /**
     * The Node subscriber registry as decoded entries.
     *
     * Node rewrites the Redis set 'rsx_rt:subs' on every subscribe/unsubscribe/close;
     * each member is canonical JSON {site_id, topic, filter} (filter keys sorted — the
     * pairing that lets the SET dedupe identical subscriptions; see realtime-server.js
     * build_registry_member()). This decodes them for two consumers: the emitter
     * dispatch gate (does any subscribed topic have a registered emitter?) and the
     * emitter engine (which site+topic+filter tuples to recompute).
     *
     * Memoized per process. The emitter task calls reset_registry_memo() before
     * reading so a long-lived worker recomputes against the current registry.
     *
     * Uses the same raw, unprefixed phpredis connection as publish() (see _redis()).
     *
     * @return array<int, array{site_id: int, topic: string, filter: array}>
     */
    public static function subscribed_registry_entries(): array
    {
        if (self::$registry_memo !== null) {
            return self::$registry_memo;
        }

        // Maintenance mode: the registry lives in redis, which is stopped and restarts EMPTY.
        // An empty registry is the truthful answer and it also closes the emitter-kick path
        // (has_watched_emitter_topic) with no further change.
        if (self::_maintenance_active()) {
            return [];
        }

        $redis = self::_redis();
        if ($redis === null) {
            return [];
        }

        $entries = [];
        foreach ($redis->sMembers(self::REDIS_PREFIX . ':subs') as $member) {
            $decoded = json_decode($member, true);
            if (!is_array($decoded) || !isset($decoded['topic'])) {
                continue;
            }

            $filter = $decoded['filter'] ?? [];
            $entries[] = [
                'site_id' => (int) ($decoded['site_id'] ?? 0),
                'topic' => (string) $decoded['topic'],
                'filter' => is_array($filter) ? $filter : [],
            ];
        }

        self::$registry_memo = $entries;

        return $entries;
    }

    /**
     * Discard the per-process registry memo so the next subscribed_registry_entries()
     * re-reads Redis. Called by the emitter engine before each run.
     */
    public static function reset_registry_memo(): void
    {
        self::$registry_memo = null;
    }

    /**
     * Last published value-hash for an emitter identity, or null if never stored.
     * Identity = sha1(site|topic|canonical_filter), computed by the emitter engine.
     * Keys live under 'rsx_rt:em:' with a TTL so a retired subscription's key ages out.
     */
    public static function emitter_hash_get(string $identity): ?string
    {
        $redis = self::_redis();
        if ($redis === null) {
            return null;
        }

        $value = $redis->get(self::REDIS_PREFIX . ':em:' . $identity);

        return $value === false ? null : $value;
    }

    /**
     * Store the current value-hash for an emitter identity with a TTL (seconds).
     */
    public static function emitter_hash_put(string $identity, string $hash, int $ttl): void
    {
        $redis = self::_redis();
        if ($redis === null) {
            self::_maintenance_drop();

            return;
        }

        $redis->setex(self::REDIS_PREFIX . ':em:' . $identity, $ttl, $hash);
    }

    /**
     * Sign a payload with HMAC-SHA256
     *
     * Token format: base64(json_payload).base64(hmac_signature)
     *
     * @param array $payload Data to sign
     * @return string Signed token
     */
    private static function _sign_token(array $payload): string
    {
        $json = json_encode($payload);
        $signature = hash_hmac('sha256', $json, config('app.key'));

        return base64_encode($json) . '.' . $signature;
    }

    /**
     * Raw phpredis connection for publishing (deliberately NOT the Illuminate
     * Redis facade). The facade's default connection has a key-prefix option
     * configured for application caching, and phpredis applies that prefix to
     * PUBLISH channel names too - so Redis::publish('rsx_rt:1', ...) actually
     * publishes to '{prefix}rsx_rt:1', which realtime-server.js's unprefixed
     * pSubscribe('rsx_rt:*') never sees. A raw, unprefixed connection - the
     * same pattern RsxLocks already uses for the same reason - keeps PHP and
     * Node talking on the literal channel name.
     *
     * Stays LOUD (shouldnt_happen) on a refused connection - with ONE exception: the
     * straggler path, a process that booted before maintenance mode went up and therefore
     * carries a "no maintenance" snapshot. Only there (flag on disk, one extra stat on the
     * failure path) does it return null and let the caller drop the frame.
     *
     * @return \Redis|null
     */
    private static function _redis(): ?\Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        $redis = new \Redis();

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);
        $password = env('REDIS_PASSWORD');

        if (!$redis->connect($host, $port, 2.0)) {
            if (Framework_Maintenance::is_active_on_disk()) {
                return null;
            }

            shouldnt_happen("Realtime: failed to connect to Redis at {$host}:{$port}");
        }

        if ($password && $password !== 'null') {
            $redis->auth($password);
        }

        self::$redis = $redis;

        return self::$redis;
    }

    // -------------------------------------------------------------------------
    // Test seam (framework-internal). Null-capture path adds zero production cost.
    // -------------------------------------------------------------------------

    /**
     * The raw, unprefixed phpredis connection, for framework tests that seed the
     * subscriber registry / inspect emitter hash keys. Reuses the one connection so
     * tests talk on the literal channel/key names (not the Illuminate cache prefix).
     *
     * _redis() is nullable ONLY on the maintenance-straggler path (a process that
     * booted before maintenance mode went up). A test running in that window has no
     * redis to assert against, so throw rather than return a TypeError-shaped null.
     * shouldnt_happen() returns void and therefore cannot sit on `??`.
     */
    public static function _testing_redis(): \Redis
    {
        return self::_redis() ?? throw new \RuntimeException(
            'Realtime test redis unavailable (maintenance straggler path)'
        );
    }

    /**
     * Begin capturing publish() calls (topic, data, site_id) — even when disabled.
     */
    public static function _testing_start_publish_capture(): void
    {
        self::$publish_capture = [];
    }

    /**
     * The publish() calls recorded since capture began.
     *
     * @return array<int, array{topic: string, data: array, site_id: int}>
     */
    public static function _testing_published(): array
    {
        return self::$publish_capture ?? [];
    }

    /**
     * Stop capturing and clear recorded publishes and the registry memo.
     */
    public static function _testing_reset(): void
    {
        self::$publish_capture = null;
        self::$registry_memo = null;
    }
}
