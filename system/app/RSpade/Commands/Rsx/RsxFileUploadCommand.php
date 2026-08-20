<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\Models\File_Storage_Model;
use Rsx\Models\File_Attachment_Model;
use App\RSpade\Core\Locks\RsxLocks;

/**
 * File Upload Command
 * ===================
 *
 * PURPOSE:
 * Upload a file from disk and create a File_Attachment_Model record.
 * Supports metadata, polymorphic attachments, and optional model associations.
 *
 * FEATURES:
 * - Uploads file to deduplicated storage (File_Storage_Model)
 * - Creates logical attachment record (File_Attachment_Model)
 * - Supports metadata (category, type_meta, meta JSON)
 * - Can attach to any model via polymorphic relationship
 * - Uses file locking to prevent race conditions
 *
 * LOCKING:
 * All file operations acquire LOCK_FILE_WRITE to ensure:
 * - No concurrent hash collisions during upload
 * - Atomic file and database operations
 * - Safe concurrent command execution
 */
class RsxFileUploadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:file:upload
                            {path : Path to file to upload}
                            {--site= : Site ID (required)}
                            {--name= : Override filename}
                            {--category= : Set fileable_category}
                            {--type-meta= : Set fileable_type_meta}
                            {--meta= : Set fileable_meta JSON}
                            {--model= : Attach to model (Model:ID)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upload a file and create a File_Attachment_Model';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $file_path = $this->argument('path');
        $site_id = $this->option('site');

        // Validate required options
        if (!$site_id) {
            $this->error('Error: --site option is required');
            $this->info('Usage: rsx:file:upload /path/to/file --site=1');
            return 1;
        }

        // Validate file exists
        if (!file_exists($file_path)) {
            $this->error("Error: File not found: {$file_path}");
            return 1;
        }

        if (!is_readable($file_path)) {
            $this->error("Error: File is not readable: {$file_path}");
            return 1;
        }

        // Acquire file write lock
        $lock = RsxLocks::named_write_lock(RsxLocks::LOCK_FILE_WRITE);

        try {
            // Create or find existing storage
            $this->info('Uploading file to storage...');
            $storage = File_Storage_Model::find_or_create($file_path);

            // Determine filename
            $filename = $this->option('name') ?: basename($file_path);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);

            // Detect MIME type (raw byte sniff); bucket file_type_id on the pipeline mime
            // (extension-first for documents) so a zip-sniffed OOXML doc buckets as DOCUMENT.
            $mime_type = mime_content_type($file_path);
            $file_type_id = File_Attachment_Model::determine_file_type(
                File_Attachment_Model::resolve_pipeline_mime($mime_type, $extension)
            );

            // Create attachment record
            $attachment = new File_Attachment_Model();
            $attachment->key = File_Attachment_Model::generate_key();
            $attachment->file_storage_id = $storage->id;
            $attachment->file_name = $filename;
            $attachment->file_extension = $extension;
            $attachment->file_type_id = $file_type_id;
            $attachment->site_id = $site_id;

            // Set optional metadata
            if ($category = $this->option('category')) {
                $attachment->fileable_category = $category;
            }

            if ($type_meta = $this->option('type-meta')) {
                $attachment->fileable_type_meta = $type_meta;
            }

            if ($meta_json = $this->option('meta')) {
                $meta = json_decode($meta_json, true);
                if ($meta === null && json_last_error() !== JSON_ERROR_NONE) {
                    $this->error('Error: Invalid JSON in --meta option');
                    return 1;
                }
                $attachment->set_meta($meta);
            }

            // Handle model attachment
            if ($model_spec = $this->option('model')) {
                if (!str_contains($model_spec, ':')) {
                    $this->error('Error: --model format must be Model:ID (e.g., User_Model:42)');
                    return 1;
                }

                list($model_class, $model_id) = explode(':', $model_spec, 2);
                $attachment->fileable_type = $model_class;
                $attachment->fileable_id = (int)$model_id;
            }

            $attachment->save();

            // Output success information
            $this->info('');
            $this->info('[OK] File uploaded successfully');
            $this->info('');
            $this->info("  File Key:     {$attachment->key}");
            $this->info("  Filename:     {$attachment->file_name}");
            $this->info("  Size:         {$attachment->get_human_size()}");
            $this->info("  Type:         {$attachment->get_file_type_name()}");
            $this->info("  Storage Hash: {$storage->hash}");

            if ($attachment->fileable_type) {
                $this->info("  Attached To:  {$attachment->fileable_type}:{$attachment->fileable_id}");
            }

            if ($attachment->fileable_category) {
                $this->info("  Category:     {$attachment->fileable_category}");
            }

            if ($attachment->fileable_type_meta) {
                $this->info("  Type Meta:    {$attachment->fileable_type_meta}");
            }

            $this->info('');
            $this->info("Access URL: {$attachment->get_url()}");

            return 0;

        } finally {
            RsxLocks::release_lock($lock);
        }
    }
}
