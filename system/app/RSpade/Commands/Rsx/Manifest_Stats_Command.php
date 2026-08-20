<?php

namespace App\RSpade\Commands\Rsx;

use App\Console\Commands\FrameworkDeveloperCommand;
use App\RSpade\Core\Manifest\Manifest;
use Illuminate\Console\Command;

class Manifest_Stats_Command extends FrameworkDeveloperCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:manifest:stats {--detailed : Show detailed file information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show statistics about the RSX manifest';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Initialize manifest (handles loading/rebuilding as needed)
        Manifest::init();

        $stats = Manifest::get_stats();
        $data = Manifest::get_all();

        // Get cache file location for stats
        $cache_file = storage_path('rsx-build/manifest_data.php');

        $this->info('RSX Manifest Statistics');
        $this->info('=======================');
        $this->newLine();

        // Basic stats table
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Files', $stats['total_files']],
                ['PHP Files', $stats['php']],
                ['JavaScript Files', $stats['js']],
                ['Blade Templates', $stats['blade']],
                ['SCSS Files', $stats['scss']],
                ['Other Files', $stats['other']],
            ]
        );

        // Cache information
        $cache_size = round(filesize($cache_file) / 1024, 2);
        $cache_modified = date('Y-m-d H:i:s', filemtime($cache_file));
        $manifest_hash = Manifest::get_build_key();

        $this->info('Cache Information:');
        $this->line("  File: {$cache_file}");
        $this->line("  Format: PHP (var_export with opcache support)");
        $this->line("  Size: {$cache_size} KB");
        $this->line("  Modified: {$cache_modified}");
        $this->line("  Hash: {$manifest_hash}");
        $this->newLine();

        // Detailed file information
        if ($this->option('detailed')) {
            $this->info('File Details:');
            $this->newLine();

            // Group files by type
            $php_files = [];
            $js_files = [];
            $blade_files = [];
            $scss_files = [];
            $other_files = [];

            foreach ($data as $file => $metadata) {
                $size_kb = round($metadata['size'] / 1024, 2);
                $modified = date('Y-m-d H:i:s', $metadata['mtime']);
                $file_info = [
                    'file' => basename($file),
                    'path' => dirname(str_replace(base_path() . '/', '', $file)),
                    'size' => "{$size_kb} KB",
                    'modified' => $modified,
                ];

                if (str_ends_with($file, '.blade.php')) {
                    $blade_files[] = $file_info;
                } elseif (str_ends_with($file, '.php')) {
                    $php_files[] = $file_info;
                } elseif (str_ends_with($file, '.js')) {
                    $js_files[] = $file_info;
                } elseif (str_ends_with($file, '.scss')) {
                    $scss_files[] = $file_info;
                } else {
                    $other_files[] = $file_info;
                }
            }

            if (!empty($php_files)) {
                $this->info('PHP Files:');
                $this->table(['File', 'Path', 'Size', 'Modified'], $php_files);
                $this->newLine();
            }

            if (!empty($js_files)) {
                $this->info('JavaScript Files:');
                $this->table(['File', 'Path', 'Size', 'Modified'], $js_files);
                $this->newLine();
            }

            if (!empty($blade_files)) {
                $this->info('Blade Templates:');
                $this->table(['File', 'Path', 'Size', 'Modified'], $blade_files);
                $this->newLine();
            }

            if (!empty($scss_files)) {
                $this->info('SCSS Files:');
                $this->table(['File', 'Path', 'Size', 'Modified'], $scss_files);
                $this->newLine();
            }

            if (!empty($other_files)) {
                $this->info('Other Files:');
                $this->table(['File', 'Path', 'Size', 'Modified'], $other_files);
                $this->newLine();
            }
        }

        // Storage usage
        $total_size = 0;
        foreach ($data as $file => $metadata) {
            $total_size += $metadata['size'];
        }
        $total_size_mb = round($total_size / 1024 / 1024, 2);

        $this->info("Total file size tracked: {$total_size_mb} MB");

        return Command::SUCCESS;
    }
}