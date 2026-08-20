<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Manifest\Manifest;
use Symfony\Component\Yaml\Yaml;

class Manifest_Dump_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:manifest:dump
                            {--format=json : Output format (json, yaml, php)}
                            {--filter= : Filter by file path pattern}
                            {--class= : Filter by class name}
                            {--stats : Show only statistics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dump the RSX manifest for debugging';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Initialize manifest
        Manifest::init();
        
        // Get format option
        $format = $this->option('format');
        $filter = $this->option('filter');
        $class_filter = $this->option('class');
        $stats_only = $this->option('stats');
        
        // Validate format
        if (!in_array($format, ['json', 'yaml', 'php'])) {
            $this->error("Invalid format: {$format}. Must be one of: json, yaml, php");
            return Command::FAILURE;
        }
        
        // Check if yaml is available
        // @PHP-CLASS-01-EXCEPTION - Optional dependency check for symfony/yaml
        if ($format === 'yaml' && !class_exists(Yaml::class)) {
            $this->error('YAML support not available. Install symfony/yaml package.');
            return Command::FAILURE;
        }
        
        // Handle stats-only mode
        if ($stats_only) {
            $stats = Manifest::get_stats();
            $this->output_data($stats, $format);
            return Command::SUCCESS;
        }
        
        // Get full manifest structure for dumping
        $data = Manifest::get_full_manifest();
        
        // Apply filters
        if ($filter) {
            $data = $this->filter_by_path($data, $filter);
        }
        
        if ($class_filter) {
            $data = $this->filter_by_class($data, $class_filter);
        }
        
        // Output the data
        $this->output_data($data, $format);
        
        return Command::SUCCESS;
    }
    
    /**
     * Filter manifest data by file path pattern
     */
    protected function filter_by_path(array $data, string $pattern): array
    {
        if (!isset($data['data']['files'])) {
            return $data;
        }
        
        $filtered_files = [];
        
        foreach ($data['data']['files'] as $path => $metadata) {
            if (str_contains($path, $pattern)) {
                $filtered_files[$path] = $metadata;
            }
        }
        
        // Return with same structure
        return [
            'generated' => $data['generated'] ?? date('Y-m-d H:i:s'),
            'hash' => $data['hash'] ?? '',
            'data' => ['files' => $filtered_files]
        ];
    }
    
    /**
     * Filter manifest data by class name
     */
    protected function filter_by_class(array $data, string $class_name): array
    {
        if (!isset($data['data']['files'])) {
            return $data;
        }
        
        $filtered_files = [];
        
        foreach ($data['data']['files'] as $path => $metadata) {
            if (isset($metadata['class']) && str_contains($metadata['class'], $class_name)) {
                $filtered_files[$path] = $metadata;
            } elseif (isset($metadata['fqcn']) && str_contains($metadata['fqcn'], $class_name)) {
                $filtered_files[$path] = $metadata;
            }
        }
        
        // Return with same structure
        return [
            'generated' => $data['generated'] ?? date('Y-m-d H:i:s'),
            'hash' => $data['hash'] ?? '',
            'data' => ['files' => $filtered_files]
        ];
    }
    
    /**
     * Output data in the specified format
     * All formats are pretty-printed by default for readability
     */
    protected function output_data(array $data, string $format): void
    {
        switch ($format) {
            case 'json':
                // Always pretty print JSON for readability
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                break;
                
            case 'yaml':
                // YAML is naturally pretty-printed
                $this->line(Yaml::dump($data, 10, 2));
                break;
                
            case 'php':
                // Always output as readable PHP array
                $this->line('<?php' . PHP_EOL . PHP_EOL . 'return ' . var_export($data, true) . ';');
                break;
        }
    }
}