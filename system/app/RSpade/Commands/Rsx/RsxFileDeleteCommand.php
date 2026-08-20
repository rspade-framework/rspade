<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Rsx\Models\File_Attachment_Model;
use App\RSpade\Core\Locks\RsxLocks;

/**
 * File Delete Command
 * ====================
 *
 * PURPOSE:
 * Delete a file attachment and cleanup orphaned storage.
 *
 * BEHAVIOR:
 * 1. Deletes File_Attachment_Model record
 * 2. Auto-cleanup triggers (via model boot() event):
 *    - If no other attachments reference the same storage:
 *      * Deletes physical file from disk
 *      * Deletes File_Storage_Model record
 *    - If other attachments exist, storage is preserved
 *
 * SAFETY:
 * - Requires confirmation unless --force flag provided
 * - Uses file write lock to prevent race conditions
 */
class RsxFileDeleteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:file:delete
                            {key : 64-char hex attachment key}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete file attachment and cleanup orphaned storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $key = $this->argument('key');

        // Find attachment
        $attachment = File_Attachment_Model::where('key', $key)->first();

        if (!$attachment) {
            $this->error("Error: File attachment not found with key: {$key}");
            return 1;
        }

        // Get storage info before deletion
        $storage_id = $attachment->file_storage_id;
        $filename = $attachment->file_name;

        // Check if storage will be deleted. Retention-aware pin count: an attachment pins
        // its blob while it is live OR soft-deleted-but-not-destroyed (destroyed tombstones
        // do not). This CLI is an explicit admin erasure, so it force_destroy()s below.
        $ref_count = File_Attachment_Model::withTrashed()
            ->where('file_storage_id', $storage_id)
            ->whereNull('destroyed_at')
            ->count();
        $will_delete_storage = ($ref_count === 1);

        // Confirmation prompt
        if (!$this->option('force')) {
            $this->info('');
            $this->info("File: {$filename}");
            $this->info("Key:  {$key}");

            if ($will_delete_storage) {
                $this->warn('');
                $this->warn('Warning: This is the last reference to the physical file.');
                $this->warn('         Physical storage will be deleted from disk.');
            } else {
                $this->info('');
                $this->info("Note: Physical storage has {$ref_count} references and will be preserved.");
            }

            $this->info('');

            if (!$this->confirm('Delete this file attachment?', false)) {
                $this->info('Deletion cancelled');
                return 0;
            }
        }

        // Acquire file write lock
        $lock = RsxLocks::named_write_lock(RsxLocks::LOCK_FILE_WRITE);

        try {
            // Explicit admin erasure: force_destroy() destroys the attachment immediately
            // (bypassing the retention window) and releases the blob if nothing else pins it.
            // A plain delete() would only SOFT-delete into retention, contradicting this
            // command's "physical storage will be deleted" contract.
            $attachment->force_destroy();

            $this->info('');
            $this->info('[OK] File attachment deleted');
            $this->info('');
            $this->info("  File: {$filename}");
            $this->info("  Key:  {$key}");

            if ($will_delete_storage) {
                $this->info('  Physical storage was also deleted');
            } else {
                $this->info("  Physical storage preserved ({$ref_count} total references)");
            }

            $this->info('');

            return 0;

        } finally {
            RsxLocks::release_lock($lock);
        }
    }
}
