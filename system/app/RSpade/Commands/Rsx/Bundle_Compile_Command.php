<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Manifest\Manifest;
use Exception;
use Illuminate\Console\Command;
use App\RSpade\Core\Console\Rsx_Artisan;

class Bundle_Compile_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:bundle:compile
                            {bundle? : Bundle class name to compile (optional, compiles all if not specified)}
                            {--build-debug : Enable verbose build output (shows detailed compilation steps)}
                            {--clean : Clear all caches before compiling (runs rsx:clean first)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compile RSX bundles into JavaScript and CSS files';

    /**
     * Create a new command instance
     */
    public function __construct()
    {
        parent::__construct();

        // Check if --build-debug flag is present in command line args
        // Define BUILD_DEBUG_MODE early (though Debugger also checks $_SERVER['argv'] directly)
        if (in_array('--build-debug', $_SERVER['argv'] ?? [])) {
            define('BUILD_DEBUG_MODE', true);
        }
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Prevent being called via $this->call() - must use passthru for fresh process
        $this->prevent_call_from_another_command();

        // Handle --clean flag: run rsx:clean then re-invoke without --clean
        if ($this->option('clean')) {
            $this->info('Clearing caches before compile...');
            // --_no-system-reset: build tooling wants cache invalidation only.
            Rsx_Artisan::passthru('rsx:clean', ['--_no-system-reset']);

            // Re-invoke with all args except --clean.
            $args = [];
            if ($this->argument('bundle')) {
                $args[] = $this->argument('bundle');
            }
            if ($this->option('build-debug')) {
                $args[] = '--build-debug';
            }

            exit(Rsx_Artisan::passthru('rsx:bundle:compile', $args));
        }

        $bundle_arg = $this->argument('bundle');

        // Get all MODULE bundle classes from manifest (not asset bundles)
        // Module bundles are the top-level page bundles that get compiled
        // Asset bundles are dependency declarations auto-discovered during compilation
        $manifest_data = Manifest::get_all();
        $bundle_classes = [];

        foreach ($manifest_data as $file_info) {
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

        // Ensure storage directory exists
        $bundle_dir = storage_path('rsx-build/bundles');
        if (!is_dir($bundle_dir)) {
            mkdir($bundle_dir, 0755, true);
        }

        $this->info('Compiling bundles...');
        $compiled_count = 0;
        $skipped_count = 0;
        $failed_bundles = [];
        $is_single_bundle = count($bundle_classes) === 1;

        foreach ($bundle_classes as $fqcn => $class_name) {
            $this->info("Processing: {$class_name}");

            // Generate hash for this bundle
            if (env('ENHANCED_DEBUG', false)) {
                // In debug mode, use only class name for predictable URLs
                $bundle_hash = substr(hash('sha256', $fqcn), 0, 32);
            } else {
                // In production, include manifest hash for cache-busting
                $manifest_hash = Manifest::get_build_key();
                $bundle_hash = substr(hash('sha256', $manifest_hash . '|' . $fqcn), 0, 32);
            }

            $js_path = "{$bundle_dir}/app.{$bundle_hash}.js";
            $css_path = "{$bundle_dir}/app.{$bundle_hash}.css";

            // Check if files already exist
            if (file_exists($js_path) && file_exists($css_path)) {
                $this->line("  [OK] Already compiled (hash: {$bundle_hash})");
                $skipped_count++;
                continue;
            }

            try {
                // Compile the bundle
                $compiler = new BundleCompiler();
                console_debug('BUNDLE', "Calling compile for {$fqcn}");
                $compiled = $compiler->compile($fqcn);
                console_debug('BUNDLE', 'Compile returned: ' . json_encode(array_keys($compiled)));

                // Read compiled content from bundle files
                $bundle_dir = storage_path('rsx-build/bundles');
                $js_content = '';
                $css_content = '';

                // In production mode, we have single combined files
                if (app()->environment('production')) {
                    if (isset($compiled['js_bundle_path'])) {
                        $js_content = file_get_contents("{$bundle_dir}/{$compiled['js_bundle_path']}");
                    }
                    if (isset($compiled['css_bundle_path'])) {
                        $css_content = file_get_contents("{$bundle_dir}/{$compiled['css_bundle_path']}");
                    }

                    // Write combined files for production
                    file_put_contents_safe($js_path, $js_content);
                    file_put_contents_safe($css_path, $css_content);

                    $js_size = strlen($js_content);
                    $css_size = strlen($css_content);
                } else {
                    // In development mode, don't create combined files
                    // Just report on the individual vendor and app bundles
                    $js_size = 0;
                    $css_size = 0;

                    if (isset($compiled['vendor_js_bundle_path'])) {
                        $js_size += filesize("{$bundle_dir}/{$compiled['vendor_js_bundle_path']}");
                    }
                    if (isset($compiled['app_js_bundle_path'])) {
                        $js_size += filesize("{$bundle_dir}/{$compiled['app_js_bundle_path']}");
                    }

                    if (isset($compiled['vendor_css_bundle_path'])) {
                        $css_size += filesize("{$bundle_dir}/{$compiled['vendor_css_bundle_path']}");
                    }
                    if (isset($compiled['app_css_bundle_path'])) {
                        $css_size += filesize("{$bundle_dir}/{$compiled['app_css_bundle_path']}");
                    }
                }

                $this->line('  [OK] Compiled successfully');
                if (app()->environment('production')) {
                    $this->line('    JS:  ' . $this->format_size($js_size) . " -> app.{$bundle_hash}.js");
                    $this->line('    CSS: ' . $this->format_size($css_size) . " -> app.{$bundle_hash}.css");
                } else {
                    // In dev mode, show the actual split files (vendor and app)
                    if (isset($compiled['vendor_js_bundle_path'])) {
                        $vendor_js_size = filesize("{$bundle_dir}/{$compiled['vendor_js_bundle_path']}");
                        $this->line('    Vendor JS:  ' . $this->format_size($vendor_js_size) . ' -> ' . basename($compiled['vendor_js_bundle_path']));
                    }
                    if (isset($compiled['vendor_css_bundle_path'])) {
                        $vendor_css_size = filesize("{$bundle_dir}/{$compiled['vendor_css_bundle_path']}");
                        $this->line('    Vendor CSS: ' . $this->format_size($vendor_css_size) . ' -> ' . basename($compiled['vendor_css_bundle_path']));
                    }
                    if (isset($compiled['app_js_bundle_path'])) {
                        $app_js_size = filesize("{$bundle_dir}/{$compiled['app_js_bundle_path']}");
                        $this->line('    App JS:     ' . $this->format_size($app_js_size) . ' -> ' . basename($compiled['app_js_bundle_path']));
                    }
                    if (isset($compiled['app_css_bundle_path'])) {
                        $app_css_size = filesize("{$bundle_dir}/{$compiled['app_css_bundle_path']}");
                        $this->line('    App CSS:    ' . $this->format_size($app_css_size) . ' -> ' . basename($compiled['app_css_bundle_path']));
                    }
                }

                $compiled_count++;
            } catch (Exception $e) {
                $this->error('  [ERROR] Failed to compile: ' . $e->getMessage());
                $failed_bundles[$class_name] = $e->getMessage();

                // If compiling a single bundle, fail immediately
                if ($is_single_bundle) {
                    return 1;
                }

                // For multiple bundles, continue to next bundle
                continue;
            }
        }

        $this->newLine();

        // Report results
        if (empty($failed_bundles)) {
            // Success - all bundles compiled
            $this->info("[OK] Compilation complete: {$compiled_count} compiled, {$skipped_count} skipped");

            if ($compiled_count > 0) {
                $this->newLine();
                $this->info("Tip: Use 'php artisan rsx:bundle:show <bundle>' to inspect bundle contents");
            }

            // Clear console_debug environment variables
            if ($this->option('build-debug')) {
                putenv('CONSOLE_DEBUG_FILTER');
                putenv('CONSOLE_DEBUG_CLI');
            }

            return 0;
        } else {
            // Failures occurred
            $total_bundles = $compiled_count + $skipped_count + count($failed_bundles);
            $this->error("[ERROR] Bundle compilation failed");
            $this->newLine();
            $this->line("Results: {$compiled_count} compiled, {$skipped_count} skipped, " . count($failed_bundles) . " failed");
            $this->newLine();
            $this->error("Failed bundles:");
            foreach ($failed_bundles as $bundle_name => $error_message) {
                $this->line("  - {$bundle_name}");
                $this->line("    {$error_message}");
            }

            // Clear console_debug environment variables
            if ($this->option('build-debug')) {
                putenv('CONSOLE_DEBUG_FILTER');
                putenv('CONSOLE_DEBUG_CLI');
            }

            return 1;
        }
    }

    /**
     * Format file size for display
     */
    protected function format_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }

    /**
     * Prevent this command from being called via $this->call() from another command
     *
     * This command MUST run in a fresh process to ensure in-memory caches are cleared.
     * Use Rsx_Artisan::passthru() instead of $this->call() when calling from other commands.
     *
     * @return void
     */
    protected function prevent_call_from_another_command()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($trace as $frame) {
            // Check if we're being called from Artisan::call() or Command::call()
            if (isset($frame['class']) && isset($frame['function'])) {
                $class = $frame['class'];
                $function = $frame['function'];

                // Detect $this->call() from another command
                if ($function === 'call' && str_contains($class, 'Command')) {
                    $this->error('');
                    $this->error('[ERROR] FATAL ERROR: rsx:bundle:compile cannot be called via $this->call()');
                    $this->error('');
                    $this->error('This command MUST run in a fresh process to properly clear in-memory caches.');
                    $this->error('');
                    $this->error('FIX: Use Rsx_Artisan::passthru() instead of $this->call():');
                    $this->error('');
                    $this->line('  // [ERROR] WRONG - runs in same process, caches remain in memory');
                    $this->line('  $this->call(\'rsx:bundle:compile\');');
                    $this->error('');
                    $this->line('  // [OK] CORRECT - fresh process, all caches cleared');
                    $this->line('  Rsx_Artisan::passthru(\'rsx:bundle:compile\');');
                    $this->error('');

                    exit(1);
                }
            }
        }
    }
}
