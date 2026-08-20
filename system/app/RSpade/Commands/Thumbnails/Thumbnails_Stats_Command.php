<?php

namespace App\RSpade\Commands\Thumbnails;

use Illuminate\Console\Command;
use App\RSpade\Core\Files\File_Thumbnail_Service;

class Thumbnails_Stats_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:thumbnails:stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display thumbnail cache usage statistics';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $stats = File_Thumbnail_Service::get_statistics();

        $this->info('Thumbnail Cache Statistics');
        $this->info('');

        // Preset thumbnails
        $this->display_directory_stats('Preset Thumbnails', $stats['preset']);
        $this->info('');

        // Dynamic thumbnails
        $this->display_directory_stats('Dynamic Thumbnails', $stats['dynamic']);
        $this->info('');

        // Breakdown by preset
        if (!empty($stats['preset_breakdown'])) {
            $this->display_preset_breakdown($stats['preset_breakdown']);
        }

        return 0;
    }

    /**
     * Display statistics for a thumbnail directory
     *
     * @param string $title Display title
     * @param array $stats Statistics from service
     * @return void
     */
    protected function display_directory_stats($title, $stats)
    {
        if (!$stats['exists']) {
            $this->warn("{$title}: Directory not found");
            return;
        }

        $total_mb = round($stats['total_bytes'] / 1024 / 1024, 2);
        $quota_mb = round($stats['max_bytes'] / 1024 / 1024, 2);

        $this->info("{$title}:");
        $this->info("  Files: " . number_format($stats['file_count']));
        $this->info("  Total Size: {$total_mb} MB / {$quota_mb} MB ({$stats['usage_percent']}%)");

        if ($stats['oldest'] !== null) {
            $oldest_age = $this->format_age($stats['oldest']);
            $newest_age = $this->format_age($stats['newest']);
            $this->info("  Oldest: {$oldest_age}");
            $this->info("  Newest: {$newest_age}");
        }
    }

    /**
     * Display breakdown of preset thumbnails by preset name
     *
     * @param array $breakdown Breakdown from service
     * @return void
     */
    protected function display_preset_breakdown($breakdown)
    {
        $this->info('Breakdown by preset:');
        foreach ($breakdown as $preset_name => $stats) {
            if ($stats['file_count'] > 0) {
                $mb = round($stats['total_bytes'] / 1024 / 1024, 2);
                $this->info("  {$preset_name}: {$stats['file_count']} files, {$mb} MB");
            }
        }
    }

    /**
     * Format timestamp as human-readable age
     *
     * @param int $timestamp Unix timestamp
     * @return string Human-readable age
     */
    protected function format_age($timestamp)
    {
        $age = time() - $timestamp;

        if ($age < 60) {
            return "{$age} seconds ago";
        } elseif ($age < 3600) {
            $minutes = floor($age / 60);
            return "{$minutes} minute" . ($minutes > 1 ? 's' : '') . " ago";
        } elseif ($age < 86400) {
            $hours = floor($age / 3600);
            return "{$hours} hour" . ($hours > 1 ? 's' : '') . " ago";
        } else {
            $days = floor($age / 86400);
            return "{$days} day" . ($days > 1 ? 's' : '') . " ago";
        }
    }
}
