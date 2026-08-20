<?php

namespace App\RSpade\Core\Realtime;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Realtime_Emitter_Service
 *
 * Runs #[Emitter] methods: recompute each live-subscribed emitter value, hash-diff
 * it against the last published hash, and publish the emitter's topic when it changed.
 *
 * An emitter is a `public static` method carrying `#[Emitter('Topic_Class_Name')]`
 * with the signature `(int $site_id, array $filter): mixed`. Discovery mirrors the
 * #[Task]/#[Schedule] manifest attribute scan (Task::get_scheduled_tasks()): the
 * manifest already indexes every public static method's attributes, so an #[Emitter]
 * anywhere in the scanned tree is found without a class registry.
 *
 * Trigger model (owner amendment: NO timer): destructive model-layer writes kick this
 * task via Realtime_Emissions on transaction commit — nothing ticks while nothing is
 * happening. The dispatch gate (Realtime_Emissions::_maybe_dispatch_emitters) only
 * fires when a subscribed topic actually has a registered emitter, and this engine
 * only recomputes topics present in the Node subscriber registry — so emitters run
 * only for topics someone is watching.
 */
class Realtime_Emitter_Service extends Rsx_Service_Abstract
{
    /**
     * Emitter value-hash TTL (seconds). A subscription that goes away stops being
     * recomputed; its stored hash ages out so a later resubscribe re-seeds cleanly.
     */
    private const HASH_TTL = 86400;

    /**
     * Per-process memo of discovered emitters: [{class, method, topic, model_constraint}].
     *
     * @var array<int, array{class: string, method: string, topic: string, model_constraint: ?string}>|null
     */
    private static ?array $emitters_memo = null;

    /**
     * All registered #[Emitter] methods, discovered from the manifest attribute index.
     *
     * The OPTIONAL second positional attribute argument is a model constraint —
     * #[Emitter('Model_Changed_Topic', 'Client_Model')] — that scopes the emitter to
     * registry entries whose filter.model matches (see has_watched_emitter_topic and
     * run_emitters_engine). It is REQUIRED to compose an emitter onto the shared
     * Model_Changed_Topic without making every model write kick the task and fan out to
     * every watched record: the constraint keeps the no-churn property exactly. Absent
     * (null) = unconstrained (the original behavior; custom per-emitter topics need no
     * constraint and stay unchanged).
     *
     * @return array<int, array{class: string, method: string, topic: string, model_constraint: ?string}>
     */
    public static function registered_emitters(): array
    {
        if (self::$emitters_memo !== null) {
            return self::$emitters_memo;
        }

        $found = [];

        foreach (Manifest::get_all() as $info) {
            if (!isset($info['fqcn']) || !isset($info['public_static_methods'])) {
                continue;
            }

            foreach ($info['public_static_methods'] as $method_name => $method_info) {
                foreach ($method_info['attributes'] ?? [] as $attr_name => $attr_instances) {
                    if ($attr_name !== 'Emitter' && !str_ends_with($attr_name, '\\Emitter')) {
                        continue;
                    }

                    foreach ($attr_instances as $instance) {
                        $topic = $instance[0] ?? null;
                        if ($topic) {
                            $constraint = $instance[1] ?? null;
                            $found[] = [
                                'class' => $info['fqcn'],
                                'method' => $method_name,
                                'topic' => (string) $topic,
                                'model_constraint' => $constraint !== null ? (string) $constraint : null,
                            ];
                        }
                    }
                }
            }
        }

        self::$emitters_memo = $found;

        return $found;
    }

    /**
     * Whether any #[Emitter] is registered (first, cheap dispatch gate).
     */
    public static function has_emitters(): bool
    {
        return !empty(self::registered_emitters());
    }

    /**
     * The distinct topic names that have a registered emitter.
     *
     * @return array<int, string>
     */
    public static function emitter_topics(): array
    {
        $topics = [];
        foreach (self::registered_emitters() as $emitter) {
            $topics[$emitter['topic']] = true;
        }

        return array_keys($topics);
    }

