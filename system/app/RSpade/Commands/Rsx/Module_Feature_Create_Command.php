<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\CodeTemplates\StubProcessor;

class Module_Feature_Create_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:app:module:feature:create
                            {module : Module name (must exist)}
                            {feature : Feature name (lowercase with underscores)}
                            {--blade : Scaffold a server-rendered Blade feature instead of an SPA Action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a feature (screen / CRUD page group) within a module. Default is an SPA Action pair + gated Ajax controller; --blade scaffolds the server-rendered controller + view.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $module_name = $this->argument('module');
        $feature_name = $this->argument('feature');
        $blade_mode = (bool) $this->option('blade');

        // Validate names (lowercase and underscores only)
        if (!preg_match('/^[a-z_]+$/', $module_name)) {
            $this->error('Module name must contain only lowercase letters and underscores.');
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
            $this->line('NOMENCLATURE:');
            $this->line('  Module = Top-level section with shared layout (e.g., frontend, admin)');
            $this->line('  Feature = Screen or CRUD page group within a module (e.g., clients, tasks)');
            $this->line('');
            $this->line('Create the module first:');
            $this->info("  php artisan rsx:app:module:create {$module_name}");
            return 1;
        }

        if ($blade_mode) {
            return $this->create_blade_feature($module_name, $feature_name, $module_path);
        }

        return $this->create_spa_feature($module_name, $feature_name, $module_path);
    }

    /**
     * Scaffold an SPA feature: Action pair + gated Ajax controller, in its own directory.
     *
     * Every SPA feature lives in its own directory - the index feature included, so a
     * module root holds only module-wide files (bundle, bootstrap, layout).
     */
    protected function create_spa_feature(string $module_name, string $feature_name, string $module_path): int
    {
        $replacements = StubProcessor::generate_spa_replacements($module_name, $feature_name);

        $feature_path = "{$module_path}/{$feature_name}";
        $action_class = $replacements['action_class'];
        $file_prefix = $replacements['file_prefix'];

        if (is_dir($feature_path)) {
            $this->error("Feature '{$feature_name}' already exists in module '{$module_name}'");
            return 1;
        }

        if (!mkdir($feature_path, 0755, true)) {
            $this->error("Failed to create feature directory: {$feature_path}");
            return 1;
        }

        $this->info("Creating SPA feature '{$feature_name}' in module '{$module_name}'...");

        try {
            // Action (JS + template)
            file_put_contents_safe("{$feature_path}/{$action_class}.js", StubProcessor::process('spa_action', $replacements));
            $this->info("Created action: {$action_class}.js");
            file_put_contents_safe("{$feature_path}/{$action_class}.jqhtml", StubProcessor::process('spa_action_template', $replacements));
            $this->info("Created template: {$action_class}.jqhtml");

            // Ajax endpoint controller (empty, gated)
            $controller_file = "{$feature_path}/{$file_prefix}_controller.php";
            file_put_contents_safe($controller_file, StubProcessor::process('spa_feature_controller', $replacements));
            $this->info("Created controller: {$file_prefix}_controller.php");
        } catch (\Exception $e) {
            $this->error("Failed to create feature: " . $e->getMessage());
            $this->cleanup_directory($feature_path);
            return 1;
        }

        $this->info("[OK] Feature '{$feature_name}' created successfully in module '{$module_name}'!");
        $this->info("Route: {$replacements['route_path']}");
        $this->info("Action: {$action_class}");
        $this->info("Controller: {$replacements['controller_class']}");
        $this->line('');
        $this->line('Next steps:');
        $this->line('  SPA routing:    php artisan rsx:man spa');
        $this->line('  CRUD shape:     php artisan rsx:man crud');

        return 0;
    }

    /**
     * Scaffold a server-rendered Blade feature (public / SEO pages).
     */
    protected function create_blade_feature(string $module_name, string $feature_name, string $module_path): int
    {
        // Determine feature path and naming
        if ($feature_name === 'index') {
            // Index feature goes in module root
            $feature_path = $module_path;
            $file_prefix = "{$module_name}_index";
        } else {
            // Other features go in subdirectory
            $feature_path = "{$module_path}/{$feature_name}";
            $file_prefix = "{$module_name}_{$feature_name}";

            // Check if feature directory already exists
            if (is_dir($feature_path)) {
                $this->error("Feature '{$feature_name}' already exists in module '{$module_name}'");
                return 1;
            }

            // Create feature directory
            if (!mkdir($feature_path, 0755, true)) {
                $this->error("Failed to create feature directory: {$feature_path}");
                return 1;
            }
        }

        // Check if any feature files already exist
        $files_to_create = [
            "{$feature_path}/{$file_prefix}_controller.php" => 'controller',
            "{$feature_path}/{$file_prefix}.blade.php" => 'view',
            "{$feature_path}/{$file_prefix}.js" => 'JavaScript',
            "{$feature_path}/{$file_prefix}.scss" => 'SCSS',
        ];

        foreach ($files_to_create as $file => $type) {
            if (file_exists($file)) {
                $this->error("Cannot create feature '{$feature_name}': {$type} file already exists at {$file}");
                return 1;
            }
        }

        $this->info("Creating Blade feature '{$feature_name}' in module '{$module_name}'...");

        // Generate replacements
        $replacements = StubProcessor::generate_replacements($module_name, null, $feature_name);

        try {
            // Create controller
            $controller_content = StubProcessor::process('controller', $replacements);
            $controller_file = "{$feature_path}/{$file_prefix}_controller.php";
            file_put_contents_safe($controller_file, $controller_content);
            $this->info("Created controller: {$file_prefix}_controller.php");

            // Create view
            $view_content = StubProcessor::process('view', $replacements);
            $view_file = "{$feature_path}/{$file_prefix}.blade.php";
            file_put_contents_safe($view_file, $view_content);
            $this->info("Created view: {$file_prefix}.blade.php");

            // Create JavaScript
            $js_content = StubProcessor::process('javascript', $replacements);
            $js_file = "{$feature_path}/{$file_prefix}.js";
            file_put_contents_safe($js_file, $js_content);
            $this->info("Created JavaScript: {$file_prefix}.js");

            // Create SCSS
            $scss_content = StubProcessor::process('scss', $replacements);
            $scss_file = "{$feature_path}/{$file_prefix}.scss";
            file_put_contents_safe($scss_file, $scss_content);
            $this->info("Created SCSS: {$file_prefix}.scss");

        } catch (\Exception $e) {
            $this->error("Failed to create feature: " . $e->getMessage());
            // Clean up on failure
            if (is_dir($feature_path) && $feature_name !== 'index') {
                $this->cleanup_directory($feature_path);
            }
            return 1;
        }

        $this->info("[OK] Feature '{$feature_name}' created successfully in module '{$module_name}'!");
        $this->info("Route: {$replacements['route_path']}");
        $this->info("Controller: {$replacements['controller_class']}");
        $this->info("View ID: {$replacements['view_class']}");
        $this->line('');
        $this->line('Blade features are for public / server-rendered pages (login, marketing, SEO).');
        $this->line('Authenticated app screens belong in an SPA Action (drop --blade).');

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
