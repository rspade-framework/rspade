<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

class Console_Debug_List_Channels_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:console_debug:list_channels
                            {--php : Show only PHP channels}
                            {--js : Show only JavaScript channels}
                            {--count : Include usage count for each channel}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all console_debug channels used in the codebase';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $show_php = $this->option('php');
        $show_js = $this->option('js');
        $show_count = $this->option('count');

        // If neither specified, show both
        if (!$show_php && !$show_js) {
            $show_php = true;
            $show_js = true;
        }

        $channels = [];

        // Directories to scan
        $directories = [
            base_path('app'),
            base_path('rsx'),
        ];

        // Scan PHP files
        if ($show_php) {
            $this->info('Scanning PHP files...');
            foreach ($directories as $dir) {
                if (is_dir($dir)) {
                    $this->_scan_php_files($dir, $channels);
                }
            }
        }

        // Scan JavaScript files
        if ($show_js) {
            $this->info('Scanning JavaScript files...');
            foreach ($directories as $dir) {
                if (is_dir($dir)) {
                    $this->_scan_js_files($dir, $channels);
                }
            }
        }

        // Sort channels
        ksort($channels);

        // Display results
        $this->newLine();
        $this->info('Found ' . count($channels) . ' unique channels:');
        $this->newLine();

        // Prepare table data
        $table_data = [];
        foreach ($channels as $channel => $info) {
            $row = [$channel];

            // Add language column
            $languages = [];
            if ($info['php'] > 0) $languages[] = 'PHP';
            if ($info['js'] > 0) $languages[] = 'JS';
            $row[] = implode(', ', $languages);

            // Add count if requested
            if ($show_count) {
                $total = $info['php'] + $info['js'];
                $row[] = $total;
                if ($show_php && $show_js) {
                    $row[] = $info['php'];
                    $row[] = $info['js'];
                }
            }

            $table_data[] = $row;
        }

        // Build headers
        $headers = ['Channel', 'Language'];
        if ($show_count) {
            $headers[] = 'Total';
            if ($show_php && $show_js) {
                $headers[] = 'PHP';
                $headers[] = 'JS';
            }
        }

        $this->table($headers, $table_data);

        return Command::SUCCESS;
    }

    /**
     * Scan PHP files for console_debug calls
     */
    private function _scan_php_files(string $directory, array &$channels): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getRealPath());

                // Match console_debug calls
                // Pattern: console_debug("CHANNEL", ...) or console_debug('CHANNEL', ...)
                if (preg_match_all('/console_debug\s*\(\s*[\'"]([^\'",]+)[\'"]/', $content, $matches)) {
                    foreach ($matches[1] as $channel) {
                        // Normalize channel name
                        $channel = strtoupper(str_replace(['[', ']'], '', $channel));

                        if (!isset($channels[$channel])) {
                            $channels[$channel] = ['php' => 0, 'js' => 0];
                        }
                        $channels[$channel]['php']++;
                    }
                }
            }
        }
    }

    /**
     * Scan JavaScript files for console_debug calls
     */
    private function _scan_js_files(string $directory, array &$channels): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['js', 'jsx', 'ts', 'tsx'])) {
                $content = file_get_contents($file->getRealPath());

                // Match console_debug calls
                // Pattern: console_debug("CHANNEL", ...) or console_debug('CHANNEL', ...) or console_debug(`CHANNEL`, ...)
                if (preg_match_all('/console_debug\s*\(\s*[\'"`]([^\'"`\$,]+)[\'"`]/', $content, $matches)) {
                    foreach ($matches[1] as $channel) {
                        // Normalize channel name
                        $channel = strtoupper(str_replace(['[', ']'], '', $channel));

                        if (!isset($channels[$channel])) {
                            $channels[$channel] = ['php' => 0, 'js' => 0];
                        }
                        $channels[$channel]['js']++;
                    }
                }
            }
        }
    }
}