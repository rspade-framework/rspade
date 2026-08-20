<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\Console\Commands\FrameworkDeveloperCommand;
use App\RSpade\Core\Manifest\Manifest;

class Manifest_Show_Command extends FrameworkDeveloperCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:manifest:show 
                            {--classes : Show all registered classes}
                            {--files : Show all indexed files}
                            {--filter= : Filter files by extension (e.g., js, php, blade.php)}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display the current RSX manifest';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get manifest (will auto-build if needed)
        $manifest = Manifest::get_full_manifest();
        $data = $manifest['data'] ?? [];
        
        // Apply filter if provided
        $filter = $this->option('filter');
        if ($filter) {
            $filtered_files = [];
            foreach ($data['files'] ?? [] as $path => $file_data) {
                if (isset($file_data['extension']) && $file_data['extension'] === $filter) {
                    $filtered_files[$path] = $file_data;
                }
            }
            $data['files'] = $filtered_files;
        }
        
        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT));
            return 0;
        }
        
        $this->info('RSX Manifest Information');
        $this->line('');
        $this->line('Build Hash: ' . ($manifest['hash'] ?? 'N/A'));
        $total_count = count($data['files'] ?? []);
        $filter_msg = $this->option('filter') ? ' (filtered: ' . $this->option('filter') . ')' : '';
        $this->line('Total Files: ' . $total_count . $filter_msg);
        $this->line('');
        
        if ($this->option('classes')) {
            $this->show_classes($data);
        } elseif ($this->option('files')) {
            $this->show_files($data);
        } else {
            $this->show_summary($data);
        }
        
        return 0;
    }
    
    protected function show_summary($data)
    {
        // Count file types
        $php_count = 0;
        $js_count = 0;
        $blade_count = 0;
        $other_count = 0;
        
        foreach ($data['files'] ?? [] as $file_data) {
            if (!isset($file_data['extension'])) continue;
            
            switch ($file_data['extension']) {
                case 'php':
                    $php_count++;
                    break;
                case 'js':
                case 'jsx':
                case 'ts':
                case 'tsx':
                    $js_count++;
                    break;
                case 'blade.php':
                    $blade_count++;
                    break;
                default:
                    $other_count++;
            }
        }
        
        $this->line('File Type Summary:');
        $this->line('  PHP Files: ' . $php_count);
        $this->line('  JavaScript/TypeScript: ' . $js_count);
        $this->line('  Blade Templates: ' . $blade_count);
        if ($other_count > 0) {
            $this->line('  Other: ' . $other_count);
        }
        
        // Show routes
        $routes = [];
        foreach ($data['files'] ?? [] as $file_data) {
            if (!empty($file_data['routes'])) {
                foreach ($file_data['routes'] as $route) {
                    $routes[] = [
                        'Route' => $route['path'] ?? '',
                        'Class' => $file_data['class'] ?? 'Unknown',
                        'Methods' => implode(',', $route['methods'] ?? ['GET'])
                    ];
                }
            }
        }
        
        if (!empty($routes)) {
            $this->line('');
            $this->line('Registered Routes:');
            $this->table(['Route', 'Class', 'Methods'], $routes);
        }
    }
    
    protected function show_classes($data)
    {
        $php_classes = [];
        $js_classes = [];
        $views = [];
        
        foreach ($data['files'] ?? [] as $file_data) {
            if (!empty($file_data['class'])) {
                $extends = !empty($file_data['extends']) ? ' extends ' . $file_data['extends'] : '';
                $info = $file_data['class'] . $extends;
                
                if ($file_data['extension'] === 'php') {
                    if (!empty($file_data['routes'])) {
                        $routes = array_map(fn($r) => $r['path'] ?? '', $file_data['routes']);
                        $info .= ' [' . implode(', ', $routes) . ']';
                    }
                    $php_classes[] = $info;
                } elseif (in_array($file_data['extension'], ['js', 'jsx', 'ts', 'tsx'])) {
                    $js_classes[] = $info;
                }
            }
            
            if (!empty($file_data['view_id'])) {
                $views[] = $file_data['view_id'];
            }
        }
        
        if (!empty($php_classes)) {
            $this->line('PHP Classes:');
            foreach ($php_classes as $class) {
                $this->line('  ' . $class);
            }
        }
        
        if (!empty($js_classes)) {
            $this->line('');
            $this->line('JavaScript Classes:');
            foreach ($js_classes as $class) {
                $this->line('  ' . $class);
            }
        }
        
        if (!empty($views)) {
            $this->line('');
            $this->line('Blade Views:');
            foreach ($views as $view) {
                $this->line('  ' . $view);
            }
        }
    }
    
    protected function show_files($data)
    {
        $this->line('Indexed Files:');
        foreach ($data['files'] ?? [] as $path => $info) {
            $class = !empty($info['class']) ? ' [' . $info['class'] . ']' : '';
            $size = $this->format_bytes($info['size'] ?? 0);
            $clean_path = str_replace('\\', '/', $path);
            $this->line(sprintf('  %s (%s)%s', $clean_path, $size, $class));
        }
    }
    
    protected function format_bytes($bytes)
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1) . 'KB';
        } else {
            return round($bytes / 1048576, 1) . 'MB';
        }
    }
}