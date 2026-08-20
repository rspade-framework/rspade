<?php

namespace App\RSpade\Core\Files;

use Illuminate\Support\Facades\DB;
use Throwable;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * File_Disposal_Service - the SOLE authority that releases blob bytes.
 *
 * File_Attachment_Model uses SoftDeletes: delete() means "enter the retention window"
 * (recoverable for rsx.files.deleted_retention_days). Nothing is destroyed inline. This
 * service owns the whole back half of the lifecycle:
 *   - the daily disposal task (destroy pass + blob-release pass), see Phase 2 methods,
 *   - the 6-hourly claim-window sweep of unattached uploads,
 *   - the monthly deep orphan sweep,
 *   - and the shared release_blob_if_orphaned() helper that force_destroy() also calls.
 *
 * RETENTION-AWARE REFCOUNT (the crux): a deduplicated storage blob is PINNED by any
 * attachment that is LIVE or SOFT-DELETED-BUT-NOT-DESTROYED. Only once every attachment
 * referencing a blob is DESTROYED (destroyed_at set) may the bytes be released. Every
 * refcount check therefore uses withTrashed() + whereNull('destroyed_at') - the naive
 * "count remaining attachments" of the old inline hook would under-count and free a blob a
 * recoverable attachment still needs.
 *
 * All blob-touching work serializes on the shared FILE_BLOB_DISPOSAL named write lock so
 * the daily and monthly passes (and force_destroy) can never interleave a refcount check
 * with another pass's unlink.
 */
