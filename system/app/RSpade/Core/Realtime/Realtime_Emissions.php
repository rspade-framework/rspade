<?php

namespace App\RSpade\Core\Realtime;

use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Realtime\Realtime_Emitter_Service;
use App\RSpade\Core\Realtime\Realtime_Touch_Registry;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;

/**
 * Realtime_Emissions
 *
 * Per-request buffer + transaction-commit flush for model change notifications.
 *
 * A model opts in with `public static $realtime = true;`. On a successful
 * save()/delete() (Rsx_Model_Abstract choke point), the model layer calls
 * queue_model_change(), which:
 *   - records a deduped "record N of model M on site S changed" entry, and
 *   - walks the model's realtime_touch() parent cascade (cycle-guarded), queuing
 *     each reached parent as its own emission (a child declaring a dependency on
 *     its parent's result sets) WITHOUT writing any parent row.
 *
 * The buffer is flushed exactly once per generation via DB::afterCommit(): when
 * no transaction is active the flush runs immediately; inside a transaction it
 * runs after commit; a rollback discards it (Laravel transaction-manager
 * semantics). Flush publishes one Model_Changed_Topic message per deduped entry
 * and performs the emitter dirty-kick (below).
 *
 * Emitter dirty-kick: EVERY successful save/delete of a non-$realtime_silent
 * model marks emitters dirty. At flush time the emitter task is dispatched IFF
 * realtime is enabled AND at least one #[Emitter] is registered AND the Node
 * subscriber registry is non-empty — so "nothing ticks while nothing is
 * happening" and emitters only run for topics someone is actually watching.
 *
 * Messages are notification-only ({model, id} hints) — never record data.
 */
class Realtime_Emissions
{
    /**
     * Maximum touch-cascade depth (levels), mirroring the CHAIN_DEPTH_CAP runaway
     * guard in Task_Model::resolve_chain_project_id(). The visited set is the real
     * cycle guard; this is the backstop against a pathological unbounded chain.
     */
    private const TOUCH_DEPTH_CAP = 25;

    /**
     * Maximum record ids per bulk WS frame. A group of same-(site, model) emissions is
     * coalesced into {model, ids:[...]} frames chunked at this size, so a single burst of
     * writes to one model rides a handful of frames instead of one-per-record. 100 keeps a
     * frame small on the wire while collapsing the common bulk-sweep case to one message.
     */
    private const FRAME_ID_CHUNK = 100;

    /**
     * Pending model-change emissions for the current generation, deduped by
     * "model|id|site_id" => ['model' => string, 'id' => int, 'site_id' => int].
     *
     * @var array<string, array{model: string, id: int, site_id: int}>
     */
    private static array $pending = [];

    /**
     * Whether any non-silent write occurred this generation (drives the emitter kick).
     */
    private static bool $emitters_dirty = false;

    /**
     * Whether the outermost-rollback listener has been installed for this process.
     */
    private static bool $rollback_listener_installed = false;

    /**
     * WEB request outbox: emissions that have flushed (committed) this request but are
     * NOT yet transmitted, deduped by "model|id|site_id" across the WHOLE request. In a
     * web request the actual Realtime::publish() calls (and the emitter dispatch) happen
     * ONCE at request termination (app()->terminating), so a record changed several times
     * in one request notifies its subscribers exactly once. CLI (tasks/tinker/tests)
     * transmits at flush and never populates this.
     *
     * @var array<string, array{model: string, id: int, site_id: int}>
     */
    private static array $outbox = [];

    /**
     * Whether the deferred web transmit should also dispatch the emitter task.
     */
    private static bool $outbox_dispatch_emitters = false;

    /**
     * Keys ("model|id|site_id") that have already flushed (been transmitted in CLI, or
     * moved into the web outbox) this request. Drives two things: the web outbox's
     * request-wide dedup, and realtime_emit()'s once-per-request semantics. Populated at
     * flush ONLY (never at call time) so a rolled-back generation — which never flushes —
     * leaves no trace and a later legitimate emit of the same record still succeeds.
     *
     * @var array<string, bool>
     */
    private static array $sent_keys = [];

    /**
     * Whether the request-termination transmit hook has been registered this process.
     */
    private static bool $termination_hook_registered = false;