    /**
     * Whether the Node subscriber registry currently holds a subscription that a registered
     * emitter actually serves. This is the smart dispatch gate: it prevents task churn when
     * subscribers exist but none watch a topic an emitter can produce for.
     *
     * A registry entry counts for an emitter only when the topic matches AND — for a
     * MODEL-CONSTRAINED emitter (#[Emitter('Model_Changed_Topic', 'Client_Model')]) — the
     * entry's filter.model equals the constraint. So a page watching Model_Changed_Topic for
     * some OTHER model (e.g. only Contact_Model watchers) does NOT open the gate for a
     * Client_Model-constrained emitter — the exact no-churn property that lets an emitter be
     * composed onto the shared model-change topic. An unconstrained emitter counts any entry
     * on its topic (original behavior). Independent of realtime enablement so it is
     * unit-testable against a seeded registry.
     */
    public static function has_watched_emitter_topic(): bool
    {
        if (!self::has_emitters()) {
            return false;
        }

        foreach (Realtime::subscribed_registry_entries() as $entry) {
            if (!empty(self::emitters_serving_entry($entry))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registered emitters that serve one registry entry: topic match AND — for a
     * MODEL-CONSTRAINED emitter — filter.model equal to the constraint. The single
     * predicate behind has_watched_emitter_topic(), the engine loop, and the
     * subs-changed endpoint's entry filter, so all three agree by construction.
     *
     * @param array{site_id?: int, topic?: string, filter?: array} $entry
     * @return array<int, array{class: string, method: string, topic: string, model_constraint: ?string}>
     */
    public static function emitters_serving_entry(array $entry): array
    {
        $topic = $entry['topic'] ?? null;
        if ($topic === null) {
            return [];
        }

        $filter = is_array($entry['filter'] ?? null) ? $entry['filter'] : [];

        $serving = [];
        foreach (self::registered_emitters() as $emitter) {
            if ($emitter['topic'] !== $topic) {
                continue;
            }

            if ($emitter['model_constraint'] !== null
                && ($filter['model'] ?? null) !== $emitter['model_constraint']) {
                continue;
            }

            $serving[] = $emitter;
        }

        return $serving;
    }

    /**
     * Recompute and publish changed emitters.
     *
     * #[Debounce(2)]: at most one running + one pending per class::method, and the
     * coalesced follow-up waits 2s after the prior run completed — so a burst of writes
     * collapses into a single trailing recompute.
     */
    #[Task('Run realtime emitters')]
    #[Debounce(2)]
    public static function run_emitters(Task_Instance $task, array $params = []): array
    {
        $result = self::run_emitters_engine();

        $task->info("Realtime emitters: ran {$result['ran']}, published {$result['published']}.");

        return $result;
    }

    /**
     * The engine body, callable directly (no Task_Instance) for deterministic tests.
     *
     * For each Node registry entry (site_id, topic, filter) whose topic has a
     * registered emitter (and, for a model-constrained emitter, whose filter.model matches
     * the constraint): call the emitter, hash the value, compare against the stored
     * hash keyed by sha1(class::method|site|topic|canonical_filter):
     *   - missing key  -> store + PUBLISH (the belt; see the absent-hash reasoning at
     *                     the comparison itself). Baselines are seeded at SUBSCRIBE time
     *                     by seed_subscriptions_engine(), so an absent hash here is never
     *                     "the subscriber just resynced".
     *   - changed hash -> store + publish the topic (payload = the filter itself, so
     *                     Node's server-side filter matching routes it to the watcher)
     *   - same hash    -> nothing
     * An emitter that throws bubbles (fail loud — the task records the failure).
     *
     * @return array{ran: int, published: int}
     */
    public static function run_emitters_engine(): array
    {
        // Group emitters by topic (normally one per topic, but allow more).
        $by_topic = [];
        foreach (self::registered_emitters() as $emitter) {
            $by_topic[$emitter['topic']][] = $emitter;
        }

        if (empty($by_topic)) {
            return ['ran' => 0, 'published' => 0];
        }

        // Read the current registry fresh (a long-lived worker must not reuse a memo).
        Realtime::reset_registry_memo();

        // Distinct (site_id, topic, filter) work items whose topic has an emitter.
        $seen = [];
        $work = [];
        foreach (Realtime::subscribed_registry_entries() as $entry) {
            $topic = $entry['topic'];
            if (!isset($by_topic[$topic])) {
                continue;
            }

            $site_id = $entry['site_id'];
            $filter = $entry['filter'];
            $canonical = self::_canonical_filter_json($filter);

            $dedupe_key = $site_id . '|' . $topic . '|' . $canonical;
            if (isset($seen[$dedupe_key])) {
                continue;
            }
            $seen[$dedupe_key] = true;

            $work[] = [
                'site_id' => $site_id,
                'topic' => $topic,
                'filter' => $filter,
                'canonical' => $canonical,
            ];
        }

        $ran = 0;
        $published = 0;

        foreach ($work as $item) {
            foreach ($by_topic[$item['topic']] as $emitter) {
                // A model-constrained emitter only runs for registry entries whose filter.model
                // matches — so a Model_Changed_Topic-composed emitter recomputes only for the
                // records of the model it serves, never for every model watched on the topic.
                // The constraint is per-emitter (multiple emitters may share one topic).
                if ($emitter['model_constraint'] !== null
                    && ($item['filter']['model'] ?? null) !== $emitter['model_constraint']) {
                    continue;
                }

                $value = $emitter['class']::{$emitter['method']}($item['site_id'], $item['filter']);
                $ran++;

                $value_hash = sha1(json_encode($value));
                $identity = self::_emitter_identity($emitter, $item['site_id'], $item['topic'], $item['canonical']);
                $previous = Realtime::emitter_hash_get($identity);

                if ($previous === $value_hash) {
                    continue; // unchanged — nothing to publish
                }

                Realtime::emitter_hash_put($identity, $value_hash, self::HASH_TTL);

                // ABSENT BASELINE PUBLISHES (the belt). A work item exists only because the
                // Node subscriber registry holds a live subscription for it, and the task is
                // dispatched only when a live watcher exists — so at this point a subscriber
                // EXISTS BY CONSTRUCTION and an absent baseline means one of exactly three
                // things, all of which need the frame:
                //   (a) the subscribe -> seed race (the write beat seed_subscriptions),
                //   (b) redis was wiped (a maintenance window / framework update restarts it
                //       empty, so every stored hash is gone while the pages stay open),
                //   (c) the 86400s TTL expired under a long-lived quiet subscription — which
                //       produces NO new-member event ever (the member never left the
                //       registry), so nothing else would re-seed it.
                // Suppressing here is what silently swallowed the first change after any hash
                // gap. Over-emitting is the harmless direction: frames are content-free
                // doorbells and every callback is an idempotent refetch that repaints only on
                // changed data.
                Realtime::publish($item['topic'], $item['filter'], $item['site_id']);
                $published++;
            }
        }

        return ['ran' => $ran, 'published' => $published];
    }

    /**
     * Seed emitter baselines for identities that just became subscribed.
     *
     * UNMANAGED ON PURPOSE — no #[Exclusive]/#[Debounce]. A managed task coalesces by
     * identity and the coalesced enqueue DROPS the new dispatch's params, so a second
     * seed batch arriving while one is pending would silently lose its targets. Seeds
     * are cheap and idempotent, and the relay already coalesces bursts into one POST.
     */
    #[Task('Seed emitter baselines for newly subscribed identities')]
    public static function seed_subscriptions(Task_Instance $task, array $params = []): array
    {
        $entries = is_array($params['entries'] ?? null) ? $params['entries'] : [];

        $result = self::seed_subscriptions_engine($entries);

        $task->info("Realtime emitter seed: entries {$result['entries']}, seeded {$result['seeded']}.");

        return $result;
    }

    /**
     * The seed body, callable directly (no Task_Instance) for deterministic tests —
     * mirroring run_emitters_engine().
     *
     * The work list IS the notified entries: this does NOT read the subscriber registry
     * (the registry is the run loop's input, and re-reading it here would seed baselines
     * for identities that never became new). For each entry, every emitter serving it is
     * computed and its baseline hash stored UNCONDITIONALLY — no get, and NO publish. A
     * newly-subscribed member has just resynced (the subscribe ack IS the resync signal),
     * and identical subscriptions collapse to ONE registry member, so a NEW member means
     * no other live holder of that identity is waiting on a frame. The unconditional put
     * also refreshes the TTL when an existing subscriber resubscribes.
     *
     * @param array<int, array{site_id?: int, topic?: string, filter?: array}> $entries
     * @return array{entries: int, seeded: int}
     */
    public static function seed_subscriptions_engine(array $entries): array
    {
        if (!self::has_emitters()) {
            return ['entries' => 0, 'seeded' => 0];
        }

        $counted = 0;
        $seeded = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['topic'])) {
                continue;
            }

            $site_id = (int) ($entry['site_id'] ?? 0);
            $topic = (string) $entry['topic'];
            $filter = is_array($entry['filter'] ?? null) ? $entry['filter'] : [];

            $serving = self::emitters_serving_entry(['topic' => $topic, 'filter' => $filter]);
            if (empty($serving)) {
                continue;
            }

            $counted++;
            $canonical = self::_canonical_filter_json($filter);

            foreach ($serving as $emitter) {
                $value = $emitter['class']::{$emitter['method']}($site_id, $filter);
                $identity = self::_emitter_identity($emitter, $site_id, $topic, $canonical);

                Realtime::emitter_hash_put($identity, sha1(json_encode($value)), self::HASH_TTL);
                $seeded++;
            }
        }

        return ['entries' => $counted, 'seeded' => $seeded];
    }

