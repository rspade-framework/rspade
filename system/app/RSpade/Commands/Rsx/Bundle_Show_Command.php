<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;

class Bundle_Show_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:bundle:show {bundle? : Bundle class name to show (optional, shows all if not specified)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display the contents of RSX bundles';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $bundle_arg = $this->argument('bundle');
        
        // Get all bundle classes from manifest
        $manifest_data = Manifest::get_all();
        $bundle_classes = [];
        
        foreach ($manifest_data as $file_info) {
            // Only show Module Bundles (compilable page bundles), not Asset Bundles
            $class_name = $file_info['class'] ?? null;
            if ($class_name && Manifest::php_is_subclass_of($class_name, 'Rsx_Module_Bundle_Abstract')) {
                $fqcn = $file_info['fqcn'] ?? $class_name;
                $bundle_classes[$fqcn] = $class_name;
            }
        }
        
        if (empty($bundle_classes)) {
            $this->error('No bundle classes found in manifest');
            return 1;
        }
        
        // If specific bundle requested, filter to just that one
        if ($bundle_arg) {
            $found = false;
            foreach ($bundle_classes as $fqcn => $class_name) {
                if ($class_name === $bundle_arg || $fqcn === $bundle_arg) {
                    $bundle_classes = [$fqcn => $class_name];
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $this->error("Bundle class not found: {$bundle_arg}");
                $this->info('Available bundles:');
                foreach ($bundle_classes as $fqcn => $class_name) {
                    $this->info("  - {$class_name}");
                }
                return 1;
            }
        }
        
        // Show each bundle
        foreach ($bundle_classes as $fqcn => $class_name) {
            $this->show_bundle($fqcn, $class_name);
            if (count($bundle_classes) > 1) {
                $this->newLine();
            }
        }
        
        return 0;
    }
    