    /**
     * Test override for the web/CLI context decision. Null = derive from
     * app()->runningInConsole() (production). A framework test sets this true to exercise
     * the web outbox path deterministically (the runner itself is CLI).
     */
    private static ?bool $force_web_context = null;

    /**
     * Test capture seam. Null in production (zero overhead). When a test calls
     * _testing_start_capture(), flush appends every flushed emission here so a test
     * can assert what WOULD be published without a live Redis subscriber.
     *
     * @var array<int, array{model: string, id: int, site_id: int}>|null
     */
    private static ?array $capture = null;

    /**
     * Pending targeted control frames (session/user refresh pushes) for the current
     * generation, deduped by frame key. These ride the identical afterCommit flush +
     * web-termination transmit machinery as model-change emissions, so a refresh is
     * discarded on rollback and transmitted once, after the response, in a web request.
     *
     * @var array<string, array>
     */
    private static array $pending_control = [];

    /**
     * WEB request outbox for control frames: flushed (committed) but not yet transmitted,
     * deduped by frame key across the whole request (a session mutated twice in one request
     * pushes ONE refresh). CLI transmits at flush and never populates this.
     *
     * @var array<string, array>
     */
    private static array $outbox_control = [];

    /**
     * Test capture seam for control frames — the control-plane analogue of $capture.
     *
     * @var array<int, array>|null
     */
    private static ?array $control_capture = null;

    /**
     * Queue a model-change emission for a model that has opted in ($realtime = true),
     * plus its realtime_touch() parent cascade. Registers the flush.
     */
    public static function queue_model_change(Rsx_Model_Abstract $model): void
    {
        self::_queue_one($model);
        self::_queue_touch_cascade($model);
        self::_ensure_flush_registered();
    }

    /**
     * The method-surface analogue of queue_attributed_touches(): walk ONLY the
     * realtime_touch() METHOD cascade for a child that has NOT opted into its own emission
     * ($realtime falsy). Queues the reached PARENTS as their own emissions — never the
     * child's own frame — and registers the flush.
     *
     * This mirrors #[Realtime_Touch] attribute semantics for the method escape hatch: a
     * touch-only polymorphic child (the exact case realtime_touch() exists for) must still
     * notify its parents even though nothing subscribes to the child directly. The own frame
     * is published only when the model sets $realtime = true (via queue_model_change()).
     */
    public static function queue_touch_cascade(Rsx_Model_Abstract $model): void
    {
        self::_queue_touch_cascade($model);
        self::_ensure_flush_registered();
    }

    /**
     * Queue a targeted session-refresh control frame (realm + session id). Deduped per
     * (realm, session_id) for the generation; registers the flush. A non-positive session
     * id is ignored. See Realtime::push_session_refresh().
     */
    public static function queue_session_refresh(string $realm, int $session_id): void
    {
        if ($session_id <= 0) {
            return;
        }

        $key = 's|' . $realm . '|' . $session_id;
        self::$pending_control[$key] = [
            'kind' => 'session_refresh',
            'realm' => $realm,
            'session_id' => $session_id,
        ];

        self::_ensure_flush_registered();
    }

    /**
     * Queue a targeted user-refresh control frame (staff realm, site + site-user id). Deduped
     * per (site_id, user_id) for the generation; registers the flush. A non-positive user id
     * is ignored. See Realtime::push_user_refresh().
     */
    public static function queue_user_refresh(int $site_id, int $user_id): void
    {
        if ($user_id <= 0) {
            return;
        }

        $key = 'u|' . $site_id . '|' . $user_id;
        self::$pending_control[$key] = [
            'kind' => 'user_refresh',
            'realm' => 'staff',
            'site_id' => $site_id,
            'user_id' => $user_id,
        ];

        self::_ensure_flush_registered();
    }

    /**
     * Mark that a non-silent model wrote this generation, so the flush dispatches the
     * emitter task (subject to the enabled/registered/subscribed gates). Registers the flush.
     */
    public static function mark_emitters_dirty(): void
    {
        self::$emitters_dirty = true;
        self::_ensure_flush_registered();
    }

