<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\CodeTemplates\StubProcessor;

class Submodule_Create_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:app:submodule:create
                            {module : Module name (must exist)}
                            {submodule : Submodule name (lowercase with underscores)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a submodule (page group with own layout within a module). Example: settings within frontend';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $module_name = $this->argument('module');
        $submodule_name = $this->argument('submodule');

        // Validate names (lowercase and underscores only)
        if (!preg_match('/^[a-z_]+$/', $module_name)) {
            $this->error('Module name must contain only lowercase letters and underscores.');
            return 1;
        }

        if (!preg_match('/^[a-z_]+$/', $submodule_name)) {
            $this->error('Submodule name must contain only lowercase letters and underscores.');
            return 1;
        }

        // Check if module exists
        $module_path = base_path("rsx/app/{$module_name}");
        if (!is_dir($module_path)) {
            $this->error("Module '{$module_name}' does not exist.");
            $this->line('');
            $this->line('NOMENCLATURE:');
            $this->line('  Module = Top-level section with shared layout (e.g., frontend, admin)');
            $this->line('  Submodule = Page group with own layout within a module (e.g., settings within frontend)');
            $this->line('');
            $this->line('Create the module first:');
            $this->info("  php artisan rsx:app:module:create {$module_name} --blade");
            return 1;
        }

        // Check if submodule already exists
        $submodule_path = "{$module_path}/{$submodule_name}";
        if (is_dir($submodule_path)) {
            $this->error("Submodule '{$submodule_name}' already exists in module '{$module_name}'");
            return 1;
        }

        // Create submodule directory
        if (!mkdir($submodule_path, 0755, true)) {
            $this->error("Failed to create submodule directory: {$submodule_path}");
            return 1;
        }

        $this->info("Creating submodule '{$submodule_name}' in module '{$module_name}'...");

        // Generate replacements
        $replacements = StubProcessor::generate_replacements($module_name, $submodule_name, 'index');

        // Get parent layout
        $replacements['parent_layout_class'] = StubProcessor::get_parent_layout($module_path);

        try {
            // Create submodule layout file
            $layout_content = StubProcessor::process('submodule_layout', $replacements);
            $layout_file = "{$submodule_path}/{$module_name}_{$submodule_name}_layout.blade.php";
            file_put_contents_safe($layout_file, $layout_content);
            $this->line("Created: {$layout_file}");

            // Create submodule SCSS file
            $scss_content = StubProcessor::process('submodule_scss', $replacements);
            $scss_file = "{$submodule_path}/{$module_name}_{$submodule_name}.scss";
            file_put_contents_safe($scss_file, $scss_content);
            $this->line("Created: {$scss_file}");

            // Create index feature (controller, view, js, scss)
            $this->create_feature_files($submodule_path, $replacements);

        } catch (\Exception $e) {
            $this->error("Failed to create submodule: " . $e->getMessage());
            // Clean up on failure
            if (is_dir($submodule_path)) {
                $this->cleanup_directory($submodule_path);
            }
            return 1;
        }

        $this->info("Submodule '{$submodule_name}' created successfully!");
        $this->info("Route: /{$module_name}/{$submodule_name}");
        $this->info("Add features with: php artisan rsx:app:submodule:feature:create {$module_name} {$submodule_name} <feature>");

        return 0;
    }

    /**
     * Create feature files (controller, view, js, scss)
     */
    protected function create_feature_files($path, $replacements)
    {
        $file_prefix = $replacements['file_prefix'];

        // Create controller
        $controller_content = StubProcessor::process('controller', $replacements);
        $controller_file = "{$path}/{$file_prefix}_controller.php";
        file_put_contents_safe($controller_file, $controller_content);
        $this->line("Created: {$controller_file}");

        // Create view
        $replacements['extends_layout'] = $replacements['layout_class'];
        $replacements['section_declaration'] = "@section('submodule_content')";
        $replacements['section_end'] = "@endsection";

        $view_content = StubProcessor::process('view', $replacements);
        $view_file = "{$path}/{$file_prefix}.blade.php";
        file_put_contents_safe($view_file, $view_content);
        $this->line("Created: {$view_file}");

        // Create JavaScript
        $js_content = StubProcessor::process('javascript', $replacements);
        $js_file = "{$path}/{$file_prefix}.js";
        file_put_contents_safe($js_file, $js_content);
        $this->line("Created: {$js_file}");

        // Create SCSS
        $scss_content = StubProcessor::process('scss', $replacements);
        $scss_file = "{$path}/{$file_prefix}.scss";
        file_put_contents_safe($scss_file, $scss_content);
        $this->line("Created: {$scss_file}");
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