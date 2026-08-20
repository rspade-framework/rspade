<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\CodeTemplates\StubProcessor;

class Subfeature_Create_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:app:subfeature:create
                            {module : Module name (must exist)}
                            {feature : Feature name (must exist)}
                            {subfeature : Subfeature name (lowercase with underscores)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new subfeature within an RSX module feature';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $module_name = $this->argument('module');
        $feature_name = $this->argument('feature');
        $subfeature_name = $this->argument('subfeature');

        // Validate names (lowercase and underscores only)
        if (!preg_match('/^[a-z_]+$/', $module_name)) {
            $this->error('Module name must contain only lowercase letters and underscores.');
            return 1;
        }

        if (!preg_match('/^[a-z_]+$/', $feature_name)) {
            $this->error('Feature name must contain only lowercase letters and underscores.');
            return 1;
        }

        if (!preg_match('/^[a-z_]+$/', $subfeature_name)) {
            $this->error('Subfeature name must contain only lowercase letters and underscores.');
            return 1;
        }

        // Check if module exists
        $module_path = base_path("rsx/app/{$module_name}");
        if (!is_dir($module_path)) {
            $this->error("Module '{$module_name}' does not exist. Create it first with: php artisan rsx:app:module:create {$module_name} --blade");
            return 1;
        }

        // Determine feature path
        if ($feature_name === 'index') {
            $feature_path = $module_path;
        } else {
            $feature_path = "{$module_path}/{$feature_name}";
        }

        // Check if feature exists
        if (!is_dir($feature_path)) {
            $this->error("Feature '{$feature_name}' does not exist in module '{$module_name}'. Create it first with: php artisan rsx:app:module:feature:create {$module_name} {$feature_name}");
            return 1;
        }

        // Check this is not a submodule (submodules have their own command)
        if (StubProcessor::is_submodule($feature_path)) {
            $this->error("'{$feature_name}' is a submodule. Use: php artisan rsx:app:submodule:feature:create {$module_name} {$feature_name} {$subfeature_name}");
            return 1;
        }

        // Use flat directory structure - subfeatures go in parent feature directory
        // This prevents namespace mismatch with Php_Fixer (namespace must match directory)
        $subfeature_path = $feature_path;
        $file_prefix = "{$module_name}_{$feature_name}_{$subfeature_name}";

        // Check if subfeature controller already exists
        $controller_file = "{$subfeature_path}/{$file_prefix}_controller.php";
        if (file_exists($controller_file)) {
            $this->error("Subfeature '{$subfeature_name}' already exists in feature '{$feature_name}'");
            $this->line("File exists: {$controller_file}");
            return 1;
        }

        $this->info("Creating subfeature '{$subfeature_name}' in feature '{$module_name}/{$feature_name}'...");

        // Generate replacements
        $replacements = StubProcessor::generate_replacements($module_name, null, $feature_name, $subfeature_name);

        try {
            // Create controller
            $controller_content = StubProcessor::process('controller', $replacements);
            $controller_file = "{$subfeature_path}/{$file_prefix}_controller.php";
            file_put_contents_safe($controller_file, $controller_content);
            $this->line("Created: {$controller_file}");

            // Create view
            $view_content = StubProcessor::process('view', $replacements);
            $view_file = "{$subfeature_path}/{$file_prefix}.blade.php";
            file_put_contents_safe($view_file, $view_content);
            $this->line("Created: {$view_file}");

            // Create JavaScript
            $js_content = StubProcessor::process('javascript', $replacements);
            $js_file = "{$subfeature_path}/{$file_prefix}.js";
            file_put_contents_safe($js_file, $js_content);
            $this->line("Created: {$js_file}");

            // Create SCSS
            $scss_content = StubProcessor::process('scss', $replacements);
            $scss_file = "{$subfeature_path}/{$file_prefix}.scss";
            file_put_contents_safe($scss_file, $scss_content);
            $this->line("Created: {$scss_file}");

        } catch (\Exception $e) {
            $this->error("Failed to create subfeature: " . $e->getMessage());
            // Clean up created files on failure
            $files_to_clean = [
                "{$subfeature_path}/{$file_prefix}_controller.php",
                "{$subfeature_path}/{$file_prefix}.blade.php",
                "{$subfeature_path}/{$file_prefix}.js",
                "{$subfeature_path}/{$file_prefix}.scss",
            ];
            foreach ($files_to_clean as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            return 1;
        }

        $this->info("Subfeature '{$subfeature_name}' created successfully!");
        $this->info("Route: /{$module_name}/{$feature_name}/{$subfeature_name}");

        return 0;
    }

    /**
     * Clean up directory on failure
     */
    protected function cleanup_directory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->cleanup_directory($path) : unlink($path);
        }
        rmdir($dir);
    }
}