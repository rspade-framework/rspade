<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Rsx\Models\File_Attachment_Model;

/**
 * File Info Command
 * ==================
 *
 * PURPOSE:
 * Display detailed information about a file attachment by its key.
 *
 * DISPLAYS:
 * - File identification (key, name, extension)
 * - File type and size
 * - Storage information (hash, path)
 * - Attachment metadata (category, type_meta, meta JSON)
 * - Polymorphic relationship (if attached to model)
 * - Site and user information
 * - Timestamps
 */
class RsxFileInfoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:file:info {key : 64-char hex attachment key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display detailed information about a file attachment';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $key = $this->argument('key');

        // withTrashed: an attachment inside the retention window is exactly the one an operator
        // needs to inspect (is it recoverable, when did it go, and WHO deleted it).
        $attachment = File_Attachment_Model::withTrashed()->where('key', $key)->first();

        if (!$attachment) {
            $this->error("Error: File attachment not found with key: {$key}");

            return 1;
        }

        // Load relationships explicitly
        $storage = null;
        if ($attachment->file_storage_id) {
            $storage = \App\Models\File_Storage_Model::find($attachment->file_storage_id);
        }

        $site = null;
        if ($attachment->site_id) {
            $site = \Rsx\Models\Site_Model::find($attachment->site_id);
        }

        // Display file information
        $this->info('');
        $this->info('File Attachment Information');
        $this->info('===========================');
        $this->info('');

        $this->info('Identification:');
        $this->info("  Key:          {$attachment->key}");
        $this->info("  Filename:     {$attachment->file_name}");
        $this->info("  Extension:    {$attachment->file_extension}");
        $this->info('');

        $this->info('File Properties:');
        $this->info("  Type:         {$attachment->file_type_id__label}");
        // Authoritative attachment-level metadata (works without a resident blob).
        $this->info("  Size:         " . number_format((int) $attachment->file_size) . ' bytes');
        $this->info('  MIME:         ' . ($attachment->mime_type ?: '(unknown)'));
        $this->info('  Has Thumb:    ' . ($attachment->has_thumbnail() ? 'Yes' : 'No'));
        $this->info('');

        // External byte residency (WP-A). Do NOT materialize just to print info.
        if ($attachment->handler_class) {
            $this->info('External Handler:');
            $this->info("  Handler:      {$attachment->handler_class}");
            $this->info('  Resident:     ' . ($attachment->file_storage_id ? 'Yes (blob cached locally)' : 'No (bytes materialize on demand)'));
            $ref = $attachment->handler_ref;
            if ($ref !== null) {
                $this->info('  Handler Ref:  ' . json_encode($ref));
            }
            $this->info('');
        }

        if ($storage) {
            $this->info('Storage:');
            $this->info("  Hash:         {$storage->hash}");
            $this->info("  Path:         {$storage->get_storage_path()}");
            $this->info('  File Exists:  ' . ($storage->file_exists() ? 'Yes' : 'No'));

            // Count references (retention-aware pin count: live OR soft-deleted-but-not-destroyed).
            $ref_count = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereNull('destroyed_at')
                ->count();
            $this->info("  References:   {$ref_count}");
            $this->info('');
        } else {
            $this->info('Storage:');
            $this->info('  (no resident blob - external attachment)');
            $this->info('');
        }

        if ($attachment->fileable_type) {
            $this->info('Attached To:');
            $this->info("  Model:        {$attachment->fileable_type}");
            $this->info("  Model ID:     {$attachment->fileable_id}");
            $this->info('');
        }

        if ($attachment->fileable_category || $attachment->fileable_type_meta || $attachment->fileable_meta) {
            $this->info('Metadata:');

            if ($attachment->fileable_category) {
                $this->info("  Category:     {$attachment->fileable_category}");
            }

            if ($attachment->fileable_type_meta) {
                $this->info("  Type Meta:    {$attachment->fileable_type_meta}");
            }

            if ($attachment->fileable_order !== null) {
                $this->info("  Order:        {$attachment->fileable_order}");
            }

            if ($attachment->fileable_meta) {
                $meta = $attachment->get_meta();
                $this->info('  Meta JSON:    ' . json_encode($meta, JSON_PRETTY_PRINT));
            }

            $this->info('');
        }

        $this->info('Site:');
        $this->info("  Site ID:      {$attachment->site_id}");
        if ($site) {
            $this->info("  Site Name:    {$site->name}");
        }
        $this->info('');

        $this->info('Timestamps:');
        $this->info("  Created:      {$attachment->created_at}");
        $this->info("  Updated:      {$attachment->updated_at}");
        if ($attachment->created_by_id) {
            $this->info("  Created By:   {$attachment->created_by_type} #{$attachment->created_by_id}");
        }
        if ($attachment->updated_by_id) {
            $this->info("  Updated By:   {$attachment->updated_by_type} #{$attachment->updated_by_id}");
        }
        if ($attachment->deleted_at) {
            $this->info("  Deleted:      {$attachment->deleted_at}");
            if ($attachment->deleted_by_id) {
                $this->info("  Deleted By:   {$attachment->deleted_by_type} #{$attachment->deleted_by_id}");
            }
        }
        if ($attachment->destroyed_at) {
            $this->info("  Destroyed:    {$attachment->destroyed_at}");
        }
        $this->info('');

        $this->info('URLs:');
        $this->info("  View:         {$attachment->get_url()}");
        $this->info("  Download:     {$attachment->get_download_url()}");
        $this->info('');

        return 0;
    }
}
