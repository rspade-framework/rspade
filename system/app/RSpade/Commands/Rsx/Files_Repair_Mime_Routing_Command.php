<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Files\File_Mime_Repair;
use App\RSpade\Core\Task\Task;
use Illuminate\Console\Command;

/**
 * rsx:files:repair_mime_routing - re-bucket + re-process document attachments that a per-file-flaky
 * byte sniff mis-routed at ingest (the classic case: a valid .docx/.xlsx sniffed as the generic
 * container application/zip, silently disabling its preview, text extraction, and thumbnail).
 *
 * Thin wrapper over File_Mime_Repair::run() (the same repair the framework migration
 * 2026_07_28_..._repair_document_mime_routing runs automatically on the next update). Run it by
 * hand to repair on demand or to preview with --dry-run. After a real run with re-queued blobs it
 * dispatches the extraction task for an immediate drain (the migration relies on the
 * every-5-minutes extraction cron).
 *
 * Idempotent: after a run the file_type_id matches, so a second run finds nothing.
 */
class Files_Repair_Mime_Routing_Command extends Command
{
    protected $signature = 'rsx:files:repair_mime_routing
                            {--dry-run : Report what would change without modifying anything}';

    protected $description = 'Re-bucket + re-process document attachments mis-routed by a flaky byte sniff (e.g. docx sniffed as application/zip)';

    public function handle(): int
    {
        $dry_run = (bool) $this->option('dry-run');

        $counts = File_Mime_Repair::run($dry_run);

        if ($dry_run) {
            $this->line('[DRY RUN] No changes written.');
            $this->line("  Attachments that would be re-bucketed : {$counts['attachments']}");
            $this->line("  Blobs that would be re-queued          : {$counts['blobs']}");
            $this->line("  Cache files that would be deleted      : {$counts['caches']}");
            return 0;
        }

        if ($counts['attachments'] === 0) {
            // Silent success (Unix philosophy): nothing was wrong.
            $this->line('[OK] No mis-routed document attachments found - nothing to repair.');
            return 0;
        }

        if ($counts['blobs'] > 0) {
            Task::dispatch('Search_Index_Service', 'index_pending');
        }

        $this->line('[OK] Mime-routing repair complete.');
        $this->line("  Attachments re-bucketed  : {$counts['attachments']}");
        $this->line("  Blobs re-queued          : {$counts['blobs']}" . ($counts['blobs'] > 0 ? ' (extraction task dispatched)' : ''));
        $this->line("  Cache files invalidated  : {$counts['caches']}");
        return 0;
    }
}