    /**
     * Explicit, manual change emission for one record — the engine behind
     * Rsx_Model_Abstract::realtime_emit(). Unlike the save/delete hook, this fires
     * regardless of the model's $realtime flag (calling it IS the intent) and is meant
     * for write paths the model layer never sees (DB::table / raw SQL / ->getQuery()
     * drops, post-import notifications, derived-value changes). Model-builder bulk
     * writes need no manual emit — RestrictedEloquentBuilder covers them.
     *
     * ONCE PER (model, id, site) PER REQUEST, deduped at the BUFFER/OUTBOX level (never a
     * call-time latch): a no-op only if this record's emission is already pending in the
     * current generation OR has already flushed (transmitted/outboxed) this request. A
     * generation that rolls back clears $pending and never populates $sent_keys, so a
     * later legitimate emit of the same record after a rollback still succeeds. Runs the
     * SAME path as auto-emission, touch cascade included.
     */
    public static function request_emit(Rsx_Model_Abstract $model): void
    {
        $key = self::_pending_key(class_basename($model), (int) $model->id, self::_resolve_site_id($model));

        if (isset(self::$pending[$key]) || isset(self::$sent_keys[$key])) {
            return;
        }

        self::queue_model_change($model);
    }

    /**
     * Queue a change emission for a record identified only by (model name, id, site) —
     * NO instance required. Used by the #[Realtime_Touch] parent cascade and the bulk
     * builder pre-select, which know a parent's identity without hydrating it. Deduped into
     * the same $pending buffer as instance-based emissions (identical key), registers the
     * flush. A non-positive id is ignored (a meaningless "record 0 changed").
     */
    public static function queue_change_by_identity(string $model, int $id, int $site_id): void
    {
        if ($id <= 0) {
            return;
        }

        $key = self::_pending_key($model, $id, $site_id);
        self::$pending[$key] = ['model' => $model, 'id' => (int) $id, 'site_id' => $site_id];

        self::_ensure_flush_registered();
    }

    /**
     * Queue the #[Realtime_Touch] attribute-declared parent emissions for a child instance —
     * fired on EVERY write to the child (save/delete) regardless of the child's own
     * $realtime flag (the whole point of the attribute: a touch-only child still notifies
     * its parents). Reads each attributed FK off the raw attributes and walks outward.
     */
    public static function queue_attributed_touches(Rsx_Model_Abstract $child): void
    {
        $visited = [];
        self::_walk_attributed($child, $visited, 0);
    }

    /**
     * Queue one touched parent from a resolved touch-metadata entry + a raw FK value + the
     * emission site — the entry point the bulk builder uses (it has row data, not a child
     * instance). Honors the onward-cascade rule: a parent that itself has onward touches is
     * hydrated and walked; otherwise it is queued by identity.
     */
    public static function queue_touched_parent(array $entry, int $fk, int $site_id): void
    {
        $visited = [];
        self::_dispatch_touched_parent($entry, $fk, $site_id, $visited, 0);
    }

    /**
     * Walk the #[Realtime_Touch] entries of a hydrated node, dispatching each to its parent.
     * Depth-capped + visited-guarded across hydration hops so an attribute cycle terminates.
     */
    private static function _walk_attributed(Rsx_Model_Abstract $node, array &$visited, int $depth): void
    {
        if ($depth > self::TOUCH_DEPTH_CAP) {
            return;
        }

        $entries = Realtime_Touch_Registry::touch_metadata(get_class($node));
        if (empty($entries)) {
            return;
        }

        $site_id = self::_resolve_site_id($node);
        $attributes = $node->getAttributes();

        foreach ($entries as $entry) {
            $raw = $attributes[$entry['fk_column']] ?? null;
            if ($raw === null) {
                continue;
            }

            self::_dispatch_touched_parent($entry, (int) $raw, $site_id, $visited, $depth);
        }
    }

