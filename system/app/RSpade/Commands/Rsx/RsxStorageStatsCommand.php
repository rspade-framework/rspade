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
use Illuminate\Support\Facades\DB;

/**
 * Storage Stats Command
 * ======================
 *
 * PURPOSE:
 * Display statistics about the file storage system.
 *
 * FRAMEWORK DEVELOPER ONLY:
 * This command is hidden unless IS_FRAMEWORK_DEVELOPER=true in .env
 *
 * STATISTICS:
 * - Total physical files (storage count)
 * - Total attachments (attachment count)
 * - Deduplication ratio (attachments / storage)
 * - Total disk usage
 * - Average file size
 * - Files by type breakdown
 * - Orphaned storage count
 * - Orphaned attachments count
 */
class RsxStorageStatsCommand extends FrameworkDeveloperCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:storage:stats {--site= : Filter stats for specific site}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display file storage system statistics';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $site_id = $this->option('site');

        $this->info('');
        $this->info('File Storage System Statistics');
        $this->info('==============================');
        $this->info('');

        // Basic counts
        $storage_count = File_Storage_Model::count();

        $attachment_query = File_Attachment_Model::query();
        if ($site_id) {
            $attachment_query->where('site_id', $site_id);
        }
        $attachment_count = $attachment_query->count();

        // Deduplication ratio
        $dedup_ratio = $storage_count > 0 ? round($attachment_count / $storage_count, 2) : 0;

        $this->info('Overview:');
        $this->info("  Physical Files:     {$storage_count}");
        $this->info("  Total Attachments:  {$attachment_count}");
        $this->info("  Deduplication:      {$dedup_ratio}x (avg attachments per file)");
        $this->info('');

        // Storage size stats
        $total_size = File_Storage_Model::sum('size');
        $avg_size = $storage_count > 0 ? round($total_size / $storage_count) : 0;

        $this->info('Storage Usage:');
        $this->info("  Total Disk Space:   " . $this->format_bytes($total_size));
        $this->info("  Average File Size:  " . $this->format_bytes($avg_size));
        $this->info('');

        // Files by type
        $type_query = File_Attachment_Model::select('file_type_id', DB::raw('COUNT(*) as count'));
        if ($site_id) {
            $type_query->where('site_id', $site_id);
        }
        $types_breakdown = $type_query->groupBy('file_type_id')->get();

        if ($types_breakdown->isNotEmpty()) {
            $this->info('Files by Type:');

            $type_names = [
                1 => 'Image',
                2 => 'Animated Image',
                3 => 'Video',
                4 => 'Archive',
                5 => 'Text',
                6 => 'Document',
                7 => 'Other',
            ];

            foreach ($types_breakdown as $type) {
                $name = $type_names[$type->file_type_id] ?? 'Unknown';
                $percentage = $attachment_count > 0 ? round(($type->count / $attachment_count) * 100, 1) : 0;
                $this->info(sprintf("  %-16s %6d (%5.1f%%)", $name . ':', $type->count, $percentage));
            }
            $this->info('');
        }

        // Orphaned storage (no attachments)
        $orphaned_storage = DB::table('_file_storage')
            ->leftJoin('file_attachments', 'file_storage.id', '=', 'file_attachments.file_storage_id')
            ->whereNull('file_attachments.id')
            ->count();

        // Orphaned attachments (no fileable)
        $orphaned_query = File_Attachment_Model::whereNull('fileable_type')
            ->whereNull('fileable_id');
        if ($site_id) {
            $orphaned_query->where('site_id', $site_id);
        }
        $orphaned_attachments = $orphaned_query->count();

        // Old orphaned attachments (>24 hours)
        $old_orphaned_query = File_Attachment_Model::whereNull('fileable_type')
            ->whereNull('fileable_id')
            ->where('created_at', '<', now()->subHours(24));
        if ($site_id) {
            $old_orphaned_query->where('site_id', $site_id);
        }
        $old_orphaned_attachments = $old_orphaned_query->count();

        $this->info('Orphaned Files:');
        $this->info("  Orphaned Storage:        {$orphaned_storage}");
        $this->info("  Orphaned Attachments:    {$orphaned_attachments}");
        $this->info("  Old Orphans (>24h):      {$old_orphaned_attachments}");

        if ($old_orphaned_attachments > 0) {
            $this->warn("  Warning: {$old_orphaned_attachments} attachments eligible for cleanup");
        }

        $this->info('');

        if ($site_id) {
            $this->info("(Statistics filtered for site ID: {$site_id})");
            $this->info('');
        }

        return 0;
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
