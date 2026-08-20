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
 * File List Command
 * ==================
 *
 * PURPOSE:
 * List file attachments with filtering options.
 *
 * FEATURES:
 * - Requires site, user, or model scope
 * - Filter by category, type_meta, file_type
 * - Multiple output formats (table, json, csv)
 * - Configurable result limit
 *
 * SCOPING REQUIREMENT:
 * Prevents accidentally listing all files across all sites.
 * Must specify at least one of: --site, --user, or --model
 */
class RsxFileListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:file:list
                            {--site= : Site ID}
                            {--user= : Actor ID (created_by_id)}
                            {--model= : Model (Model:ID)}
                            {--category= : Filter by fileable_category}
                            {--type-meta= : Filter by fileable_type_meta}
                            {--type= : Filter by file_type (image, video, document, etc.)}
                            {--format=table : Output format (table, json, csv)}
                            {--limit=50 : Result limit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List file attachments with filtering options';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Validate at least one scope option provided
        if (!$this->option('site') && !$this->option('user') && !$this->option('model')) {
            $this->error('Error: Must specify at least one scope option');
            $this->info('');
            $this->info('Scope options:');
            $this->info('  --site=ID          List files for site');
            $this->info('  --user=ID          List files created by user');
            $this->info('  --model=Model:ID   List files attached to model');

            return 1;
        }

        // Build query
        $query = File_Attachment_Model::query();

        // Apply scoping
        if ($site_id = $this->option('site')) {
            $query->where('site_id', $site_id);
        }

        if ($user_id = $this->option('user')) {
            $query->where('created_by_id', $user_id);
        }

        if ($model_spec = $this->option('model')) {
            if (!str_contains($model_spec, ':')) {
                $this->error('Error: --model format must be Model:ID (e.g., User_Model:42)');

                return 1;
            }

            list($model_class, $model_id) = explode(':', $model_spec, 2);
            $query->where('fileable_type', $model_class)
                  ->where('fileable_id', (int)$model_id);
        }

        // Apply filters
        if ($category = $this->option('category')) {
            $query->where('fileable_category', $category);
        }

        if ($type_meta = $this->option('type-meta')) {
            $query->where('fileable_type_meta', $type_meta);
        }

        if ($type = $this->option('type')) {
            $type_map = [
                'image' => 1,
                'animated_image' => 2,
                'video' => 3,
                'archive' => 4,
                'text' => 5,
                'document' => 6,
                'other' => 7,
            ];

            if (!isset($type_map[$type])) {
                $this->error("Error: Invalid type '{$type}'. Valid types: " . implode(', ', array_keys($type_map)));

                return 1;
            }

            $query->where('file_type_id', $type_map[$type]);
        }

        // Apply limit
        $limit = (int)$this->option('limit');
        $query->limit($limit);

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        // Execute query
        $attachments = $query->get();

        if ($attachments->isEmpty()) {
            $this->info('No files found matching criteria');

            return 0;
        }

        // Load storage for size information
        $storage_ids = $attachments->pluck('file_storage_id')->unique()->toArray();
        $storage_records = \App\Models\File_Storage_Model::whereIn('id', $storage_ids)->get()->keyBy('id');

        // Output based on format
        $format = $this->option('format');

        switch ($format) {
            case 'json':
                $this->output_json($attachments, $storage_records);
                break;

            case 'csv':
                $this->output_csv($attachments, $storage_records);
                break;

            case 'table':
            default:
                $this->output_table($attachments, $storage_records);
                break;
        }

        return 0;
    }

    /**
     * Output results as table
     */
    protected function output_table($attachments, $storage_records)
    {
        $rows = [];

        foreach ($attachments as $attachment) {
            $storage = $storage_records[$attachment->file_storage_id] ?? null;

            $rows[] = [
                substr($attachment->key, 0, 16) . '...',
                $attachment->file_name,
                $attachment->file_type_id__label,
                $storage ? $storage->get_human_size() : 'N/A',
                $attachment->fileable_type ? "{$attachment->fileable_type}:{$attachment->fileable_id}" : '-',
                $attachment->fileable_category ?: '-',
                $attachment->created_at->format('Y-m-d H:i'),
            ];
        }

        $this->table(
            ['Key', 'Filename', 'Type', 'Size', 'Attached To', 'Category', 'Created'],
            $rows
        );

        $this->info('');
        $this->info("Total: {$attachments->count()} files");
    }

    /**
     * Output results as JSON
     */
    protected function output_json($attachments, $storage_records)
    {
        $data = [];

        foreach ($attachments as $attachment) {
            $storage = $storage_records[$attachment->file_storage_id] ?? null;

            $data[] = [
                'key' => $attachment->key,
                'filename' => $attachment->file_name,
                'extension' => $attachment->file_extension,
                'type' => $attachment->file_type_id__label,
                'size_bytes' => $storage ? $storage->size : null,
                'size_human' => $storage ? $storage->get_human_size() : null,
                'storage_hash' => $storage ? $storage->hash : null,
                'fileable_type' => $attachment->fileable_type,
                'fileable_id' => $attachment->fileable_id,
                'category' => $attachment->fileable_category,
                'type_meta' => $attachment->fileable_type_meta,
                'order' => $attachment->fileable_order,
                'site_id' => $attachment->site_id,
                'created_at' => $attachment->created_at->toIso8601String(),
                'created_by_id' => $attachment->created_by_id,
                'created_by_type' => $attachment->created_by_type,
                'url' => $attachment->get_url(),
            ];
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Output results as CSV
     */
    protected function output_csv($attachments, $storage_records)
    {
        // Header
        $this->line('key,filename,extension,type,size_bytes,fileable_type,fileable_id,category,type_meta,created_at,url');

        // Rows
        foreach ($attachments as $attachment) {
            $storage = $storage_records[$attachment->file_storage_id] ?? null;

            $row = [
                $attachment->key,
                $attachment->file_name,
                $attachment->file_extension,
                $attachment->file_type_id__label,
                $storage ? $storage->size : '',
                $attachment->fileable_type ?: '',
                $attachment->fileable_id ?: '',
                $attachment->fileable_category ?: '',
                $attachment->fileable_type_meta ?: '',
                $attachment->created_at->toIso8601String(),
                $attachment->get_url(),
            ];

            $this->line(implode(',', array_map(function ($field) {
                // Escape CSV fields
                if (str_contains($field, ',') || str_contains($field, '"')) {
                    return '"' . str_replace('"', '""', $field) . '"';
                }

                return $field;
            }, $row)));
        }
    }
}
