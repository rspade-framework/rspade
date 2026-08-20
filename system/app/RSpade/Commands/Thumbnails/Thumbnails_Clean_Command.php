<?php

namespace App\RSpade\Commands\Thumbnails;

use Illuminate\Console\Command;
use App\RSpade\Core\Files\File_Thumbnail_Service;
use App\RSpade\Core\Files\Rsx_File_Paths;

class Thumbnails_Clean_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:thumbnails:clean
        {--preset : Clean only preset thumbnails}
        {--dynamic : Clean only dynamic thumbnails}
        {--all : Clean both preset and dynamic thumbnails (default)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enforce thumbnail cache quotas by deleting oldest files until under limit (LRU eviction)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $clean_preset = $this->option('preset') || $this->option('all') || (!$this->option('preset') && !$this->option('dynamic'));
        $clean_dynamic = $this->option('dynamic') || $this->option('all') || (!$this->option('preset') && !$this->option('dynamic'));

        $total_deleted = 0;
        $total_freed = 0;

        if ($clean_preset) {
            [$deleted, $freed] = $this->clean_directory_with_output('preset');
            $total_deleted += $deleted;
            $total_freed += $freed;
        }

        if ($clean_dynamic) {
            [$deleted, $freed] = $this->clean_directory_with_output('dynamic');
            $total_deleted += $deleted;
            $total_freed += $freed;
        }

        if ($total_deleted > 0) {
            $freed_mb = round($total_freed / 1024 / 1024, 2);
            $this->info("Cleanup complete: {$total_deleted} files deleted, {$freed_mb} MB freed");
        } else {
            $this->info('No cleanup needed - all directories under quota');
        }

        return 0;
    }

    /**
     * Clean a thumbnail directory with CLI output
     *
     * Wrapper around File_Thumbnail_Service::clean_directory() that adds CLI output.
     *
     * @param string $type Either 'preset' or 'dynamic'
     * @return array [files_deleted, bytes_freed]
     */
    protected function clean_directory_with_output($type)
    {
        $quota_key = $type . '_max_bytes';
        $max_bytes = config("rsx.thumbnails.quotas.{$quota_key}");
        $dir = Rsx_File_Paths::thumbnails_root() . "/{$type}/";

        if (!is_dir($dir)) {
            $this->warn("Directory not found: {$dir}");
            return [0, 0];
        }

        // Get current stats before cleanup
        $before_count = count(glob($dir . '*.webp'));
        $before_size = array_sum(array_map('filesize', glob($dir . '*.webp')));

        $current_mb = round($before_size / 1024 / 1024, 2);
        $quota_mb = round($max_bytes / 1024 / 1024, 2);

        $this->info("[$type] Current usage: {$current_mb} MB / {$quota_mb} MB ({$before_count} files)");

        // Run cleanup via service
        [$deleted, $freed] = File_Thumbnail_Service::clean_directory($type);

        if ($deleted > 0) {
            $over_by = round(($before_size - $max_bytes) / 1024 / 1024, 2);
            $freed_mb = round($freed / 1024 / 1024, 2);
            $final_mb = round(($before_size - $freed) / 1024 / 1024, 2);

            $this->warn("[$type] Was over quota by {$over_by} MB");
            $this->info("[$type] Deleted {$deleted} files, freed {$freed_mb} MB, new usage: {$final_mb} MB");
        }

        return [$deleted, $freed];
    }
}
