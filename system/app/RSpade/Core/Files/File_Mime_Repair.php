<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;

/**
 * Shared repair for document attachments a per-file-flaky byte sniff mis-routed at ingest
 * (the classic case: a valid .docx/.xlsx sniffed as the generic container application/zip,
 * silently disabling its preview, text extraction, and thumbnail).
 *
 * Pipeline-type resolution is extension-first for documents (File_Attachment_Model::
 * resolve_pipeline_mime). This one-shot brings EXISTING rows into line with that policy:
 *
 *   - Affected attachment = one whose stored file_type_id disagrees with the coarse bucket
 *     the pipeline mime now yields. Only document-extension rows can diverge (the pipeline
 *     mime equals the sniff for every non-document family), so those are the only candidates.
 *   - For each affected attachment: recompute + save file_type_id from the pipeline mime.
 *     The stored mime_type column (the raw sniff) is LEFT ALONE - it is the serve-time value.
 *   - For each affected BLOB (deduplicated): reset _file_storage.is_indexed=0 to re-queue
 *     text extraction, and delete the cached PDF rendition + thumbnails (stale icon artifacts
 *     produced while the document was mis-routed).
 *
 * The re-queued extraction is drained by the normal Search_Index_Service::index_pending task
 * (its every-5-minutes cron). Callers that want an immediate drain dispatch that task after a
 * run() with a non-zero blob count - run() itself never dispatches, so it is safe to call from
 * a migration (which must not assume a task worker is available mid-migrate).
 *
 * Idempotent: after a run the file_type_id matches the pipeline bucket, so a second run finds
 * nothing. dry_run reports the counts without changing anything.
 */
class File_Mime_Repair
{
    /**
     * Run the repair. Returns counts: ['attachments' => int, 'blobs' => int, 'caches' => int].
     * Never dispatches a task (see class docblock); the caller decides whether to drain now.
     *
     * @param bool $dry_run When true, computes the counts without writing anything.
     * @return array{attachments:int,blobs:int,caches:int}
     */
    public static function run(bool $dry_run = false): array
    {
        $document_extensions = array_keys(config('rsx.files.document_mime_by_extension', []));
        if (empty($document_extensions)) {
            // The config map ships in the same release as this repair; its absence means the
            // caller is running against an incomplete tree. Nothing to repair against.
            return ['attachments' => 0, 'blobs' => 0, 'caches' => 0];
        }

        $attachments_repaired = 0;
        $affected_storage_ids = [];

        // A cross-site maintenance sweep: bypass the site read-scope so EVERY site's attachments
        // are repaired regardless of the invoking session (a migration/CLI has no meaningful one).
        File_Attachment_Model::without_site_scope(function () use ($document_extensions, &$attachments_repaired, &$affected_storage_ids, $dry_run) {
            File_Attachment_Model::whereIn('file_extension', $document_extensions)
                ->orderBy('id')
                ->chunkById(500, function ($attachments) use (&$attachments_repaired, &$affected_storage_ids, $dry_run) {
                    foreach ($attachments as $attachment) {
                        $pipeline_mime = File_Attachment_Model::resolve_pipeline_mime(
                            $attachment->mime_type,
                            $attachment->file_extension
                        );
                        $correct_type = File_Attachment_Model::determine_file_type($pipeline_mime);

                        if ((int) $attachment->file_type_id === (int) $correct_type) {
                            continue;  // Already correctly bucketed - nothing to repair.
                        }

                        $attachments_repaired++;

                        if ($attachment->file_storage_id !== null) {
                            $affected_storage_ids[$attachment->file_storage_id] = true;
                        }

                        if ($dry_run) {
                            continue;
                        }

                        // Recompute file_type_id only; mime_type (the raw sniff) is the serve-time
                        // value and is intentionally NOT rewritten.
                        $attachment->file_type_id = $correct_type;
                        $attachment->save();
                    }
                });
        });

        $blobs_requeued = 0;
        $caches_invalidated = 0;

        foreach (array_keys($affected_storage_ids) as $storage_id) {
            $storage = File_Storage_Model::find($storage_id);
            if (!$storage) {
                continue;
            }

            if (!$dry_run) {
                // Re-queue text extraction for the (deduplicated) blob.
                $storage->is_indexed = 0;
                $storage->save();
            }
            $blobs_requeued++;

            $caches_invalidated += self::__invalidate_blob_caches($storage, $dry_run);
        }

        return [
            'attachments' => $attachments_repaired,
            'blobs'       => $blobs_requeued,
            'caches'      => $caches_invalidated,
        ];
    }

    /**
     * Delete the cached PDF rendition and any cached thumbnails for a blob (both content-
     * addressed on the blob hash). Returns the number of files removed (or that would be removed
     * under dry_run). All disk paths resolve through Rsx_File_Paths (test-storage isolation).
     *
     * @param File_Storage_Model $storage
     * @param bool $dry_run
     * @return int
     */
    private static function __invalidate_blob_caches(File_Storage_Model $storage, bool $dry_run): int
    {
        $removed = 0;

        // PDF rendition (renditions_root/{hash}.pdf).
        $rendition = File_Preview_Controller::rendition_cache_path($storage);
        if (is_file($rendition)) {
            $removed++;
            if (!$dry_run) {
                @unlink($rendition);
            }
        }

        // Thumbnails (preset + dynamic). Filenames embed the SHA blob hash; glob on it (the hash
        // is long enough to be collision-free as a match token).
        $thumbnails_root = Rsx_File_Paths::thumbnails_root();
        foreach (['preset', 'dynamic'] as $type) {
            $pattern = $thumbnails_root . '/' . $type . '/*' . $storage->hash . '*.webp';
            foreach (glob($pattern) ?: [] as $file) {
                $removed++;
                if (!$dry_run) {
                    @unlink($file);
                }
            }
        }

        return $removed;
    }
}