    /**
     * The stored-hash identity for one (emitter, site, topic, canonical filter) tuple.
     * Includes the emitter's class::method: two emitters on the same topic must not share
     * a stored hash, or they would clobber each other and ping-pong publishes for
     * unchanged values on every run. Shared by the run loop and the seed engine so a
     * seeded baseline and the value the run loop compares are the SAME key.
     *
     * @param array{class: string, method: string, topic: string, model_constraint: ?string} $emitter
     */
    private static function _emitter_identity(array $emitter, int $site_id, string $topic, string $canonical): string
    {
        return sha1(
            $emitter['class'] . '::' . $emitter['method']
            . '|' . $site_id . '|' . $topic . '|' . $canonical
        );
    }

    /**
     * Canonical JSON of a (shallow) subscription filter: keys sorted, so identical
     * filters hash to the same emitter identity regardless of key order. Paired with
     * realtime-server.js build_registry_member(), which sorts filter keys the same way
     * before writing the registry member.
     */
    private static function _canonical_filter_json(array $filter): string
    {
        ksort($filter);

        return json_encode($filter);
    }

    /**
     * Clear the per-process emitter memo (framework tests only — a fixture emitter may
     * be added/removed relative to when the memo was first built in the process).
     */
    public static function _testing_reset(): void
    {
        self::$emitters_memo = null;
    }
}