class File_Disposal_Service extends Rsx_Service_Abstract
{
    /**
     * Release a storage blob IFF no attachment still pins it under the retention-aware
     * refcount rule. Purges the blob-keyed caches too: the search index rides along on
     * File_Storage_Model::delete()'s own cascade; the rendition + thumbnails are NOT
     * cascade-cleaned, so they are unlinked explicitly here.
     *
     * Serialized on FILE_BLOB_DISPOSAL. Safe to call for a NULL/absent storage id (no-op).
     *
     * @param int $storage_id
     * @return bool true if the blob + storage row were released
     */
    public static function release_blob_if_orphaned(int $storage_id): bool
    {
        $token = RsxLocks::named_write_lock('FILE_BLOB_DISPOSAL');
        try {
            // Retention-aware refcount: still pinned by any live-or-retained attachment.
            $pinned = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage_id)
                ->whereNull('destroyed_at')
                ->exists();
            if ($pinned) {
                return false;
            }

            $storage = File_Storage_Model::find($storage_id);
            if ($storage === null) {
                return false;
            }

            $hash = $storage->hash;

            // Release the FK claim from the (now all-destroyed) tombstone attachments so the
            // storage row can be deleted - the _file_attachments.file_storage_id FK is
            // ON DELETE RESTRICT. This IS "releasing the claim on the blob": the tombstone
            // rows persist forever as audit records, with their metadata intact and
            // file_storage_id nulled. Raw update (these are destroyed rows; no lifecycle).
            DB::table('_file_attachments')->where('file_storage_id', $storage_id)->update(['file_storage_id' => null]);

            // Physical blob (resolved through the Rsx_File_Paths choke point).
            $blob_path = $storage->get_full_path();
            if (is_file($blob_path)) {
                @unlink($blob_path);
            }

            // Blob-keyed derived caches that $storage->delete() does NOT cascade.
            self::__purge_blob_derived_caches($hash);

            // Deletes the storage row and (via its own deleted() hook) the _search_indexes row.
            $storage->delete();

            return true;
        } finally {
            RsxLocks::release_lock($token);
        }
    }

    /**
     * Remove the rendition + thumbnail caches derived from a blob hash. Both key on the
     * deduplicated blob hash and are otherwise only reclaimed by their LRU quota sweeps, so
     * a destroyed blob would leave them orphaned. Paths resolve through Rsx_File_Paths.
     *
     * @param string $hash storage blob content hash
     * @return void
     */
    private static function __purge_blob_derived_caches(string $hash): void
    {
        // PDF rendition: renditions_root()/{hash}.pdf
        $rendition = Rsx_File_Paths::renditions_root() . '/' . $hash . '.pdf';
        if (is_file($rendition)) {
            @unlink($rendition);
        }

        // Thumbnails: thumbnails_root()/{preset,dynamic}/{hash}_*.webp
        foreach (['preset', 'dynamic'] as $sub) {
            $matches = glob(Rsx_File_Paths::thumbnails_root() . '/' . $sub . '/' . $hash . '_*.webp');
            foreach ($matches ?: [] as $thumb) {
                @unlink($thumb);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Scheduled disposal tasks
    // -------------------------------------------------------------------------

    /**
     * DAILY disposal (02:00). Two batched, keyset-paginated passes (bounded memory):
     *   (a) DESTROY: attachments whose retention window has elapsed (deleted_at < now -
     *       deleted_retention_days, destroyed_at still NULL) go through the destroy.hold gate
     *       + the file.attachment.destroyed action, then get stamped destroyed_at (a permanent
     *       audit tombstone).
     *   (b) BLOB-RELEASE: for blobs referenced by attachments destroyed within
     *       disposal_lookback_days, recompute the retention-aware refcount and release the
     *       bytes at zero.
     */
    #[Task('Dispose of attachments past their retention window and release orphaned blobs')]
    #[Exclusive]
    #[Schedule('0 2 * * *')]
    public static function run_daily_disposal(Task_Instance $task, array $params = [])
    {
        $chunk = 1000;
        $retention_days = (int) config('rsx.files.deleted_retention_days', 30);
        $lookback_days = (int) config('rsx.files.disposal_lookback_days', 60);

        // (a) DESTROY PASS.
        $destroy_cutoff = Rsx_Time::subtract(Rsx_Time::now_iso(), $retention_days * 86400);
        $destroyed = 0;
        $held = 0;
        // Keyset-walked: a HELD attachment keeps destroyed_at NULL and so stays in the
        // predicate, but the cursor has already passed its id - no re-visit, no spin.
        $past_retention = File_Attachment_Model::withTrashed()
            ->whereNotNull('deleted_at')
            ->whereNull('destroyed_at')
            ->where('deleted_at', '<', $destroy_cutoff)
            ->result_set($chunk);

        foreach ($past_retention as $attachment) {
            if (self::__destroy_attachment($attachment, $task)) {
                $destroyed++;
            } else {
                $held++;
            }
            $task->heartbeat();
        }

        // (b) BLOB-RELEASE PASS: distinct blobs of recently-destroyed attachments.
        $release_cutoff = Rsx_Time::subtract(Rsx_Time::now_iso(), $lookback_days * 86400);
        $released = 0;
        $last_sid = 0;
        while (true) {
            $storage_ids = File_Attachment_Model::withTrashed()
                ->whereNotNull('destroyed_at')
                ->where('destroyed_at', '>=', $release_cutoff)
                ->whereNotNull('file_storage_id')
                ->where('file_storage_id', '>', $last_sid)
                ->orderBy('file_storage_id')
                ->distinct()
                ->limit($chunk)
                ->pluck('file_storage_id');
            if ($storage_ids->isEmpty()) {
                break;
            }
            foreach ($storage_ids as $sid) {
                $last_sid = (int) $sid;
                if (self::release_blob_if_orphaned((int) $sid)) {
                    $released++;
                }
            }
            $task->heartbeat();
        }

        if ($destroyed || $held || $released) {
            $task->info("Destroyed {$destroyed} attachment(s), {$held} held; released {$released} blob(s).");
        }

        return ['destroyed' => $destroyed, 'held' => $held, 'blobs_released' => $released];
    }

    /**
     * MONTHLY deep orphan sweep (first Sunday, 02:00). Classic cron cannot express "first
     * Sunday", so this fires every Sunday (0 2 * * 0) and guards on day-of-month <= 7. Full
     * reconciliation with no time window, three keyset/streaming passes:
     *   (a) DB: every _file_storage row with a retention-aware refcount of 0 -> release.
     *   (b) DISK: stream-walk blob_root(); unlink files older than the age guard that have no
     *       _file_storage row (the guard protects a just-written blob whose row commits after
     *       the file lands).
     *   (c) UPLOADS: soft-delete stale plain-local unassigned uploads (they enter retention;
     *       WP-A external attachments and their bytes are NEVER touched).
     *
     * A ['force' => true] param bypasses the first-Sunday guard (tests / manual invocation).
     */
    #[Task('Monthly deep file sweep: reconcile blob refcounts + disk, sweep stale unassigned uploads')]
    #[Exclusive]
    #[Schedule('0 2 * * 0')]
    public static function run_monthly_deep_sweep(Task_Instance $task, array $params = [])
    {
        if (empty($params['force']) && (int) date('j') > 7) {
            return ['skipped' => 'not the first Sunday of the month'];
        }

        $chunk = 1000;
        $min_age_days = (int) config('rsx.files.disk_orphan_min_age_days', 14);

        // (a) DB SIDE: storage rows with zero retention-aware references. Raw DB::table so the
        // SoftDeletes global scope does not hide the retained rows that must still count.
        $released = 0;
        $last_sid = 0;
        while (true) {
            $orphan_ids = DB::table('_file_storage as s')
                ->where('s.id', '>', $last_sid)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('_file_attachments as a')
                        ->whereColumn('a.file_storage_id', 's.id')
                        ->whereNull('a.destroyed_at');
                })
                ->orderBy('s.id')
                ->limit($chunk)
                ->pluck('s.id');
            if ($orphan_ids->isEmpty()) {
                break;
            }
            foreach ($orphan_ids as $sid) {
                $last_sid = (int) $sid;
                if (self::release_blob_if_orphaned((int) $sid)) {
                    $released++;
                }
            }
            $task->heartbeat();
        }

        // (b) DISK SIDE.
        $disk_deleted = self::__sweep_disk_orphans($task, $min_age_days, $chunk);

        // (c) UNASSIGNED UPLOADS: soft-delete stale plain-local unattached uploads.
        $upload_cutoff = Rsx_Time::subtract(Rsx_Time::now_iso(), $min_age_days * 86400);
        $uploads_swept = 0;
        $stale_uploads = File_Attachment_Model::whereNull('fileable_id')
            ->whereNull('handler_class')          // plain local uploads only (never WP-A external)
            ->whereNotNull('file_storage_id')
            ->where('created_at', '<', $upload_cutoff)
            ->result_set($chunk);

        foreach ($stale_uploads as $attachment) {
            $attachment->delete();   // soft-delete -> enters the retention window
            $uploads_swept++;
            $task->heartbeat();
        }

        if ($released || $disk_deleted || $uploads_swept) {
            $task->info("Released {$released} orphaned blob(s); removed {$disk_deleted} orphaned disk file(s); swept {$uploads_swept} stale unassigned upload(s).");
        }

        return ['blobs_released' => $released, 'disk_files_removed' => $disk_deleted, 'uploads_swept' => $uploads_swept];
    }

    /**
     * CLAIM-WINDOW SWEEP (every 6 hours). An upload arrives UNATTACHED and is reachable only
     * by its unguessable key until app code claims it with attach_to()/add_to(). That claim
     * window is what bounds the key's usefulness - it is the successor to the bound the old
     * session_id stamp implied, now that claimability is a property of the attachment rather
     * than of a browser session.
     *
     * Anything still unattached past config('rsx.attachments.unattached_claim_window_hours')
     * is SOFT-deleted (delete(), the retention-aware path - NOT force_destroy): the row enters
     * the normal recoverable window and its blob stays pinned, so a mis-timed sweep of a file
     * someone still wanted is recoverable for the full retention period.
     *
     * Never touched: handler-backed (external) attachments, anything already attached,
     * anything already soft-deleted. 0/null window disables the sweep.
     *
     * The monthly deep sweep has its own, much older (disk_orphan_min_age_days) unassigned
     * pass; this one is the tight, frequent bound and the monthly pass remains the backstop
     * that also reconciles blobs and disk.
     */
    #[Task('Sweep unattached uploads past their claim window')]
    #[Exclusive]
    #[Schedule('every 6 hours')]
    public static function sweep_unclaimed_uploads(Task_Instance $task, array $params = [])
    {
        $window_hours = (int) config('rsx.attachments.unattached_claim_window_hours', 24);
        if ($window_hours <= 0) {
            return ['skipped' => 'claim-window sweep disabled', 'swept' => 0];
        }

        $cutoff = Rsx_Time::subtract(Rsx_Time::now_iso(), $window_hours * 3600);

        // Keyset-walked (result_set): an unbounded table, and a backlog of abandoned uploads
        // is exactly the shape that grows without warning. Each delete() removes the row from
        // the predicate, and the cursor only moves forward, so there is no re-visit or spin.
        $swept = 0;
        $unclaimed = File_Attachment_Model::whereNull('fileable_type')
            ->whereNull('fileable_id')
            ->whereNull('handler_class')          // plain local uploads only (never WP-A external)
            ->where('created_at', '<', $cutoff)
            ->result_set(1000);

        foreach ($unclaimed as $attachment) {
            $attachment->delete();   // soft-delete -> enters the retention window
            $swept++;
            $task->heartbeat();
        }

        if ($swept) {
            $task->info("Swept {$swept} unattached upload(s) past the {$window_hours}h claim window.");
        }

        return ['swept' => $swept, 'window_hours' => $window_hours];
    }

    /**
     * Destroy ONE attachment: consult the hold gate, fire the destroyed action, then stamp
     * destroyed_at. Returns true if destroyed, false if HELD (the gate held it, or a
     * destroyed listener threw - either way the row is left un-stamped for the next run,
     * never half-destroyed). The gate uses the framework convention (a listener returns true
     * to PERMIT, a non-true value HOLDS). The action fires OUTSIDE any blob-lock section.
     */
    private static function __destroy_attachment(File_Attachment_Model $attachment, Task_Instance $task): bool
    {
        if (Rsx::trigger_gate('file.attachment.destroy.hold', $attachment) !== true) {
            return false;
        }

        try {
            Rsx::trigger_action('file.attachment.destroyed', $attachment);
        } catch (Throwable $e) {
            // A throwing listener ABORTS this attachment's destruction (fail-loud, not
            // half-done): leave it un-stamped and retry next run.
            $task->error('file.attachment.destroyed listener threw for attachment ' . $attachment->id . '; deferring: ' . $e->getMessage());
            return false;
        }

        $attachment->destroyed_at = Rsx_Time::now_iso();
        $attachment->save();
        return true;
    }

    /**
     * Streaming disk-orphan sweep: walk blob_root() with a recursive iterator (never
     * materializing the file list), batch-probe each chunk of hashes against _file_storage,
     * and unlink files older than the age guard whose hash has no row.
     */
    private static function __sweep_disk_orphans(Task_Instance $task, int $min_age_days, int $chunk): int
    {
        $blob_root = Rsx_File_Paths::blob_root();
        if (!is_dir($blob_root)) {
            return 0;
        }

        $age_cutoff = time() - $min_age_days * 86400;
        $deleted = 0;
        $pending = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($blob_root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            // Age guard: a just-written blob's DB row can commit AFTER the file lands.
            if ($file->getMTime() > $age_cutoff) {
                continue;
            }
            // The blob filename IS the content hash (the two-level shard dirs sit above it).
            $pending[$file->getFilename()] = $file->getPathname();
            if (count($pending) >= $chunk) {
                $deleted += self::__sweep_disk_batch($pending);
                $pending = [];
                $task->heartbeat();
            }
        }
        if (!empty($pending)) {
            $deleted += self::__sweep_disk_batch($pending);
        }

        return $deleted;
    }

    /**
     * Probe a batch of {hash => path} against _file_storage and unlink the files whose hash
     * has no row. Serialized on FILE_BLOB_DISPOSAL and re-checked per file inside the lock, so
     * a blob written between the walk and the unlink is never destroyed.
     *
     * @param array<string, string> $pending hash => absolute path
     */
    private static function __sweep_disk_batch(array $pending): int
    {
        $known = array_flip(
            DB::table('_file_storage')->whereIn('hash', array_keys($pending))->pluck('hash')->all()
        );

        $deleted = 0;
        $token = RsxLocks::named_write_lock('FILE_BLOB_DISPOSAL');
        try {
            foreach ($pending as $hash => $path) {
                if (isset($known[$hash])) {
                    continue;
                }
                // Re-check under the lock (a fresh upload may have created the row meanwhile).
                if (DB::table('_file_storage')->where('hash', $hash)->exists()) {
                    continue;
                }
                if (is_file($path)) {
                    @unlink($path);
                    $deleted++;
                }
            }
        } finally {
            RsxLocks::release_lock($token);
        }

        return $deleted;
    }
}