    /**
     * Dispatch a single touched parent. Onward cascade nuance (a by-identity queue carries
     * no instance, so a parent's own onward touches would be skipped): if the parent class
     * itself has attributed touches OR overrides realtime_touch(), HYDRATE the parent
     * (withTrashed when it soft-deletes, matching the method cascade) and walk it fully —
     * its own change, its method cascade, and its attributed touches. Otherwise queue the
     * parent by identity only (no hydration).
     */
    private static function _dispatch_touched_parent(array $entry, int $fk, int $site_id, array &$visited, int $depth): void
    {
        if ($fk <= 0) {
            return;
        }

        if (!$entry['parent_has_onward']) {
            self::queue_change_by_identity($entry['parent_class'], $fk, $site_id);

            return;
        }

        $fqcn = $entry['parent_fqcn'];
        $parent = $entry['parent_soft_deletes']
            ? $fqcn::withTrashed()->find($fk)
            : $fqcn::find($fk);

        if (!$parent) {
            return;
        }

        $visit_key = self::_visit_key($parent);
        if (isset($visited[$visit_key])) {
            return;
        }
        $visited[$visit_key] = true;

        self::_queue_one($parent);
        self::_queue_touch_cascade($parent);
        self::_ensure_flush_registered();

        self::_walk_attributed($parent, $visited, $depth + 1);
    }

    /**
     * Record a single emission (the model itself, or a touched parent), deduped.
     */
    private static function _queue_one(Rsx_Model_Abstract $model): void
    {
        $id = $model->id;
        if (empty($id)) {
            // A successful save/delete always has an id; guard anyway rather than
            // publish a meaningless "record null changed".
            return;
        }

        $name = class_basename($model);
        $site_id = self::_resolve_site_id($model);

        $key = self::_pending_key($name, (int) $id, $site_id);
        self::$pending[$key] = ['model' => $name, 'id' => (int) $id, 'site_id' => $site_id];
    }

    /**
     * The canonical dedup key for an emission (buffer, outbox, and sent-set all agree on
     * it). Site is part of the identity so the same row id on different sites is distinct.
     */
    private static function _pending_key(string $name, int $id, int $site_id): string
    {
        return $name . '|' . $id . '|' . $site_id;
    }

    /**
     * Resolve the site the emission is scoped to: the row's own site_id column when
     * present, else the CURRENT REQUEST'S session site (matching Realtime::publish()'s
     * default), else 0.
     *
     * The session tier is realm-aware. A model with no site_id column written
     * during a portal request must not be scoped by the STAFF session's site — in
     * prefix mode that is a real value belonging to a different realm's session, so
     * the frame would be routed to the wrong tenant's connections. Portal_Session's
     * site is declared per request by the app (Portal_Main::init()) and throws if it
     * was not, which on a portal request is the correct fail-loud.
     */
    private static function _resolve_site_id(Rsx_Model_Abstract $model): int
    {
        // Read the raw attribute (not the accessor) so non-site models — which have
        // no site_id column — fall through cleanly instead of triggering magic.
        $attributes = $model->getAttributes();
        if (array_key_exists('site_id', $attributes) && $attributes['site_id'] !== null) {
            return (int) $attributes['site_id'];
        }

        if (Rsx_Portal::is_portal_request()) {
            return (int) Portal_Session::get_site_id();
        }

        return (int) Session::get_site_id();
    }

    /**
     * Walk realtime_touch() outward from the model, queuing each reached parent as its
     * own emission. Iterative BFS with a visited set (model|id) + a depth cap so a
     * self-referential or cyclic touch graph terminates instead of looping.
     */
    private static function _queue_touch_cascade(Rsx_Model_Abstract $model): void
    {
        $visited = [self::_visit_key($model) => true];

        $frontier = $model->realtime_touch();
        $depth = 0;

        while (!empty($frontier)) {
            if (++$depth > self::TOUCH_DEPTH_CAP) {
                break;
            }

            $next = [];

            foreach ($frontier as $parent) {
                if (!$parent instanceof Rsx_Model_Abstract) {
                    shouldnt_happen('realtime_touch() must return Rsx_Model_Abstract instances, got: ' . get_debug_type($parent));
                }

                $key = self::_visit_key($parent);
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;

                self::_queue_one($parent);

                foreach ($parent->realtime_touch() as $grandparent) {
                    $next[] = $grandparent;
                }
            }

            $frontier = $next;
        }
    }

    /**
     * Visited-set key for cycle detection (identity, ignoring site_id).
     */
    private static function _visit_key(Rsx_Model_Abstract $model): string
    {
        return class_basename($model) . '|' . $model->id;
    }

