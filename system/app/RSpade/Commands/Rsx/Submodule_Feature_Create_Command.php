<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\CodeTemplates\StubProcessor;

class Submodule_Feature_Create_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:app:submodule:feature:create
                            {module : Module name (must exist)}
                            {submodule : Submodule name (must exist)}
                            {feature : Feature name (lowercase with underscores)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a feature (CRUD page group) within a submodule';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $module_name = $this->argument('module');
        $submodule_name = $this->argument('submodule');
        $feature_name = $this->argument('feature');

        // Validate names (lowercase and underscores only)
        if (!preg_match('/^[a-z_]+$/', $module_name)) {
            $this->error('Module name must contain only lowercase letters and underscores.');
            return 1;
        }

        if (!preg_match('/^[a-z_]+$/', $submodule_name)) {
            $this->error('Submodule name must contain only lowercase letters and underscores.');
            return 1;
        }

        if (!preg_match('/^[a-z_]+$/', $feature_name)) {
            $this->error('Feature name must contain only lowercase letters and underscores.');
            return 1;
        }

        // Check if module exists
        $module_path = base_path("rsx/app/{$module_name}");
        if (!is_dir($module_path)) {
            $this->error("Module '{$module_name}' does not exist.");
            $this->line('');
            $this->line('Create the module first:');
            $this->info("  php artisan rsx:app:module:create {$module_name} --blade");
            return 1;
        }

        // Check if submodule exists
        $submodule_path = "{$module_path}/{$submodule_name}";
        if (!is_dir($submodule_path)) {
            $this->error("Submodule '{$submodule_name}' does not exist in module '{$module_name}'.");
            $this->line('');
            $this->line('NOMENCLATURE:');
            $this->line('  Submodule = Page group with own layout within a module');
            $this->line('  Feature = CRUD page group within a submodule');
            $this->line('');
            $this->line('Create the submodule first:');
            $this->info("  php artisan rsx:app:submodule:create {$module_name} {$submodule_name}");
            return 1;
        }

        // Verify it's actually a submodule
        if (!StubProcessor::is_submodule($submodule_path)) {
            $this->error("'{$submodule_name}' is not a valid submodule (no embedded layout found)");
            return 1;
        }

        // Determine feature path and naming
        if ($feature_name === 'index') {
            // Index feature already exists
            $this->error("Index feature already exists for submodule '{$submodule_name}'");
            return 1;
        } else {
            // Other features go in subdirectory
            $feature_path = "{$submodule_path}/{$feature_name}";
            $file_prefix = "{$module_name}_{$submodule_name}_{$feature_name}";
        }

        // Check if feature already exists
        if (is_dir($feature_path)) {
            $this->error("Feature '{$feature_name}' already exists in submodule '{$submodule_name}'");
            return 1;
        }

        // Create feature directory
        if (!mkdir($feature_path, 0755, true)) {
            $this->error("Failed to create feature directory: {$feature_path}");
            return 1;
        }

        $this->info("Creating feature '{$feature_name}' in submodule '{$module_name}/{$submodule_name}'...");

        // Generate replacements
        $replacements = StubProcessor::generate_replacements($module_name, $submodule_name, $feature_name);

        try {
            // Create controller
            $controller_content = StubProcessor::process('controller', $replacements);
            $controller_file = "{$feature_path}/{$file_prefix}_controller.php";
            file_put_contents_safe($controller_file, $controller_content);
            $this->line("Created: {$controller_file}");

            // Create view
            $replacements['extends_layout'] = StubProcessor::to_class_name("{$module_name}_{$submodule_name}") . '_Layout';
            $replacements['section_declaration'] = "@section('submodule_content')";
            $replacements['section_end'] = "@endsection";

            // Add breadcrumb for non-index features
            $replacements['page_title'] = StubProcessor::to_title($feature_name);

            $view_content = StubProcessor::process('view', $replacements);
            $view_file = "{$feature_path}/{$file_prefix}.blade.php";
            file_put_contents_safe($view_file, $view_content);
            $this->line("Created: {$view_file}");

            // Create JavaScript
            $js_content = StubProcessor::process('javascript', $replacements);
            $js_file = "{$feature_path}/{$file_prefix}.js";
            file_put_contents_safe($js_file, $js_content);
            $this->line("Created: {$js_file}");

            // Create SCSS
            $scss_content = StubProcessor::process('scss', $replacements);
            $scss_file = "{$feature_path}/{$file_prefix}.scss";
            file_put_contents_safe($scss_file, $scss_content);
            $this->line("Created: {$scss_file}");

        } catch (\Exception $e) {
            $this->error("Failed to create feature: " . $e->getMessage());
            // Clean up on failure
            if (is_dir($feature_path)) {
                $this->cleanup_directory($feature_path);
            }
            return 1;
        }

        $this->info("Feature '{$feature_name}' created successfully!");
        $this->info("Route: /{$module_name}/{$submodule_name}/{$feature_name}");
        $this->info("Add subfeatures with: php artisan rsx:app:submodule:subfeature:create {$module_name} {$submodule_name} {$feature_name} <subfeature>");

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