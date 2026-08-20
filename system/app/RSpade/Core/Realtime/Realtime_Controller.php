<?php

namespace App\RSpade\Core\Realtime;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Realtime\Realtime_Emitter_Service;
use App\RSpade\Core\Task\Task;

/**
 * Realtime_Controller
 *
 * Ajax endpoints for WebSocket token generation.
 * Called by Rsx_Realtime.js to authenticate and subscribe.
 *
 * AUTHORIZATION: the class gate is 'public' - deliberately unauthenticated. This
 * controller has no notion of "who must be logged in": a topic can require staff
 * auth, portal auth, or no auth at all (Realtime_Topic_Abstract::$requires_auth).
 * The ONLY enforcement boundary is each topic's can_subscribe(), called from
 * Realtime::subscribe_token() before a subscribe token is ever issued. A
 * connection token alone grants nothing beyond "you may open a WebSocket and
 * attempt to subscribe" - see man realtime SECURITY. The subs_changed route is
 * not a browser surface at all; its caller is the relay, authenticated by an HMAC
 * of the raw body inside the method.
 */
#[Auth('public')]
#[Auth_Realm('any')]
class Realtime_Controller extends Rsx_Controller_Abstract
{
    public static function pre_dispatch(Request $request, array $params = [])
    {
        if (!Realtime::is_enabled()) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_GENERIC, 'Realtime is not enabled');
        }

        return null;
    }

    /**
     * Get a connection token for WebSocket authentication
     */
    #[Ajax_Endpoint]
    public static function get_connection_token(Request $request, array $params = [])
    {
        return ['token' => Realtime::connection_token()];
    }

    /**
     * Get a subscribe token for a specific topic
     *
     * Params:
     *   topic  - Topic class name (e.g., 'Contact_Updated_Topic')
     *   filter - Optional filter object (e.g., {id: 5})
     */
    #[Ajax_Endpoint]
    public static function get_subscribe_token(Request $request, array $params = [])
    {
        $topic = $params['topic'] ?? null;
        $filter = $params['filter'] ?? [];

        if (empty($topic)) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'Topic is required');
        }

        if (!is_array($filter)) {
            $filter = [];
        }

        $token = Realtime::subscribe_token($topic, $filter);

        return ['token' => $token];
    }

    /**
     * Relay -> PHP notify channel: "these subscription identities just became live."
     *
     * The Node relay POSTs here (fire-and-forget, 200ms-coalesced) whenever its registry
     * rewrite added members. PHP seeds an emitter BASELINE hash for every notified entry an
     * emitter serves — at subscribe time, the one moment "the subscriber has current state"
     * is actually true (the subscribe ack IS the resync signal). Everything else about the
     * emitter engine then treats an absent baseline as a change worth publishing.
     *
     * NOT a browser endpoint. The caller is the relay process, authenticated by an HMAC of
     * the raw body under APP_KEY — the same shared secret both sides already use for
     * websocket tokens. Untrusted-input boundary: a bad signature or a stale timestamp is
     * LOGGED and answered 403; it never throws (this is reachable from the network).
     *
     * Body: {"ts": <unix>, "members": ["<canonical registry member json>", ...]}
     * Header: X-Realtime-Signature: hex(hmac_sha256(raw_body, APP_KEY))
     */
    #[Route('/_realtime/subs_changed', methods: ['POST'])]
    public static function subs_changed(Request $request, array $params = [])
    {
        // Maintenance window: redis is stopped and the relay is down, so a straggler POST
        // has nothing to seed against (emitter_hash_put drops too). Answer cleanly.
        if (Framework_Maintenance::is_active()) {
            return ['ok' => true, 'dropped' => true];
        }

        $raw = $request->getContent();
        $signature = (string) $request->header('X-Realtime-Signature', '');
        $expected = hash_hmac('sha256', $raw, (string) config('app.key'));

        if ($signature === '' || !hash_equals($expected, $signature)) {
            Log::warning('realtime subs_changed: rejected (signature mismatch)');

            return response()->json(['ok' => false], 403);
        }

        $body = json_decode($raw, true);
        $ts = is_array($body) ? (int) ($body['ts'] ?? 0) : 0;

        // Replay window. The signature is over the body, so a captured body stays valid
        // forever without this; 60s is the same order as the websocket token expiry.
        if ($ts === 0 || abs(time() - $ts) > 60) {
            Log::warning('realtime subs_changed: rejected (timestamp outside the 60s window)');

            return response()->json(['ok' => false], 403);
        }

        // Cheapest possible exit for the overwhelmingly common case: no emitters exist at
        // all, so no notified member can ever need a baseline.
        if (!Realtime_Emitter_Service::has_emitters()) {
            return ['ok' => true, 'entries' => 0];
        }

        // Decode members exactly as Realtime::subscribed_registry_entries() does — the relay
        // sends the identical canonical JSON it writes into the registry SET.
        $kept = [];
        foreach ((is_array($body['members'] ?? null) ? $body['members'] : []) as $member) {
            if (!is_string($member)) {
                continue;
            }

            $decoded = json_decode($member, true);
            if (!is_array($decoded) || !isset($decoded['topic'])) {
                continue;
            }

            $filter = $decoded['filter'] ?? [];
            $entry = [
                'site_id' => (int) ($decoded['site_id'] ?? 0),
                'topic' => (string) $decoded['topic'],
                'filter' => is_array($filter) ? $filter : [],
            ];

            // The relay is deliberately dumb: it reports EVERY new member. Emitter topic
            // (and model-constraint) filtering is PHP's job, right here.
            if (empty(Realtime_Emitter_Service::emitters_serving_entry($entry))) {
                continue;
            }

            $kept[] = $entry;
        }

        if (empty($kept)) {
            return ['ok' => true, 'entries' => 0];
        }

        Task::dispatch('Realtime_Emitter_Service', 'seed_subscriptions', ['entries' => $kept]);

        return ['ok' => true, 'entries' => count($kept)];
    }
}
