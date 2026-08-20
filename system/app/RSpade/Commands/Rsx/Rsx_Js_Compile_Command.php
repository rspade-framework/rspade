<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Bundle\RsxJavaScriptCompiler;
use App\RSpade\Core\Manifest\Manifest;

class Rsx_Js_Compile_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:js:compile {name=frontend}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compile RSX JavaScript bundle';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $bundle_name = $this->argument('name');
        
        $this->info("Compiling RSX JavaScript bundle: {$bundle_name}");
        
        try {
            // First scan the manifest
            $this->info("Scanning manifest...");
            Manifest::scan();
            
            // Compile the JavaScript bundle
            $compiler = new RsxJavaScriptCompiler();
            
            $start = microtime(true);
            $result = $compiler->compile($bundle_name);
            $time = round(microtime(true) - $start, 2);
            
            $this->info("[OK] Bundle compiled in {$time}s");
            
            // Show results
            $this->info("\nBundle Information:");
            $this->info("  Entry class: " . ($result['entry_class'] ?? 'None'));
            $this->info("  Files included: " . count($result['files']));
            $this->info("  Bundle path: " . str_replace(base_path() . '/', '', $result['bundle_path']));
            
            if ($this->option('verbose')) {
                $this->info("\nIncluded files:");
                foreach ($result['files'] as $file) {
                    $this->info("  - " . str_replace(base_path() . '/', '', $file));
                }
            }
            
            // Show sample usage
            $this->info("\nUsage in Blade template:");
            $this->info("  <script src=\"/" . str_replace(base_path() . '/', '', $result['bundle_path']) . "\"></script>");
            $this->info("  <script>");
            $this->info("    window.RSX_DATA = @json(\$data);");
            $this->info("  </script>");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Failed to compile bundle: " . $e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            
            return 1;
        }
    }
}