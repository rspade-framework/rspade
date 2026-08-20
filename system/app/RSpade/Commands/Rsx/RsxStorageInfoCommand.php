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

/**
 * Storage Info Command
 * =====================
 *
 * PURPOSE:
 * Display detailed information about physical file storage by hash.
 *
 * FRAMEWORK DEVELOPER ONLY:
 * This command is hidden unless IS_FRAMEWORK_DEVELOPER=true in .env
 * Provides low-level access to physical storage system for debugging.
 *
 * DISPLAYS:
 * - Storage hash and file size
 * - Storage path on disk
 * - Physical file existence
 * - Reference count (number of attachments)
 * - Audit information (created/updated)
 */
class RsxStorageInfoCommand extends FrameworkDeveloperCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:storage:info {hash : SHA-256 hash or incremented variant}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display detailed information about physical file storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hash = $this->argument('hash');

        // Find storage by hash
        $storage = File_Storage_Model::where('hash', $hash)->first();

        if (!$storage) {
            $this->error("Error: File storage not found with hash: {$hash}");
            return 1;
        }

        // Count references
        // Retention-aware: include soft-deleted-but-not-destroyed attachments (they still
        // pin the blob); exclude destroyed tombstones.
        // A popular blob can be referenced by any number of attachments, so the count is a
        // COUNT(*) and only the handful this command actually prints is fetched.
        $references = File_Attachment_Model::withTrashed()
            ->where('file_storage_id', $storage->id)
            ->whereNull('destroyed_at');

        $ref_count = (clone $references)->count();
        $attachments = $ref_count > 0 && $ref_count <= 10
            ? $references->orderBy('id')->limit(10)->get()
            : collect();

        // Display storage information
        $this->info('');
        $this->info('File Storage Information');
        $this->info('========================');
        $this->info('');

        $this->info('Storage:');
        $this->info("  ID:           {$storage->id}");
        $this->info("  Hash:         {$storage->hash}");
        $this->info("  Size:         {$storage->size} bytes ({$storage->get_human_size()})");
        $this->info('');

        $this->info('Physical File:');
        $this->info("  Path:         {$storage->get_storage_path()}");
        $this->info("  Full Path:    {$storage->get_full_path()}");
        $this->info("  Exists:       " . ($storage->file_exists() ? 'Yes' : 'No'));

        if ($storage->file_exists()) {
            $mime = mime_content_type($storage->get_full_path());
            $this->info("  MIME Type:    {$mime}");
        }

        $this->info('');

        $this->info('References:');
        $this->info("  Attachments:  {$ref_count}");

        if ($ref_count > 0 && $ref_count <= 10) {
            $this->info('');
            $this->info('  Attachment Keys:');
            foreach ($attachments as $attachment) {
                $attached_to = $attachment->fileable_type
                    ? " -> {$attachment->fileable_type}:{$attachment->fileable_id}"
                    : " (orphaned)";
                $this->info("    - " . substr($attachment->key, 0, 24) . "... {$attached_to}");
            }
        } elseif ($ref_count > 10) {
            $this->info("  (Use rsx:file:list to see all attachments)");
        }

        $this->info('');

        $this->info('Audit:');
        $this->info("  Created:      {$storage->created_at}");
        $this->info("  Updated:      {$storage->updated_at}");
        if ($storage->created_by_id) {
            $this->info("  Created By:   {$storage->created_by_type} #{$storage->created_by_id}");
        }
        if ($storage->updated_by_id) {
            $this->info("  Updated By:   {$storage->updated_by_type} #{$storage->updated_by_id}");
        }
        $this->info('');

        return 0;
    }
}