    /**
     * Register the afterCommit flush. DB::afterCommit runs the callback immediately
     * when no transaction is active, after commit inside one, and discards it on
     * rollback.
     *
     * Registered on EVERY queue/mark call (not once per generation): a rollback —
     * including a nested savepoint rollback — discards previously registered
     * callbacks, so a "registered once" flag would go stale and silently suppress
     * every later flush in the same process (under-emit, the one failure mode this
     * system must not have). _flush() drains state, so the extra callbacks no-op.
     * Outermost rollbacks additionally discard the buffered generation (listener
     * below) so rolled-back change hints are not published by a later flush.
     */
    private static function _ensure_flush_registered(): void
    {
        self::_ensure_rollback_listener();

        DB::afterCommit(function () {
            self::_flush();
        });
    }

    /**
     * Install (once per process) a listener that discards the buffered generation
     * when the OUTERMOST transaction rolls back — everything queued in it is void.
     * Savepoint (nested) rollbacks are deliberately coarse: buffered entries from a
     * rolled-back savepoint are kept and may over-emit a change hint for a write
     * that never committed. Messages are notification-only refetch hints, so an
     * over-emit is harmless; an under-emit is not.
     */
    private static function _ensure_rollback_listener(): void
    {
        if (self::$rollback_listener_installed) {
            return;
        }

        self::$rollback_listener_installed = true;
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) {
            if ($event->connection->transactionLevel() === 0) {
                self::$pending = [];
                self::$pending_control = [];
                self::$emitters_dirty = false;
            }
        });
    }

    /**
     * Flush the buffered generation: capture intent, then EITHER transmit immediately (CLI)
     * OR stage into the request outbox for a single transmit at request termination (web).
     * Resets generation state so a subsequent write in the same request opens a fresh one.
     *
     * The capture seam records intent at FLUSH for both paths (unchanged), so framework
     * tests assert the same thing regardless of transmission timing. The web/CLI split is
     * ONLY about WHEN the Realtime::publish() calls happen — never about what was intended.
     */
    private static function _flush(): void
    {
        $emissions = array_values(self::$pending);
        $control = array_values(self::$pending_control);
        $dispatch_emitters = self::$emitters_dirty;

        self::$pending = [];
        self::$pending_control = [];
        self::$emitters_dirty = false;

        if (self::$capture !== null) {
            foreach ($emissions as $emission) {
                self::$capture[] = $emission;
            }
        }

        if (self::$control_capture !== null) {
            foreach ($control as $frame) {
                self::$control_capture[] = $frame;
            }
        }

        if (self::_is_web_context()) {
            self::_stage_outbox($emissions, $dispatch_emitters);
            self::_stage_control_outbox($control);
            return;
        }

        // CLI (tasks, tinker, tests): transmit at flush — a long-running task must emit as
        // it works, not at process exit. Mark sent (per-record, unchanged), then publish the
        // whole generation coalesced into bulk frames.
        foreach ($emissions as $emission) {
            self::$sent_keys[self::_pending_key($emission['model'], $emission['id'], $emission['site_id'])] = true;
        }

        self::_publish_grouped($emissions);

        if ($dispatch_emitters) {
            self::_maybe_dispatch_emitters();
        }

        // Control frames transmit at flush too (CLI). No cross-flush dedup here — the
        // per-generation buffer already collapsed duplicates.
        foreach ($control as $frame) {
            Realtime::transmit_control_frame($frame);
        }
    }

    /**
     * Stage flushed control frames into the request outbox (web path), deduped by frame key
     * across the whole request (a session mutated twice pushes ONE refresh). Registers the
     * one-shot termination transmit hook.
     */
    private static function _stage_control_outbox(array $control): void
    {
        foreach ($control as $frame) {
            self::$outbox_control[self::_control_key($frame)] = $frame;
        }

        if (!empty(self::$outbox_control)) {
            self::_ensure_termination_hook_registered();
        }
    }

    /**
     * Request-wide dedup key for a control frame (matches the generation buffer's keys).
     */
    private static function _control_key(array $frame): string
    {
        if ($frame['kind'] === 'session_refresh') {
            return 's|' . $frame['realm'] . '|' . $frame['session_id'];
        }

        return 'u|' . $frame['site_id'] . '|' . $frame['user_id'];
    }

    /**
     * Whether this request transmits at termination (web) rather than at flush (CLI).
     * Derived from app()->runningInConsole(); a test override forces it deterministically.
     */
    private static function _is_web_context(): bool
    {
        return self::$force_web_context ?? !app()->runningInConsole();
    }

    /**
     * Stage a flushed generation into the request outbox (web path). Deduped by
     * "model|id|site_id" across the whole request via $sent_keys, so a record that already
     * flushed once this request is dropped (one notification per record per request — the
     * client refetches once at request end and sees final state). Registers the one-shot
     * termination transmit hook.
     */
    private static function _stage_outbox(array $emissions, bool $dispatch_emitters): void
    {
        foreach ($emissions as $emission) {
            $key = self::_pending_key($emission['model'], $emission['id'], $emission['site_id']);
            if (isset(self::$sent_keys[$key])) {
                continue;
            }
            self::$sent_keys[$key] = true;
            self::$outbox[$key] = $emission;
        }

        if ($dispatch_emitters) {
            self::$outbox_dispatch_emitters = true;
        }

        if (!empty(self::$outbox) || self::$outbox_dispatch_emitters) {
            self::_ensure_termination_hook_registered();
        }
    }

    /**
     * Register (once per process, lazily on first outboxed emission) the hook that
     * transmits the request outbox after the response is sent. app()->terminating()
     * callbacks run inside kernel->terminate() (invoked in public/index.php after
     * $response->send()), which is exactly when we want the browser-visible response
     * unblocked by the publish round-trip.
     */
    private static function _ensure_termination_hook_registered(): void
    {
        if (self::$termination_hook_registered) {
            return;
        }

        self::$termination_hook_registered = true;
        app()->terminating(function () {
            self::_transmit_outbox();
        });
    }

    /**
     * Transmit the request outbox: publish every staged emission once and perform the
     * (deferred) emitter kick. No capture here — intent was captured at flush. Safe no-op
     * when the outbox is empty (e.g. a rolled-back request, or a second terminating pass).
     */
    private static function _transmit_outbox(): void
    {
        $emissions = array_values(self::$outbox);
        $control = array_values(self::$outbox_control);
        $dispatch_emitters = self::$outbox_dispatch_emitters;

        self::$outbox = [];
        self::$outbox_control = [];
        self::$outbox_dispatch_emitters = false;

        self::_publish_grouped($emissions);

        if ($dispatch_emitters) {
            self::_maybe_dispatch_emitters();
        }

        foreach ($control as $frame) {
            Realtime::transmit_control_frame($frame);
        }
    }

    /**
     * Publish a flushed set of emissions as Model_Changed_Topic messages, coalescing per
     * (site_id, model) into bulk {model, ids:[...]} frames (chunked at FRAME_ID_CHUNK) so a
     * burst of writes to the same model on one site rides ONE frame instead of N. A
     * single-record group keeps the singleton {model, id} shape — wire-compatible with the
     * pre-bulk format and matching the emitter payload shape (Node + client matchers accept
     * an 'id' watcher against either 'id' or an 'ids' array). The request-wide dedup
     * ($sent_keys) is the callers' job; this only shapes and sends.
     *
     * @param array<int, array{model: string, id: int, site_id: int}> $emissions
     */
    private static function _publish_grouped(array $emissions): void
    {
        // Group ids by (site_id, model), preserving first-seen order.
        $groups = [];
        foreach ($emissions as $emission) {
            $group_key = $emission['site_id'] . '|' . $emission['model'];
            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'model' => $emission['model'],
                    'site_id' => $emission['site_id'],
                    'ids' => [],
                ];
            }
            $groups[$group_key]['ids'][] = $emission['id'];
        }

        foreach ($groups as $group) {
            $ids = $group['ids'];

            // Single-record group -> singleton {model, id} (wire compat + emitter parity).
            if (count($ids) === 1) {
                Realtime::publish(
                    'Model_Changed_Topic',
                    ['model' => $group['model'], 'id' => $ids[0]],
                    $group['site_id']
                );

                continue;
            }

            // Multi-record group -> one bulk {model, ids:[...]} frame per chunk.
            foreach (array_chunk($ids, self::FRAME_ID_CHUNK) as $chunk) {
                Realtime::publish(
                    'Model_Changed_Topic',
                    ['model' => $group['model'], 'ids' => array_values($chunk)],
                    $group['site_id']
                );
            }
        }
    }

    /**
     * Dispatch the emitter task only when it can do useful work: realtime enabled,
     * at least one #[Emitter] registered, AND the Node subscriber registry holds a
     * subscription a registered emitter actually serves (has_watched_emitter_topic).
     *
     * The last gate is stricter than "any subscriber exists": with fixture/real
     * emitters present but only Model_Changed_Topic watchers connected, no UNCONSTRAINED
     * emitter watches that topic and any Model_Changed_Topic-composed emitter is model-
     * constrained — so a watcher for a model no emitter serves leaves the gate closed and
     * no task is dispatched — no churn. At most one dispatch per flush; #[Debounce(2)]
     * coalesces across requests.
     */
    private static function _maybe_dispatch_emitters(): void
    {
        if (!Realtime::is_enabled()) {
            return;
        }

        if (!Realtime_Emitter_Service::has_emitters()) {
            return;
        }

        if (!Realtime_Emitter_Service::has_watched_emitter_topic()) {
            return;
        }

        Task::dispatch('Realtime_Emitter_Service', 'run_emitters');
    }

    // -------------------------------------------------------------------------
    // Test seam (framework-internal). Null-capture path adds zero production cost.
    // -------------------------------------------------------------------------

    /**
     * Clear all generation state and capture. Call at the start of a test to prevent
     * static leakage between tests (statics are process-global and tests do not isolate).
     */
    public static function _testing_reset(): void
    {
        self::$pending = [];
        self::$pending_control = [];
        self::$emitters_dirty = false;
        self::$capture = null;
        self::$control_capture = null;
        self::$outbox = [];
        self::$outbox_control = [];
        self::$outbox_dispatch_emitters = false;
        self::$sent_keys = [];
        // NOT reset: $rollback_listener_installed / $termination_hook_registered (process
        // infra, mirrors the rollback listener) and $force_web_context (a test owns it).
    }

    /**
     * Begin capturing flushed control frames (session/user refresh pushes).
     */
    public static function _testing_start_control_capture(): void
    {
        self::$control_capture = [];
    }

    /**
     * The control frames flushed since control capture began.
     *
     * @return array<int, array>
     */
    public static function _testing_captured_control(): array
    {
        return self::$control_capture ?? [];
    }

    /**
     * The staged (not-yet-transmitted) web control outbox.
     *
     * @return array<int, array>
     */
    public static function _testing_control_outbox(): array
    {
        return array_values(self::$outbox_control);
    }

    /**
     * Force (true) or unforce (null) the web transmission path for the next flush, so a
     * framework test can exercise the outbox deterministically from the CLI runner.
     */
    public static function _testing_set_web_context(?bool $web): void
    {
        self::$force_web_context = $web;
    }

    /**
     * The staged (not-yet-transmitted) web outbox, for asserting deferral before the
     * termination transmit runs.
     *
     * @return array<int, array{model: string, id: int, site_id: int}>
     */
    public static function _testing_outbox(): array
    {
        return array_values(self::$outbox);
    }

    /**
     * Run the deferred web transmit directly (the termination hook cannot fire inside the
     * test runner). Publishes the outbox and performs the deferred emitter kick.
     */
    public static function _testing_transmit_outbox(): void
    {
        self::_transmit_outbox();
    }

    /**
     * Begin capturing flushed emissions.
     */
    public static function _testing_start_capture(): void
    {
        self::$capture = [];
    }

    /**
     * The emissions flushed since capture began.
     *
     * @return array<int, array{model: string, id: int, site_id: int}>
     */
    public static function _testing_captured(): array
    {
        return self::$capture ?? [];
    }

    /**
     * Peek the not-yet-flushed buffer (deduped), for asserting buffer state inside a
     * still-open transaction before commit triggers the flush.
     *
     * @return array<int, array{model: string, id: int, site_id: int}>
     */
    public static function _testing_pending(): array
    {
        return array_values(self::$pending);
    }

    /**
     * Peek whether the emitter dirty flag is set for the current generation.
     */
    public static function _testing_emitters_dirty(): bool
    {
        return self::$emitters_dirty;
    }
}
