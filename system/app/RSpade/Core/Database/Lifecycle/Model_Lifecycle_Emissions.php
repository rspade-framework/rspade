<?php

namespace App\RSpade\Core\Database\Lifecycle;

use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Model_Lifecycle_Emissions
 *
 * Per-request dispatch buffer + transaction-commit flush for model lifecycle hooks
 * (after_create / after_update / after_delete). Modeled on Realtime_Emissions but kept
 * SEPARATE for this phase (a later phase merges realtime into this engine); it must not
 * perturb realtime behavior.
 *
 * The single-write choke points (Rsx_Model_Abstract::save()/delete()) call queue() with a
 * model instance, an event, and — for updates — the changed field names. Entries buffer in
 * order and flush EXACTLY ONCE via DB::afterCommit(): when no transaction is active the
 * flush runs immediately; inside a transaction it runs after commit; an OUTERMOST rollback
 * discards the buffered generation (Laravel transaction-manager semantics), so a hook fires
 * only for a COMMITTED write. At flush each entry runs its model's after_* method
 * SYNCHRONOUSLY in the caller's post-commit context.
 *
 * Unlike realtime emissions (which are notification-only, deduped hints), lifecycle entries
 * are NOT deduped: each committed write is a distinct side-effect event whose hook must run.
 *
 * Re-entrancy / cycle guard: a hook may itself write, which (running post-commit, outside a
 * transaction) queues a new entry and triggers a nested flush. A well-behaved finite chain
 * (A's hook writes B, B's hook writes C, ...) terminates naturally; a genuine cycle (a hook
 * that unconditionally rewrites its own record) would recurse forever, so a flush-depth cap
 * (mirroring Realtime_Emissions::TOUCH_DEPTH_CAP) drops the runaway generation to break the
 * loop rather than exhaust the stack.
 */
class Model_Lifecycle_Emissions
{
    /**
     * Lifecycle event kinds carried on a buffered entry.
     */
    public const EVENT_CREATE = 'create';
    public const EVENT_UPDATE = 'update';
    public const EVENT_DELETE = 'delete';

    /**
     * Maximum nested flush depth. A hook that writes triggers a nested flush; a legitimate
     * chain is shallow, so exceeding this cap indicates a write cycle. Mirrors the
     * TOUCH_DEPTH_CAP runaway guard in Realtime_Emissions.
     */
    private const FLUSH_DEPTH_CAP = 25;

    /**
     * Buffered lifecycle entries for the current generation, in write order.
     *
     * @var array<int, array{model: Rsx_Model_Abstract, event: string, changed: array<int, string>}>
     */
    private static array $pending = [];

    /**
     * Current nested-flush depth (a hook that writes re-enters _flush()).
     */
    private static int $flush_depth = 0;

    /**
     * Whether the outermost-rollback listener has been installed for this process.
     */
    private static bool $rollback_listener_installed = false;

    /**
     * Test capture seam. Null in production (zero overhead). When a test calls
     * _testing_start_capture(), each flushed entry is recorded here (model name, event,
     * changed fields) so a test can assert dispatch without relying on hook side effects.
     *
     * @var array<int, array{model: string, event: string, changed: array<int, string>}>|null
     */
    private static ?array $capture = null;

    /**
     * Queue a lifecycle entry for a model that declares the corresponding hook. The
     * choke points already gate on Model_Lifecycle_Registry, so this is only reached for a
     * model that actually overrides the event's hook. Registers the afterCommit flush.
     *
     * @param array<int, string> $changed_fields Changed column names (update only).
     */
    public static function queue(Rsx_Model_Abstract $model, string $event, array $changed_fields = []): void
    {
        self::$pending[] = [
            'model' => $model,
            'event' => $event,
            'changed' => $changed_fields,
        ];

        self::_ensure_flush_registered();
    }