    /**
     * Show details of a single bundle
     */
    protected function show_bundle(string $fqcn, string $class_name): void
    {
        try {
            // Always compile first to ensure fresh data (uses caching if already compiled)
            $compiler = new BundleCompiler();
            $compiled = $compiler->compile($fqcn);

            // Now show the bundle info
            $this->info("Bundle: {$class_name}");
            $this->info(str_repeat('=', strlen("Bundle: {$class_name}")));

            // Get bundle definition
            $definition = Rsx_Bundle_Abstract::get_resolved_definition($fqcn);
            
            // Show included bundles
            if (!empty($definition['bundles'])) {
                $this->newLine();
                $this->info('Included Bundles:');
                foreach ($definition['bundles'] as $bundle) {
                    $this->line("  - {$bundle}");
                }
            }
            
            // Show modules
            if (!empty($definition['modules'])) {
                $this->newLine();
                $this->info('Modules:');
                foreach ($definition['modules'] as $module) {
                    $this->line("  - {$module}");
                }
            }

            // Show NPM modules
            if (!empty($definition['npm_modules'])) {
                $this->newLine();
                $this->info('NPM Modules:');
                foreach ($definition['npm_modules'] as $module => $global) {
                    $this->line("  - {$module} -> {$global}");
                }
            }
            
            // Show CDN assets
            $has_cdn = false;
            if (!empty($compiled['cdn_css']) || !empty($compiled['cdn_js'])) {
                $this->newLine();
                $this->info('CDN Assets:');
                $has_cdn = true;
                
                // CSS CDN
                if (!empty($compiled['cdn_css'])) {
                    $this->line('  CSS:');
                    foreach ($compiled['cdn_css'] as $asset) {
                        $this->line("    - {$asset['url']}");
                        if (!empty($asset['integrity'])) {
                            $this->line("      integrity: {$asset['integrity']}");
                        }
                    }
                }
                
                // JS CDN
                if (!empty($compiled['cdn_js'])) {
                    $this->line('  JavaScript:');
                    foreach ($compiled['cdn_js'] as $asset) {
                        $this->line("    - {$asset['url']}");
                        if (!empty($asset['integrity'])) {
                            $this->line("      integrity: {$asset['integrity']}");
                        }
                    }
                }
            }
            
            // Show included files
            if (!empty($definition['include'])) {
                $this->newLine();
                $this->info('Include Patterns:');
                $js_count = 0;
                $css_count = 0;
                $total_size = 0;

                foreach ($definition['include'] as $pattern) {
                    $this->line("  - {$pattern}");

                    // Show files matching this pattern
                    $files = $this->get_files_for_pattern($pattern);
                    if (!empty($files)) {
                        foreach ($files as $file) {
                            // Get manifest data for this file
                            $manifest_data = Manifest::get_all();
                            $file_info = $manifest_data[$file] ?? null;

                            $extra_info = '';
                            if ($file_info) {
                                // Add class information if available
                                if (!empty($file_info['class'])) {
                                    $class_name = $file_info['class'];
                                    if (!empty($file_info['extends'])) {
                                        $extra_info = " [class: {$class_name} extends {$file_info['extends']}]";
                                    } else {
                                        $extra_info = " [class: {$class_name}]";
                                    }
                                }

                                // Count files by type
                                $extension = $file_info['extension'] ?? pathinfo($file, PATHINFO_EXTENSION);
                                if ($extension === 'js' || !empty($file_info['js_stub'])) {
                                    $js_count++;
                                } elseif ($extension === 'css' || $extension === 'scss') {
                                    $css_count++;
                                }

                                // Add to total size
                                if (!empty($file_info['size'])) {
                                    $total_size += $file_info['size'];
                                }
                            }

                            $this->line("    -> {$file}{$extra_info}");
                        }
                    }
                }

                // Show file count summary
                $this->newLine();
                $this->info('File Count Summary:');
                $this->line("  JavaScript files: {$js_count}");
                $this->line("  CSS/SCSS files: {$css_count}");
                $this->line("  Total size (before compilation): " . $this->format_size($total_size));
            }

            // Show processors
            $this->newLine();
            $this->info('Processors:');

            // Global processors from config
            $global_processors = config('rsx.bundle_processors', []);
            if (!empty($global_processors)) {
                $this->line('  Global (from config):');
                foreach ($global_processors as $processor) {
                    $processor_name = class_basename($processor);
                    $this->line("    - {$processor_name}");
                }
            }

            // Bundle-specific processors
            if (!empty($definition['processors'])) {
                $this->line('  Bundle-specific:');
                foreach ($definition['processors'] as $processor) {
                    $processor_name = is_string($processor) ? class_basename($processor) : $processor;
                    $this->line("    - {$processor_name}");
                }
            }

            if (empty($global_processors) && empty($definition['processors'])) {
                $this->line('  None configured');
            }

            // Show JavaScript API stubs
            $this->show_javascript_api_stubs($compiled, $definition);
            
            
            // Show compiled bundle info
            $this->newLine();
            $this->info('Compiled Bundle:');

            // Use the actual paths from compilation
            if (empty($compiled['js_bundle_path']) && empty($compiled['css_bundle_path'])) {
                throw new \RuntimeException("Bundle compilation failed - no output files generated");
            }

            // JS Bundle
            if (!empty($compiled['js_bundle_path'])) {
                if (!file_exists($compiled['js_bundle_path'])) {
                    throw new \RuntimeException("JS bundle file missing after compilation: {$compiled['js_bundle_path']}");
                }
                $js_size = filesize($compiled['js_bundle_path']);
                $js_filename = basename($compiled['js_bundle_path']);
                $this->line("  JS:  " . $this->format_size($js_size) . " ({$js_filename})");
            } else {
                $this->line("  JS:  No JavaScript content");
            }

            // CSS Bundle
            if (!empty($compiled['css_bundle_path'])) {
                if (!file_exists($compiled['css_bundle_path'])) {
                    throw new \RuntimeException("CSS bundle file missing after compilation: {$compiled['css_bundle_path']}");
                }
                $css_size = filesize($compiled['css_bundle_path']);
                $css_filename = basename($compiled['css_bundle_path']);
                $this->line("  CSS: " . $this->format_size($css_size) . " ({$css_filename})");
            } else {
                $this->line("  CSS: No CSS content");
            }
            
            // Show configuration
            if (!empty($definition['config']) && count($definition['config']) > 4) {
                // Only show if there's more than base config (environment, debug, api_url, build_key)
                $custom_config = array_diff_key($definition['config'], [
                    'environment' => true,
                    'debug' => true,
                    'api_url' => true,
                    'build_key' => true
                ]);
                
                if (!empty($custom_config)) {
                    $this->newLine();
                    $this->info('Configuration:');
                    foreach ($custom_config as $key => $value) {
                        if (is_array($value)) {
                            $this->line("  {$key}: " . json_encode($value));
                        } else {
                            $this->line("  {$key}: {$value}");
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->error("  Failed to analyze bundle: " . $e->getMessage());
        }
    }
    
    /**
     * Get files matching a pattern
     */
    protected function get_files_for_pattern(string $pattern): array
    {
        // Use manifest-based discovery
        $manifest_data = Manifest::get_all();
        $files = [];
        
        // Convert pattern to regex
        $regex = $this->pattern_to_regex($pattern);
        
        foreach ($manifest_data as $file_info) {
            $file_path = $file_info['file'] ?? $file_info['relative_path'] ?? '';
            if (preg_match($regex, $file_path)) {
                $files[] = $file_path;
            }
        }
        
        return $files;
    }
    
    /**
     * Convert glob pattern to regex
     */
    protected function pattern_to_regex(string $pattern): string
    {
        $pattern = str_replace('\\', '/', $pattern);

        // If pattern doesn't contain wildcards, treat it as a directory prefix
        if (strpos($pattern, '*') === false && strpos($pattern, '?') === false) {
            // Match files in this directory and subdirectories
            $pattern = preg_quote($pattern, '/');
            return '/^' . $pattern . '(\/.*)?$/';
        }

        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*\*', '.*', $pattern);
        $pattern = str_replace('\*', '[^/]*', $pattern);
        $pattern = str_replace('\?', '.', $pattern);
        return '/^' . $pattern . '$/';
    }
    
    /**
     * Format file size for display
     */
    protected function format_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1) . " KB";
        } else {
            return round($bytes / 1048576, 2) . " MB";
        }
    }

    /**
     * Show JavaScript API stubs included in the bundle
     */
    protected function show_javascript_api_stubs(array $compiled, array $definition): void
    {
        // Collect all js-stub files that would be in this bundle
        $stub_files = [];
        $manifest_data = Manifest::get_all();

        // Check each file in the include patterns for js_stub references
        if (!empty($definition['include'])) {
            foreach ($definition['include'] as $pattern) {
                $files = $this->get_files_for_pattern($pattern);
                foreach ($files as $file) {
                    $file_info = $manifest_data[$file] ?? null;
                    if ($file_info && !empty($file_info['js_stub'])) {
                        $stub_files[$file_info['js_stub']] = $file;
                    }
                }
            }
        }

        if (empty($stub_files)) {
            return;
        }

        $this->newLine();
        $this->info('JavaScript API Stubs:');

        foreach ($stub_files as $stub_path => $source_file) {
            $full_path = rsx_project_file_path($stub_path);
            if (!file_exists($full_path)) {
                $this->line("  [ERROR] Missing: {$stub_path}");
                continue;
            }

            // Parse the JavaScript file to extract class and methods
            $content = file_get_contents($full_path);

            // Extract class name
            if (preg_match('/^class\s+(\w+)\s*{/m', $content, $class_match)) {
                $class_name = $class_match[1];

                // Extract static methods
                preg_match_all('/^\s*static\s+(?:async\s+)?(\w+)\s*\(/m', $content, $method_matches);
                $methods = $method_matches[1] ?? [];

                $this->line("  {$class_name} - " . implode(', ', $methods));
                $this->line("    (from: {$source_file})");
            }
        }
    }
}