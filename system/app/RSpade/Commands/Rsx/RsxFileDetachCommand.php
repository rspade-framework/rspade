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
 * File Detach Command
 * ====================
 *
 * PURPOSE:
 * Detach a file from its parent model (sets fileable_type/id to NULL).
 *
 * WARNING:
 * Detached files become orphans and may be deleted by cleanup operations
 * after 24 hours if not re-attached to a model.
 *
 * LOCKING:
 * Uses file write lock to ensure atomic operation.
 */
class RsxFileDetachCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:file:detach {key : 64-char hex attachment key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detach file from its parent model';

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

        // Check if already detached
        if (!$attachment->fileable_type && !$attachment->fileable_id) {
            $this->info("File is already detached from any model");
            return 0;
        }

        $attached_to = "{$attachment->fileable_type}:{$attachment->fileable_id}";

        // Acquire file write lock
        $lock = RsxLocks::named_write_lock(RsxLocks::LOCK_FILE_WRITE);

        try {
            $attachment->fileable_type = null;
            $attachment->fileable_id = null;
            $attachment->save();

            $this->info('');
            $this->info('[OK] File detached from model');
            $this->info('');
            $this->info("  File:         {$attachment->file_name}");
            $this->info("  Key:          {$attachment->key}");
            $this->info("  Was Attached: {$attached_to}");
            $this->info('');
            $this->warn('Warning: This file is now orphaned and may be deleted by cleanup');
            $this->warn('         operations after 24 hours if not re-attached.');
            $this->info('');

            return 0;

        } finally {
            RsxLocks::release_lock($lock);
        }
    }
}