    /**
     * Register the afterCommit flush. DB::afterCommit runs the callback immediately when no
     * transaction is active, after commit inside one, and discards it on rollback.
     *
     * Registered on EVERY queue (not once per generation), mirroring Realtime_Emissions: a
     * rollback — including a nested savepoint rollback — discards previously registered
     * callbacks, so a "registered once" flag would go stale and silently suppress every later
     * flush in the process. _flush() drains state, so the extra callbacks no-op. The
     * outermost-rollback listener additionally discards the buffered generation.
     */
    private static function _ensure_flush_registered(): void
    {
        self::_ensure_rollback_listener();

        DB::afterCommit(function () {
            self::_flush();
        });
    }

    /**
     * Install (once per process) a listener that discards the buffered generation when the
     * OUTERMOST transaction rolls back — every hook queued in it is void (the write never
     * committed). Savepoint (nested) rollbacks are deliberately NOT handled here, matching
     * Realtime_Emissions; a Phase-1 single write does not nest savepoints.
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
            }
        });
    }

    /**
     * Flush the buffered generation: drain the buffer, then run each entry's hook
     * synchronously. Draining first (like Realtime_Emissions::_flush) means a hook that
     * itself writes opens a FRESH generation whose own afterCommit drives a nested flush.
     *
     * The flush-depth cap breaks a write cycle: past the cap the runaway generation is
     * dropped rather than recursed into.
     */
    private static function _flush(): void
    {
        if (empty(self::$pending)) {
            return;
        }

        if (self::$flush_depth >= self::FLUSH_DEPTH_CAP) {
            // A hook keeps writing the record(s) that re-trigger it — a cycle. Drop the
            // runaway generation to break the loop instead of exhausting the stack.
            self::$pending = [];

            return;
        }

        $entries = self::$pending;
        self::$pending = [];

        self::$flush_depth++;

        try {
            foreach ($entries as $entry) {
                self::_run($entry);
            }
        } finally {
            self::$flush_depth--;
        }
    }

    /**
     * Run one entry's hook (and record it in the capture seam when active).
     *
     * @param array{model: Rsx_Model_Abstract, event: string, changed: array<int, string>} $entry
     */
    private static function _run(array $entry): void
    {
        $model = $entry['model'];

        if (self::$capture !== null) {
            self::$capture[] = [
                'model' => class_basename($model),
                'event' => $entry['event'],
                'changed' => $entry['changed'],
            ];
        }

        switch ($entry['event']) {
            case self::EVENT_CREATE:
                $model->after_create();
                break;

            case self::EVENT_UPDATE:
                $model->after_update($entry['changed']);
                break;

            case self::EVENT_DELETE:
                $model->after_delete();
                break;

            default:
                shouldnt_happen('Unknown model lifecycle event: ' . $entry['event']);
        }
    }

    // -------------------------------------------------------------------------
    // Test seam (framework-internal). Null-capture path adds zero production cost.
    // -------------------------------------------------------------------------

    /**
     * Clear all generation state and capture. Call at the start of a test to prevent static
     * leakage between tests (statics are process-global and tests do not isolate).
     */
    public static function _testing_reset(): void
    {
        self::$pending = [];
        self::$flush_depth = 0;
        self::$capture = null;
        // NOT reset: $rollback_listener_installed (process infra, mirrors Realtime_Emissions).
    }

    /**
     * Begin capturing flushed lifecycle entries.
     */
    public static function _testing_start_capture(): void
    {
        self::$capture = [];
    }

    /**
     * The lifecycle entries flushed since capture began.
     *
     * @return array<int, array{model: string, event: string, changed: array<int, string>}>
     */
    public static function _testing_captured(): array
    {
        return self::$capture ?? [];
    }

    /**
     * Peek the not-yet-flushed buffer, for asserting buffer state inside a still-open
     * transaction before commit triggers the flush.
     *
     * @return array<int, array{model: string, event: string, changed: array<int, string>}>
     */
    public static function _testing_pending(): array
    {
        $out = [];

        foreach (self::$pending as $entry) {
            $out[] = [
                'model' => class_basename($entry['model']),
                'event' => $entry['event'],
                'changed' => $entry['changed'],
            ];
        }

        return $out;
    }
}
