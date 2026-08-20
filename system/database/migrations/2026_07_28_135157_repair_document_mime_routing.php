<?php

use App\RSpade\Core\Files\File_Mime_Repair;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * DATA repair, not a schema change. Brings existing document attachments into line with the
     * extension-first pipeline-type policy (File_Attachment_Model::resolve_pipeline_mime): a valid
     * .docx/.xlsx whose bytes libmagic sniffed as the generic container application/zip was
     * mis-bucketed as an archive at ingest, silently disabling its preview, text extraction, and
     * thumbnail. This runs automatically on every downstream box during the framework update's
     * migrate step, so no manual command run is required to heal existing rows.
     *
     * Delegates to File_Mime_Repair::run() (the same repair the rsx:files:repair_mime_routing
     * command wraps): re-buckets each affected attachment's file_type_id (the stored mime_type -
     * the raw sniff, the serve-time value - is left alone), resets _file_storage.is_indexed=0 to
     * re-queue text extraction for the deduplicated blob, and deletes the stale icon rendition +
     * thumbnails. It does NOT dispatch the extraction task - a migration must not assume a task
     * worker is available mid-migrate; the normal Search_Index_Service::index_pending cron drains
     * the re-queued blobs.
     *
     * Idempotent: after the repair each file_type_id already matches the pipeline bucket, so a
     * re-run (or a run on a fresh install with no affected rows) is a no-op. Cross-site by design
     * (File_Mime_Repair bypasses the site read-scope), so every tenant's attachments are healed.
     *
     * @return void
     */
    public function up()
    {
        File_Mime_Repair::run(false);
    }
};
