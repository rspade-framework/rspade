<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\Console\Commands\FrameworkDeveloperCommand;
use App\Models\File_Storage_Model;
use Rsx\Models\File_Attachment_Model;
use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Locks\RsxLocks;
use Illuminate\Support\Facades\DB;

/**
 * Storage Cleanup Command
 * ========================
 *
 * PURPOSE:
 * Force cleanup of orphaned storage and attachments.
 *
 * FRAMEWORK DEVELOPER ONLY:
 * This command is hidden unless IS_FRAMEWORK_DEVELOPER=true in .env
 *
 * CLEANUP OPERATIONS:
 * 1. Delete orphaned attachments (no fileable, older than orphan-age hours)
 * 2. Delete orphaned storage (no attachments)
 * 3. Delete physical files for deleted storage
 *
 * SAFETY:
 * - Supports --dry-run to preview operations
 * - Requires --force to skip confirmation
 * - Uses file write lock to prevent race conditions
 */
class RsxStorageCleanupCommand extends FrameworkDeveloperCommand
{
    /** Rows resident at once while walking an orphan set. */
    private const BATCH_SIZE = 500;

    /** Orphan rows listed before the "... and N more" summary. */
    private const PREVIEW_ROWS = 10;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:storage:cleanup
                            {--dry-run : Show what would be deleted without deleting}
                            {--force : Skip confirmation prompts}
                            {--orphan-age=24 : Hours before orphaned attachment is deleted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Force cleanup of orphaned storage and attachments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dry_run = $this->option('dry-run');
        $force = $this->option('force');
        $orphan_age = (int)$this->option('orphan-age');

        $this->info('');
        $this->info('File Storage Cleanup');
        $this->info('====================');
        $this->info('');

        if ($dry_run) {
            $this->warn('DRY RUN MODE - No files will be deleted');
            $this->info('');
        }

        // Both orphan sets are unbounded - an install that has never been cleaned can hold
        // millions of rows - so nothing here ever materializes a whole set. Counts come from
        // COUNT(*), the preview from a LIMIT, and the deletion below walks by keyset.
        $orphaned_attachments = fn () => File_Attachment_Model::whereNull('fileable_type')
            ->whereNull('fileable_id')
            ->where('created_at', '<', now()->subHours($orphan_age));

        // Orphaned storage: no attachment references it - including rows orphaned by
        // attachment eviction or relink.
        $orphaned_storage = fn () => DB::table('_file_storage')
            ->leftJoin('_file_attachments', '_file_storage.id', '=', '_file_attachments.file_storage_id')
            ->whereNull('_file_attachments.id')
            ->select('_file_storage.*');

        $attachment_count = $orphaned_attachments()->count();
        $storage_count = $orphaned_storage()->count();

        $this->info("Found {$attachment_count} orphaned attachments (>{$orphan_age}h)");
        $this->info("Found {$storage_count} orphaned storage records");
        $this->info('');

        if ($attachment_count === 0 && $storage_count === 0) {
            $this->info('Nothing to clean up');
            return 0;
        }

        // Show details
        if ($attachment_count > 0) {
            $this->info('Orphaned Attachments:');
            foreach ($orphaned_attachments()->orderBy('id')->limit(self::PREVIEW_ROWS)->get() as $attachment) {
                // created_at is an ISO string (Rsx datetime cast), not a Carbon instance.
                $age_hours = round(\App\RSpade\Core\Time\Rsx_Time::seconds_since($attachment->created_at) / 3600, 1);
                $this->info("  - {$attachment->file_name} (key: " . substr($attachment->key, 0, 16) . "..., age: {$age_hours}h)");
            }
            if ($attachment_count > self::PREVIEW_ROWS) {
                $remaining = $attachment_count - self::PREVIEW_ROWS;
                $this->info("  ... and {$remaining} more");
            }
            $this->info('');
        }

        if ($storage_count > 0) {
            $this->info('Orphaned Storage:');
            foreach ($orphaned_storage()->orderBy('_file_storage.id')->limit(self::PREVIEW_ROWS)->get() as $storage) {
                $storage_obj = File_Storage_Model::find($storage->id);
                $size = $storage_obj ? $storage_obj->get_human_size() : 'unknown';
                $this->info("  - " . substr($storage->hash, 0, 24) . "... ({$size})");
            }
            if ($storage_count > self::PREVIEW_ROWS) {
                $remaining = $storage_count - self::PREVIEW_ROWS;
                $this->info("  ... and {$remaining} more");
            }
            $this->info('');
        }

        // Confirmation
        if (!$dry_run && !$force) {
            if (!$this->confirm('Proceed with cleanup?', false)) {
                $this->info('Cleanup cancelled');
                return 0;
            }
            $this->info('');
        }

        if ($dry_run) {
            $this->info('[DRY RUN] Would delete:');
            $this->info("  - {$attachment_count} orphaned attachments");
            $this->info("  - {$storage_count} orphaned storage records");
            $this->info('');
            return 0;
        }

        // Acquire file write lock
        // Waits forever, and holds for as long as the sweep runs - minutes to hours on a
        // large store. That is the point: nothing else may write files underneath it.
        $lock = RsxLocks::named_write_lock(RsxLocks::LOCK_FILE_WRITE);

        try {
            $deleted_attachments = 0;
            $deleted_storage = 0;
            $deleted_bytes = 0;

            // Erase orphaned attachments immediately. force_destroy() (NOT delete()) because
            // a plain delete() now only SOFT-deletes into the retention window; this is a
            // manual admin cleanup that should reclaim now, and it releases each blob through
            // the retention-aware guard.
            // Keyset-walked (result_set) so the loop terminates whether force_destroy()
            // removes the row outright or leaves a tombstone the orphan predicate still
            // matches - either way the cursor only moves forward.
            foreach ($orphaned_attachments()->result_set(self::BATCH_SIZE) as $attachment) {
                $attachment->force_destroy();
                $deleted_attachments++;
            }

            // Release orphaned storage through the retention-aware disposal guard: it
            // re-checks the refcount (live OR soft-deleted-but-not-destroyed) under the shared
            // blob lock and unlinks + purges derived caches only for a truly-unpinned blob, so
            // a naive orphan-detection query can never release a blob a retained attachment
            // still needs.
            // Hand-rolled keyset here rather than result_set(): this is a JOINED
            // DB::table() query, not an Eloquent model builder, so there is no model to
            // walk and no result_set() to call.
            $last_storage_id = 0;
            while (true) {
                $batch = $orphaned_storage()
                    ->where('_file_storage.id', '>', $last_storage_id)
                    ->orderBy('_file_storage.id')
                    ->limit(self::BATCH_SIZE)
                    ->get();

                if ($batch->isEmpty()) {
                    break;
                }

                foreach ($batch as $storage_data) {
                    $last_storage_id = (int) $storage_data->id;
                    $storage = File_Storage_Model::find($storage_data->id);
                    if (!$storage) {
                        continue;
                    }
                    $size = $storage->size;
                    if (File_Disposal_Service::release_blob_if_orphaned((int) $storage->id)) {
                        $deleted_bytes += $size;
                        $deleted_storage++;
                    }
                }
            }

            $this->info('[OK] Cleanup completed');
            $this->info('');
            $this->info("  Deleted Attachments:  {$deleted_attachments}");
            $this->info("  Deleted Storage:      {$deleted_storage}");
            $this->info("  Freed Disk Space:     " . $this->format_bytes($deleted_bytes));
            $this->info('');

            return 0;

        } finally {
            RsxLocks::release_lock($lock);
        }
    }

    /**
     * Format bytes to human-readable size
     */
    protected function format_bytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
